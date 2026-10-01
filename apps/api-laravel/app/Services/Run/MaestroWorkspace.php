<?php

namespace App\Services\Run;

/**
 * Prepares the repository's Maestro workspace for a web run.
 *
 * Maestro identifies a session by `appId:` for mobile and by `url:` for the web
 * lane, so a flow written for Android has to be retargeted or Maestro looks for
 * a device that is not there. The workspace is copied rather than edited in
 * place, which keeps relative `runFlow: ../shared/...` references working while
 * leaving the checkout untouched.
 */
class MaestroWorkspace
{
    const MAESTRO_DIR = '.maestro';

    const FLOWS_DIR = 'flows';

    /** The flow that signs in; it runs before any other flow the run names. */
    const SMOKE_FLOW = 'smoke';

    public function __construct(private Directory $directories = new Directory) {}

    /** @return string the prepared workspace root */
    public function prepare(string $repoDir, string $runDir, string $appUrl, bool $isProduction, bool $retarget = true): string
    {
        $source = rtrim($repoDir, '/').'/'.self::MAESTRO_DIR;

        if (! is_dir($source)) {
            throw new \RuntimeException('No '.self::MAESTRO_DIR.' directory in the repository.');
        }

        $target = rtrim($runDir, '/').'/'.self::MAESTRO_DIR;
        $this->directories->remove($target);
        $this->directories->copy($source, $target);

        // The Android lane runs the flows exactly as the repository wrote them:
        // `appId:` is what tells Maestro which device app to drive.
        if (! $retarget) {
            return $target;
        }

        foreach ($this->yamlFiles($target) as $file) {
            $original = (string) file_get_contents($file);
            $rewritten = $this->rewriteFlowForWeb($original, $appUrl, $isProduction);

            if ($rewritten !== $original) {
                file_put_contents($file, $rewritten);
            }
        }

        return $target;
    }

    /**
     * `full_test.yaml` is the repository convention for a folder's entry point;
     * otherwise every flow directly inside the folder is used.
     *
     * @param array<int, string> $tests
     * @return array{flows: array<int, string>, missing: array<int, string>}
     */
    /**
     * Every application carries a `smoke` flow that signs in and reaches its home
     * screen. A run that names other flows needs that sign-in to happen first, so
     * smoke is put in front of them; picking nothing means every flow (smoke
     * included) and picking a whole-suite test covers it by itself.
     *
     * @param  array<int, string>  $tests  the flow folders the run asked for
     * @return array<int, string>
     */
    public function withSmokeFirst(string $maestroRoot, array $tests): array
    {
        if ($tests === []) {
            return $tests;
        }

        $root = rtrim($maestroRoot, '/').'/'.self::FLOWS_DIR;

        foreach ($tests as $test) {
            if (is_file($root.'/'.$test.'/full_test.yaml')) {
                return $tests;
            }
        }

        $rest = array_values(array_filter(
            $tests,
            fn ($test) => strcasecmp((string) $test, self::SMOKE_FLOW) !== 0
        ));

        return array_merge([self::SMOKE_FLOW], $rest);
    }

    public function resolveFlows(string $maestroRoot, array $tests): array
    {
        $flows = [];
        $missing = [];

        foreach ($tests as $test) {
            $folder = rtrim($maestroRoot, '/').'/'.self::FLOWS_DIR.'/'.$test;

            if (is_file($folder.'/full_test.yaml')) {
                $flows[] = $folder.'/full_test.yaml';
                continue;
            }

            $found = [];
            if (is_dir($folder)) {
                foreach (scandir($folder) ?: [] as $entry) {
                    if (is_file($folder.'/'.$entry) && preg_match('/\.ya?ml$/i', $entry) === 1) {
                        $found[] = $folder.'/'.$entry;
                    }
                }
                sort($found);
            }

            if ($found !== []) {
                array_push($flows, ...$found);
                continue;
            }

            $missing[] = $test;
        }

        return ['flows' => $flows, 'missing' => $missing];
    }

    /**
     * The header has to name a URL for the web lane, because Maestro uses it as
     * the session identifier. `clearState: true` is also neutralised for
     * production runs: wiping app data there would remove the credentials the app
     * needs to sign in at all.
     */
    public function rewriteFlowForWeb(string $source, string $appUrl, bool $isProduction): string
    {
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $output = $source;

        if (preg_match('/^\s*appId\s*:/m', $output) === 1) {
            $output = preg_replace('/^(\s*)appId\s*:.*$/m', '$1url: '.$appUrl, $output, 1) ?? $output;
        } elseif (preg_match('/^\s*url\s*:/m', $output) !== 1) {
            // A flow with no header at all still needs one to know what to launch.
            $output = 'url: '.$appUrl.$eol.'---'.$eol.$output;
        }

        if ($isProduction) {
            $output = preg_replace('/clearState\s*:\s*true/', 'clearState: false', $output) ?? $output;
        }

        return $output;
    }

    /** @return array<int, string> */
    private function yamlFiles(string $directory): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && preg_match('/\.ya?ml$/i', $file->getFilename()) === 1) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
