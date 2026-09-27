<?php

namespace App\Jobs;

use App\Models\Run;
use App\Repositories\Run\RunRepository;
use App\Services\Run\ExecuteRunAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One queued run. Work that must outlive the request belongs in a job, and a
 * queue worker gives retries and failure records for free.
 */
class ExecuteRunJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(private string $runId) {}

    public function handle(RunRepository $runs): void
    {
        $run = $runs->find($this->runId);

        if ($run === null || $run->getStatus() !== Run::STATUS_QUEUED) {
            return;
        }

        // Claiming is the worker's job, so a run picked up here is already
        // running when the action sees it.
        $run->setAttribute(Run::STATUS, Run::STATUS_RUNNING);
        $run->setAttribute(Run::STARTED_AT, now());
        $run->save();

        run(new ExecuteRunAction($run));
    }
}
