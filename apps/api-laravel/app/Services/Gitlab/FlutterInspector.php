<?php

namespace App\Services\Gitlab;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers "is this branch testable" by inspecting the repository itself.
 *
 * A Flutter *application* is distinguished from a package or plugin through
 * pubspec.yaml plus the presence of platform directories.
 *
 * The tests are the *leaves* of .maestro/flows, the same way the repository's own
 * suite runner finds them: a leaf is a folder that holds full_test.yaml and has no
 * runnable folder inside it. Feature folders may nest, so a test is named by its
 * path — `orders/add_order` — and selecting a parent is never required.
 */
class FlutterInspector
{
    const MAESTRO_FLOWS_PATH = '.maestro/flows';

    const TEST_FLOW_FILE = 'full_test.yaml';

    /** Reusable subflows, not tests; Maestro's own convention. */
    const NON_TEST_FLOW_FOLDERS = ['shared'];

    /** Whole-app suites, orchestrated rather than run as one flow. */
    const EXCLUSIVE_TEST_FOLDERS = ['full_app'];

    /** Presence of any of these marks a runnable application. */
    const PLATFORM_DIRECTORIES = ['android', 'ios', 'web', 'linux', 'macos', 'windows'];

    /** Deep enough for the layouts people actually use, and a bound on API calls. */
    const MAX_FLOW_DEPTH = 5;

    public function __construct(private GitlabClient $client = new GitlabClient) {}

    public function checkBranch(GitlabConnectionData $connection, int $projectId, string $ref): array
    {
        $rootEntries = $this->client->getTree($connection, $projectId, $ref) ?? [];

        $hasPubspec = $this->hasEntry($rootEntries, 'pubspec.yaml', 'blob');
        $platforms = array_values(array_filter(
            self::PLATFORM_DIRECTORIES,
            fn (string $directory) => $this->hasEntry($rootEntries, $directory, 'tree')
        ));

        [$isFlutterApp, $isFlutterAppReason] = $this->inspectPubspec(
            $connection,
            $projectId,
            $ref,
            $hasPubspec,
            $platforms
        );

        $flowsTree = $this->client->getTree($connection, $projectId, $ref, self::MAESTRO_FLOWS_PATH);
        $tests = $flowsTree === null ? [] : $this->leaves($connection, $projectId, $ref);

        if ($flowsTree === null) {
            $hasMaestroFlows = false;
            $hasMaestroFlowsReason = 'No '.self::MAESTRO_FLOWS_PATH.' folder at the project root.';
        } elseif ($tests === []) {
            $hasMaestroFlows = false;
            $hasMaestroFlowsReason = self::MAESTRO_FLOWS_PATH.' holds no tests: a test is a folder with '
                .self::TEST_FLOW_FILE.' and no test folder inside it.';
        } else {
            $hasMaestroFlows = true;
            $plural = count($tests) === 1 ? '' : 's';
            $hasMaestroFlowsReason = 'Found '.count($tests)." test{$plural} in ".self::MAESTRO_FLOWS_PATH.'.';
        }

        return [
            'ref' => $ref,
            'isFlutterApp' => $isFlutterApp,
            'isFlutterAppReason' => $isFlutterAppReason,
            'hasMaestroFlows' => $hasMaestroFlows,
            'hasMaestroFlowsReason' => $hasMaestroFlowsReason,
            'canContinue' => $isFlutterApp && $hasMaestroFlows,
        ];
    }

    /**
     * Every leaf test, named by its path under .maestro/flows, so a nested feature
     * can be picked on its own instead of taking its whole folder.
     *
     * @return array<int, array{name: string, displayName: string, path: string, exclusive: bool}>
     */
    public function listMaestroTests(GitlabConnectionData $connection, int $projectId, string $ref): array
    {
        $tests = [];

        foreach ($this->leaves($connection, $projectId, $ref) as $relative) {
            $tests[] = [
                'name' => $relative,
                'displayName' => self::displayNameFor($relative),
                'path' => self::MAESTRO_FLOWS_PATH.'/'.$relative,
                'exclusive' => in_array(basename($relative), self::EXCLUSIVE_TEST_FOLDERS, true),
            ];
        }

        usort($tests, function (array $a, array $b): int {
            // Whole-suite tests lead the list; everything else is alphabetical.
            $exclusive = (int) $b['exclusive'] <=> (int) $a['exclusive'];

            return $exclusive !== 0 ? $exclusive : strcmp($a['name'], $b['name']);
        });

        return $tests;
    }

