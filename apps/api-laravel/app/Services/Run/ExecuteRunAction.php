<?php

namespace App\Services\Run;

use App\Models\ProjectSetting;
use App\Models\Run;
use App\Repositories\Run\RunRepository;
use App\Repositories\Settings\AiConnectionRepository;
use App\Repositories\Settings\GitlabConnectionRepository;
use App\Http\Resources\RunArtifacts;
use App\Services\Ai\AiLane;
use App\Services\Gitlab\GitlabClient;
use App\Services\Telegram\TelegramNotifier;
use Symfony\Component\Process\Process;

/**
 * Runs one job to completion.
 *
 * The stages that shell out to real tools (git, Flutter, Maestro) are ported here
 * directly. The boot check is delegated to the Node sidecar, because reading a
 * Flutter web app's runtime state means reading its accessibility tree over CDP.
 * The AI lane instead writes a request for the host runner, which drives the app
 * on the device with the Command Code CLI inside a sandboxed container — this
 * process is given no Docker socket, so it cannot start one itself.
 */
class ExecuteRunAction
{
    const COMMAND_TIMEOUT_SECONDS = 600;

    const BUILD_TIMEOUT_SECONDS = 900;

    const MAESTRO_TIMEOUT_SECONDS = 1200;

    const INSTALL_TIMEOUT_SECONDS = 600;

    const ADB_TIMEOUT_SECONDS = 120;

    /** The redroid container runs x86_64; Flutter builds ARM unless asked otherwise. */
    const ANDROID_TARGET = 'android-x64';

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
        private LiveCapture $live = new LiveCapture,
        private DevicePower $power = new DevicePower,
        private TelegramNotifier $telegram = new TelegramNotifier,
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

        $android = $this->isAndroid();
        $url = '';

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

            $android ? $this->buildAndroid($repoDir, $target) : $this->build($repoDir, $target);
            if ($this->failed()) {
                return;
            }

            if ($android) {
                $this->installOnDevice($repoDir);
            } else {
                $url = $this->serve($repoDir);
                $this->browse($url);
            }

            if ($this->failed()) {
                return;
            }

            $kinds = $this->run->getRunKinds() ?? [];

            if (! in_array(Run::KIND_MAESTRO, $kinds, true)) {
                $this->runs->skipStage($this->run, 'maestro', 'Skipped — Maestro was not selected.');
            } elseif ($android) {
                $this->maestroOnDevice($workspace, $repoDir);
            } else {
                $this->maestro($workspace, $repoDir, $url);
            }

            if ($this->failed()) {
                return;
            }

            $aiLaneEnabled = (bool) ($this->aiConnections->row()?->getAiLaneEnabled() ?? true);

