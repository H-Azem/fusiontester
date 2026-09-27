<?php

namespace App\Actions\Settings;

use App\Actions\Auth\WriteAuditAction;
use App\Repositories\Settings\AiConnectionRepository;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiConnectionData;

/**
 * Proves both halves of the AI configuration with the smallest calls available.
 */
class TestAiConnectionAction
{
    public function __construct(
        private array $input = [],
        private ?int $actorUserId = null,
        private ?string $ip = null,
        private AiConnectionRepository $connections = new AiConnectionRepository,
        private AiClient $client = new AiClient,
    ) {}

    public function handle(): array
    {
        $stored = $this->connections->data();

        $connection = new AiConnectionData(
            AiClient::normalizeBaseUrl((string) ($this->input['openaiBaseUrl'] ?? $stored?->openaiBaseUrl ?? '')),
            trim((string) ($this->input['openaiModel'] ?? $stored?->model ?? '')),
            trim((string) ($this->input['openaiToken'] ?? '')) ?: ($stored?->openaiToken ?? ''),
            AiClient::normalizeBaseUrl((string) ($this->input['jevBaseUrl'] ?? $stored?->jevBaseUrl ?? '')),
            trim((string) ($this->input['jevToken'] ?? '')) ?: ($stored?->jevToken ?? ''),
        );

        if ($connection->openaiBaseUrl === '' || $connection->model === '' || $connection->openaiToken === '' || $connection->jevToken === '') {
            return [
                'ok' => false,
                'error' => 'not_configured',
                'message' => 'Provide a model, an API key and a jev token, or save them first.',
            ];
        }

        $result = $this->client->verify($connection);

        $testedStoredConfig = trim((string) ($this->input['openaiToken'] ?? '')) === ''
            && trim((string) ($this->input['jevToken'] ?? '')) === ''
            && trim((string) ($this->input['openaiBaseUrl'] ?? '')) === '';

        if ($testedStoredConfig) {
            $this->connections->recordVerification($result['ok'], $result['ok'] ? null : implode(' · ', array_filter([
                $result['openai']['ok'] ? null : 'model: '.$result['openai']['detail'],
                $result['jev']['ok'] ? null : 'jev: '.$result['jev']['detail'],
            ])));
        }

        run(new WriteAuditAction('ai.connection_tested', $this->actorUserId, $this->ip, null, [
            'ok' => $result['ok'],
            'model' => $result['openai']['model'],
        ]));

        return $result;
    }
}
