<?php

namespace App\Actions\Automation;

use App\Jobs\ExecuteRunJob;
use App\Models\AutomationTrigger;
use App\Models\Run;
use App\Repositories\Automation\AutomationTriggerRepository;
use App\Repositories\Run\RunRepository;

/**
 * Turns one push into one queued run, when the rule matches it.
 *
 * Runs are rows and a single worker executes them, so several pushes arriving at
 * once simply line up rather than competing for the device.
 */
class FireAutomationTriggerAction
{
    public function __construct(
        private AutomationTrigger $trigger,
        private string $branch,
        private string $sha = '',
        private RunRepository $runs = new RunRepository,
        private AutomationTriggerRepository $triggers = new AutomationTriggerRepository,
    ) {}

    /** @return array{status: string, runId?: string} */
    public function handle(): array
    {
        $trigger = $this->trigger;
        $branch = $this->branch;
        $sha = $this->sha;
        if (! $trigger->getEnabled()) {
            return ['status' => 'disabled'];
        }

        if (! self::matches((string) $trigger->getBranchPattern(), $branch)) {
            return ['status' => 'no_match'];
        }

        // GitLab retries a webhook it thinks failed, and a push carries a stable
        // head sha, so the same push never queues twice.
        if ($sha !== '' && $sha === (string) $trigger->getLastFiredSha()) {
            return ['status' => 'duplicate'];
        }

        $run = $this->runs->create([
            Run::PROJECT_ID => (int) $trigger->getProjectId(),
            Run::PROJECT_PATH => (string) $trigger->getProjectPath(),
            Run::BRANCH => $branch,
            Run::TESTS => $trigger->getTests() ?? [],
            Run::RUN_KINDS => $trigger->getRunKinds() ?? ['maestro'],
            Run::ENVIRONMENTS => $trigger->getEnvironments() ?? ['development'],
            Run::ORIENTATION => (string) $trigger->getOrientation(),
            Run::PLATFORM => (string) $trigger->getPlatform(),
            Run::LIVE => (bool) $trigger->getLive(),
            Run::DART_DEFINES => (string) ($trigger->getDartDefines() ?? ''),
        ]);

        ExecuteRunJob::dispatch((string) $run->getId());

        $this->triggers->recordFire($trigger, $branch, $sha);

        return ['status' => 'queued', 'runId' => (string) $run->getId()];
    }

    /** `release/*` and a bare `*` are the shapes people actually want. */
    public static function matches(string $pattern, string $branch): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '' || $branch === '') {
            return false;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

        return (bool) preg_match($regex, $branch);
    }
}
