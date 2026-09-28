<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteRunJob;
use App\Models\ProjectSetting;
use App\Models\Run;
use App\Repositories\Run\RunRepository;
use App\Http\Resources\RunArtifacts;
use App\Http\Resources\RunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs are rows, not in-flight requests: the dashboard polls them and the worker
 * picks them up, so a closed browser cannot stop a run.
 */
class RunController extends Controller
{
    public function __construct(private RunRepository $runs = new RunRepository) {}

    public function index(): JsonResponse
    {
        return $this->legacyResponse([
            'runs' => RunResource::collection($this->runs->recent())->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'projectId' => ['required', 'integer', 'min:1'],
            'projectPath' => ['required', 'string', 'max:500'],
            'branch' => ['required', 'string', 'max:300'],
            'tests' => ['sometimes', 'array', 'max:200'],
            'tests.*' => ['string', 'max:300'],
            'runKinds' => ['required', 'array', 'min:1', 'max:2'],
            'runKinds.*' => ['in:maestro,ai'],
            'environments' => ['required', 'array', 'min:1', 'max:2'],
            'environments.*' => ['in:development,production'],
            'orientation' => ['sometimes', 'in:horizontal,vertical'],
            'dartDefines' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $run = $this->runs->create([
            Run::PROJECT_ID => (int) $validated['projectId'],
            Run::PROJECT_PATH => $validated['projectPath'],
            Run::BRANCH => $validated['branch'],
            Run::TESTS => $validated['tests'] ?? [],
            Run::RUN_KINDS => $validated['runKinds'],
            Run::ENVIRONMENTS => $validated['environments'],
            Run::ORIENTATION => $validated['orientation'] ?? ProjectSetting::ORIENTATION_DEFAULT,
            Run::DART_DEFINES => $validated['dartDefines'] ?? '',
        ]);

        ExecuteRunJob::dispatch((string) $run->getId());

        return $this->legacyResponse(
            (new RunResource($run))->resolve(),
            Response::HTTP_CREATED
        );
    }

    public function show(string $id): JsonResponse
    {
        $run = $this->runs->find($id);

        if ($run === null) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->legacyResponse((new RunResource($run))->resolve());
    }

    public function screenshot(string $id)
    {
        return $this->serveArtifact($id, 'screenshot.png');
    }

    public function maestroScreenshot(string $id)
    {
        return $this->serveArtifact($id, 'maestro-failure.png');
    }

    public function aiScreenshot(string $id)
    {
        return $this->serveArtifact($id, 'ai-failure.png');
    }

    private function serveArtifact(string $id, string $file)
    {
        $path = RunArtifacts::path($id, $file);

        if (! is_file($path)) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // The second argument is a header array, not a content type.
        return ResponseFactory::file($path, ['Content-Type' => 'image/png']);
    }
}
