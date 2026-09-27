<?php

namespace App\Repositories\Run;

use App\Models\Run;
use App\Models\RunStep;

/**
 * Runs are queue rows rather than in-memory work, so the dashboard can read them
 * and the worker can pick them up after a restart.
 */
class RunRepository
{
    const LIST_LIMIT = 50;

    /** The stages every run walks through, in order. */
    const STAGES = [
        ['key' => 'queued', 'label' => 'Added to queue, waiting to start…'],
        ['key' => 'fetch', 'label' => 'Getting source…'],
        ['key' => 'packages', 'label' => 'Getting packages…'],
        ['key' => 'launch', 'label' => 'Reading launch configuration…'],
        ['key' => 'build', 'label' => 'Building the web app…'],
        ['key' => 'browse', 'label' => 'Opening in a browser…'],
        ['key' => 'maestro', 'label' => 'Running Maestro tests…'],
        ['key' => 'ai', 'label' => 'Running AI checks…'],
        ['key' => 'done', 'label' => 'Done'],
    ];

    public function recent(int $limit = self::LIST_LIMIT): array
    {
        return Run::query()
            ->with('steps')
            ->orderByDesc(Run::CREATED_AT)
            // Timestamps stored with second precision tie for runs created in the
            // same second, and UUIDv7 keys are time-ordered, so they break it.
            ->orderByDesc(Run::ID)
            ->limit($limit)
            ->get()
            ->all();
    }

    public function find(string $id): ?Run
    {
        return Run::query()->with('steps')->where(Run::ID, $id)->first();
    }

    public function create(array $attributes): Run
    {
        $run = Run::query()->create($attributes + [
            Run::STATUS => Run::STATUS_QUEUED,
            Run::CURRENT_STEP => Run::STEP_QUEUED,
            Run::CREATED_AT => now(),
        ]);

        // Every stage is pre-created so the dashboard can show the whole path
        // immediately rather than growing as the run progresses.
        foreach (self::STAGES as $position => $stage) {
            RunStep::query()->create([
                RunStep::RUN_ID => $run->getId(),
                RunStep::KEY => $stage['key'],
                RunStep::LABEL => $stage['label'],
                RunStep::POSITION => $position,
            ]);
        }

        return $run->load('steps');
    }

    /**
     * Claims the oldest queued run. The conditional update makes the claim
     * atomic, so a run can never be picked up twice.
     */
    public function claimNextQueued(): ?Run
    {
        $candidate = Run::query()
            ->where(Run::STATUS, Run::STATUS_QUEUED)
            ->orderBy(Run::CREATED_AT)
            ->first();

        if ($candidate === null) {
            return null;
        }

        $claimed = Run::query()
            ->where(Run::ID, $candidate->getId())
            ->where(Run::STATUS, Run::STATUS_QUEUED)
            ->update([
                Run::STATUS => Run::STATUS_RUNNING,
                Run::STARTED_AT => now(),
            ]);

        return $claimed === 1 ? $candidate->refresh() : null;
    }

    /** Runs left mid-flight by a previous process are failed, not left stuck. */
    public function failInterrupted(): int
    {
        return Run::query()
            ->where(Run::STATUS, Run::STATUS_RUNNING)
            ->update([
                Run::STATUS => Run::STATUS_FAILED,
                Run::ERROR_MESSAGE => 'Interrupted by a server restart.',
                Run::FINISHED_AT => now(),
            ]);
    }

    public function queuedCount(): int
    {
        return Run::query()->where(Run::STATUS, Run::STATUS_QUEUED)->count();
    }

    public function setStep(string $runId, string $key, array $values): void
    {
        RunStep::query()
            ->where(RunStep::RUN_ID, $runId)
            ->where(RunStep::KEY, $key)
            ->update($values);
    }

    public function startStage(Run $run, string $key): void
    {
        $run->setAttribute(Run::CURRENT_STEP, $key);
        $run->save();

        $this->setStep((string) $run->getId(), $key, [
            RunStep::STATUS => RunStep::STATUS_RUNNING,
            RunStep::STARTED_AT => now(),
        ]);
    }

    public function finishStage(Run $run, string $key, string $output): void
    {
        $this->setStep((string) $run->getId(), $key, [
            RunStep::STATUS => RunStep::STATUS_DONE,
            RunStep::OUTPUT => $this->tail($output),
            RunStep::FINISHED_AT => now(),
        ]);
    }

    public function failRun(Run $run, string $key, string $message): void
    {
        $this->setStep((string) $run->getId(), $key, [
            RunStep::STATUS => RunStep::STATUS_FAILED,
            RunStep::OUTPUT => $this->tail($message),
            RunStep::FINISHED_AT => now(),
        ]);

        $run->setAttribute(Run::STATUS, Run::STATUS_FAILED);
        $run->setAttribute(Run::CURRENT_STEP, $key);
        $run->setAttribute(Run::ERROR_MESSAGE, mb_substr($this->tail($message), 0, 1000));
        $run->setAttribute(Run::FINISHED_AT, now());
        $run->save();
    }

    public function passRun(Run $run): void
    {
        $run->setAttribute(Run::STATUS, Run::STATUS_PASSED);
        $run->setAttribute(Run::CURRENT_STEP, 'done');
        $run->setAttribute(Run::FINISHED_AT, now());
        $run->save();
    }

    public function skipStage(Run $run, string $key, string $reason): void
    {
        $this->setStep((string) $run->getId(), $key, [
            RunStep::STATUS => RunStep::STATUS_SKIPPED,
            RunStep::OUTPUT => $reason,
        ]);
    }

    /** Step output is truncated the same way the previous implementation did. */
    private function tail(string $text, int $limit = 4000): string
    {
        $trimmed = trim($text);

        return mb_strlen($trimmed) <= $limit ? $trimmed : '…'.mb_substr($trimmed, -$limit);
    }
}
