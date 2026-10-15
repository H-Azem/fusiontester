<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateFlutterSdkJob;
use App\Models\Run;
use App\Services\Run\FlutterSdk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Flutter SDK behind every build.
 *
 * Updating it can change how an app resolves its packages, so it is explicit,
 * it refuses to start while a test is in flight, and the revision it replaced is
 * kept so it can be put back.
 */
class FlutterSdkController extends Controller
{
    public function __construct(private FlutterSdk $sdk = new FlutterSdk) {}

    public function show(): JsonResponse
    {
        return $this->legacyResponse($this->sdk->status());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A release tag such as 3.44.8; anything else is taken as "latest stable".
            'version' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z._-]+$/'],
        ]);

        if ($busy = $this->inFlightRun()) {
            return $this->legacyResponse([
                'error' => 'run_in_flight',
                'message' => "A test is still using the SDK ({$busy}). Try again once it finishes.",
            ], Response::HTTP_CONFLICT);
        }

        UpdateFlutterSdkJob::dispatch($validated['version'] ?? null);

        return $this->legacyResponse(['started' => true] + $this->sdk->status());
    }

    public function restore(): JsonResponse
    {
        if ($busy = $this->inFlightRun()) {
            return $this->legacyResponse([
                'error' => 'run_in_flight',
                'message' => "A test is still using the SDK ({$busy}). Try again once it finishes.",
            ], Response::HTTP_CONFLICT);
        }

        if (($this->sdk->status()['previousRevision'] ?? null) === null) {
            return $this->legacyResponse([
                'error' => 'nothing_to_restore',
                'message' => 'No previous SDK has been recorded yet.',
            ], Response::HTTP_BAD_REQUEST);
        }

        UpdateFlutterSdkJob::dispatch(null, true);

        return $this->legacyResponse(['started' => true] + $this->sdk->status());
    }

    /** The label of a run that is queued or running, or null when nothing is. */
    private function inFlightRun(): ?string
    {
        $run = Run::query()
            ->whereIn(Run::STATUS, [Run::STATUS_QUEUED, Run::STATUS_RUNNING])
            ->orderByDesc(Run::CREATED_AT)
            ->first();

        if ($run === null) {
            return null;
        }

        $tests = $run->getTests() ?? [];

        return $tests === [] ? 'a run' : implode(', ', array_slice($tests, 0, 3));
    }
}
