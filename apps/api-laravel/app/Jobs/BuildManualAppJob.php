<?php

namespace App\Jobs;

use App\Repositories\Manual\ManualAppRepository;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Services\Gitlab\GitlabClient;
use App\Services\Run\ApkBuilder;
use App\Services\Run\DevicePower;
use App\Services\Run\Directory;
use App\Services\Run\EntrySemanticsPatcher;
use App\Services\Run\LaunchTargetReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

/**
 * Builds one manual app's APK from its branch.
 *
 * This is a build, not a run: nothing is queued in the test list, no device is driven.
 * The APK replaces the previous one, so the app always points at the newest commit.
 */
class BuildManualAppJob implements ShouldQueue
{
    use Queueable;

    const CLONE_TIMEOUT_SECONDS = 900;

    const PACKAGES_TIMEOUT_SECONDS = 900;

    public function __construct(private int $appId) {}

    public function handle(): void
    {
        $apps = new ManualAppRepository;
        $app = $apps->find($this->appId);

        if ($app === null) {
            return;
        }

        $connection = (new GitlabConnectionRepository)->data();

        if ($connection === null) {
            $apps->recordFailure($app, 'GitLab is not connected.');

            return;
        }

        $root = storage_path('app/fusion/manual/'.$app->getId());
        $repoDir = $root.'/repo';
        $directories = new Directory;
        $directories->remove($root);
        @mkdir($root, 0775, true);

        try {
            $clone = $this->run(
                ['git', 'clone', '--depth', '1', '--single-branch', '--branch', (string) $app->getBranch(),
                    GitlabClient::cloneUrl($connection, (string) $app->getProjectPath()), $repoDir],
                $root,
                self::CLONE_TIMEOUT_SECONDS
            );

            if (! $clone['ok']) {
                $apps->recordFailure($app, GitlabClient::redact($clone['output'], $connection->token));

                return;
            }

            $this->run(['flutter', 'pub', 'get'], $repoDir, self::PACKAGES_TIMEOUT_SECONDS);

            $target = (new LaunchTargetReader)->read($repoDir);
            (new EntrySemanticsPatcher)->ensure($repoDir, $target['program']);

            // The device holds what the build needs, and nothing is testing it now.
            $power = new DevicePower;

            if ((bool) config('fusion.android.sleep_for_build')) {
                $power->sleep();
            }

            try {
                $result = (new ApkBuilder)->build($repoDir, (string) $target['program']);
            } finally {
                $power->wake();
            }

            if (! $result['ok'] || $result['apk'] === null) {
                $apps->recordFailure($app, $result['output']);

                return;
            }

            $path = storage_path('app/fusion/manual-apk/'.$app->getId().'.apk');
            @mkdir(dirname($path), 0775, true);
            @copy($result['apk'], $path);

            $apps->recordBuild($app, $path, $this->headSha($repoDir));
        } finally {
            $directories->remove($repoDir);
        }
    }

    private function headSha(string $repoDir): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'], $repoDir, 60)['output']);
    }

    /** @param array<int, string> $command @return array{ok: bool, output: string} */
    private function run(array $command, ?string $cwd, int $timeout): array
    {
        $process = new Process($command, $cwd, null, null, $timeout);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'output' => $exception->getMessage()];
        }

        return [
            'ok' => $process->isSuccessful(),
            'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
        ];
    }
}
