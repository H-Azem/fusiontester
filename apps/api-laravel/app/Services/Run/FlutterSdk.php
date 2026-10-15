<?php

namespace App\Services\Run;

use Symfony\Component\Process\Process;

/**
 * The Flutter SDK every build uses.
 *
 * The image ships one; the Settings page can move it onto a volume and update it
 * there, so the SDK itself is asked for its version rather than the database
 * remembering one — the volume is the source of truth.
 */
class FlutterSdk
{
    const SCRIPT = 'flutter-sdk';

    const STATUS_TIMEOUT_SECONDS = 60;

    /** A clone of the SDK plus the Dart download: minutes, not seconds. */
    const UPDATE_TIMEOUT_SECONDS = 3600;

    /**
     * @return array{available: bool, path: string|null, source: string|null, version: string|null,
     *               revision: string|null, previousRevision: string|null, busy: bool,
     *               state: array<string, mixed>|null}
     */
    public function status(): array
    {
        $fallback = [
            'available' => false,
            'path' => (string) (getenv('FLUTTER_HOME') ?: '/opt/flutter'),
            'source' => null,
            'version' => null,
            'revision' => null,
            'previousRevision' => null,
            'busy' => false,
            'state' => null,
        ];

        $result = $this->run(['status'], self::STATUS_TIMEOUT_SECONDS);
        $decoded = json_decode($result['output'], true);

        if (! is_array($decoded)) {
            return $fallback;
        }

        return array_merge($fallback, $decoded, ['available' => true]);
    }

    public function update(?string $tag = null): array
    {
        return $this->run(
            $tag === null || $tag === '' ? ['update'] : ['update', $tag],
            self::UPDATE_TIMEOUT_SECONDS
        );
    }

    public function restore(): array
    {
        return $this->run(['restore'], self::UPDATE_TIMEOUT_SECONDS);
    }

    public function available(): bool
    {
        return is_file('/usr/local/bin/'.self::SCRIPT);
    }

    /** @return array{exit: int, output: string} */
    private function run(array $arguments, int $timeout): array
    {
        if (! $this->available()) {
            return ['exit' => 127, 'output' => 'The SDK helper is not in this image.'];
        }

        $process = new Process(array_merge([self::SCRIPT], $arguments), null, null, null, $timeout);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return ['exit' => 1, 'output' => $exception->getMessage()];
        }

        return [
            'exit' => (int) $process->getExitCode(),
            'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
        ];
    }
}
