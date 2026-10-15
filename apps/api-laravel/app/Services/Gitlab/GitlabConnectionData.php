<?php

namespace App\Services\Gitlab;

/**
 * One forge connection. The provider is part of it because the same three fields
 * mean different paths, headers and pagination on GitLab and Gitea.
 */
class GitlabConnectionData
{
    const PROVIDER_GITLAB = 'gitlab';

    const PROVIDER_GITEA = 'gitea';

    public function __construct(
        public readonly string $baseUrl,
        public readonly string $token,
        public readonly ?string $caCertificate = null,
        public readonly string $provider = self::PROVIDER_GITLAB,
    ) {}

    public function isGitea(): bool
    {
        return $this->provider === self::PROVIDER_GITEA;
    }
}
