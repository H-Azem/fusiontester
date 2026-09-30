<?php

namespace Tests\Feature;

use App\Http\Resources\RunArtifacts;
use App\Models\Run;
use App\Services\Run\LiveCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The two lanes a run can take, and the live view that belongs to the device one.
 */
class RunLaneTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    #[Test]
    public function a_run_can_target_the_device_lane_with_the_live_view_on(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/runs', [
            'projectId' => 54,
            'projectPath' => 'fusion-food-tech/mobile-apps/pos',
            'branch' => 'stage',
            'tests' => ['gift_card'],
            'runKinds' => ['maestro'],
            'environments' => ['development'],
            'orientation' => 'horizontal',
            'platform' => Run::PLATFORM_ANDROID,
            'live' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('platform', Run::PLATFORM_ANDROID)
            ->assertJsonPath('live', true);

        $run = Run::query()->firstOrFail();

        $this->assertSame(Run::PLATFORM_ANDROID, $run->getPlatform());
        $this->assertTrue((bool) $run->getLive());
    }

    #[Test]
    public function a_run_defaults_to_the_web_lane_with_the_live_view_off(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/runs', [
            'projectId' => 33,
            'projectPath' => 'fusion-food-tech/mobile-apps/self-ordering-kiosk',
            'branch' => 'maestro',
            'runKinds' => ['maestro'],
            'environments' => ['development'],
        ])
            ->assertCreated()
            ->assertJsonPath('platform', Run::PLATFORM_WEB)
            ->assertJsonPath('live', false);
    }

    #[Test]
    public function an_unknown_platform_is_rejected(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/runs', [
            'projectId' => 33,
            'projectPath' => 'fusion-food-tech/mobile-apps/self-ordering-kiosk',
            'branch' => 'maestro',
            'runKinds' => ['maestro'],
            'environments' => ['development'],
            'platform' => 'windows',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_live_frame_is_served_and_missing_until_the_run_writes_one(): void
    {
        Queue::fake();
        $token = $this->loginToken();

        $created = $this->asSession($token)->postJson('/runs', [
            'projectId' => 54,
            'projectPath' => 'fusion-food-tech/mobile-apps/pos',
            'branch' => 'stage',
            'runKinds' => ['maestro'],
            'environments' => ['development'],
            'platform' => Run::PLATFORM_ANDROID,
            'live' => true,
        ])->assertCreated();

        $id = (string) $created->json('id');

        $this->asSession($token)->get("/runs/{$id}/live")->assertNotFound();

        $path = RunArtifacts::liveFrame($id);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, "\xFF\xD8\xFF\xDB\x00\x43live-frame-bytes");

        $this->asSession($token)
            ->get("/runs/{$id}/live")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->asSession($token)->getJson("/runs/{$id}")->assertJsonPath('hasLiveFrame', true);

        @unlink($path);
    }

    #[Test]
    public function the_live_view_stays_off_unless_the_run_asked_for_it(): void
    {
        $dir = sys_get_temp_dir().'/live-'.uniqid();
        mkdir($dir);

        $run = new Run([Run::LIVE => false]);
        $live = new LiveCapture;

        $this->assertNull($live->start($run, $dir));
        $this->assertFileDoesNotExist(LiveCapture::flag($dir));

        rmdir($dir);
    }
}