    /**
     * `orders/add_order` -> `Orders · Add order`: the path says where it lives, which
     * matters once two features can share a leaf name.
     */
    public static function displayNameFor(string $relative): string
    {
        $parts = array_values(array_filter(explode('/', $relative), fn (string $part) => $part !== ''));

        if ($parts === []) {
            return $relative;
        }

        $leaf = self::humanizeTestName((string) array_pop($parts));
        $parents = array_map([self::class, 'humanizeTestName'], $parts);

        return $parents === [] ? $leaf : implode(' · ', $parents).' · '.$leaf;
    }

    /** `orders_list` -> `Orders list`: only the first character is upper-cased. */
    public static function humanizeTestName(string $folder): string
    {
        $spaced = trim(str_replace('_', ' ', $folder));

        return $spaced === '' ? $folder : mb_strtoupper(mb_substr($spaced, 0, 1)).mb_substr($spaced, 1);
    }

    public function isTestFolder(string $folderName): bool
    {
        return ! in_array($folderName, self::NON_TEST_FLOW_FOLDERS, true);
    }

    /**
     * Relative paths of every leaf under .maestro/flows.
     *
     * A folder is a leaf when it holds the entry flow and nothing runnable inside it.
     * A folder with children is a menu: the children are the tests, and its own entry
     * flow is not offered — which is how the repository's own runner reads it.
     *
     * @return array<int, string>
     */
    private function leaves(GitlabConnectionData $connection, int $projectId, string $ref): array
    {
        return $this->leavesUnder($connection, $projectId, $ref, '', 0);
    }

    /** @return array<int, string> */
    private function leavesUnder(
        GitlabConnectionData $connection,
        int $projectId,
        string $ref,
        string $relative,
        int $depth
    ): array {
        if ($depth > self::MAX_FLOW_DEPTH) {
            return [];
        }

        $path = $relative === '' ? self::MAESTRO_FLOWS_PATH : self::MAESTRO_FLOWS_PATH.'/'.$relative;
        $entries = $this->client->getTree($connection, $projectId, $ref, $path) ?? [];

        $hasEntryFlow = false;
        $folders = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (($entry['type'] ?? '') === 'blob') {
                $hasEntryFlow = $hasEntryFlow || $name === self::TEST_FLOW_FILE;
                continue;
            }

            if (($entry['type'] ?? '') !== 'tree' || $this->isReservedFolder($name)) {
                continue;
            }

            $folders[] = $name;
        }

        sort($folders);

        $leaves = [];

        foreach ($folders as $folder) {
            $child = $relative === '' ? $folder : $relative.'/'.$folder;

            $leaves = array_merge(
                $leaves,
                $this->leavesUnder($connection, $projectId, $ref, $child, $depth + 1)
            );
        }

        if ($leaves !== []) {
            return $leaves;
        }

        // Nothing runnable below, so this folder is itself the test.
        return $hasEntryFlow && $relative !== '' ? [$relative] : [];
    }

    private function isReservedFolder(string $folder): bool
    {
        return in_array(
            $folder,
            array_merge(self::NON_TEST_FLOW_FOLDERS, self::EXCLUSIVE_TEST_FOLDERS),
            true
        );
    }

    private function inspectPubspec(
        GitlabConnectionData $connection,
        int $projectId,
        string $ref,
        bool $hasPubspec,
        array $platforms
    ): array {
        if (! $hasPubspec) {
            return [false, 'No pubspec.yaml at the repository root, so this is not a Dart or Flutter project.'];
        }

        $contents = $this->client->getFileRaw($connection, $projectId, 'pubspec.yaml', $ref);
        if ($contents === null) {
            return [false, 'pubspec.yaml could not be read at this branch.'];
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException) {
            return [false, 'pubspec.yaml could not be parsed as YAML.'];
        }

        if (! is_array($parsed)) {
            return [false, 'pubspec.yaml is empty or malformed.'];
        }

        $flutter = $parsed['flutter'] ?? null;

        if (! is_array($flutter)) {
            return [false, 'This is a plain Dart package — pubspec.yaml has no flutter section.'];
        }

        if (array_key_exists('plugin', $flutter)) {
            return [false, 'This is a Flutter plugin (pubspec.yaml declares flutter.plugin), not an application.'];
        }

        if ($platforms === []) {
            return [false, 'This is a Flutter package — it has no platform directories, so there is nothing to launch.'];
        }

        return [true, 'Flutter application ('.implode(', ', $platforms).').'];
    }

    private function hasEntry(array $entries, string $name, string $type): bool
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name && ($entry['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }
}
