<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Repositories\Settings\TelegramConnectionRepository;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where run reports are posted. Like the other credentials it is stored encrypted,
 * shown only as a hint, and reached behind the settings password.
 */
class TelegramSettingsController extends Controller
{
    public function __construct(
        private TelegramConnectionRepository $connections = new TelegramConnectionRepository,
        private TelegramClient $client = new TelegramClient,
    ) {}

    public function show(): JsonResponse
    {
        $row = $this->connections->row();

        if ($row === null) {
            return $this->legacyResponse(['configured' => false, 'notifyOnPass' => true]);
        }

        return $this->legacyResponse([
            'configured' => true,
            'chatId' => $row->getChatId(),
            'botTokenHint' => $row->getBotTokenHint(),
            'notifyOnPass' => (bool) $row->getNotifyOnPass(),
            'lastVerifiedAt' => $row->getLastVerifiedAt()?->toIso8601String(),
            'lastVerifyOk' => $row->getLastVerifyOk(),
            'lastVerifyError' => $row->getLastVerifyError(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'botToken' => ['sometimes', 'nullable', 'string', 'max:200'],
            'chatId' => ['required', 'string', 'max:200'],
            'notifyOnPass' => ['sometimes', 'boolean'],
        ]);

        $this->connections->save(
            (string) ($validated['botToken'] ?? ''),
            (string) $validated['chatId'],
            (bool) ($validated['notifyOnPass'] ?? true)
        );

        return $this->show();
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'botToken' => ['sometimes', 'nullable', 'string', 'max:200'],
            'chatId' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $stored = $this->connections->data();
        $token = (string) ($validated['botToken'] ?? '') !== ''
            ? (string) $validated['botToken']
            : (string) $stored?->token;
        $chatId = (string) ($validated['chatId'] ?? '') !== ''
            ? (string) $validated['chatId']
            : (string) $stored?->chatId;

        if ($token === '' || $chatId === '') {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => 'Add the bot token and the channel id first.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $identity = $this->client->verify($token);

        if (! $identity['ok']) {
            $this->connections->recordVerification(false, $identity['detail']);

            return $this->legacyResponse([
                'ok' => false,
                'bot' => null,
                'message' => $identity['detail'],
            ]);
        }

        $delivery = $this->client->sendMessage(
            $token,
            $chatId,
            "✅ <b>Fusion Tester is connected</b>\nRun reports will arrive in this channel."
        );

        $this->connections->recordVerification($delivery['ok'], $delivery['ok'] ? null : $delivery['detail']);

        return $this->legacyResponse([
            'ok' => $delivery['ok'],
            'bot' => $identity['bot'],
            'message' => $delivery['detail'],
        ]);
    }
}
