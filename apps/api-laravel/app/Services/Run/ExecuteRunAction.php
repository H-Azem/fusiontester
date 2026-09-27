<?php

namespace App\Services\Run;

use App\Models\Run;
use App\Repositories\Run\RunRepository;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Services\Gitlab\GitlabClient;
use Symfony\Component\Process\Process;

/**
 * Runs one job to completion.
 *
 * The stages that shell out to real tools (git, Flutter, Maestro) are ported here
 * directly. Two stages are deliberately honest about their boundary:
 *
 *  - `browse` starts the built bundle and fetches it, which proves the static
 *    server and the bundle are intact, but it cannot detect a Dart runtime error
 *    the way a real browser can. That check lives in the Node sidecar.
 *  - `ai` likewise needs a browser to read the accessibility tree, so it is
 *    marked skipped with the reason rather than pretended.
 */
class ExecuteRunAction
{
    const COMMAND_TIMEOUT_SECONDS = 600;

    const BUILD_TIMEOUT_SECONDS = 900;

    const MAESTRO_TIMEOUT_SECONDS = 1200;

    /** Chrome/Chromium is required to actually run the app; it is not bundled. */
    const BROWSER_CANDIDATES = [
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    public function __construct(
        private Run $run,
        private RunRepository $runs = new RunRepository,
        private GitlabConnectionRepository $connections = new GitlabConnectionRepository,
        private GitlabClient $gitlab = new GitlabClient,
        private LaunchTargetReader $launch = new LaunchTargetReader,
    ) {}

    public function handle(): void
    {
        $runId = (string) $this->run->getId();
        $workspace = \App\Http\Resources\RunArtifacts::workspace($runId);
        $repoDir = $workspace.'/repo';

        $connection = $this->connections->data();
        if ($connection === null) {
            $this->runs->failRun($this->run, 'queued', 'GitLab is not connected, so the source cannot be fetched.');

            return;
        }

        $this->runs->finishStage($this->run, 'queued', 'Started.');

        try {
            $this->fetch($workspace, $repoDir, $connection);
            if ($this->failed()) {
                return;
            }

            $this->packages($repoDir);
            if ($this->failed()) {
                return;
            }

            $target = $this->launchTarget($repoDir);
            if ($this->failed()) {
                return;
            }

            $this->build($repoDir, $target);
            if ($this->failed()) {
                return;
            }

            $url = $this->serve($repoDir);
            $this->browse($url);

            if ($this->run->getRunKinds() !== null && in_array(Run::KIND_MAESTRO, $this->run->getRunKinds(), true)) {
                $this->maestro($workspace, $repoDir, $url);
            } else {
                $this->runs->skipStage($this->run, 'maestro', 'Skipped — Maestro was not selected.');
            }

            if ($this->failed()) {
                return;
            }

            $this->runs->skipStage(
                $this->run,
                'ai',
                'Skipped — the AI lane needs a browser to read the accessibility tree; it runs in the Node sidecar.'
            );

            $this->runs->startStage($this->run, 'done');
            $this->runs->finishStage($this->run, 'done', 'Test passed.');
            $this->runs->passRun($this->run);
        } catch (\Throwable $exception) {
            $this->runs->failRun(
                $this->run,
                (string) $this->run->getCurrentStep(),
                GitlabClient::redact($exception->getMessage(), $connection->token)
            );
        }
    }

    private function fetch(string $workspace, string $repoDir, \App\Services\Gitlab\GitlabConnectionData $connection): void
    {
        $this->runs->startStage($this->run, 'fetch');
        $this->removeDirectory($workspace);

        $result = $this->process(
            ['git', 'clone', '--depth', '1', '--single-branch', '--branch', (string) $this->run->getBranch(), $this->cloneUrl($connection), $repoDir],
            dirname($repoDir),
            self::COMMAND_TIMEOUT_SECONDS
        );

        if (! $result['ok']) {
            $this->runs->failRun($this->run, 'fetch', GitlabClient::redact($result['output'], $connection->token));

            return;
        }

        $this->runs->finishStage($this->run, 'fetch', $result['output'] ?: 'Cloned '.$this->run->getBranch().'.');
    }

    private function packages(string $repoDir): void
    {
        $this->runs->startStage($this->run, 'packages');
        $result = $this->process(['flutter', 'pub', 'get'], $repoDir, self::COMMAND_TIMEOUT_SECONDS);

        $result['ok']
            ? $this->runs->finishStage($this->run, 'packages', $result['output'])
            : $this->runs->failRun($this->run, 'packages', $result['output']);
    }

    /** @return array{name: string, program: string} */
    private function launchTarget(string $repoDir): array
    {
        $this->runs->startStage($this->run, 'launch');

        try {
            $target = $this->launch->read($repoDir);
        } catch (\Throwable $exception) {
            $this->runs->failRun($this->run, 'launch', $exception->getMessage());

            return ['name' => '', 'program' => ''];
        }

        $this->runs->finishStage($this->run, 'launch', "Using \"{$target['name']}\" → {$target['program']}");

        return $target;
    }

    private function build(string $repoDir, array $target): void
    {
        $this->runs->startStage($this->run, 'build');

        $args = ['flutter', 'build', 'web', '-t', $target['program']];
        foreach ($this->dartDefines() as $define) {
            $args[] = '--dart-define='.$define;
        }

        $result = $this->process($args, $repoDir, self::BUILD_TIMEOUT_SECONDS);
        $commandLine = '$ '.implode(' ', $args);

        $result['ok']
            ? $this->runs->finishStage($this->run, 'build', $commandLine."\n\n".($result['output'] ?: 'Web build succeeded.'))
            : $this->runs->failRun($this->run, 'build', $commandLine."\n\n".$result['output']);
    }

    /** Starts a static server for the built bundle and returns its URL. */
    private function serve(string $repoDir): string
    {
        $port = random_int(20000, 29999);
        $documentRoot = $repoDir.'/build/web';

        $process = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $documentRoot],
            $documentRoot
        );
        $process->start();

