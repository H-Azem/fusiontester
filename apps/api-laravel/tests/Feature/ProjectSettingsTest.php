<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The shape and the lane a run was started with are remembered per project, so the
 * next visit opens on the same answer instead of asking again.
 */
class ProjectSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    #[Test]
    public function it_starts_with_the_defaults_and_says_nobody_has_chosen_yet(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->getJson('/projects/54/settings')
            ->assertSuccessful()
            ->assertJsonPath('orientation', 'horizontal')
            ->assertJsonPath('platform', 'web')
            ->assertJsonPath('orientationSet', false)
            ->assertJsonPath('platformSet', false);
    }

    #[Test]
    public function it_remembers_the_shape_and_the_lane_for_the_next_run(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->putJson('/projects/54/settings', ['orientation' => 'vertical', 'platform' => 'android'])
            ->assertSuccessful()
            ->assertJsonPath('orientation', 'vertical')
            ->assertJsonPath('platform', 'android')
            ->assertJsonPath('orientationSet', true)
            ->assertJsonPath('platformSet', true);

        // And the choice survives a later read, which is the whole point.
        $this->asSession($token)
            ->getJson('/projects/54/settings')
            ->assertJsonPath('orientation', 'vertical')
            ->assertJsonPath('platform', 'android');
    }

    #[Test]
    public function it_saves_one_choice_without_resetting_the_other(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->putJson('/projects/54/settings', ['platform' => 'android']);
        $this->asSession($token)->putJson('/projects/54/settings', ['orientation' => 'vertical']);

        $this->asSession($token)
            ->getJson('/projects/54/settings')
            ->assertJsonPath('platform', 'android')
            ->assertJsonPath('orientation', 'vertical');
    }

    #[Test]
    public function it_rejects_a_value_it_does_not_understand(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->putJson('/projects/54/settings', ['platform' => 'windows'])
            ->assertStatus(422);

        $this->asSession($token)
            ->putJson('/projects/54/settings', ['orientation' => 'diagonal'])
            ->assertStatus(422);
    }
}
