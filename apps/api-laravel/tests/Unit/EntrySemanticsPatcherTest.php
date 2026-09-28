<?php

namespace Tests\Unit;

use App\Services\Run\EntrySemanticsPatcher;
use PHPUnit\Framework\TestCase;

class EntrySemanticsPatcherTest extends TestCase
{
    private string $dir;

    private EntrySemanticsPatcher $patcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/entry-patcher-'.uniqid();
        mkdir($this->dir.'/lib/mains', 0775, true);
        $this->patcher = new EntrySemanticsPatcher;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/lib/mains/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir.'/lib/mains');
        @rmdir($this->dir.'/lib');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_adds_both_lines_to_an_entry_that_never_enabled_semantics(): void
    {
        $entry = $this->write(<<<'DART'
        import 'package:kiosk/config/app_theme.dart';
        import 'package:kiosk/main.dart';

        void main() {
          final appConfig = FlavorConfig(flavor: 'app');
          mainCommon(appConfig);
        }
        DART);

        $note = $this->patcher->ensure($this->dir, 'lib/mains/main_app.dart');

        $this->assertNotNull($note);
        $this->assertStringContainsString("import 'package:flutter/widgets.dart';", $entry());
        $this->assertStringContainsString("import 'package:flutter/semantics.dart';", $entry());
        $this->assertStringContainsString('WidgetsFlutterBinding.ensureInitialized();', $entry());
        $this->assertStringContainsString('SemanticsBinding.instance.ensureSemantics();', $entry());
        $this->assertLessThan(strpos($entry(), 'final appConfig'), strpos($entry(), 'ensureSemantics();'));
        $this->assertStringNotContainsString("';import", $entry());
    }

    public function test_it_leaves_an_entry_that_already_enables_semantics_alone(): void
    {
        $source = <<<'DART'
        import 'package:flutter/rendering.dart';
        import 'package:flutter/widgets.dart';

        void main() {
          WidgetsFlutterBinding.ensureInitialized();
          if (const bool.fromEnvironment('ENABLE_SEMANTICS')) {
            SemanticsBinding.instance.ensureSemantics();
          }
          mainCommon('app');
        }
        DART;

        $entry = $this->write($source);

        $this->assertNull($this->patcher->ensure($this->dir, 'lib/mains/main_app.dart'));
        $this->assertSame($source."\n", $entry());
    }

    public function test_it_keeps_existing_imports_and_the_binding_call(): void
    {
        $entry = $this->write(<<<'DART'
        import 'package:flutter/material.dart';

        Future<void> main() async {
          WidgetsFlutterBinding.ensureInitialized();
          mainCommon('app');
        }
        DART);

        $this->patcher->ensure($this->dir, 'lib/mains/main_app.dart');

        // material.dart already provides the binding, and the app already calls it.
        $this->assertSame(1, substr_count($entry(), 'package:flutter/material.dart'));
        $this->assertSame(1, substr_count($entry(), 'ensureInitialized'));
        $this->assertStringContainsString("import 'package:flutter/semantics.dart';", $entry());
        $this->assertStringContainsString('SemanticsBinding.instance.ensureSemantics();', $entry());
    }

    public function test_it_ignores_a_missing_entry_file(): void
    {
        $this->assertNull($this->patcher->ensure($this->dir, 'lib/mains/nope.dart'));
    }

    /** Writes an entry and returns a reader for its current content. */
    private function write(string $source): callable
    {
        $path = $this->dir.'/lib/mains/main_app.dart';
        file_put_contents($path, $source."\n");

        return fn (): string => (string) file_get_contents($path);
    }
}
