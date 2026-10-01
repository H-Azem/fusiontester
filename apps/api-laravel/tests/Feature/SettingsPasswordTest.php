<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Settings hold credentials that reach the machine under test, so they open with a
 * second password — a placeholder from config until the signed-in user replaces it.
 */
class SettingsPasswordTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    #[Test]
    public function settings_are_locked_until_the_password_is_entered(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->getJson('/auth/settings-status')->assertJsonPath('unlocked', false);

        // Signing in is not enough to read a credential.
        $this->asSession($token)->getJson('/settings/gitlab')->assertStatus(403);
        $this->asSession($token)->getJson('/settings/ai')->assertStatus(403);

        $this->unlockSettings($token);

        $this->asSession($token)->getJson('/auth/settings-status')->assertJsonPath('unlocked', true);
        $this->asSession($token)->getJson('/settings/gitlab')->assertSuccessful();
    }

    #[Test]
    public function the_placeholder_password_is_what_config_carries(): void
    {
        $this->assertSame('12345', (string) config('fusion.admin.settings_password'));
    }

    #[Test]
    public function a_wrong_password_does_not_open_settings_and_does_not_lock_the_account(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)
            ->postJson('/auth/settings-unlock', ['password' => 'nope!'])
            ->assertStatus(401);

        $this->asSession($token)->getJson('/settings/gitlab')->assertStatus(403);

        // The sign-in lockout is untouched: this is not a login attempt.
        $this->asSession($token)->getJson('/runs')->assertSuccessful();
    }

    #[Test]
    public function the_signed_in_user_can_replace_it(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/auth/settings-password', [
            'currentPassword' => (string) config('fusion.admin.settings_password'),
            'newPassword' => 'a-better-one',
        ])->assertSuccessful();

        // The hash is stored, and the old placeholder stops working.
        $this->assertNotNull(User::query()->firstOrFail()->getSettingsPasswordHash());

        $this->asSession($token)->postJson('/auth/settings-unlock', ['password' => '12345'])->assertStatus(401);
        $this->asSession($token)->postJson('/auth/settings-unlock', ['password' => 'a-better-one'])->assertSuccessful();
        $this->asSession($token)->getJson('/settings/ai')->assertSuccessful();
    }

    #[Test]
    public function changing_it_requires_the_current_one(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->postJson('/auth/settings-password', [
            'currentPassword' => 'wrong',
            'newPassword' => 'another-one',
        ])->assertStatus(401);

        $this->assertNull(User::query()->firstOrFail()->getSettingsPasswordHash());
    }
}
