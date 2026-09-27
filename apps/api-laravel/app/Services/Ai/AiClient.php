<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * The two model endpoints the AI test lane needs: an OpenAI-compatible chat
 * endpoint that chooses actions, and jev, which answers typed questions about
 * the resulting state so a step can be judged on calibrated probabilities.
 */
class AiClient
{
    const CHAT_TIMEOUT_SECONDS = 120;

    const JEV_TIMEOUT_SECONDS = 60;

    const VERIFY_TIMEOUT_SECONDS = 45;

    const DEFAULT_MAX_STEPS = 25;

    public static function normalizeBaseUrl(string $value): string
    {
        return rtrim(trim($value), '/');
    }

    public function chat(AiConnectionData $connection, array $messages, int $maxTokens = 1200, bool $json = true, ?int $timeoutSeconds = null): array
    {
        $body = [
            'model' => $connection->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];

        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken($connection->openaiToken)
            ->timeout($timeoutSeconds ?? self::CHAT_TIMEOUT_SECONDS)
            ->post(rtrim($connection->openaiBaseUrl, '/').'/chat/completions', $body);

        if (! $response->successful()) {
            throw new AiApiError(
                'Chat request failed ('.$response->status().'): '.mb_substr($response->body(), 0, 400)
            );
        }

        $payload = $response->json();
        $text = (string) ($payload['choices'][0]['message']['content'] ?? '');

        $decoded = null;
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
            }
        }

        return [
            'text' => $text,
            'json' => $decoded,
            'model' => (string) ($payload['model'] ?? $connection->model),
            'usage' => [
                'input' => (int) ($payload['usage']['prompt_tokens'] ?? 0),
                'output' => (int) ($payload['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Sends many independent questions about one state in a single request; they
     * are evaluated in parallel, so asking extra questions costs almost nothing.
     */
    public function askJev(AiConnectionData $connection, mixed $state, array $questions): array
    {
        $response = Http::withToken($connection->jevToken)
            ->timeout(self::JEV_TIMEOUT_SECONDS)
            ->post(rtrim($connection->jevBaseUrl, '/').'/v1/systemone', [
                'state' => $state,
                'model' => 'jev-latest',
                'questions' => $questions,
            ]);

        if (! $response->successful()) {
            throw new AiApiError(
                'jev request failed ('.$response->status().'): '.mb_substr($response->body(), 0, 400)
            );
        }

        return (array) ($response->json('answers') ?? []);
    }

    /**
     * Proves both halves with the smallest possible calls, so the settings form
     * can show whether the credentials work before a run depends on them.
     */
    public function verify(AiConnectionData $connection): array
    {
        $result = [
            'openai' => ['ok' => false, 'detail' => '', 'model' => null],
            'jev' => ['ok' => false, 'detail' => '', 'noul' => null],
            'ok' => false,
        ];

        try {
            $reply = $this->chat(
                $connection,
                [
                    ['role' => 'system', 'content' => 'Reply with the JSON {"ok":true}.'],
                    ['role' => 'user', 'content' => 'Ping.'],
                ],
                maxTokens: 20,
                timeoutSeconds: self::VERIFY_TIMEOUT_SECONDS
            );

            $result['openai'] = [
                'ok' => true,
                'detail' => 'Replied with '.mb_substr(trim($reply['text']), 0, 60),
                'model' => $reply['model'],
            ];
        } catch (\Throwable $exception) {
            $result['openai'] = ['ok' => false, 'detail' => $exception->getMessage(), 'model' => null];
        }

        try {
            $answers = $this->askJev($connection, 'The sky is blue.', [
                'ping' => ['type' => 'noul', 'instructions' => 'Is the sky blue?'],
            ]);

            $noul = $answers['ping']['noul'] ?? null;
            $result['jev'] = [
                'ok' => is_numeric($noul) && (float) $noul > 0.5,
                'detail' => $noul === null
                    ? 'No answer returned.'
                    : sprintf('Answered "is the sky blue" with %s.', $noul),
                'noul' => $noul,
            ];
        } catch (\Throwable $exception) {
            $result['jev'] = ['ok' => false, 'detail' => $exception->getMessage(), 'noul' => null];
        }

        $result['ok'] = $result['openai']['ok'] && $result['jev']['ok'];

        return $result;
    }
}
