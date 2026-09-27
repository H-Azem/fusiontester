<?php

namespace App\Http\Controllers;

use App\Repositories\Run\PinRepository;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Services\Gitlab\FlutterInspector;
use App\Services\Gitlab\GitlabApiError;
use App\Services\Gitlab\GitlabClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repositories, branches, and what a branch is actually made of.
 *
 * Projects are returned in the gateway's natural order with a `pinned` flag;
 * ordering is applied by the dashboard so unpinning re-sorts immediately.
 */
class GitlabController extends Controller
{
    const NOT_CONFIGURED_MESSAGE = 'Connect GitLab on the settings page before loading repositories.';

    public function __construct(
        private GitlabConnectionRepository $connections = new GitlabConnectionRepository,
        private PinRepository $pins = new PinRepository,
        private GitlabClient $client = new GitlabClient,
        private FlutterInspector $inspector = new FlutterInspector,
    ) {}

    public function projects(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $connection = $this->connections->data();

        if ($connection === null) {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => self::NOT_CONFIGURED_MESSAGE],
                Response::HTTP_CONFLICT
            );
        }

        try {
            $listing = $this->client->listProjects($connection, is_string($search) ? $search : null);
        } catch (\Throwable $exception) {
            return $this->gitlabError($exception, $connection->token);
        }

        $projects = $listing['projects'];
        $pinnedIds = $this->pins->pinnedRepositoryIds();

        // A pinned project has to appear even when it falls outside the recency
        // window that the listing fetched.
        $missing = array_values(array_filter(
            $pinnedIds,
            fn (int $id) => ! $this->containsProject($projects, $id)
        ));

        if ($missing !== []) {
            $projects = array_merge($this->client->fetchProjectsByIds($connection, $missing), $projects);
        }

        return $this->legacyResponse([
            'truncated' => $listing['truncated'],
            'projects' => array_map(fn (array $project) => [
                'id' => $project['id'],
                'name' => $project['name'],
                'pathWithNamespace' => $project['path_with_namespace'],
                'defaultBranch' => $project['default_branch'] ?? null,
                'visibility' => $project['visibility'] ?? 'private',
                'lastActivityAt' => $project['last_activity_at'] ?? null,
                'webUrl' => $project['web_url'] ?? '',
                'pinned' => in_array((int) $project['id'], $pinnedIds, true),
            ], $projects),
        ]);
    }

    public function branches(int $projectId): JsonResponse
    {
        $connection = $this->connections->data();

        if ($connection === null) {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => self::NOT_CONFIGURED_MESSAGE],
                Response::HTTP_CONFLICT
            );
        }

        try {
            $listing = $this->client->listBranches($connection, $projectId);
        } catch (\Throwable $exception) {
            return $this->gitlabError($exception, $connection->token);
        }

        $pinned = $this->pins->pinnedBranches($projectId);

        return $this->legacyResponse([
            'truncated' => $listing['truncated'],
            'branches' => array_map(fn (array $branch) => [
                'name' => $branch['name'],
                'default' => (bool) ($branch['default'] ?? false),
                'protected' => (bool) ($branch['protected'] ?? false),
                'lastCommitAt' => $branch['commit']['created_at'] ?? null,
                'pinned' => in_array($branch['name'], $pinned, true),
            ], $listing['branches']),
        ]);
    }

    public function branchCheck(Request $request, int $projectId): JsonResponse
    {
        $ref = (string) $request->query('ref', '');
        if ($ref === '') {
            return $this->legacyResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->connections->data();
        if ($connection === null) {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => self::NOT_CONFIGURED_MESSAGE],
                Response::HTTP_CONFLICT
            );
        }

        try {
            return $this->legacyResponse($this->inspector->checkBranch($connection, $projectId, $ref));
        } catch (\Throwable $exception) {
            return $this->gitlabError($exception, $connection->token);
        }
    }

    public function tests(Request $request, int $projectId): JsonResponse
    {
        $ref = (string) $request->query('ref', '');
        if ($ref === '') {
            return $this->legacyResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->connections->data();
        if ($connection === null) {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => self::NOT_CONFIGURED_MESSAGE],
                Response::HTTP_CONFLICT
            );
        }

        try {
            return $this->legacyResponse([
                'ref' => $ref,
                'tests' => $this->inspector->listMaestroTests($connection, $projectId, $ref),
            ]);
        } catch (\Throwable $exception) {
            return $this->gitlabError($exception, $connection->token);
        }
    }

    private function containsProject(array $projects, int $id): bool
    {
        foreach ($projects as $project) {
            if ((int) $project['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    private function gitlabError(\Throwable $exception, string $token): JsonResponse
    {
        if ($exception instanceof GitlabApiError) {
            return $this->legacyResponse(
                ['error' => 'gitlab_error', 'message' => $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return $this->legacyResponse(
            ['error' => 'unreachable', 'message' => GitlabClient::redact($exception->getMessage(), $token)],
            Response::HTTP_BAD_GATEWAY
        );
    }
}
