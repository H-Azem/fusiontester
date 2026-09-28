<?php

namespace App\Services\Run;

use App\Models\ProjectSetting;
use App\Models\Run;
use App\Repositories\Run\RunRepository;
use App\Repositories\Settings\AiConnectionRepository;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Http\Resources\RunArtifacts;
use App\Services\Gitlab\GitlabClient;
use Symfony\Component\Process\Process;

/**
 * Runs one job to completion.
 *
 * The stages that shell out to real tools (git, Flutter, Maestro) are ported here
 * directly. The two that need a real browser — the boot check and the AI lane —
 * are delegated to the Node sidecar, because reading a Flutter web app's runtime
 * state means reading its accessibility tree over CDP. When the sidecar is
 * unavailable those stages fail with that reason rather than reporting a pass.
 */
class ExecuteRunAction
{
    const COMMAND_TIMEOUT_SECONDS = 600;

    const BUILD_TIMEOUT_SECONDS = 900;

    const MAESTRO_TIMEOUT_SECONDS = 1200;

    /** The kiosk lays itself out from the viewport it is handed, on this design canvas. */
    const SCREEN_SIZE_VERTICAL = '1080x1920';

    const SCREEN_SIZE_HORIZONTAL = '1920x1080';

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
        private AiConnectionRepository $aiConnections = new AiConnectionRepository,
        private GitlabClient $gitlab = new GitlabClient,
        private LaunchTargetReader $launch = new LaunchTargetReader,
        private BrowserSidecar $sidecar = new BrowserSidecar,
        private MaestroWorkspace $maestro = new MaestroWorkspace,
        private EntrySemanticsPatcher $semantics = new EntrySemanticsPatcher,
        private Directory $directories = new Directory,
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

            $this->packages($repoDir, $connection);
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

            if ($this->run->getRunKinds() !== null && in_array(Run::KIND_AI, $this->run->getRunKinds(), true)) {
                $this->ai($workspace, $repoDir, $url);
            } else {
                $this->runs->skipStage($this->run, 'ai', 'Skipped — the AI check was not selected.');
            }

            if ($this->failed()) {
                return;
            }

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

        // The clone runs with the workspace as its working directory, so it has
        // to exist first: git will not create the parent for us.
        if (! is_dir($workspace) && ! @mkdir($workspace, 0775, true) && ! is_dir($workspace)) {
            $this->runs->failRun($this->run, 'fetch', "Could not create the run workspace at {$workspace}.");

            return;
        }

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

    private function packages(string $repoDir, \App\Services\Gitlab\GitlabConnectionData $connection): void
    {
        $this->runs->startStage($this->run, 'packages');
        $this->authorizeGitForPubDependencies($connection);

        $result = $this->process(['flutter', 'pub', 'get'], $repoDir, self::COMMAND_TIMEOUT_SECONDS);
        // The token is in git's global config, so it can surface in this output.
        $output = GitlabClient::redact($result['output'], $connection->token);

        $result['ok']
            ? $this->runs->finishStage($this->run, 'packages', $output)
            : $this->runs->failRun($this->run, 'packages', $output);
    }

