<?php

namespace App\Services\Ai;

use App\Models\AiConnection;

class AiConnectionData
{
    public function __construct(
        public readonly string $openaiBaseUrl,
        public readonly string $model,
        public readonly string $openaiToken,
        public readonly string $jevBaseUrl,
        public readonly string $jevToken,
        public readonly int $maxSteps = AiClient::DEFAULT_MAX_STEPS,
        public readonly bool $aiLaneEnabled = true,
        public readonly bool $shareReportToTelegram = false,
        public readonly int $maxTokens = AiConnection::DEFAULT_MAX_TOKENS,
    ) {}
}
