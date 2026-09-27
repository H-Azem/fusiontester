<?php

namespace App\Services\Gitlab;

class GitlabConnectionData
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $token,
        public readonly ?string $caCertificate = null,
    ) {}
}
