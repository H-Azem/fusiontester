<?php

namespace App\Repositories\Settings;

use App\Models\TelegramConnection;
use App\Services\Settings\SecretBox;
use App\Services\Telegram\TelegramConnectionData;

class TelegramConnectionRepository
{
    public function __construct(private SecretBox $box = new SecretBox) {}

    public function row(): ?TelegramConnection
    {
        return TelegramConnection::query()->first();
    }

    public function data(): ?TelegramConnectionData
    {
        $row = $this->row();

        if ($row === null) {
            return null;
        }

        return new TelegramConnectionData(
            $this->box->decrypt((string) $row->getBotTokenCiphertext()),
            (string) $row->getChatId(),
            (bool) $row->getNotifyOnPass()
        );
    }

    public function save(string $botToken, string $chatId, bool $notifyOnPass): TelegramConnection
    {
        $current = $this->row();

        $attributes = [
            TelegramConnection::BOT_TOKEN_CIPHERTEXT => $botToken !== ''
                ? $this->box->encrypt($botToken)
                : (string) $current?->getBotTokenCiphertext(),
            TelegramConnection::BOT_TOKEN_HINT => $botToken !== ''
                ? $this->box->hint($botToken)
                : (string) $current?->getBotTokenHint(),
            TelegramConnection::CHAT_ID => $chatId,
            TelegramConnection::NOTIFY_ON_PASS => $notifyOnPass,
        ];

        TelegramConnection::query()->delete();

        return TelegramConnection::query()->create($attributes);
    }

    public function recordVerification(bool $ok, ?string $error): void
    {
        $row = $this->row();

        if ($row === null) {
            return;
        }

        $row->setAttribute(TelegramConnection::LAST_VERIFIED_AT, now());
        $row->setAttribute(TelegramConnection::LAST_VERIFY_OK, $ok);
        $row->setAttribute(TelegramConnection::LAST_VERIFY_ERROR, $error);
        $row->save();
    }
}