    /**
     * `flutter pub get` fetches private `git:` dependencies with git itself, so
     * they need credentials that cloning the repository never provided. Those
     * packages almost always live on the same GitLab the repository came from,
     * so git is pointed at the token already stored for cloning.
     */
    private function authorizeGitForPubDependencies(\App\Services\Gitlab\GitlabConnectionData $connection): void
    {
        if ($connection->token === '') {
            return;
        }

        $host = parse_url($connection->baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return;
        }

        $this->process([
            'git', 'config', '--global',
            'url.https://oauth2:'.$connection->token.'@'.$host.'/.insteadOf',
            'https://'.$host.'/',
        ], null, self::COMMAND_TIMEOUT_SECONDS);
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

        $patch = $this->semantics->ensure($repoDir, $target['program']);

        $this->runs->finishStage(
            $this->run,
            'launch',
            "Using \"{$target['name']}\" → {$target['program']}".($patch === null ? '' : "\n\n".$patch)
        );

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

        if ($this->findBrowser() === null) {
            $this->runs->failRun($this->run, 'browse', 'No Chrome or Chromium found. Set CHROME_PATH to the browser executable.');

            return;
        }

        $result = $this->sidecar->browse($url);

        if ($result['data'] === null) {
            // A plain fetch would prove the bundle is served but not that the app
            // boots, so say the check could not run instead of reporting a pass.
            $this->runs->failRun($this->run, 'browse', 'The browser check could not run: '.$result['error']);

            return;
        }

        $data = $result['data'];
        $this->saveScreenshot($data['screenshot'] ?? null, 'screenshot.png');

        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];

        if (empty($data['booted'])) {
            $this->runs->failRun(
                $this->run,
                'browse',
                $errors === [] ? 'The app never started in the browser.' : implode("\n", $errors)
            );

            return;
        }

        if (empty($data['ok'])) {
            $this->runs->failRun($this->run, 'browse', implode("\n", $errors));

            return;
        }

