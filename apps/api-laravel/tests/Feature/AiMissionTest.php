<?php

namespace Tests\Feature;

use App\Services\Ai\AiLane;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The lane's mission: the flows that were picked, plus — when the operator typed
 * one — their own test. With the override on, the custom test is the whole
 * mission and the picked flows are not checked at all.
 */
class AiMissionTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // A test is a folder under flows/, with its entry point inside it.
        $this->repo = sys_get_temp_dir().'/ai-mission-'.uniqid();
        @mkdir($this->repo.'/.maestro/flows/orders/add_order', 0775, true);
        file_put_contents($this->repo.'/.maestro/flows/orders/add_order/full_test.yaml', implode("\n", [
            'appId: com.example.app',
            '---',
            '- launchApp',
            '- tapOn:',
            '    id: "home_orders_button"',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->repo.'/.maestro/flows/orders/add_order/full_test.yaml');
        @rmdir($this->repo.'/.maestro/flows/orders/add_order');
        @rmdir($this->repo.'/.maestro/flows/orders');
        @rmdir($this->repo.'/.maestro/flows');
        @rmdir($this->repo.'/.maestro');
        @rmdir($this->repo);

        parent::tearDown();
    }

    #[Test]
    public function a_custom_test_is_checked_alongside_the_selected_flows(): void
    {
        $mission = (new AiLane)->mission(
            $this->repo,
            ['orders/add_order'],
            'com.example.app',
            '',
            "add a gift card\nopen customers",
        );

        // The flow keeps its place first, then the operator's own tests.
        $this->assertStringContainsString('1. ', $mission);
        $this->assertStringContainsString('2. add a gift card', $mission);
        $this->assertStringContainsString('3. open customers', $mission);

        // The flows still contribute their signals.
        $this->assertStringContainsString('Signals from the app', $mission);
    }

    #[Test]
    public function an_override_makes_the_custom_test_the_whole_mission(): void
    {
        $mission = (new AiLane)->mission(
            $this->repo,
            ['orders/add_order'],
            'com.example.app',
            '',
            'add a gift card',
            true,
        );

        $this->assertStringContainsString('1. add a gift card', $mission);
        $this->assertStringNotContainsString('2. ', $mission);

        // Nothing is borrowed from the flows that the override replaced.
        $this->assertStringNotContainsString('Signals from the app', $mission);
    }

    #[Test]
    public function blank_lines_in_the_custom_test_are_ignored(): void
    {
        $mission = (new AiLane)->mission(
            $this->repo,
            ['orders/add_order'],
            'com.example.app',
            '',
            "  add a gift card \n\n   \n",
            true,
        );

        $this->assertStringContainsString('1. add a gift card', $mission);
        $this->assertStringNotContainsString('2. ', $mission);
    }

    #[Test]
    public function an_empty_custom_test_is_not_an_override(): void
    {
        $mission = (new AiLane)->mission(
            $this->repo,
            ['orders/add_order'],
            'com.example.app',
            '',
            "   \n  ",
            true,
        );

        // Nothing to override with, so the picked flow is checked as usual.
        $this->assertStringContainsString('Signals from the app', $mission);
        $this->assertStringContainsString('Features to test:', $mission);
    }
}
