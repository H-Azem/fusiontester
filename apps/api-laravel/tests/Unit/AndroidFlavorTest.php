<?php

namespace Tests\Unit;

use App\Services\Run\ExecuteRunAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An entry point names its flavor, but Gradle does not always spell it the same way:
 * the flavor has to be one the Android project actually declares, or the build dies
 * on a Gradle task that does not exist.
 */
class AndroidFlavorTest extends TestCase
{
    private string $dir;

    private object $action;

    private ReflectionMethod $flavorFor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/flavor-'.uniqid();
        mkdir($this->dir.'/android/app', 0775, true);

        $this->action = (new \ReflectionClass(ExecuteRunAction::class))->newInstanceWithoutConstructor();
        $this->flavorFor = new ReflectionMethod($this->action, 'flavorFor');
        $this->flavorFor->setAccessible(true);
    }

    protected function tearDown(): void
    {
        foreach (['build.gradle.kts', 'build.gradle'] as $file) {
            if (is_file($this->dir.'/android/app/'.$file)) {
                unlink($this->dir.'/android/app/'.$file);
            }
        }

        foreach ([$this->dir.'/android/app', $this->dir.'/android', $this->dir] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_it_uses_the_spelling_the_android_project_declares(): void
    {
        // The real file from fusion-order-receiver: file says general_app, Gradle
        // says generalApp, and the spelled-out version is what the task name needs.
        file_put_contents($this->dir.'/android/app/build.gradle.kts', <<<'KTS'
        android {
            productFlavors {
                create("generalApp") { dimension = "app" }
                create("generalProduction") { dimension = "app" }
                create("sdkApp") { dimension = "app" }
                create("sdkProduction") { dimension = "app" }
            }
        }
        KTS);

        $this->assertSame('generalApp', $this->flavor('lib/mains/main_general_app.dart'));
        $this->assertSame('sdkProduction', $this->flavor('lib/mains/main_sdk_production.dart'));
    }

    public function test_it_keeps_a_flavor_that_is_already_spelled_the_same(): void
    {
        file_put_contents($this->dir.'/android/app/build.gradle', <<<'GROOVY'
        android {
            productFlavors {
                develop { dimension "app" }
                production { dimension "app" }
            }
        }
        GROOVY);

        $this->assertSame('develop', $this->flavor('lib/mains/main_develop.dart'));
    }

    public function test_it_falls_back_to_camel_case_without_a_gradle_file(): void
    {
        $this->assertSame('generalApp', $this->flavor('lib/mains/main_general_app.dart'));
    }

    public function test_an_entry_point_without_a_flavor_names_none(): void
    {
        $this->assertNull($this->flavor('lib/main.dart'));
    }

    private function flavor(string $program): ?string
    {
        return $this->flavorFor->invoke($this->action, $this->dir, $program);
    }
}
