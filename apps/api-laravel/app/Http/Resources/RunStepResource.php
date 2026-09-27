<?php

namespace App\Http\Resources;

use App\Models\RunStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard reads camelCase step keys, so the contract is spelled out here
 * rather than mirroring the column constants.
 *
 * @mixin RunStep
 */
class RunStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->getAttribute(RunStep::KEY),
            'label' => $this->getLabel(),
            'status' => $this->getStatus(),
            'output' => $this->getOutput(),
            'startedAt' => $this->getStartedAt()?->toIso8601String(),
            'finishedAt' => $this->getFinishedAt()?->toIso8601String(),
        ];
    }
}
