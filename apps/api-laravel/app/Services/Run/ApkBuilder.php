<?php

namespace App\Services\Run;

use Symfony\Component\Process\Process;

/**
 * Building an Android APK, in one place.
 *
 * The pipeline that runs tests and the job that builds an app for hand-testing both
 * need exactly this: the right flavor, a heap the box can spare, and the APK that came
 * out. Keeping it here means the two can never drift apart.
 */
class ApkBuilder
{
    /** A cold Android build on this host runs to ~15 minutes. */
    const BUILD_TIMEOUT_SECONDS = 1800;

    const COMMAND_TIMEOUT_SECONDS = 600;

    const ADB_TIMEOUT_SECONDS = 120;

    /** The redroid container runs x86_64; Flutter builds ARM unless asked otherwise. */
    const ANDROID_TARGET = 'android-x64';

    /**
     * @param  array<int, string>  $extraDefines
     * @return array{ok: bool, output: string, apk: string|null, command: string}
     */
    public function build(string $repoDir, string $program, array $extraDefines = []): array
    {
        $this->capGradleMemory($repoDir);

        $args = ['flutter', 'build', 'apk', '--debug', '--target-platform', self::ANDROID_TARGET, '-t', $program];

        // Without an explicit flavor Gradle packages every variant of the debug
        // build, so a run aimed at `main_develop.dart` also spends minutes (and
        // risks failing) on the flavor it will never install.
        $flavor = $this->flavorFor($repoDir, $program);

        if ($flavor !== null) {
            array_push($args, '--flavor', $flavor);
        }

        foreach ($extraDefines as $define) {
            $args[] = '--dart-define='.$define;
        }

        $result = $this->run($args, $repoDir, self::BUILD_TIMEOUT_SECONDS);

        // The daemon keeps its heap until told to stop, and the device test needs
        // that memory back on a machine this small.
        $this->run([$repoDir.'/android/gradlew', '--stop'], $repoDir.'/android', self::ADB_TIMEOUT_SECONDS);

        return [
            'ok' => $result['ok'],
            'output' => $result['output'],
            'apk' => $result['ok'] ? $this->latestApk($repoDir) : null,
            'command' => '$ '.implode(' ', $args),
        ];
    }

    /**
     * An entry point names its flavor, but not always in the spelling Gradle uses:
     * `main_general_app.dart` belongs to the `generalApp` flavor, while
     * `main_develop.dart` is plain `develop`. Guessing from the file alone turned
     * `general_app` into `--flavor general_app`, and Gradle answered with
     * "Task 'assembleGeneral_appDebug' not found". So the file gives a candidate and
     * the Android project's own flavor list decides the spelling.
     */
    public function flavorFor(string $repoDir, string $program): ?string
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

    /** Flutter names the APK after the flavor, so the newest one is the one we built. */
    public function latestApk(string $repoDir): ?string
    {
        $found = glob(rtrim($repoDir, '/').'/build/app/outputs/flutter-apk/*.apk') ?: [];

        if ($found === []) {
            return null;
        }

        usort($found, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $found[0];
    }

    /**
     * Apps ship a gradle.properties written for a developer machine — -Xmx8G is
     * common — and the Gradle daemon then dies on a shared server with "daemon
     * disappeared unexpectedly". The heap is rewritten to what this box can spare,
     * and Jetifier is switched off: jetifying the Flutter engine jar is the build's
     * memory peak, and it only matters for the pre-AndroidX support libraries these
     * apps do not use.
     */
    public function capGradleMemory(string $repoDir): void
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
