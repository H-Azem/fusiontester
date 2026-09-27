<?php

namespace App\Services\Run;

/**
 * Reads the entry point from the repository's own VS Code launch configuration,
 * because the run should match what a developer starts locally.
 *
 * launch.json allows comments and trailing commas, so it is not strictly JSON.
 */
class LaunchTargetReader
{
    const CONFIG_PATH = '.vscode/launch.json';

    public function read(string $repoDir): array
    {
        $path = rtrim($repoDir, '/').'/'.self::CONFIG_PATH;

        if (! is_file($path)) {
            throw new \RuntimeException(
                'No '.self::CONFIG_PATH.' in the repository, so the app entry point is unknown.'
            );
        }

        $parsed = json_decode($this->stripComments((string) file_get_contents($path)), true);

        if (! is_array($parsed) || ! isset($parsed['configurations']) || ! is_array($parsed['configurations'])) {
            throw new \RuntimeException(self::CONFIG_PATH.' could not be parsed as JSON.');
        }

        foreach ($parsed['configurations'] as $configuration) {
            $name = $configuration['name'] ?? null;
            $program = $configuration['program'] ?? null;

            if (is_string($name) && str_contains(mb_strtolower($name), 'develop')) {
                if (! is_string($program) || trim($program) === '') {
                    throw new \RuntimeException("Launch configuration \"{$name}\" has no program entry point.");
                }

                return ['name' => $name, 'program' => trim($program)];
            }
        }

        throw new \RuntimeException(
            'No launch configuration whose name contains "develop" was found in '.self::CONFIG_PATH.'.'
        );
    }

    /** Removes // and /* *\/ comments plus trailing commas, leaving strings alone. */
    public function stripComments(string $input): string
    {
        $out = '';
        $inString = false;
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];
            $next = $input[$index + 1] ?? '';

            if ($inString) {
                $out .= $char;
                if ($char === '\\') {
                    $out .= $next;
                    $index++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;
                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($index < $length && $input[$index] !== "\n") {
                    $index++;
                }
                $out .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $index += 2;
                while ($index < $length && ! ($input[$index] === '*' && ($input[$index + 1] ?? '') === '/')) {
                    $index++;
                }
                $index++;
                continue;
            }

            $out .= $char;
        }

        return preg_replace('/,(\s*[}\]])/', '$1', $out) ?? $out;
    }
}
