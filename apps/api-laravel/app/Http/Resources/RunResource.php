<?php

namespace App\Http\Resources;

use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard reads snake-free camelCase keys, so the resource keeps that
 * contract rather than exposing the column names.
 *
 * @mixin Run
 */
class RunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'projectId' => $this->getProjectId(),
            'projectPath' => $this->getProjectPath(),
            'branch' => $this->getBranch(),
            'tests' => $this->getTests() ?? [],
            'runKinds' => $this->getRunKinds() ?? [],
            'environments' => $this->getEnvironments() ?? [],
            'orientation' => $this->getOrientation(),
            'platform' => $this->getPlatform(),
            'live' => (bool) $this->getLive(),
            'dartDefines' => (string) $this->getDartDefines(),
            'status' => $this->getStatus(),
            'currentStep' => $this->getCurrentStep(),
            'errorMessage' => $this->getErrorMessage(),
            'createdAt' => $this->getCreatedAt()?->toIso8601String(),
            'startedAt' => $this->getStartedAt()?->toIso8601String(),
            'finishedAt' => $this->getFinishedAt()?->toIso8601String(),
            'hasScreenshot' => RunArtifacts::hasScreenshot((string) $this->getId(), 'screenshot.png'),
            'hasMaestroScreenshot' => RunArtifacts::hasScreenshot((string) $this->getId(), 'maestro-failure.png'),
            'hasAiScreenshot' => RunArtifacts::hasScreenshot((string) $this->getId(), 'ai-failure.png'),
            'hasLiveFrame' => is_file(RunArtifacts::liveFrame((string) $this->getId())),
            'steps' => RunStepResource::collection($this->whenLoaded('steps'))->resolve(),
        ];
    }
}