        // Give the built-in server a moment before the browse stage fetches it.
        usleep(400_000);

        $this->served = $process;

        return 'http://127.0.0.1:'.$port.'/';
    }

    private ?Process $served = null;

    private function browse(string $url): void
    {
        $this->runs->startStage($this->run, 'browse');

        $browser = $this->findBrowser();
        if ($browser === null && getenv('CHROME_PATH') === false) {
            $this->runs->failRun($this->run, 'browse', 'No Chrome or Chromium found. Set CHROME_PATH to the browser executable.');

            return;
        }

        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));

        if ($body === false || ! str_contains($body, 'flutter')) {
            $this->runs->failRun($this->run, 'browse', 'The built bundle was not served at '.$url);

            return;
        }

        $this->runs->finishStage(
            $this->run,
            'browse',
            'Opened '.$url." — the bundle is served. A full in-browser boot check runs in the Node sidecar "
            .'(it detects Dart runtime errors, which an HTTP fetch cannot).'
        );
    }

    private function maestro(string $workspace, string $repoDir, string $url): void
    {
        $this->runs->startStage($this->run, 'maestro');

        $flows = $repoDir.'/.maestro/flows/'.($this->run->getTests()[0] ?? 'smoke').'/full_test.yaml';
        if (! is_file($flows)) {
            $this->runs->failRun($this->run, 'maestro', 'No Maestro flow found for the selected tests.');

            return;
        }

        $result = $this->process(
            ['maestro', 'test', '--headless', '--no-ansi', '--format', 'junit', '--output', $workspace.'/maestro-results.xml', '-e', 'APP_URL='.$url, $flows],
            $workspace,
            self::MAESTRO_TIMEOUT_SECONDS
        );

        $passed = $result['ok'] && ! str_contains($result['output'], 'Failure');

        $passed
            ? $this->runs->finishStage($this->run, 'maestro', $result['output'] ?: 'Maestro reported success.')
            : $this->runs->failRun($this->run, 'maestro', $result['output']);
    }

    /** @return array<int, string> */
    private function dartDefines(): array
    {
        // Semantics must be on or Maestro cannot see anything; dev tools expose
        // the long-press test login the flows rely on.
        $base = ['ENABLE_SEMANTICS=true', 'ENABLE_DEV_TOOLS=true'];

        $extra = preg_split('/[\s,]+/', (string) $this->run->getDartDefines()) ?: [];

        foreach ($extra as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*$/', $entry) === 1) {
                $base[] = $entry;
            }
        }

        return $base;
    }

    private function cloneUrl(\App\Services\Gitlab\GitlabConnectionData $connection): string
    {
        $parsed = parse_url($connection->baseUrl);
        $basePath = rtrim($parsed['path'] ?? '', '/');
        $path = ltrim(preg_replace('/\.git$/', '', $this->run->getProjectPath()) ?? '', '/');

        return sprintf(
            '%s://oauth2:%s@%s%s/%s.git',
            $parsed['scheme'] ?? 'https',
            rawurlencode($connection->token),
            $parsed['host'] ?? '',
            $basePath,
            $path
        );
    }

    private function findBrowser(): ?string
    {
        $override = getenv('CHROME_PATH');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        foreach (self::BROWSER_CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function failed(): bool
    {
        $this->run->refresh();

        return $this->run->getStatus() === Run::STATUS_FAILED;
    }

    private function process(array $command, string $cwd, int $timeout): array
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

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
