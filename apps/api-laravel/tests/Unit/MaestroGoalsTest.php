<?php

namespace Tests\Unit;

use App\Services\Ai\MaestroGoals;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI lane is inspired by the flows, not driven by them: it is handed the few
 * landmarks a person would name, so it can behave like a user and discover the
 * rest. Nested runFlows are deliberately not followed — inlining them once turned
 * two small tests into thousands of goals.
 */
class MaestroGoalsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/maestro-goals-'.bin2hex(random_bytes(6));

        foreach (['smoke', 'customers', 'shared'] as $folder) {
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
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    #[Test]
    public function a_flow_becomes_a_few_landmarks(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/smoke/smoke.yaml']);

        $this->assertSame(
            ['reach the home screen', 'open settings menu', 'home screen is visible'],
            $goals
        );
    }

    /**
     * A field tap and a text entry are steps, not landmarks; the journey keeps the
     * navigation, the creation and the assertion.
     */
    #[Test]
    public function only_the_journey_beats_are_kept(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/customers/full_test.yaml']);

        $this->assertSame([
            'reach the home screen',
            'open customers',
            'add customer',
            'Customer saved is visible',
        ], $goals);
    }

    #[Test]
    public function a_run_flow_is_not_followed(): void
    {
        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/customers/full_test.yaml']);

        $this->assertNotContains('tap pin field', $goals);
        $this->assertNotContains('type "1234"', $goals);
    }

    /**
     * The lane is not handed the journey step by step, but the smoke flow does say
     * how the app is entered — and without that it can never get past the login.
     */
    #[Test]
    public function the_smoke_flow_says_how_to_sign_in(): void
    {
        $steps = (new MaestroGoals)->signIn([$this->root.'/flows/smoke/smoke.yaml']);

        $this->assertContains('tap settings menu', $steps);
        $this->assertContains('see home screen', $steps);
    }

    #[Test]
    public function a_smoke_flow_follows_the_subflow_it_runs(): void
    {
        $this->write('smoke/subsignin.yaml', <<<'YAML'
        appId: com.example.pos
        ---
        - runFlow: ../shared/enter_staff_pin.yaml
        YAML);

        $steps = (new MaestroGoals)->signIn([$this->root.'/flows/smoke/subsignin.yaml']);

        $this->assertContains('tap pin field', $steps);
        $this->assertContains('type "1234"', $steps);
    }

    #[Test]
    public function the_list_is_capped(): void
    {
        $steps = '';
        for ($i = 1; $i <= 12; $i++) {
            $steps .= "- tapOn:\n    id: screen_{$i}_tab\n";
        }

        $this->write('customers/many.yaml', "appId: com.example.pos\n---\n".$steps);

        $goals = (new MaestroGoals)->fromFlowFiles([$this->root.'/flows/customers/many.yaml']);

        $this->assertCount(MaestroGoals::MAX_GOALS, $goals);
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
