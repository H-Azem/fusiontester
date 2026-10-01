<?php

namespace App\Http\Controllers;

use App\Models\ProjectSetting;
use App\Repositories\Settings\ProjectSettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSettingsController extends Controller
{
    public function __construct(private ProjectSettingRepository $settings = new ProjectSettingRepository) {}

    public function show(int $projectId): JsonResponse
    {
        return $this->legacyResponse($this->payload($projectId));
    }

    /**
     * Remembers the choices a run was started with, so the next visit opens on the
     * same shape and lane instead of asking again.
     */
    public function update(Request $request, int $projectId): JsonResponse
    {
        $validated = $request->validate([
            'orientation' => ['sometimes', 'in:horizontal,vertical'],
            'platform' => ['sometimes', 'in:web,android'],
            'dartDefines' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->settings->save($projectId, [
            ProjectSetting::ORIENTATION => $validated['orientation'] ?? null,
            ProjectSetting::PLATFORM => $validated['platform'] ?? null,
            ProjectSetting::DART_DEFINES => $validated['dartDefines'] ?? null,
        ]);

        return $this->legacyResponse($this->payload($projectId));
    }

    /** @return array<string, mixed> */
    private function payload(int $projectId): array
    {
        $row = $this->settings->row($projectId);

        return [
            'projectId' => $projectId,
            'orientation' => $row?->getOrientation() ?? ProjectSetting::ORIENTATION_DEFAULT,
            'platform' => $row?->getPlatform() ?? ProjectSetting::PLATFORM_DEFAULT,
            'dartDefines' => (string) ($row?->getDartDefines() ?? ''),
            // Whether these are the project's own choices or just fallbacks: the
            // dashboard only overrides its defaults when nobody has chosen yet.
            'orientationSet' => $row?->getOrientation() !== null,
            'platformSet' => $row?->getPlatform() !== null,
        ];
    }
}
