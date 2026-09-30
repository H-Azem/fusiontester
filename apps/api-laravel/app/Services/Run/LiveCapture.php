<?php

namespace App\Services\Run;

use App\Models\Run;
use Symfony\Component\Process\Process;

/**
 * The live view: a frame of the device every couple of seconds while a run goes.
 *
 * Only meaningful for the Android lane — the web lane's browser belongs to Maestro
 * and cannot be reached from here — and only when the run asked for it, because
 * capturing is a continuous cost on a machine that also serves production sites.
 */
class LiveCapture
{
    /** Present while the capture loop should keep running. */
    const FLAG = '.capture-running';

    public static function flag(string $runDir): string
    {
        return rtrim($runDir, '/').'/'.self::FLAG;
    }

    /** Returns the loop's process so the caller can stop it, or null when it is off. */
    public function start(Run $run, string $runDir): ?Process
    {
        if (! (bool) $run->getLive()) {
            return null;
        }

        file_put_contents(self::flag($runDir), '');

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'fusion:live',
            (string) $run->getId(),
            (string) config('fusion.android.device'),
            $runDir,
            '--interval='.(int) config('fusion.android.live_interval_ms'),
        ]);

        $process->start();

        return $process;
    }

    public function stop(?Process $process, string $runDir): void
    {
        @unlink(self::flag($runDir));

        // The loop notices the missing flag by itself; stopping is the fallback for
        // a frame that is still in flight when the run ends.
        $process?->stop(10);
    }
}
