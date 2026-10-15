<?php

namespace Tests\Feature;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The SDK card. Its one destructive action must refuse while a test is using the
 * SDK, and must offer a way back when there is one.
 */
class FlutterSdkSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    private function unlocked(): string
    {
        $token = $this->loginToken();
        $this->unlockSettings($token);

        return $token;
    }

    #[Test]
    public function it_reports_the_sdk_even_when_the_helper_is_missing(): void
    {
        $token = $this->unlocked();

        $this->asSession($token)->getJson('/settings/flutter')
            ->assertSuccessful()
            ->assertJsonPath('available', false)
            ->assertJsonStructure(['available', 'path', 'source', 'busy']);
    }

    #[Test]
    public function it_refuses_to_change_the_sdk_while_a_run_is_in_flight(): void
    {
        $token = $this->unlocked();

        Run::query()->create([
            Run::PROJECT_ID => 1,
            Run::PROJECT_PATH => 'acme/pos',
            Run::BRANCH => 'main',
            Run::TESTS => ['orders/add_order'],
            Run::RUN_KINDS => [Run::KIND_AI],
            Run::ENVIRONMENTS => ['development'],
            Run::STATUS => Run::STATUS_RUNNING,
        ]);

        $this->asSession($token)->postJson('/settings/flutter/update')
            ->assertStatus(409)
            ->assertJsonPath('error', 'run_in_flight');
    }

    #[Test]
    public function restoring_without_a_previous_sdk_is_refused(): void
    {
        $token = $this->unlocked();

        $this->asSession($token)->postJson('/settings/flutter/restore')
            ->assertStatus(400)
            ->assertJsonPath('error', 'nothing_to_restore');
    }

    #[Test]
    public function the_card_needs_the_settings_password(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->getJson('/settings/flutter')->assertStatus(403);
    }
}
