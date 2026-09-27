<?php

namespace App\Console\Commands;

use App\Repositories\Run\RunRepository;
use App\Services\Run\ExecuteRunAction;
use Illuminate\Console\Command;

/**
 * The single-flight worker: one run at a time, oldest first.
 *
 * Runs live in the database, so they continue regardless of whether a browser is
 * connected, and this command is what supervisor keeps alive in production.
 */
class FusionWorkCommand extends Command
{
    protected $signature = 'fusion:work {--once : Drain the queue and exit}';

    protected $description = 'Executes queued test runs, one at a time';

    public function handle(RunRepository $runs): int
    {
        $interrupted = $runs->failInterrupted();
        if ($interrupted > 0) {
            $this->info("marked {$interrupted} interrupted run(s) as failed");
        }

        while (true) {
            $run = $runs->claimNextQueued();

            if ($run !== null) {
                $this->info("running {$run->getId()}");
                run(new ExecuteRunAction($run));

                continue;
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep(1);
        }
    }
}
