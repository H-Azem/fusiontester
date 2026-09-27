<?php

namespace App\Repositories\Settings;

use App\Models\AiConnection;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiConnectionData;
use App\Services\Settings\SecretBox;

class AiConnectionRepository
{
    public function __construct(private SecretBox $box = new SecretBox) {}

    public function row(): ?AiConnection
    {
        return AiConnection::query()->first();
    }

    public function data(): ?AiConnectionData
    {
        $row = $this->row();

        if ($row === null) {
            return null;
        }

        return new AiConnectionData(
            (string) $row->getOpenaiBaseUrl(),
            (string) $row->getOpenaiModel(),
            $this->box->decrypt((string) $row->getOpenaiTokenCiphertext()),
            (string) $row->getJevBaseUrl(),
            $this->box->decrypt((string) $row->getJevTokenCiphertext()),
            (int) $row->getMaxSteps()
        );
    }

    public function save(array $values): AiConnection
    {
        $current = $this->row();

        $openaiToken = $values['openaiToken'] ?? null;
        $jevToken = $values['jevToken'] ?? null;

        $attributes = [
            AiConnection::OPENAI_BASE_URL => $values['openaiBaseUrl'],
            AiConnection::OPENAI_MODEL => $values['openaiModel'],
            AiConnection::OPENAI_TOKEN_CIPHERTEXT => $openaiToken !== null && $openaiToken !== ''
                ? $this->box->encrypt($openaiToken)
                : (string) $current?->getOpenaiTokenCiphertext(),
            AiConnection::OPENAI_TOKEN_HINT => $openaiToken !== null && $openaiToken !== ''
                ? $this->box->hint($openaiToken)
                : (string) $current?->getOpenaiTokenHint(),
            AiConnection::JEV_BASE_URL => $values['jevBaseUrl'],
            AiConnection::JEV_TOKEN_CIPHERTEXT => $jevToken !== null && $jevToken !== ''
                ? $this->box->encrypt($jevToken)
                : (string) $current?->getJevTokenCiphertext(),
            AiConnection::JEV_TOKEN_HINT => $jevToken !== null && $jevToken !== ''
                ? $this->box->hint($jevToken)
                : (string) $current?->getJevTokenHint(),
            AiConnection::MAX_STEPS => $values['maxSteps'] ?? $current?->getMaxSteps() ?? AiClient::DEFAULT_MAX_STEPS,
        ];

        AiConnection::query()->delete();

        return AiConnection::query()->create($attributes);
    }

    public function recordVerification(bool $ok, ?string $error): void
    {
        $row = $this->row();
        if ($row === null) {
            return;
        }

        $row->setAttribute(AiConnection::LAST_VERIFIED_AT, now());
        $row->setAttribute(AiConnection::LAST_VERIFY_OK, $ok);
        $row->setAttribute(AiConnection::LAST_VERIFY_ERROR, $error);
        $row->save();
    }
}
