<?php

namespace App\Actions\Settings;

use App\Models\AuditEntry;
use App\Actions\Auth\WriteAuditAction;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Services\Gitlab\GitlabClient;
use App\Services\Gitlab\GitlabConnectionData;

/**
 * Verifies a connection, optionally from values the form has not saved yet, so a
 * credential can be proven before it becomes the one every run depends on.
 */
class TestGitlabConnectionAction
{
    public function __construct(
        private ?string $baseUrl = null,
        private ?string $token = null,
        private ?string $caCertificate = null,
        private ?string $testRepo = null,
        private ?int $actorUserId = null,
        private ?string $ip = null,
        private GitlabConnectionRepository $connections = new GitlabConnectionRepository,
        private GitlabClient $client = new GitlabClient,
    ) {}

    public function handle(): array
    {
        $stored = $this->connections->data();

        $baseUrl = GitlabClient::normalizeBaseUrl((string) ($this->baseUrl ?? $stored?->baseUrl ?? ''));
        $token = $this->token !== null && trim($this->token) !== ''
            ? trim($this->token)
            : ($stored?->token ?? '');
        $caCertificate = $this->caCertificate ?? $stored?->caCertificate;

        if ($baseUrl === '' || $token === '') {
            return [
                'ok' => false,
                'error' => 'not_configured',
                'message' => 'Provide a base URL and token, or save the connection first.',
            ];
        }

        $connection = new GitlabConnectionData($baseUrl, $token, $caCertificate);
        $result = $this->client->verifyConnection($connection, $this->testRepo);

        // Only record the outcome when it describes the saved configuration.
        $testedStoredConfig = ($this->token === null || trim($this->token) === '')
            && ($this->baseUrl === null || trim($this->baseUrl) === '');
        if ($testedStoredConfig) {
            $error = $result['ok']
                ? null
                : ($result['error']
                    ?? ($result['missingScopes'] !== []
                        ? 'Missing scope(s): '.implode(', ', $result['missingScopes'])
                        : $result['clone']['message']));

            $this->connections->recordVerification($result['ok'], $error);
        }

        run(new WriteAuditAction(
            'gitlab.connection_tested',
            $this->actorUserId,
            $this->ip,
            null,
            ['baseUrl' => $baseUrl, 'ok' => $result['ok'], 'missingScopes' => $result['missingScopes']]
        ));

        return $result;
    }
}
