<?php

namespace Tests\Unit;

use App\Services\Ai\MaestroGoals;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI lane is handed a goal list, not the raw flows: a flow's commands say
 * what the test intends, and reading that here costs no model call. The list has
 * to stay ordered, resolve `runFlow` into the steps it stands for, and survive a
 * reference it was not given.
 */
class MaestroGoalsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/maestro-goals-'.bin2hex(random_bytes(6));

        foreach (['smoke', 'customers', 'shared', 'loop'] as $folder) {
            mkdir($this->root.'/flows/'.$folder, 0777, true);
        }

        $this->write('shared/enter_staff_pin.yaml', <<<'YAML'
        appId: com.example.pos
        ---
        - tapOn:
            id: pin_field
        - inputText: "1234"
        - tapOn:
            id: unlock_button
        YAML);

        $this->write('customers/full_test.yaml', <<<'YAML'
        appId: com.example.pos
        ---
        - launchApp:
            clearState: true
        - runFlow: ../shared/enter_staff_pin.yaml
        - tapOn:
            id: customers_tab
        - tapOn:
            id: add_customer_button
        - tapOn:
            id: customer_name_field
        - inputText: "Ali Rezaei"
        - scrollUntilVisible:
            element:
              id: save_button
        - assertVisible:
            text: "Customer saved"
        - assertNotVisible:
            text: "Something went wrong"
        YAML);

        $this->write('smoke/smoke.yaml', <<<'YAML'
        appId: com.example.pos
        ---
        - launchApp
        - tapOn:
            id: settings_menu
        - assertVisible:
            id: home_screen
        YAML);

        // Each references the other: following them must stop.
        $this->write('loop/a.yaml', "appId: com.example.pos\n---\n- runFlow: b.yaml\n");
        $this->write('loop/b.yaml', "appId: com.example.pos\n---\n- runFlow: a.yaml\n- tapOn:\n    id: loop_button\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    #[Test]
    public function a_flow_becomes_ordered_goals(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/smoke/smoke.yaml']);

        $this->assertSame(
            ['reach the home screen', 'open settings menu', 'home screen is visible'],
            $goals
        );
    }

    /**
     * A runFlow path is the shared sign-in, and its steps are the run's steps:
     * the lane should be told to enter the PIN, not to "run enter_staff_pin".
     */
    #[Test]
    public function run_flow_steps_are_inlined_in_order(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/customers/full_test.yaml']);

        $this->assertSame([
            'reach the home screen',
            'tap pin field',
            'type "1234"',
            'tap unlock',
            'open customers',
            'tap add customer',
            'tap customer name field',
            'type "Ali Rezaei"',
            'scroll until save is visible',
            'Customer saved is visible',
            'Something went wrong is not visible',
        ], $goals);
    }

    #[Test]
    public function a_flow_it_was_not_handed_is_still_named(): void
    {
        $this->write('loop/c.yaml', "appId: com.example.pos\n---\n- runFlow: ../shared/does_not_exist.yaml\n");

        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/loop/c.yaml']);

        $this->assertSame(['complete the does not exist flow'], $goals);
    }

    #[Test]
    public function a_run_flow_cycle_terminates(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/loop/a.yaml']);

        $this->assertSame(['tap loop'], $goals);
    }

    #[Test]
    public function the_mission_numbers_the_goals_and_falls_back_when_there_are_none(): void
    {
        $goals = new MaestroGoals;

        $this->assertSame("1. open customers\n2. the order is saved", $goals->toText([
            'open customers',
            'the order is saved',
        ]));

        $this->assertSame(
            'Explore the app and verify its main journey still works.',
            $goals->toText([])
        );
    }

    #[Test]
    public function the_other_commands_are_phrased_too(): void
    {
        $this->write('loop/mixed.yaml', <<<'YAML'
        appId: com.example.pos
        ---
        - back
        - swipe:
            direction: LEFT
        - pressKey: Enter
        - extendedWaitUntil:
            visible:
              id: orders_screen
        - scroll
        - eraseText
        - hideKeyboard
        YAML);

        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/loop/mixed.yaml']);

        $this->assertSame([
            'go back',
            'swipe LEFT',
            'press Enter',
            'wait until orders screen is visible',
            'scroll the screen',
            'clear the field',
        ], $goals);
    }

    private function write(string $relative, string $yaml): void
    {
        file_put_contents($this->root.'/flows/'.$relative, $yaml);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
