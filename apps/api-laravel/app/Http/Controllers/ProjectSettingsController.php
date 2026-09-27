<?php

namespace App\Http\Controllers;

use App\Models\ProjectSetting;
use Illuminate\Http\JsonResponse;

class ProjectSettingsController extends Controller
{
    public function show(int $projectId): JsonResponse
    {
        $row = ProjectSetting::query()
            ->where(ProjectSetting::PROJECT_ID, $projectId)
            ->first();

        return $this->legacyResponse([
            'projectId' => $projectId,
            'orientation' => $row?->getOrientation() ?? ProjectSetting::ORIENTATION_DEFAULT,
            'dartDefines' => (string) ($row?->getDartDefines() ?? ''),
        ]);
    }
}
