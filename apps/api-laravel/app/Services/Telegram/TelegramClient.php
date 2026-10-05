<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;

/**
 * The two Bot API calls this application needs: checking the credential, and posting
 * a message. Errors are returned as text rather than thrown, because a failed
 * notification must never look like a failed test.
 */
class TelegramClient
{
    const API_BASE = 'https://api.telegram.org';

    /** @return array{ok: bool, detail: string, bot: string|null} */
    public function verify(string $token): array
    {
        $response = $this->call($token, 'getMe');

        if (! $response['ok']) {
            return ['ok' => false, 'detail' => $response['detail'], 'bot' => null];
        }

        $name = (string) ($response['json']['result']['username'] ?? '');

        return [
            'ok' => true,
            'detail' => $name !== '' ? 'bot @'.$name.' is reachable' : 'the bot token is valid',
            'bot' => $name !== '' ? $name : null,
        ];
    }

    /** @return array{ok: bool, detail: string} */
    public function sendMessage(string $token, string $chatId, string $text, ?string $threadId = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        // A forum group posts into a topic; without one the message lands in General.
        if ($threadId !== null && $threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }

        $response = $this->call($token, 'sendMessage', $payload);

        return [
            'ok' => $response['ok'],
            'detail' => $response['ok'] ? 'message delivered to '.$chatId : $response['detail'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, detail: string, json: array<string, mixed>}
     */
    private function call(string $token, string $method, array $payload = []): array
    {
        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post(self::API_BASE.'/bot'.$token.'/'.$method, $payload);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'detail' => 'Could not reach Telegram: '.$exception->getMessage(), 'json' => []];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful() && ($json['ok'] ?? false) === true) {
            return ['ok' => true, 'detail' => 'ok', 'json' => $json];
        }

        // Telegram carries the human explanation in `description`.
        return [
            'ok' => false,
            'detail' => (string) ($json['description'] ?? ('HTTP '.$response->status())),
            'json' => $json,
        ];
    }
}
