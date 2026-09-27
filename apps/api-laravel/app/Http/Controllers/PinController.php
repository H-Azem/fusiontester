<?php

namespace App\Http\Controllers;

use App\Models\Pin;
use App\Repositories\Run\PinRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PinController extends Controller
{
    public function __construct(private PinRepository $pins = new PinRepository) {}

    public function index(): JsonResponse
    {
        // Keys come from the column constants, but the payload keeps the
        // camelCase the dashboard reads — the existing contract wins.
        return $this->legacyResponse([
            'pins' => array_map(fn (Pin $pin) => [
                'kind' => $pin->getKind(),
                'projectId' => (int) $pin->getProjectId(),
                'projectPath' => $pin->getProjectPath(),
                'branch' => $pin->getBranch(),
            ], $this->pins->all()),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:repository,branch'],
            'projectId' => ['required', 'integer', 'min:1'],
            'projectPath' => ['required', 'string', 'max:500'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        if ($validated['kind'] === Pin::KIND_BRANCH && (($validated['branch'] ?? '') === '')) {
            return $this->legacyResponse(
                ['error' => 'branch_required', 'message' => 'A branch name is required for branch pins.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->pins->upsert(
            $validated['kind'],
            (int) $validated['projectId'],
            $validated['projectPath'],
            $validated['branch'] ?? null
        );

        return $this->legacyResponse(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:repository,branch'],
            'projectId' => ['required', 'integer', 'min:1'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $removed = $this->pins->delete(
            $validated['kind'],
            (int) $validated['projectId'],
            $validated['branch'] ?? null
        );

        return $this->legacyResponse(['ok' => true, 'removed' => $removed]);
    }
}
