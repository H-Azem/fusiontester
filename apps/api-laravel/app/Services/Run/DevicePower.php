<?php

namespace App\Services\Run;

/**
 * Frees the device's memory while a build runs.
 *
 * The Android container holds 1.5-2.5GB even when nothing is touching it, and the
 * build is the moment this small machine is tightest — roughly the same amount the
 * build itself needs. The API container is deliberately given no access to the Docker
 * socket (it builds code from whatever repository a run points at), so this only
 * writes a flag for the host-side watcher, which is what actually stops and starts
 * the container. If that watcher is not installed the flags go nowhere and every
 * stage still works: the device simply never sleeps.
 */
class DevicePower
{
    const PAUSE = 'pause';

    const RESUME = 'resume';

    public function __construct(private string $controlDir = '') {}

    /** Asks the host to stop the device; true when the request was written. */
    public function sleep(): bool
    {
        return $this->signal(self::PAUSE);
    }

    /** Asks the host to start the device again; true when the request was written. */
    public function wake(): bool
    {
        return $this->signal(self::RESUME);
    }

    public function installed(): bool
    {
        return is_dir($this->dir());
    }

    private function signal(string $name): bool
    {
        if (! $this->installed()) {
            return false;
        }

        return @file_put_contents($this->dir().'/'.$name, (string) time()) !== false;
    }

    private function dir(): string
    {
        $configured = $this->controlDir !== '' ? $this->controlDir : (string) config('fusion.android.control_dir');

        return rtrim($configured, '/');
    }
}
