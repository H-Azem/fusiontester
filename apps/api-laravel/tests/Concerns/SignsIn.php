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
            'username' => 'admin',
            'password' => 'admin',
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
}
