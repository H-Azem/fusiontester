<?php

namespace App\Services\Device;

/**
 * Who is using the emulator by hand, and what was installed for them.
 *
 * Both facts live as files beside the device's control files, so the host timer that
 * does the cleaning can read them without talking to the app.
 */
class ManualTestSession
{
    /** The last moment the page said it was still open. */
    const ACTIVITY_FILE = 'manual-test-activity';

    /** Only the packages this page installed are ever removed again. */
    const INSTALLED_FILE = 'manual-test-installed';

    public function __construct(private string $controlDir = '') {}

    public function touch(): void
    {
        @file_put_contents($this->path(self::ACTIVITY_FILE), (string) time());
    }

    public function lastUsedAt(): ?int
    {
        $raw = @file_get_contents($this->path(self::ACTIVITY_FILE));

        return is_string($raw) && trim($raw) !== '' ? (int) trim($raw) : null;
    }

    public function rememberInstalled(string $package): void
    {
        if ($package === '') {
            return;
        }

        $packages = $this->installed();

        if (in_array($package, $packages, true)) {
            return;
        }

        $packages[] = $package;
        @file_put_contents($this->path(self::INSTALLED_FILE), implode("\n", $packages)."\n");
    }

    /** @return array<int, string> */
    public function installed(): array
    {
        $raw = @file_get_contents($this->path(self::INSTALLED_FILE));

        if (! is_string($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    private function path(string $name): string
    {
        $dir = $this->controlDir !== '' ? $this->controlDir : (string) config('fusion.android.control_dir');

        return rtrim($dir, '/').'/'.$name;
    }
}
