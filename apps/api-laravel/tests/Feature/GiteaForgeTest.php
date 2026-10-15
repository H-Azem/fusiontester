<?php

namespace Tests\Feature;

use App\Services\Gitlab\GitlabClient;
use App\Services\Gitlab\GitlabConnectionData;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The same client serves both forges. Everything the rest of the application
 * reads — a numeric id, `path_with_namespace`, tree/blob entries — has to keep
 * working when the connection points at Gitea.
 */
class GiteaForgeTest extends TestCase
{
    private function connection(): GitlabConnectionData
    {
        return new GitlabConnectionData(
            'https://gitea.example.com',
            'tok123',
            null,
            GitlabConnectionData::PROVIDER_GITEA,
        );
    }

    /** @return array<string, mixed> */
    private function repository(): array
    {
        return [
            'id' => 7,
            'name' => 'pos',
            'full_name' => 'acme/pos',
            'default_branch' => 'main',
            'private' => true,
            'updated_at' => '2026-10-15T09:00:00Z',
            'html_url' => 'https://gitea.example.com/acme/pos',
        ];
    }

    #[Test]
    public function it_lists_repositories_in_the_shape_the_dashboard_reads(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user/repos*' => Http::response([$this->repository()], 200),
        ]);

        $listing = (new GitlabClient)->listProjects($this->connection());

        $this->assertCount(1, $listing['projects']);
        $this->assertFalse($listing['truncated']);

        $project = $listing['projects'][0];
        $this->assertSame(7, $project['id']);
        $this->assertSame('pos', $project['name']);
        $this->assertSame('acme/pos', $project['path_with_namespace']);
        $this->assertSame('main', $project['default_branch']);
        $this->assertSame('private', $project['visibility']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/user/repos')
            && $request->hasHeader('Authorization', 'token tok123'));
    }

    #[Test]
    public function it_resolves_the_id_to_owner_name_for_branches(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user/repos*' => Http::response([$this->repository()], 200),
            'gitea.example.com/api/v1/repos/acme/pos/branches*' => Http::response([
                ['name' => 'main', 'protected' => true, 'commit' => ['timestamp' => '2026-10-15T09:00:00Z']],
                ['name' => 'dev', 'protected' => false, 'commit' => ['timestamp' => '2026-10-14T09:00:00Z']],
            ], 200),
        ]);

        $listing = (new GitlabClient)->listBranches($this->connection(), 7);

        $this->assertCount(2, $listing['branches']);
        $this->assertTrue($listing['branches'][0]['default']);
        $this->assertFalse($listing['branches'][1]['default']);
        $this->assertSame('2026-10-15T09:00:00Z', $listing['branches'][0]['commit']['created_at']);
    }

    #[Test]
    public function it_maps_a_directory_listing_to_tree_entries(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user/repos*' => Http::response([$this->repository()], 200),
            'gitea.example.com/api/v1/repos/acme/pos/contents/.maestro*' => Http::response([
                ['name' => 'flows', 'path' => '.maestro/flows', 'type' => 'dir'],
                ['name' => 'config.yaml', 'path' => '.maestro/config.yaml', 'type' => 'file'],
            ], 200),
        ]);

        $entries = (new GitlabClient)->getTree($this->connection(), 7, 'main', '.maestro');

        $this->assertSame('tree', $entries[0]['type']);
        $this->assertSame('blob', $entries[1]['type']);
        $this->assertSame('.maestro/flows', $entries[0]['path']);
    }

    #[Test]
    public function a_missing_directory_is_null_not_an_empty_list(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user/repos*' => Http::response([$this->repository()], 200),
            'gitea.example.com/api/v1/repos/acme/pos/contents/*' => Http::response(['message' => 'not found'], 404),
        ]);

        $this->assertNull((new GitlabClient)->getTree($this->connection(), 7, 'main', 'nope'));
    }

    #[Test]
    public function it_reads_a_file_and_treats_a_missing_one_as_null(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user/repos*' => Http::response([$this->repository()], 200),
            'gitea.example.com/api/v1/repos/acme/pos/raw/pubspec.yaml*' => Http::response("name: pos\n", 200),
            'gitea.example.com/api/v1/repos/acme/pos/raw/missing.yaml*' => Http::response('', 404),
        ]);

        $client = new GitlabClient;

        $this->assertSame("name: pos\n", $client->getFileRaw($this->connection(), 7, 'pubspec.yaml', 'main'));
        $this->assertNull($client->getFileRaw($this->connection(), 7, 'missing.yaml', 'main'));
    }

    #[Test]
    public function it_verifies_a_token_through_the_user_endpoint(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user' => Http::response(['login' => 'ci-bot'], 200),
        ]);

        $result = (new GitlabClient)->verifyConnection($this->connection());

        $this->assertTrue($result['ok']);
        $this->assertSame('ci-bot', $result['tokenName']);
        // Gitea cannot report scopes, so the check says so instead of passing silently.
        $this->assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function a_rejected_token_is_reported_as_an_error(): void
    {
        Http::fake([
            'gitea.example.com/api/v1/user' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $result = (new GitlabClient)->verifyConnection($this->connection());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', (string) $result['error']);
    }
}
