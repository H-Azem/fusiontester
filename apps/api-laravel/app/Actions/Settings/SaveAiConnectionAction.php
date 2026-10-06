<?php

namespace App\Actions\Settings;

use App\Actions\Auth\WriteAuditAction;
use App\Repositories\Settings\AiConnectionRepository;
use App\Services\Ai\AiClient;

/**
 * Saves the AI lane's two credentials. Omitting a token keeps the stored one, so
 * a form that never displays a credential can still be re-saved.
 */
class SaveAiConnectionAction
{
    public function __construct(
        private array $input,
        private ?int $actorUserId = null,
        private ?string $ip = null,
        private AiConnectionRepository $connections = new AiConnectionRepository,
        private AiClient $client = new AiClient,
    ) {}

    public function handle(): array
    {
        $current = $this->connections->row();

        $openaiBaseUrl = AiClient::normalizeBaseUrl((string) ($this->input['openaiBaseUrl'] ?? ''));
        $jevBaseUrl = AiClient::normalizeBaseUrl((string) ($this->input['jevBaseUrl'] ?? ''));
        $model = trim((string) ($this->input['openaiModel'] ?? ''));
        $openaiToken = trim((string) ($this->input['openaiToken'] ?? ''));
        $jevToken = trim((string) ($this->input['jevToken'] ?? ''));

        if (preg_match('#^https?://[^\s]+$#i', $openaiBaseUrl) !== 1) {
            return [
                'ok' => false,
                'error' => 'invalid_base_url',
                'message' => 'Enter a valid OpenAI-compatible base URL.',
            ];
        }

        if (preg_match('#^https?://[^\s]+$#i', $jevBaseUrl) !== 1) {
            return ['ok' => false, 'error' => 'invalid_base_url', 'message' => 'Enter a valid jev base URL.'];
        }

        if ($model === '') {
            return ['ok' => false, 'error' => 'invalid_request', 'message' => 'A model name is required.'];
        }

        if (($openaiToken === '' || $jevToken === '') && $current === null) {
            return [
                'ok' => false,
                'error' => 'token_required',
                'message' => 'An API key and a jev token are required the first time.',
            ];
        }

        $row = $this->connections->save([
            'openaiBaseUrl' => $openaiBaseUrl,
            'openaiModel' => $model,
            'openaiToken' => $openaiToken,
            'jevBaseUrl' => $jevBaseUrl,
            'jevToken' => $jevToken,
            'maxSteps' => isset($this->input['maxSteps']) ? (int) $this->input['maxSteps'] : null,
            'aiLaneEnabled' => array_key_exists('aiLaneEnabled', $this->input)
                ? (bool) $this->input['aiLaneEnabled']
                : null,
            'shareReportToTelegram' => array_key_exists('shareReportToTelegram', $this->input)
                ? (bool) $this->input['shareReportToTelegram']
                : null,
        ]);

        run(new WriteAuditAction('ai.connection_saved', $this->actorUserId, $this->ip, null, [
            'openaiBaseUrl' => $openaiBaseUrl,
            'openaiModel' => $model,
            'openaiTokenChanged' => $openaiToken !== '',
            'jevTokenChanged' => $jevToken !== '',
            'maxSteps' => $row->getMaxSteps(),
            'aiLaneEnabled' => (bool) $row->getAiLaneEnabled(),
            'shareReportToTelegram' => (bool) $row->getShareReportToTelegram(),
        ]));

        return [
            'ok' => true,
            'configured' => true,
            'openaiBaseUrl' => $row->getOpenaiBaseUrl(),
            'openaiModel' => $row->getOpenaiModel(),
            'openaiTokenHint' => $row->getOpenaiTokenHint(),
            'jevBaseUrl' => $row->getJevBaseUrl(),
            'jevTokenHint' => $row->getJevTokenHint(),
            'maxSteps' => $row->getMaxSteps(),
            'aiLaneEnabled' => (bool) $row->getAiLaneEnabled(),
            'shareReportToTelegram' => (bool) $row->getShareReportToTelegram(),
        ];
    }
}
