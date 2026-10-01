<?php

namespace Tests\Unit;

use App\Services\Run\MaestroWorkspace;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every application carries a `smoke` flow that signs in. A run that names other
 * flows needs that sign-in first, so smoke is put in front of them — while a run
 * that names a whole-suite test, or names nothing at all, is left alone.
 */
class MaestroSmokeFirstTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/maestro-smoke-'.bin2hex(random_bytes(6));

        // A repository laid out the way the flow resolver expects.
        foreach (['smoke', 'gift_card', 'reports', 'full_test'] as $folder) {
            mkdir($this->root.'/flows/'.$folder, 0777, true);
        }

        file_put_contents($this->root.'/flows/smoke/smoke.yaml', "appId: app\n");
        file_put_contents($this->root.'/flows/gift_card/gift_card.yaml', "appId: app\n");
        file_put_contents($this->root.'/flows/reports/reports.yaml', "appId: app\n");
        file_put_contents($this->root.'/flows/full_test/full_test.yaml', "appId: app\n");
    }

    protected function tearDown(): void
    {
        foreach (['smoke', 'gift_card', 'reports', 'full_test'] as $folder) {
            @unlink($this->root.'/flows/'.$folder.'/'.$folder.'.yaml');
            @rmdir($this->root.'/flows/'.$folder);
        }

        @rmdir($this->root.'/flows');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function resolve(array $tests): array
    {
        $workspace = new MaestroWorkspace;

        return $workspace->resolveFlows(
            $this->root,
            $workspace->withSmokeFirst($this->root, $tests)
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
    public function a_whole_suite_test_covers_the_sign_in_by_itself(): void
    {
        $flows = $this->resolve(['full_test']);

        $this->assertCount(1, $flows);
        $this->assertStringContainsString('/full_test/full_test.yaml', $flows[0]);
    }

    #[Test]
    public function nothing_selected_still_means_every_flow(): void
    {
        $workspace = new MaestroWorkspace;

        $this->assertSame([], $workspace->withSmokeFirst($this->root, []));
    }

    #[Test]
    public function it_is_not_added_twice(): void
    {
        $workspace = new MaestroWorkspace;

        $this->assertSame(['smoke'], $workspace->withSmokeFirst($this->root, ['smoke']));
        $this->assertSame(['smoke', 'reports'], $workspace->withSmokeFirst($this->root, ['reports']));
        $this->assertSame(['smoke', 'reports'], $workspace->withSmokeFirst($this->root, ['reports', 'smoke']));
    }
}
