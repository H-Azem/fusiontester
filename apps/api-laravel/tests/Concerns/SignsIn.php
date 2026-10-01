<?php

namespace Tests\Concerns;

use App\Actions\Auth\EnsureDefaultAdminAction;
use App\Models\User;
use App\Repositories\Auth\CaptchaRepository;
use Tests\TestCase;

/**
 * Signing in as the seeded admin, the way a browser does: the session cookie is
 * sent raw and credentials are enabled, because the api middleware group neither
 * encrypts cookies nor attaches them to JSON requests by default.
 */
trait SignsIn
{
    protected function admin(): User
    {
        return run(new EnsureDefaultAdminAction) ?? User::query()->firstOrFail();
    }

    protected function loginToken(): string
    {
        $this->admin();

        $captchas = new CaptchaRepository;
        $challenge = $captchas->create($captchas->hashAnswer('ZZZZZ'), '127.0.0.1', now()->addMinutes(10));
        $cookie = (string) config('fusion.session.cookie');

        $response = $this->postJson('/auth/login', [
            // Read from config so renaming the seeded account never breaks the suite.
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $challenge->getId(),
            'captchaText' => 'ZZZZZ',
        ]);

        return (string) $response->getCookie($cookie, false)->getValue();
    }

    protected function asSession(string $token): TestCase
    {
        return $this->withCredentials()
            ->withUnencryptedCookie((string) config('fusion.session.cookie'), $token);
    }

    /**
     * Opens Settings for this session with the placeholder password config carries,
     * because every credential route sits behind it.
     */
    protected function unlockSettings(string $token): void
    {
        $this->asSession($token)
            ->postJson('/auth/settings-unlock', [
                'password' => (string) config('fusion.admin.settings_password'),
            ])
            ->assertSuccessful();
    }
}
