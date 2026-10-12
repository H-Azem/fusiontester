<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ManualApp;
use App\Repositories\Manual\ManualAppRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list of apps built for hand-testing. The build itself is a later step; this is
 * the registry: which repository, which branch, and the name it is known by.
 */
class ManualAppController extends Controller
{
    public function __construct(private ManualAppRepository $apps = new ManualAppRepository) {}

    public function index(): JsonResponse
    {
        return $this->legacyResponse([
            'apps' => $this->apps->all()->map(fn (ManualApp $app) => $this->present($app))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'projectId' => ['required', 'integer', 'min:1'],
            'projectPath' => ['required', 'string', 'max:500'],
            'branch' => ['required', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:120'],
        ]);

        $app = $this->apps->create([
            ManualApp::PROJECT_ID => (int) $validated['projectId'],
            ManualApp::PROJECT_PATH => $validated['projectPath'],
            ManualApp::BRANCH => $validated['branch'],
            ManualApp::TITLE => trim($validated['title']),
        ]);

        return $this->legacyResponse(['app' => $this->present($app)], Response::HTTP_CREATED);
    }

    public function destroy(int $id): JsonResponse
    {
        $app = $this->apps->find($id);

        if ($app === null) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $this->apps->delete($app);

        return $this->legacyResponse(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(ManualApp $app): array
    {
        return [
            'id' => (int) $app->getId(),
            'projectId' => (int) $app->getProjectId(),
            'projectPath' => $app->getProjectPath(),
            'branch' => $app->getBranch(),
            'title' => $app->getTitle(),
        ];
    }
}
