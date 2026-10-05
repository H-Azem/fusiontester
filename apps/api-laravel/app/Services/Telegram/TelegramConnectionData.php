<?php

namespace App\Services\Telegram;

/** The bot token and the channel it posts to, decrypted for use. */
class TelegramConnectionData
{
    public function __construct(
        public readonly string $token,
        public readonly string $chatId,
        public readonly bool $notifyOnPass = true,
        public readonly ?string $threadId = null,
        public readonly bool $enabled = true,
    ) {}
}
