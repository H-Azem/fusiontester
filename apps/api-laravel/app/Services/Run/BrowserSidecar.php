<?php

namespace App\Services\Run;

use Symfony\Component\Process\Process;

/**
 * Shells out to the Node sidecar for the two stages that need a real browser.
 *
 * Reading a Flutter web app's runtime state means reading its accessibility tree
 * over CDP. Rather than reimplement that in PHP, the capability stays where it
 * was built and this service speaks a small JSON contract to it, so a missing
 * sidecar degrades into a clear message instead of a silent wrong answer.
 */
class BrowserSidecar
{
    public function browse(string $url): array
    {
        return $this->call('sidecar-browse.ts', [$url], null);
    }

    /** @param array<string, mixed> $payload */
    public function runAi(array $payload): array
    {
        return $this->call('sidecar-ai.ts', [], $payload);
    }

    public function available(): bool
    {
        return is_dir((string) config('fusion.sidecar.dir'))
            && is_file($this->scriptPath('sidecar-browse.ts'));
    }

    /**
     * @param array<int, string> $arguments
     * @param array<string, mixed>|null $stdin
     * @return array{ok: bool, data: array<string, mixed>|null, error: string|null}
     */
    private function call(string $script, array $arguments, ?array $stdin): array
    {
        $scriptPath = $this->scriptPath($script);

        if (! is_file($scriptPath)) {
            return [
                'ok' => false,
                'data' => null,
                'error' => "The sidecar script {$script} was not found at {$scriptPath}.",
            ];
        }

        $process = new Process(
            array_merge(
                [(string) config('fusion.sidecar.node'), '--import', 'tsx', $scriptPath],
                $arguments
            ),
            (string) config('fusion.sidecar.dir'),
            null,
            $stdin === null ? null : json_encode($stdin),
            (int) config('fusion.sidecar.timeout_seconds')
        );

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'data' => null, 'error' => $exception->getMessage()];
        }

        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            $detail = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            return [
                'ok' => false,
                'data' => null,
                'error' => 'The sidecar did not return JSON: '.mb_substr($detail, 0, 400),
            ];
        }

        if (isset($decoded['error'])) {
            return ['ok' => false, 'data' => null, 'error' => (string) $decoded['error']];
        }

        return ['ok' => (bool) ($decoded['ok'] ?? false), 'data' => $decoded, 'error' => null];
    }

    private function scriptPath(string $script): string
    {
        return rtrim((string) config('fusion.sidecar.scripts'), '/').'/'.$script;
    }
}
