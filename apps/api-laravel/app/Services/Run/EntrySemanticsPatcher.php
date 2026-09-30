<?php

namespace App\Services\Run;

/**
 * Turns the accessibility tree on in the entry file a run is about to build.
 *
 * Flutter's web build exposes nothing to Maestro unless the app calls
 * ensureSemantics, so a repo that never does it leaves the web lane blind: the
 * app renders, no id is ever found, and the first assertion times out on a screen
 * that is plainly there. The workspace is re-cloned on every run, so the lines
 * have to be added here, after the clone and before the build. Repos that already
 * enable it are left untouched.
 *
 * Order matters: in a release build a binding getter returns null instead of
 * reporting the usual "binding has not yet been initialized", so a semantics call
 * placed ahead of the binding crashes the app on startup.
 */
class EntrySemanticsPatcher
{
    /** Any of these brings in WidgetsFlutterBinding. */
    const BINDING_IMPORTS = [
        'package:flutter/widgets.dart',
        'package:flutter/material.dart',
        'package:flutter/cupertino.dart',
    ];

    /** rendering.dart re-exports semantics.dart, so either one provides SemanticsBinding. */
    const SEMANTICS_IMPORTS = [
        'package:flutter/semantics.dart',
        'package:flutter/rendering.dart',
    ];

    const BINDING_CALL = 'WidgetsFlutterBinding.ensureInitialized';

    const SEMANTICS_CALL = 'SemanticsBinding.instance.ensureSemantics';

    const BINDING = self::BINDING_CALL.'();';

    const SEMANTICS = self::SEMANTICS_CALL.'();';

    /** Returns a note describing the edit, or null when the entry needed none. */
    public function ensure(string $repoDir, string $program): ?string
    {
        $path = rtrim($repoDir, '/').'/'.ltrim($program, '/');

        if (! is_file($path)) {
            return null;
        }

        $source = (string) file_get_contents($path);

        // Already the app's own job — leave its entry alone.
        if ($this->callOffset($source, self::SEMANTICS_CALL) !== null) {
            return null;
        }

        $binding = $this->callOffset($source, self::BINDING_CALL);

        if ($binding !== null) {
            // The entry initializes the binding itself, so the semantics call has to
            // follow it rather than open main().
            $statements = [self::SEMANTICS];
            $source = substr_replace($source, "\n  ".self::SEMANTICS, $binding, 0);
        } else {
            $body = $this->mainBodyOffset($source);

            if ($body === null) {
                return null;
            }

            $statements = [self::BINDING, self::SEMANTICS];
            $source = substr_replace($source, "\n  ".implode("\n  ", $statements), $body, 0);
        }

        $source = $this->withImports($source, $statements);

        file_put_contents($path, $source);

        return $program.' did not enable the accessibility tree, so '.count($statements)
            .' line(s) were added to its main(): the web lane finds elements by id, and '
            .'without ensureSemantics Flutter exposes none.';
    }

    /** Offset just inside main()'s body, or null when the entry has no main(). */
    private function mainBodyOffset(string $source): ?int
    {
        $pattern = '/(?:Future<void>|void)\s+main\s*\([^)]*\)\s*(?:async\s*)?\{/';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $match[0][1] + strlen($match[0][0]);
    }

    /** Offset just after the first real (uncommented) call, or null when there is none. */
    private function callOffset(string $source, string $call): ?int
    {
        $pattern = '/'.preg_quote($call, '/').'\s*\(\s*\)\s*;/';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($matches[0] as [$text, $offset]) {
            if ($this->isCommented($source, $offset)) {
                continue;
            }

            return $offset + strlen($text);
        }

        return null;
    }

    /** Whether the given offset sits inside a line or block comment. */
    private function isCommented(string $source, int $offset): bool
    {
        $before = substr($source, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $line = substr($before, $lineStart === false ? 0 : $lineStart + 1);

        if (str_contains($line, '//')) {
            return true;
        }

        $open = strrpos($before, '/*');
        $close = strrpos($before, '*/');

        return $open !== false && ($close === false || $open > $close);
    }

    /** @param  string[]  $statements */
    private function withImports(string $source, array $statements): string
    {
        $missing = [];

        if (in_array(self::BINDING, $statements, true) && ! $this->importsAny($source, self::BINDING_IMPORTS)) {
            $missing[] = "import 'package:flutter/widgets.dart';";
        }

        if (! $this->importsAny($source, self::SEMANTICS_IMPORTS)) {
            $missing[] = "import 'package:flutter/semantics.dart';";
        }

        if ($missing === []) {
            return $source;
        }

        // Imports have to precede declarations, so they go at the top — after a
        // library directive, when the entry has one.
        $offset = 0;

        if (preg_match('/^[ \t]*library\s+[^;]+;/m', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]);
        }

        $insert = "\n".implode("\n", $missing);

        if (($source[$offset] ?? "\n") !== "\n") {
            $insert .= "\n";
        }

        return substr_replace($source, $insert, $offset, 0);
    }

    /** @param  string[]  $paths */
    private function importsAny(string $source, array $paths): bool
    {
        foreach ($paths as $path) {
            // Matched as a directive, so a commented-out import does not count.
            if (preg_match('~^[ \t]*import\s+[\'"]'.preg_quote($path, '~').'[\'"]\s*;~m', $source) === 1) {
                return true;
            }
        }

        return false;
    }
}