            if (! in_array(Run::KIND_AI, $kinds, true)) {
                $this->runs->skipStage($this->run, 'ai', 'Skipped — the AI check was not selected.');
            } elseif (! $aiLaneEnabled) {
                $this->runs->skipStage($this->run, 'ai', 'Skipped — the AI lane is turned off in Settings.');
            } elseif (! $android) {
                $this->runs->skipStage($this->run, 'ai', 'Skipped — the AI lane drives the app on the Android device.');
            } else {
                $this->ai($workspace, $repoDir, $url);
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
        } finally {
            // One message per finished run, wherever it got to. Sent before the
            // workspace goes, so a failure has its screenshots to point at.
            try {
                $this->telegram->report($this->run->refresh());
            } catch (\Throwable) {
                // A notification is a courtesy; it must never change a run's outcome.
            }

            // The app stays on the device otherwise, and no run ever looks at it again.
            try {
                $this->removeInstalledApps($repoDir);
            } catch (\Throwable) {
                // Tidying up must never change a run's outcome.
            }

            // The clone and its build output are ~1.6GB per run, while the screenshots
            // and reports the dashboard serves after it are kilobytes. Keeping the
            // source would fill the disk within a week.
            if (! (bool) config('fusion.keep_workspace')) {
                $this->removeDirectory($repoDir);
            }
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
     * `flutter pub get` fetches private `git:` dependencies with git itself, so they
     * need credentials that cloning the repository never provided. The token is the
     * one stored for GitLab in Settings, written to a credential file rather than a
     * `url.<...>.insteadOf` rewrite: the container's entrypoint used to write that
     * same global key from its own environment, and with two entries for one host git
     * read whichever came first — which is how a stale token kept winning over the
     * one the dashboard was configured with.
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

        $path = (getenv('HOME') ?: '/root').'/.git-credentials';
        file_put_contents($path, 'https://oauth2:'.$connection->token.'@'.$host."\n");
        chmod($path, 0600);

        $this->process(['git', 'config', '--global', 'credential.helper', 'store'], null, self::COMMAND_TIMEOUT_SECONDS);
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

    /**
     * The device lane builds an APK instead of a web bundle. Flutter's default is
     * ARM-only, and the redroid container runs x86_64, so the target has to be
     * named or the install succeeds and nothing can start.
     */
    private function buildAndroid(string $repoDir, array $target): void
    {
        $this->runs->startStage($this->run, 'build');

        // The device holds roughly what the build needs, and nothing is testing it
        // yet: the host watcher stops it now and the install stage wakes it again.
        if ((bool) config('fusion.android.sleep_for_build')) {
            $this->power->sleep();
        }

        $this->capGradleMemory($repoDir);

        $args = ['flutter', 'build', 'apk', '--debug', '--target-platform', self::ANDROID_TARGET, '-t', $target['program']];

        // Without an explicit flavor Gradle packages every variant of the debug
        // build, so a run aimed at `main_develop.dart` also spends minutes (and
        // risks failing) on the production flavor it will never install.
        $flavor = $this->flavorFor($repoDir, (string) $target['program']);

        if ($flavor !== null) {
            array_push($args, '--flavor', $flavor);
        }

        foreach ($this->extraDartDefines() as $define) {
            $args[] = '--dart-define='.$define;
        }

        $result = $this->process($args, $repoDir, self::BUILD_TIMEOUT_SECONDS);

        // The daemon keeps its heap until told to stop, and the device test needs
        // that memory back on a machine this small.
        $this->process([$repoDir.'/android/gradlew', '--stop'], $repoDir.'/android', self::ADB_TIMEOUT_SECONDS);

        $commandLine = '$ '.implode(' ', $args);

        $result['ok']
            ? $this->runs->finishStage($this->run, 'build', $commandLine."\n\n".($result['output'] ?: 'APK built.'))
            : $this->runs->failRun($this->run, 'build', $commandLine."\n\n".$result['output']);
    }

    /**
     * An entry point names its flavor, but not always in the spelling Gradle uses:
     * `main_general_app.dart` belongs to the `generalApp` flavor, while
     * `main_develop.dart` is plain `develop`. Guessing from the file alone turned
     * `general_app` into `--flavor general_app`, and Gradle answered with
     * "Task 'assembleGeneral_appDebug' not found". So the file gives a candidate and
     * the Android project's own flavor list decides the spelling.
     */
    private function flavorFor(string $repoDir, string $program): ?string
    {
        if (preg_match('#/main_([A-Za-z0-9_]+)\.dart$#', $program, $matches) !== 1) {
            return null;
        }

        $suffix = $matches[1];
        $wanted = $this->normalizeFlavor($suffix);

        foreach ($this->declaredFlavors($repoDir) as $declared) {
            if ($this->normalizeFlavor($declared) === $wanted) {
                return $declared;
            }
        }

        // No readable Gradle file: camelCase is the Flutter convention.
        return $this->camelCase($suffix);
    }

    /** Underscores and case are the two things that differ between the two names. */
    private function normalizeFlavor(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }

    private function camelCase(string $name): string
    {
        $parts = explode('_', $name);
        $first = array_shift($parts) ?? '';

        return $first.implode('', array_map(ucfirst(...), $parts));
    }

    /**
     * The product flavors an Android project declares, in both Gradle dialects:
     * `create("generalApp")` in Kotlin DSL and `generalApp { }` in Groovy.
     *
     * @return array<int, string>
     */
    private function declaredFlavors(string $repoDir): array
    {
        foreach ([$repoDir.'/android/app/build.gradle.kts', $repoDir.'/android/app/build.gradle'] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $at = strpos($source, 'productFlavors');

            if ($at === false) {
                continue;
            }

            // The block is not delimited safely (flavors nest their own braces), so
            // the window after the keyword is scanned instead.
            preg_match_all(
                '/create\(\s*"([A-Za-z0-9_]+)"|^\s*([A-Za-z][A-Za-z0-9_]*)\s*\{/m',
                substr($source, $at, 4000),
                $names,
                PREG_SET_ORDER
            );

            $found = [];

            foreach ($names as $match) {
                $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                if ($name !== '') {
                    $found[] = $name;
                }
            }

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * Apps ship a gradle.properties written for a developer machine — -Xmx8G is
     * common — and the Gradle daemon then dies on a shared server with "daemon
     * disappeared unexpectedly". The heap is rewritten to what this box can spare,
     * and Jetifier is switched off: jetifying the Flutter engine jar is the build's
     * memory peak, and it only matters for the pre-AndroidX support libraries these
     * apps do not use.
     */
    private function capGradleMemory(string $repoDir): void
    {
        $path = rtrim($repoDir, '/').'/android/gradle.properties';

        if (! is_file($path)) {
            return;
        }

        $source = (string) file_get_contents($path);

        $args = '-Xmx'.(int) config('fusion.android.gradle_heap_mb').'m -XX:MaxMetaspaceSize=512m';

        $source = preg_match('/^[ \t]*org\.gradle\.jvmargs\s*=.*$/m', $source) === 1
            ? (string) preg_replace('/^[ \t]*org\.gradle\.jvmargs\s*=.*$/m', 'org.gradle.jvmargs='.$args, $source, 1)
            : rtrim($source, "\n")."\norg.gradle.jvmargs=".$args."\n";

        $source = preg_match('/^[ \t]*android\.enableJetifier\s*=/m', $source) === 1
            ? (string) preg_replace('/^[ \t]*android\.enableJetifier\s*=.*$/m', 'android.enableJetifier=false', $source, 1)
            : $source;

        file_put_contents($path, $source);
    }

    /**
     * Android names the package it refused to replace: "Existing package com.x.y
     * signatures do not match newer version". Reading it from the output keeps the
     * pipeline from having to guess an application id out of the Gradle files.
     */
    public static function clashingPackage(string $output): ?string
    {
        if (preg_match('/Existing package ([A-Za-z][A-Za-z0-9_.]*)/', $output, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/INSTALL_FAILED_UPDATE_INCOMPATIBLE[^\n]*?([A-Za-z][A-Za-z0-9_]*\.[A-Za-z0-9_.]+)/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /** Packages that did not ship with Android: the apps this device exists for. */
    private function installedPackages(string $repoDir): array
    {
        $result = $this->process(
            array_merge($this->adb(), ['shell', 'pm', 'list', 'packages', '-3']),
            $repoDir,
            self::INSTALL_TIMEOUT_SECONDS
        );

        if (! $result['ok']) {
            return [];
        }

        $packages = [];

        foreach (preg_split('/\R/', $result['output']) ?: [] as $line) {
            $line = trim($line);

            if (! str_starts_with($line, 'package:')) {
                continue;
            }

            $name = trim(substr($line, strlen('package:')));

            if (preg_match('/^[A-Za-z][A-Za-z0-9_.]*$/', $name) === 1) {
                $packages[] = $name;
            }
        }

        return array_unique($packages);
    }

    /**
     * The device keeps everything earlier runs installed. Removing it after every run,
     * passing or failing, is what stops the disk filling with installations nobody
     * looks at — and it means the next run starts from a clean device instead of
     * meeting an older build's signature.
     */
    private function removeInstalledApps(string $repoDir): void
    {
        foreach ($this->installedPackages($repoDir) as $package) {
            $this->process(
                array_merge($this->adb(), ['uninstall', $package]),
                $repoDir,
                self::INSTALL_TIMEOUT_SECONDS
            );
        }
    }

    /**
     * Installs the built APK and prepares the device. This is the device lane's
     * answer to the browse stage: it proves the app reached a real device.
     */
    private function installOnDevice(string $repoDir): void
    {
        $this->runs->startStage($this->run, 'browse');

        // Wake the device the build put to sleep, then wait for Android to answer
        // before anything is installed on it.
        $this->power->wake();

        if (! $this->waitForDevice()) {
            $this->runs->failRun(
                $this->run,
                'browse',
                'The device did not come back after the build: '.$this->device().' never reported boot_completed.'
            );

            return;
        }

        $apk = $this->latestApk($repoDir);

        if ($apk === null) {
            $this->runs->failRun($this->run, 'browse', 'The build produced no APK to install.');

            return;
        }

        $prepared = $this->prepareDevice();

        $install = array_merge($this->adb(), ['install', '-r', $apk]);

        $result = $this->process($install, $repoDir, self::INSTALL_TIMEOUT_SECONDS);

        // An earlier run left this app behind, built with another key. Android refuses
        // to replace it and names the package, so that one is removed and the install
        // is tried again rather than failing the run over stale state.
        $clashing = $result['ok'] ? null : self::clashingPackage($result['output']);

        if ($clashing !== null) {
            $this->process(
                array_merge($this->adb(), ['uninstall', $clashing]),
                $repoDir,
                self::INSTALL_TIMEOUT_SECONDS
            );

            $result = $this->process($install, $repoDir, self::INSTALL_TIMEOUT_SECONDS);
            $prepared .= "\nRemoved the older ".$clashing." that was still installed.";
        }

        $result['ok']
            ? $this->runs->finishStage(
                $this->run,
                'browse',
                'Installed '.basename($apk).' on '.$this->device().".\n\n".$prepared
            )
            : $this->runs->failRun($this->run, 'browse', 'adb install failed.'."\n\n".$result['output']);
    }

    /**
     * Android shows first-run dialogs above the app and they swallow the first tap
     * of a flow — the immersive-mode cling cost us a whole run of debugging — so a
     * device used for testing is told not to show them. The display follows the
     * run's orientation, because an app that locks landscape is otherwise laid out
     * for a portrait screen and taps land away from their targets.
     */
    private function prepareDevice(): string
    {
        $settings = [
            ['settings', 'put', 'secure', 'immersive_mode_confirmations', 'confirmed'],
            ['settings', 'put', 'global', 'window_animation_scale', '0'],
            ['settings', 'put', 'global', 'transition_animation_scale', '0'],
            ['settings', 'put', 'global', 'animator_duration_scale', '0'],
            ['svc', 'power', 'stayon', 'true'],
            ['wm', 'size', $this->run->getOrientation() === ProjectSetting::ORIENTATION_VERTICAL
                ? self::SCREEN_SIZE_VERTICAL
                : self::SCREEN_SIZE_HORIZONTAL],
        ];

        $applied = [];

        foreach ($settings as $setting) {
            $result = $this->process(array_merge($this->adb(), ['shell'], $setting), null, self::ADB_TIMEOUT_SECONDS);

            if ($result['ok']) {
                $applied[] = implode(' ', $setting);
            }
        }

        $keyboard = $this->useQuietKeyboard();

        if ($keyboard !== null) {
            $applied[] = $keyboard;
        }

        return 'Device prepared: '.implode(', ', $applied).'.';
    }

    /** The input method that draws nothing, and the package it ships in. */
    private const QUIET_KEYBOARD_PACKAGE = 'com.nilac.nullkeyboard';

    private const QUIET_KEYBOARD_IME = 'com.nilac.nullkeyboard/.NullKeyboardService';

    /**
     * The keyboard a test device gets: a real input method that draws nothing.
     *
     * A soft keyboard covers the bottom of the screen and pushes the app's layout
     * around, hiding the element a flow is about to assert on — the row a step just
     * added, in the case that prompted this. Maestro injects text itself and never
     * needs a keyboard, so the device keeps one that shows nothing.
     *
     * It keeps an input method at all, rather than having none: Android restores its
     * default IME when nothing is enabled, which is why disabling the keyboard does
     * not hold. And it is installed only when missing, because the device keeps it
     * across runs — this is here so a device rebuilt from nothing comes back the same.
     *
     * @return string|null what happened, for the stage log
     */
    private function useQuietKeyboard(): ?string
    {
        if (! $this->packageInstalled(self::QUIET_KEYBOARD_PACKAGE)) {
            $files = resource_path('ime/null-keyboard');

            $apks = array_values(array_filter([
                $files.'/base.apk',
                $files.'/split_config.xhdpi.apk',
                $files.'/split_config.en.apk',
            ], 'is_file'));

            if ($apks === []) {
                return null;
            }

            $install = $this->process(
                array_merge($this->adb(), ['install-multiple', '-r'], $apks),
                null,
                self::INSTALL_TIMEOUT_SECONDS
            );

            if (! $install['ok']) {
                return null;
            }
        }

        // Selecting it is what stops Android from putting its own keyboard back.
        $this->process(array_merge($this->adb(), ['shell', 'ime', 'enable', self::QUIET_KEYBOARD_IME]), null, self::ADB_TIMEOUT_SECONDS);
        $this->process(array_merge($this->adb(), ['shell', 'ime', 'set', self::QUIET_KEYBOARD_IME]), null, self::ADB_TIMEOUT_SECONDS);

        return self::QUIET_KEYBOARD_PACKAGE;
    }

    /**
     * Drops the advertisement Maestro prints after a run.
     *
     * The CLI ends its output with a boxed pitch for its cloud service, which has
     * nothing to do with the test and is the last thing a person reads in the panel.
     * It arrives inside a box drawn from box-drawing characters, and no line Maestro
     * prints about a running flow is made only of those, so the box goes and nothing
     * else does.
     */
    public static function stripMaestroPromo(string $output): string
    {
        $kept = [];
        $inBox = false;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $trimmed = ltrim($line);

            // Everything between the box's own corners goes, whatever it says.
            if (str_starts_with($trimmed, '╭')) {
                $inBox = true;
                continue;
            }

            if ($inBox) {
                $inBox = ! str_starts_with($trimmed, '╰');
                continue;
            }

            // Anything the CLI prints outside a box, and a stray border line.
            if (stripos($line, 'maestro cloud') !== false) {
                continue;
            }

            if ($line !== '' && preg_match('/^[\s╭╮╰╯│─]+$/u', $line) === 1) {
                continue;
            }

            $kept[] = $line;
        }

        // A blank or two often remains where the box was.
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));
    }

    private function packageInstalled(string $package): bool
    {
        $result = $this->process(
            array_merge($this->adb(), ['shell', 'pm', 'list', 'packages', $package]),
            null,
            self::ADB_TIMEOUT_SECONDS
        );

        return $result['ok'] && str_contains($result['output'], 'package:'.$package);
    }

    /**
     * The flows run exactly as the repository wrote them — `appId:` names the app
     * on the device — and the live view captures frames while they do.
     */
    private function maestroOnDevice(string $workspace, string $repoDir): void
    {
        $this->runs->startStage($this->run, 'maestro');

        try {
            $root = $this->maestro->prepare($repoDir, $workspace, '', false, retarget: false);
        } catch (\Throwable $exception) {
            $this->runs->failRun($this->run, 'maestro', $exception->getMessage());

            return;
        }

        $requested = $this->maestro->withSmokeFirst($this->run->getTests() ?? []);
        $resolved = $this->maestro->resolveFlows($root, $requested);
        $smokeNote = count($requested) > count($this->run->getTests() ?? [])
            ? "\nSmoke ran first, so the app was signed in before the selected tests."
            : '';

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

        $live = $this->live->start($this->run, $workspace);

        try {
            $result = $this->process(
                array_merge(
                    ['maestro', 'test', '--no-ansi', '--format', 'junit', '--output', $report,
                        '--test-output-dir', $artifacts, '--device', $this->device()],
                    $resolved['flows']
                ),
                $workspace,
                self::MAESTRO_TIMEOUT_SECONDS
            );
        } finally {
            // Whatever happened, the frames stop with the stage that asked for them.
            $this->live->stop($live, $workspace);
        }

        $this->copyMaestroScreenshots($artifacts, $workspace, $result['ok']);

        $counts = $this->maestroCounts($report);

        if (! $result['ok']) {
            $this->runs->failRun($this->run, 'maestro', "Maestro reported failures ({$counts}).\n\n".$result['output']);

            return;
        }

        $this->runs->finishStage(
            $this->run,
            'maestro',
            'Ran '.count($resolved['flows'])." flow(s): {$counts}.".$smokeNote."\n\nFlows: ".(implode(', ', $resolved['flows']))."\n\n".self::stripMaestroPromo($result['output'])
        );
    }

    /** Flutter names the APK after the flavor, so the newest one is the one we built. */
    private function latestApk(string $repoDir): ?string
    {
        $found = glob(rtrim($repoDir, '/').'/build/app/outputs/flutter-apk/*.apk') ?: [];

        if ($found === []) {
            return null;
        }

        usort($found, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $found[0];
    }

    /** @return array<int, string> */
    private function adb(): array
    {
        return [(string) config('fusion.android.adb'), '-s', $this->device()];
    }

    /** adb holds one connection per device, and a resumed container needs a new one. */
    private function connectDevice(): void
    {
        $this->process(
            [(string) config('fusion.android.adb'), 'connect', $this->device()],
            null,
            self::ADB_TIMEOUT_SECONDS
        );
    }

    /**
     * Waits for Android to finish booting — a resumed container takes ~30s and its
     * adbd only accepts a connection once it is up, so connect is retried here
     * rather than attempted once before the device is ready.
     */
    private function waitForDevice(): bool
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->connectDevice();

            $result = $this->process(
                array_merge($this->adb(), ['shell', 'getprop', 'sys.boot_completed']),
                null,
                self::ADB_TIMEOUT_SECONDS
            );

            // The first call also prints adb's own "daemon started" banner, so match
            // the line itself rather than the whole output.
            if (preg_match('/^\s*1\s*$/m', $result['output']) === 1) {
                return true;
            }

            sleep(4);
        }

        return false;
    }

    private function device(): string
    {
        return (string) config('fusion.android.device');
    }

    private function isAndroid(): bool
    {
        return $this->run->getPlatform() === Run::PLATFORM_ANDROID;
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

    /**
     * Drives the app on the device, not in a browser: the lane reads the
     * accessibility tree over adb and reports each goal as pass or fail.
     */
    private function ai(string $workspace, string $repoDir, string $url): void
    {
        $this->runs->startStage($this->run, 'ai');

        $connection = $this->aiConnections->data();
        if ($connection === null) {
            $this->runs->failRun(
                $this->run,
                'ai',
                'The AI connection is not configured. Add an OpenAI-compatible endpoint and a token in Settings.'
            );

            return;
        }

        $lane = new AiLane;
        $runId = (string) $this->run->getId();
        $tests = $this->maestro->withSmokeFirst($this->run->getTests() ?? []);
        $appId = $lane->appId($repoDir, $tests);

        // Whatever the team typed for this repository on the run sheet, so the lane
        // gets the real PIN or setup values instead of guessing them from the flows.
        $givenData = (string) (ProjectSetting::query()
            ->where(ProjectSetting::PROJECT_ID, $this->run->getProjectId())
            ->first()
            ?->getAiContext() ?? '');

        // Keep exactly what the lane was told, so a run that goes somewhere unexpected
        // can be explained from the run itself rather than guessed at.
        $mission = $lane->mission($repoDir, $tests, $appId, $givenData);
        @mkdir(dirname(RunArtifacts::path($runId, RunArtifacts::AI_MISSION)), 0775, true);
        file_put_contents(RunArtifacts::path($runId, RunArtifacts::AI_MISSION), $mission);

        // The lane can run for many minutes on the device, so the live view records
        // the whole time — otherwise a run whose only kind is ai has no frames at all.
        $live = $this->live->start($this->run, $workspace);

        try {
            $result = $lane->run(
                $runId,
                $mission,
                $connection,
                $this->device(),
                $appId,
            );
        } finally {
            $this->live->stop($live, $workspace);
        }

        $this->storeAiArtifacts($result);
        $lane->forget($result['root']);

        if ($result['exit'] === null) {
            $this->runs->failRun($this->run, 'ai', 'The AI lane did not answer in time.');

            return;
        }

        $goals = is_array($result['report']['goals'] ?? null) ? $result['report']['goals'] : [];
        $summary = (string) ($result['report']['summary'] ?? $result['result']['finalText'] ?? '');
        $trace = $this->formatAiGoals($goals);

        if ($result['exit'] !== 0 || $this->aiGoalsFailed($goals)) {
            $this->runs->failRun($this->run, 'ai', implode("\n", array_filter([
                $summary !== '' ? $summary : 'The AI lane reported a failure.',
                $trace,
                $result['stderr'] !== '' ? "\nDiagnosis:\n".$result['stderr'] : null,
            ])));

            return;
        }

        $this->runs->finishStage($this->run, 'ai', implode("\n", array_filter([
            $summary !== '' ? $summary : 'AI checks passed.',
            $trace,
        ])));
    }

    /** The report and any failure frames the lane captured, kept like Maestro's. */
    private function storeAiArtifacts(array $result): void
    {
        $runId = (string) $this->run->getId();
        $work = (string) ($result['work'] ?? '');

        if ($work === '') {
            return;
        }

        // The report is kept as one self-contained file the panel can render: the
        // agent's goals, plus the token usage from the CLI's result frame, which
        // only the pipeline sees.
        $report = is_file($work.'/report.json')
            ? (json_decode((string) file_get_contents($work.'/report.json'), true) ?: [])
            : [];

        if (! is_array($report)) {
            $report = [];
        }

        $usage = $result['result']['usage'] ?? null;
        if (is_array($usage)) {
            $report['usage'] = $usage;
        }

        if (($report['summary'] ?? '') === '' && isset($result['result']['finalText'])) {
            $report['summary'] = (string) $result['result']['finalText'];
        }

        if ($report !== []) {
            @mkdir(dirname(RunArtifacts::path($runId, RunArtifacts::AI_REPORT)), 0775, true);
            file_put_contents(
                RunArtifacts::path($runId, RunArtifacts::AI_REPORT),
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        foreach (glob($work.'/screenshots/*.png') ?: [] as $shot) {
            $target = RunArtifacts::path($runId, RunArtifacts::AI_SHOTS.'/'.basename($shot));
            @mkdir(dirname($target), 0775, true);
            @copy($shot, $target);
        }
    }

    private function formatAiGoals(array $goals): string
    {
        $lines = [];

        foreach ($goals as $goal) {
            if (! is_array($goal)) {
                continue;
            }

            $lines[] = sprintf(
                '%s %s — %s',
                strtolower((string) ($goal['status'] ?? '')) === 'pass' ? '✓' : '✗',
                (string) ($goal['text'] ?? '?'),
                (string) ($goal['evidence'] ?? '')
            );
        }

        return $lines === [] ? '' : "\nGoals:\n".implode("\n", $lines);
    }

    private function aiGoalsFailed(array $goals): bool
    {
        foreach ($goals as $goal) {
            if (is_array($goal) && strtolower((string) ($goal['status'] ?? '')) === 'fail') {
                return true;
            }
        }

        return false;
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

        $requested = $this->maestro->withSmokeFirst($this->run->getTests() ?? []);
        $resolved = $this->maestro->resolveFlows($root, $requested);
        $smokeNote = count($requested) > count($this->run->getTests() ?? [])
            ? "\nSmoke ran first, so the app was signed in before the selected tests."
            : '';

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
            'Ran '.count($resolved['flows'])." flow(s): {$counts}.".$smokeNote."\n\nFlows: ".(implode(', ', $resolved['flows']))."\n\n".self::stripMaestroPromo($result['output'])
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
        // the long-press test login the flows rely on. Both are web-lane concerns:
        // a debug APK is already in debug mode and Android supplies the tree.
        return array_merge(['ENABLE_SEMANTICS=true', 'ENABLE_DEV_TOOLS=true'], $this->extraDartDefines());
    }

    /** @return array<int, string> */
    private function extraDartDefines(): array
    {
        $extra = preg_split('/[\s,]+/', (string) $this->run->getDartDefines()) ?: [];
        $defines = [];

        foreach ($extra as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*$/', $entry) === 1) {
                $defines[] = $entry;
            }
        }

        return $defines;
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
