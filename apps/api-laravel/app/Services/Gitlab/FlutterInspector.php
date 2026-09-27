<?php

namespace App\Services\Gitlab;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers "is this branch testable" by inspecting the repository itself.
 *
 * A Flutter *application* is distinguished from a package or plugin through
 * pubspec.yaml plus the presence of platform directories, and the runnable
 * tests are the folders directly under .maestro/flows.
 */
class FlutterInspector
{
    const MAESTRO_FLOWS_PATH = '.maestro/flows';

    /** Reusable subflows, not tests; Maestro's own convention. */
    const NON_TEST_FLOW_FOLDERS = ['shared'];

    /** Whole-app suites: selecting one makes individual selection meaningless. */
    const EXCLUSIVE_TEST_FOLDERS = ['full_app'];

    /** Presence of any of these marks a runnable application. */
    const PLATFORM_DIRECTORIES = ['android', 'ios', 'web', 'linux', 'macos', 'windows'];

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
        $allFolders = $flowsTree === null
            ? []
            : array_values(array_filter($flowsTree, fn (array $entry) => ($entry['type'] ?? '') === 'tree'));
        $testFolders = array_values(array_filter($allFolders, fn (array $entry) => $this->isTestFolder((string) $entry['name'])));

        if ($flowsTree === null) {
            $hasMaestroFlows = false;
            $hasMaestroFlowsReason = 'No '.self::MAESTRO_FLOWS_PATH.' folder at the project root.';
        } elseif ($testFolders === []) {
            $hasMaestroFlows = false;
            $hasMaestroFlowsReason = $allFolders === []
                ? self::MAESTRO_FLOWS_PATH.' exists but contains no test folders.'
                : self::MAESTRO_FLOWS_PATH.' contains only shared subflow folders ('
                    .implode(', ', array_column($allFolders, 'name')).'), with no tests.';
        } else {
            $hasMaestroFlows = true;
            $plural = count($testFolders) === 1 ? '' : 's';
            $hasMaestroFlowsReason = 'Found '.count($testFolders)." test folder{$plural} in ".self::MAESTRO_FLOWS_PATH.'.';
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

    /** Each folder directly under .maestro/flows is one test. */
    public function listMaestroTests(GitlabConnectionData $connection, int $projectId, string $ref): array
    {
        $tree = $this->client->getTree($connection, $projectId, $ref, self::MAESTRO_FLOWS_PATH);
        if ($tree === null) {
            return [];
        }

        $tests = [];
        foreach ($tree as $entry) {
            if (($entry['type'] ?? '') !== 'tree' || ! $this->isTestFolder((string) $entry['name'])) {
                continue;
            }

            $name = (string) $entry['name'];
            $tests[] = [
                'name' => $name,
                'displayName' => self::humanizeTestName($name),
                'path' => (string) ($entry['path'] ?? $name),
                'exclusive' => in_array($name, self::EXCLUSIVE_TEST_FOLDERS, true),
            ];
        }

        usort($tests, function (array $a, array $b): int {
            // Whole-suite tests lead the list; everything else is alphabetical.
            $exclusive = (int) $b['exclusive'] <=> (int) $a['exclusive'];

            return $exclusive !== 0 ? $exclusive : strcmp($a['displayName'], $b['displayName']);
        });

        return $tests;
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
