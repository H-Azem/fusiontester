<?php

namespace Tests\Unit;

use App\Services\Run\MaestroWorkspace;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every application carries a `smoke` flow that signs in. A run that names other
 * flows needs that sign-in first, so smoke is put in front of them — while a run
 * that names the whole-application suite, or names nothing at all, is left alone.
 */
class MaestroSmokeFirstTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/maestro-smoke-'.bin2hex(random_bytes(6));

        foreach (['smoke', 'gift_card', 'reports', 'full_app'] as $folder) {
            mkdir($this->root.'/flows/'.$folder, 0777, true);
        }

        file_put_contents($this->root.'/flows/smoke/smoke.yaml', "appId: app\n");
        file_put_contents($this->root.'/flows/gift_card/gift_card.yaml', "appId: app\n");

        // The shape that failed in production: an ordinary test folder whose flow file
        // is called full_test.yaml. Maestro reports the flow by that file name, which
        // is what made it look like a whole-application suite.
        file_put_contents($this->root.'/flows/reports/full_test.yaml', "appId: app\n");

        file_put_contents($this->root.'/flows/full_app/full_app.yaml', "appId: app\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/flows/smoke/smoke.yaml');
        @unlink($this->root.'/flows/gift_card/gift_card.yaml');
        @unlink($this->root.'/flows/reports/full_test.yaml');
        @unlink($this->root.'/flows/full_app/full_app.yaml');

        foreach (['smoke', 'gift_card', 'reports', 'full_app'] as $folder) {
            @rmdir($this->root.'/flows/'.$folder);
        }

        @rmdir($this->root.'/flows');
        @rmdir($this->root);

        parent::tearDown();
    }

    /** @param array<int, string> $tests */
    private function resolve(array $tests): array
    {
        $workspace = new MaestroWorkspace;

        return $workspace->resolveFlows(
            $this->root,
            $workspace->withSmokeFirst($tests)
        )['flows'];
    }

    #[Test]
    public function another_flow_gets_smoke_in_front_of_it(): void
    {
        $flows = $this->resolve(['gift_card']);

        $this->assertCount(2, $flows);
        $this->assertStringContainsString('/smoke/smoke.yaml', $flows[0]);
        $this->assertStringContainsString('/gift_card/gift_card.yaml', $flows[1]);
    }

    /**
     * The reported failure: the flow file being named full_test.yaml does not make the
     * folder a whole-application suite, so the sign-in still has to come first.
     */
    #[Test]
    public function an_ordinary_test_whose_flow_file_is_called_full_test_still_signs_in_first(): void
    {
        $flows = $this->resolve(['reports']);

        $this->assertCount(2, $flows);
        $this->assertStringContainsString('/smoke/smoke.yaml', $flows[0]);
        $this->assertStringContainsString('/reports/full_test.yaml', $flows[1]);
    }

    #[Test]
    public function several_flows_keep_their_order_behind_smoke(): void
    {
        $flows = $this->resolve(['reports', 'gift_card']);

        $this->assertCount(3, $flows);
        $this->assertStringContainsString('/smoke/', $flows[0]);
        $this->assertStringContainsString('/reports/', $flows[1]);
        $this->assertStringContainsString('/gift_card/', $flows[2]);
    }

    #[Test]
    public function choosing_smoke_alone_is_left_as_it_is(): void
    {
        $flows = $this->resolve(['smoke']);

        $this->assertCount(1, $flows);
        $this->assertStringContainsString('/smoke/', $flows[0]);
    }

    #[Test]
    public function smoke_chosen_later_is_moved_to_the_front(): void
    {
        $flows = $this->resolve(['gift_card', 'smoke']);

        $this->assertCount(2, $flows);
        $this->assertStringContainsString('/smoke/', $flows[0]);
        $this->assertStringContainsString('/gift_card/', $flows[1]);
    }

    #[Test]
    public function the_whole_application_suite_covers_the_sign_in_by_itself(): void
    {
        $flows = $this->resolve(['full_app']);

        $this->assertCount(1, $flows);
        $this->assertStringContainsString('/full_app/full_app.yaml', $flows[0]);
    }

    #[Test]
    public function nothing_selected_still_means_every_flow(): void
    {
        $this->assertSame([], (new MaestroWorkspace)->withSmokeFirst([]));
    }

    #[Test]
    public function it_is_not_added_twice(): void
    {
        $workspace = new MaestroWorkspace;

        $this->assertSame(['smoke'], $workspace->withSmokeFirst(['smoke']));
        $this->assertSame(['smoke', 'reports'], $workspace->withSmokeFirst(['reports']));
        $this->assertSame(['smoke', 'reports'], $workspace->withSmokeFirst(['reports', 'smoke']));
        $this->assertSame(['full_app'], $workspace->withSmokeFirst(['full_app']));
    }
}
