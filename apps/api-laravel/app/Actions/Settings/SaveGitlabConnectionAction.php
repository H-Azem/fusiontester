<?php

namespace App\Actions\Settings;

use App\Models\AuditEntry;
use App\Models\GitlabConnection;
use App\Actions\Auth\WriteAuditAction;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Services\Gitlab\GitlabClient;
use App\Services\Gitlab\GitlabConnectionData;

/**
 * Saves the GitLab connection. An omitted token means "keep the stored one", so
 * the form can be saved without re-typing a credential it never displays.
 */
class SaveGitlabConnectionAction
{
    public function __construct(
        private ?string $baseUrl,
        private ?string $token,
        private ?string $caCertificate,
        private string $provider = GitlabConnectionData::PROVIDER_GITLAB,
        private ?int $actorUserId = null,
        private ?string $ip = null,
        private GitlabConnectionRepository $connections = new GitlabConnectionRepository,
        private GitlabClient $client = new GitlabClient,
    ) {}

    public function handle(): array
    {
        $baseUrl = GitlabClient::normalizeBaseUrl((string) $this->baseUrl);

        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            return ['ok' => false, 'error' => 'invalid_base_url', 'message' => 'Enter a valid GitLab URL.'];
        }

        $token = $this->token === null ? null : trim($this->token);
        $caCertificate = $this->caCertificate === null ? null : (trim($this->caCertificate) ?: null);

        if (($token === null || $token === '') && $this->connections->row() === null) {
            return [
                'ok' => false,
                'error' => 'token_required',
                'message' => 'A token is required when saving the connection for the first time.',
            ];
        }

        $row = $this->connections->save($baseUrl, $token, $caCertificate, $this->provider);

        run(new WriteAuditAction(
            'gitlab.connection_saved',
            $this->actorUserId,
            $this->ip,
            null,
            [
                'baseUrl' => $baseUrl,
                'provider' => $this->provider,
                'tokenChanged' => $token !== null && $token !== '',
                'hasCaCertificate' => $caCertificate !== null,
            ]
        ));

        return [
            'ok' => true,
            'configured' => true,
            'baseUrl' => $row->getBaseUrl(),
            'provider' => (string) $row->getProvider(),
            'tokenHint' => $row->getTokenHint(),
            'hasCaCertificate' => $row->getCaCertificate() !== null,
        ];
    }
}
