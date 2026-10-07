<?php

namespace App\Services\Ai;

use App\Services\Run\MaestroWorkspace;

/**
 * Drives one AI lane run on the host runner.
 *
 * The API container has no Docker socket, so it cannot start the ai-tester
 * container itself. It writes a request into the shared control directory — the
 * same directory the device watcher watches — and the host-side runner picks it
 * up. This class writes that request and reads back what the run produced.
 */
class AiLane
{
    public function __construct(
        private MaestroGoals $goals = new MaestroGoals,
        private string $controlDir = '',
        private int $timeoutSeconds = 0,
        private int $pollMilliseconds = 0,
    ) {}

    /**
     * The mission for a run. The flows give the shape of the journey, not a
     * script: the lane is told to behave like a person and adapt, because the
     * point of the lane is to discover how the app really behaves today.
     *
     * @param array<int, string> $tests
     */
    public function mission(string $repoDir, array $tests, string $appId): string
    {
        $root = rtrim($repoDir, '/').'/'.MaestroWorkspace::MAESTRO_DIR;
        $resolved = (new MaestroWorkspace)->resolveFlows($root, $tests);
        $goals = $this->goals->fromFlowFiles($resolved['flows']);

        // The sign-in lives in the shared flows, so they are read for the values the
        // lane needs to get past a login — never for more steps to follow.
        $shared = glob($root.'/'.MaestroWorkspace::FLOWS_DIR.'/shared/*.yaml') ?: [];
        $inputs = $this->goals->inputs(array_merge($resolved['flows'], $shared));

        $lines = [
            'You are a real user of the app'.($appId === '' ? '' : ' '.$appId).' on the device.'
                .' Explore it the way a person would and make sure the journey below works end to end.',
            "The landmarks come from the app's own test suite: they show the general flow, not a script."
                .' Do not follow them step by step — adapt to the screens you actually find.',
            '',
            'Journey: '.($tests === [] ? "the app's main flows" : implode(', ', $tests)),
        ];

        if ($goals !== []) {
            $lines[] = '';
            $lines[] = 'Landmarks to confirm:';

            foreach (array_values($goals) as $index => $goal) {
                $lines[] = ($index + 1).'. '.$goal;
            }
        }

        if ($inputs !== []) {
            $lines[] = '';
            $lines[] = 'Sign-in values from the test suite (use them to get past any login, then carry on like a normal user):';

            foreach ($inputs as $input) {
                $lines[] = '- '.$input;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The app the flows drive, taken from the first flow header that names one.
     * The lane needs it to bring the app back to the front mid-run.
     */
    public function appId(string $repoDir, array $tests): string
    {
        $root = rtrim($repoDir, '/').'/'.MaestroWorkspace::MAESTRO_DIR;
        $resolved = (new MaestroWorkspace)->resolveFlows($root, $tests);

        foreach ($resolved['flows'] as $file) {
            if (! is_file($file)) {
                continue;
            }

            if (preg_match('/^\s*appId\s*:\s*["\']?([A-Za-z0-9_.]+)["\']?\s*$/m', (string) file_get_contents($file), $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    /**
     * Writes the request, waits for the runner to finish it, and reads the result.
     *
     * @return array{exit: int|null, result: array<string, mixed>|null, report: array<string, mixed>|null, stderr: string, root: string, work: string}
     */
    public function run(string $runId, string $mission, AiConnectionData $connection, string $device, string $appId): array
    {
        $root = $this->root($runId);
        $work = $root.'/work';

        $this->remove($root);
        @mkdir($work.'/screenshots', 0777, true);

        // The container runs as its own non-root user, so the shared work dir has
        // to be writable by it. The mission carries nothing secret.
        file_put_contents($work.'/mission.txt', $mission);
        @chmod($work.'/mission.txt', 0644);
        @chmod($work, 0777);
        @chmod($work.'/screenshots', 0777);

        // The key is passed to the runner's env file and removed when the run ends;
        // it is never written into the work directory the agent can read.
        file_put_contents($root.'/env', implode("\n", [
            'FUSION_AI_BASE_URL='.$connection->openaiBaseUrl,
            'FUSION_AI_MODEL='.$connection->model,
            'FUSION_AI_KEY='.$connection->openaiToken,
            'FUSION_MAX_TURNS='.$connection->maxSteps,
            'FUSION_DEVICE='.$device,
            'FUSION_APP_ID='.$appId,
        ])."\n");
        @chmod($root.'/env', 0600);

        // Written last: its presence is what tells the runner the request is ready.
        file_put_contents($root.'/request.json', json_encode([
            'runId' => $runId,
            'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        $this->wait($root);

        return [
            'exit' => $this->exitCode($root),
            'result' => $this->result($work.'/agent.ndjson'),
            'report' => $this->report($work.'/report.json'),
            'stderr' => is_file($work.'/agent.err') ? trim((string) file_get_contents($work.'/agent.err')) : '',
            'root' => $root,
            'work' => $work,
        ];
    }

    public function forget(string $root): void
    {
        $this->remove($root);
    }

    private function wait(string $root): void
    {
        $timeout = $this->timeoutSeconds > 0 ? $this->timeoutSeconds : (int) config('fusion.ai.timeout_seconds');
        $poll = $this->pollMilliseconds > 0 ? $this->pollMilliseconds : (int) config('fusion.ai.poll_ms');
        $deadline = time() + $timeout;

        while (! is_file($root.'/done') && time() < $deadline) {
            usleep(max(100, $poll) * 1000);
        }
    }

    private function exitCode(string $root): ?int
    {
        if (! is_file($root.'/done')) {
            return null;
        }

        return (int) trim((string) file_get_contents($root.'/done'));
    }

    /** The last NDJSON frame of kind "result" is the run's verdict and usage. */
    private function result(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $found = null;

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '"type":"result"')) {
                continue;
            }

            $frame = json_decode($line, true);
            if (is_array($frame) && ($frame['type'] ?? null) === 'result') {
                $found = $frame;
            }
        }

        return $found;
    }

    private function report(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function root(string $runId): string
    {
        $control = $this->controlDir !== ''
            ? $this->controlDir
            : (string) config('fusion.android.control_dir');

        return rtrim($control, '/').'/ai/'.$runId;
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