        $this->runs->finishStage(
            $this->run,
            'browse',
            "Opened {$url} — the app started without errors.".($data['screenshot'] ? ' Screenshot captured.' : '')
        );
    }

    private function ai(string $workspace, string $repoDir, string $url): void
    {
        $this->runs->startStage($this->run, 'ai');

        $connection = $this->aiConnections->data();
        if ($connection === null) {
            $this->runs->failRun(
                $this->run,
                'ai',
                'The AI connection is not configured. Add a jev token and an OpenAI-compatible endpoint in Settings.'
            );

            return;
        }

        $result = $this->sidecar->runAi([
            'url' => $url,
            'repoDir' => $repoDir,
            'runDir' => $workspace,
            'tests' => $this->run->getTests() ?? [],
            'maxSteps' => $connection->maxSteps,
            'config' => [
                'openaiBaseUrl' => $connection->openaiBaseUrl,
                'openaiModel' => $connection->model,
                'openaiToken' => $connection->openaiToken,
                'jevBaseUrl' => $connection->jevBaseUrl,
                'jevToken' => $connection->jevToken,
            ],
        ]);

        if ($result['data'] === null) {
            $this->runs->failRun($this->run, 'ai', 'The AI lane could not run: '.$result['error']);

            return;
        }

        $data = $result['data'];
        $this->saveScreenshot($data['screenshot'] ?? null, 'ai-failure.png');

        $trace = '';
        foreach ((array) ($data['steps'] ?? []) as $step) {
            $trace .= sprintf(
                "%s. %s — %s\n     expected: %s\n     observed: %s\n",
                $step['step'] ?? '?',
                $step['action'] ?? '?',
                $step['detail'] ?? '',
                $step['expect'] ?? '(none)',
                $step['check'] ?? ''
            );
        }

        if (empty($data['ok'])) {
            $this->runs->failRun($this->run, 'ai', implode("\n", array_filter([
                (string) ($data['summary'] ?? 'The AI lane reported a failure.'),
                isset($data['diagnosis']) ? "\nDiagnosis:\n".$data['diagnosis'] : null,
                $trace !== '' ? "\nSteps:\n".$trace : null,
            ])));

            return;
        }

        $this->runs->finishStage(
            $this->run,
            'ai',
            implode("\n", array_filter([(string) ($data['summary'] ?? 'AI checks passed.'), $trace !== '' ? "\nSteps:\n".$trace : null]))
        );
    }

    /** The sidecar returns base64 so the frame can travel over stdout. */
    private function saveScreenshot(mixed $base64, string $file): void
    {
        if (! is_string($base64) || $base64 === '') {
            return;
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return;
        }

        $path = RunArtifacts::path((string) $this->run->getId(), $file);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $decoded);
    }

    private function maestro(string $workspace, string $repoDir, string $url): void
    {
        $this->runs->startStage($this->run, 'maestro');

        $isProduction = in_array(Run::ENVIRONMENT_PRODUCTION, $this->run->getEnvironments() ?? [], true);

        // The flows are retargeted at the served app first: a flow that still
        // says appId makes Maestro look for a device that is not there.
        try {
            $root = $this->maestro->prepare($repoDir, $workspace, $url, $isProduction);
        } catch (\Throwable $exception) {
            $this->runs->failRun($this->run, 'maestro', $exception->getMessage());

            return;
        }

        $resolved = $this->maestro->resolveFlows($root, $this->run->getTests() ?? []);

        if ($resolved['missing'] !== []) {
            $this->runs->failRun($this->run, 'maestro', 'No flow files found for: '.implode(', ', $resolved['missing']).'.');

            return;
        }

        if ($resolved['flows'] === []) {
            $this->runs->failRun($this->run, 'maestro', 'No tests were selected for this run.');

            return;
        }

        $artifacts = $workspace.'/maestro-artifacts';
        $report = $workspace.'/maestro-results.xml';
        @mkdir($artifacts, 0775, true);

        // Maestro's browser opens in a small landscape window by default, and a browser
        // has no orientation for the app to lock — so the run's orientation is what tells
        // the app how to lay itself out. Without this the answer cards are laid out for a
        // viewport they were never designed for and taps land beside them.
        $screenSize = $this->run->getOrientation() === ProjectSetting::ORIENTATION_VERTICAL
            ? self::SCREEN_SIZE_VERTICAL
            : self::SCREEN_SIZE_HORIZONTAL;

        $result = $this->process(
            array_merge(
                ['maestro', 'test', '--headless', '--no-ansi', '--format', 'junit', '--output', $report,
                    '--test-output-dir', $artifacts, '--screen-size', $screenSize, '-e', 'APP_URL='.$url],
                $resolved['flows']
            ),
            $workspace,
            self::MAESTRO_TIMEOUT_SECONDS
        );

        $this->copyMaestroScreenshots($artifacts, $workspace, $result['ok']);

        $counts = $this->maestroCounts($report);

        if (! $result['ok']) {
            $this->runs->failRun($this->run, 'maestro', "Maestro reported failures ({$counts}).\n\n".$result['output']);

            return;
        }

        $this->runs->finishStage(
            $this->run,
            'maestro',
            'Ran '.count($resolved['flows'])." flow(s): {$counts}.\n\n".$result['output']
        );
    }

    /** Maestro's artifacts are what the dashboard shows when a run fails. */
    private function copyMaestroScreenshots(string $artifacts, string $workspace, bool $passed): void
    {
        if (! is_dir($artifacts)) {
            return;
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($artifacts, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        foreach ($found as $index => $source) {
            $name = $passed
                ? 'maestro-'.($index + 1).'.png'
                : ($index === 0 ? 'maestro-failure.png' : 'maestro-failure-'.($index + 1).'.png');

            copy($source, rtrim($workspace, '/').'/'.$name);
        }
    }

    /** The junit report is the only place the flow counts are recorded. */
    private function maestroCounts(string $report): string
    {
        if (! is_file($report)) {
            return 'results unreadable';
        }

        $xml = (string) file_get_contents($report);

        if (preg_match('/\btests="(\d+)"/', $xml, $tests) !== 1) {
            return 'results unreadable';
        }

        $failures = preg_match('/\bfailures="(\d+)"/', $xml, $failed) === 1 ? (int) $failed[1] : 0;
        $errors = preg_match('/\berrors="(\d+)"/', $xml, $err) === 1 ? (int) $err[1] : 0;

        return ((int) $tests[1] - $failures - $errors).'/'.$tests[1].' passed';
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

    private function process(array $command, ?string $cwd, int $timeout): array
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
        $this->directories->remove($path);
    }
}
