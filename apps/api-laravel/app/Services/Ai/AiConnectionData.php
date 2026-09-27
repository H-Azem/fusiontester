<?php

namespace App\Services\Ai;

class AiConnectionData
{
    public function __construct(
        public readonly string $openaiBaseUrl,
        public readonly string $model,
        public readonly string $openaiToken,
        public readonly string $jevBaseUrl,
        public readonly string $jevToken,
        public readonly int $maxSteps = AiClient::DEFAULT_MAX_STEPS,
    ) {}
}
