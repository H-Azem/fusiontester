<?php

namespace App\Console\Commands;

use App\Http\Resources\RunArtifacts;
use App\Services\Run\LiveCapture;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Streams the device screen into the run's workspace as small JPEGs.
 *
 * Runs as its own process so the queued job that owns the run is never blocked by
 * a screencap, and stops as soon as the run that started it removes the flag
 * file — which means a finished, failed or killed run cannot leave it behind.
 */
class FusionLiveCommand extends Command
{
    protected $signature = 'fusion:live {run : run id} {serial : adb serial} {dir : run workspace} {--interval=2500}';

    protected $description = 'Captures device screenshots while a run is in flight';

    public function handle(): int
    {
        $runId = (string) $this->argument('run');
        $serial = (string) $this->argument('serial');
        $runDir = (string) $this->argument('dir');
        $intervalMs = max(500, (int) $this->option('interval'));

        $flag = LiveCapture::flag($runDir);
        $frame = RunArtifacts::liveFrame($runId);
        $raw = $runDir.'/.frame.png';

        // The flag is written by the parent before this process starts, so waking
        // up without it means the run is already over.
        while (is_file($flag)) {
            $startedAt = microtime(true);

            $this->capture($serial, $raw, $frame);

            $spentMs = (int) round((microtime(true) - $startedAt) * 1000);
            usleep(max(0, $intervalMs - $spentMs) * 1000);
        }

        @unlink($raw);

        return self::SUCCESS;
    }

    /**
     * One frame: the device encodes a PNG, the host shrinks it to a JPEG and swaps
     * it in atomically, so the dashboard never reads a half-written image.
     */
    private function capture(string $serial, string $raw, string $frame): void
    {
        $screencap = new Process([
            (string) config('fusion.android.adb'), '-s', $serial, 'exec-out', 'screencap', '-p',
        ]);
        $screencap->run();

        if (! $screencap->isSuccessful() || $screencap->getOutput() === '') {
            return;
        }

        file_put_contents($raw, $screencap->getOutput());

        $next = $frame.'.new';
        $convert = new Process([
            'convert', $raw,
            '-resize', (string) config('fusion.android.live_width').'x',
            '-quality', '70',
            // The format is named explicitly: the temporary file has no extension
            // for ImageMagick to infer from, and it would otherwise keep the PNG.
            'jpg:'.$next,
        ]);
        $convert->run();

        if (is_file($next) && filesize($next) > 0) {
            rename($next, $frame);
        }
    }
}
