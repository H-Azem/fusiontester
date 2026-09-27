<?php

namespace Tests\Feature;

use App\Models\GitlabConnection;
use App\Models\Pin;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The contract the dashboard reads for everything after login.
 */
class PlatformApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    #[Test]
    public function every_platform_endpoint_requires_a_session(): void
    {
        foreach (['settings/gitlab', 'settings/ai', 'gitlab/projects', 'pins', 'runs'] as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }

    #[Test]
    public function gitlab_settings_round_trip_without_ever_returning_the_token(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->getJson('/settings/gitlab')
            ->assertSuccessful()
            ->assertJsonPath('configured', false);

        $this->asSession($token)->putJson('/settings/gitlab', [
            'baseUrl' => 'git.example.com/',
            'token' => 'glpat-super-secret',
        ])->assertSuccessful()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('baseUrl', 'https://git.example.com')
            ->assertJsonPath('tokenHint', '••••cret')
            ->assertJsonMissingPath('token');

        // The scheme is added when missing, and only the ciphertext is stored.
        $row = GitlabConnection::query()->firstOrFail();
        $this->assertStringNotContainsString('glpat-super-secret', (string) $row->getTokenCiphertext());
    }

    #[Test]
    public function saving_gitlab_settings_requires_a_token_the_first_time(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->putJson('/settings/gitlab', ['baseUrl' => 'https://git.example.com'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'token_required');
    }

    #[Test]
    public function ai_settings_show_defaults_and_save_both_credentials(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->getJson('/settings/ai')
            ->assertSuccessful()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('openaiBaseUrl', 'https://api.openai.com/v1')
            ->assertJsonPath('jevBaseUrl', 'https://api.typesafe.ai');

        $this->asSession($token)->putJson('/settings/ai', [
            'openaiBaseUrl' => 'https://api.openai.com/v1',
            'openaiModel' => 'gpt-4o-mini',
            'openaiToken' => 'sk-test-1234',
            'jevBaseUrl' => 'https://api.typesafe.ai',
            'jevToken' => 'ts-test-5678',
            'maxSteps' => 12,
        ])->assertSuccessful()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('openaiModel', 'gpt-4o-mini')
            ->assertJsonPath('openaiTokenHint', '••••1234')
            ->assertJsonPath('maxSteps', 12);
    }

    #[Test]
    public function pins_are_listed_upserted_and_removed(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->getJson('/pins')->assertSuccessful()->assertJsonCount(0, 'pins');

        $this->asSession($token)->putJson('/pins', [
            'kind' => Pin::KIND_REPOSITORY,
            'projectId' => 33,
            'projectPath' => 'group/app',
        ])->assertSuccessful();

        $this->asSession($token)->putJson('/pins', [
            'kind' => Pin::KIND_BRANCH,
            'projectId' => 33,
            'projectPath' => 'group/app',
            'branch' => 'main',
        ])->assertSuccessful();

        $this->asSession($token)
            ->getJson('/pins')
            ->assertSuccessful()
            ->assertJsonCount(2, 'pins')
            ->assertJsonPath('pins.0.projectId', 33);

        $this->asSession($token)
            ->deleteJson('/pins?kind=repository&projectId=33')
            ->assertSuccessful()
            ->assertJsonPath('removed', true);

        $this->asSession($token)->getJson('/pins')->assertJsonCount(1, 'pins');
    }

    #[Test]
    public function a_branch_pin_without_a_branch_is_rejected(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->putJson('/pins', [
            'kind' => Pin::KIND_BRANCH,
            'projectId' => 33,
            'projectPath' => 'group/app',
        ])->assertStatus(400)->assertJsonPath('error', 'branch_required');
    }

    #[Test]
    public function project_settings_fall_back_to_the_default_orientation(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->getJson('/projects/33/settings')
            ->assertSuccessful()
            ->assertJsonPath('projectId', 33)
            ->assertJsonPath('orientation', 'horizontal')
            ->assertJsonPath('dartDefines', '');
    }

    #[Test]
    public function creating_a_run_pre_creates_every_stage_and_queues_the_work(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $response = $this->asSession($token)->postJson('/runs', [
            'projectId' => 33,
            'projectPath' => 'group/app',
            'branch' => 'main',
            'tests' => ['smoke'],
            'runKinds' => ['maestro'],
            'environments' => ['development'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('currentStep', 'queued')
            ->assertJsonPath('runKinds.0', 'maestro')
            ->assertJsonPath('orientation', 'horizontal');

        $this->assertCount(9, $response->json('steps'));
        // The steps payload is camelCase, matching what the dashboard reads.
        $response->assertJsonPath('steps.0.key', 'queued');
        $this->assertArrayHasKey('startedAt', $response->json('steps.0'));
        $this->assertArrayHasKey('finishedAt', $response->json('steps.0'));
        $this->assertSame(1, Run::query()->count());
        Queue::assertPushed(\App\Jobs\ExecuteRunJob::class);
    }

    #[Test]
    public function runs_are_listed_newest_first_and_unknown_ids_are_not_found(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/runs', [
            'projectId' => 1, 'projectPath' => 'a/one', 'branch' => 'main',
            'runKinds' => ['maestro'], 'environments' => ['development'],
        ])->assertStatus(201);

        $second = $this->asSession($token)->postJson('/runs', [
            'projectId' => 2, 'projectPath' => 'a/two', 'branch' => 'dev',
            'runKinds' => ['ai'], 'environments' => ['development'],
        ])->assertStatus(201);

        $this->asSession($token)
            ->getJson('/runs')
            ->assertSuccessful()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.projectPath', 'a/two');

        $id = $second->json('id');
        $this->asSession($token)->getJson('/runs/'.$id)->assertSuccessful()->assertJsonPath('id', $id);
        $this->asSession($token)->getJson('/runs/does-not-exist')->assertStatus(404);
    }

    #[Test]
    public function loading_repositories_without_a_connection_answers_conflict(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->getJson('/gitlab/projects')
            ->assertStatus(409)
            ->assertJsonPath('error', 'not_configured');
    }
}
