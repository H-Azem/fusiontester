<?php

namespace App\Console\Commands;

use App\Repositories\Run\RunRepository;
use Illuminate\Console\Command;

/**
 * Marks runs that were mid-flight when the process died as failed.
 *
 * Deliberately not a polling worker: runs are queued jobs and a queue worker
 * executes them, so this only repairs the state a crash can leave behind, and
 * the entrypoint runs it once before starting supervisor.
 */
class FusionRecoverCommand extends Command
{
    protected $signature = 'fusion:recover';

    protected $description = 'Fails runs that were interrupted by a restart';

    public function handle(RunRepository $runs): int
    {
        $interrupted = $runs->failInterrupted();

        $this->info(
            $interrupted > 0
                ? "marked {$interrupted} interrupted run(s) as failed"
                : 'no interrupted runs'
        );

        return self::SUCCESS;
    }
}
