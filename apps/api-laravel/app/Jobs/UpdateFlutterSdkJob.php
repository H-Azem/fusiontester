<?php

namespace App\Jobs;

use App\Services\Run\FlutterSdk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Updates (or returns to) the Flutter SDK the pipeline builds with.
 *
 * It takes minutes — the SDK is copied onto the volume once, then fetched — so it
 * belongs on the queue rather than in the request the dashboard is waiting on.
 */
class UpdateFlutterSdkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(private ?string $tag = null, private bool $restore = false) {}

    public function handle(FlutterSdk $sdk): void
    {
        if ($this->restore) {
            $sdk->restore();

            return;
        }

        $sdk->update($this->tag);
    }
}
