<?php

namespace Tests\Feature;

use App\Actions\Auth\EnsureDefaultAdminAction;
use App\Models\AuthSession;
use App\Models\Contracts\UserInterface;
use App\Models\IpBlock;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Repositories\Auth\CaptchaRepository;
use App\Repositories\Auth\LockoutRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for the auth behaviour the dashboard depends on, ported from
 * the Node smoke test so the contract stays provably identical.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function captcha_is_issued_as_an_svg_with_a_challenge_id(): void
    {
        $response = $this->getJson('/auth/captcha')->assertSuccessful();

        $this->assertIsString($response->json('id'));
        $this->assertStringContainsString('<svg', (string) $response->json('svg'));
    }

    #[Test]
    public function a_wrong_captcha_is_rejected_without_counting_toward_a_block(): void
    {
        $this->admin();

        $response = $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha('AAAAA'),
            'captchaText' => 'WRONG',
        ]);

        $response->assertStatus(401)->assertJsonPath('error', 'invalid');
        $this->assertSame(0, LoginAttempt::query()->where(LoginAttempt::COUNTED, true)->count());
    }

    #[Test]
    public function three_bad_passwords_block_the_ip_for_six_hours(): void
    {
        $this->admin();

        foreach (['BBBBB', 'CCCCC'] as $answer) {
            $this->postJson('/auth/login', [
                'username' => (string) config('fusion.admin.username'),
                'password' => 'wrong-password',
                'captchaId' => $this->issueCaptcha($answer),
                'captchaText' => $answer,
            ])->assertStatus(401);
        }

        $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => 'wrong-password',
            'captchaId' => $this->issueCaptcha('DDDDD'),
            'captchaText' => 'DDDDD',
        ])->assertStatus(429)->assertJsonPath('error', 'blocked');

        $block = IpBlock::query()->firstOrFail();
        $this->assertSame(3, $block->getFailedCount());
        $this->assertEqualsWithDelta(6, $block->getBlockedAt()->diffInHours($block->getExpiresAt()), 0.01);
    }

    #[Test]
    public function a_blocked_ip_is_refused_even_with_correct_credentials(): void
    {
        $this->admin();
        (new LockoutRepository)->block('127.0.0.1', 3, 'too_many_failures', now()->addHours(6));

        $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha('EEEEE'),
            'captchaText' => 'EEEEE',
        ])->assertStatus(429);
    }

    #[Test]
    public function a_successful_login_sets_an_httponly_lax_cookie_and_returns_the_user(): void
    {
        $this->admin();
        $cookie = (string) config('fusion.session.cookie');

        $response = $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha('FFFFF'),
            'captchaText' => 'fffff',
        ])->assertSuccessful()->assertJsonPath('user.username', config('fusion.admin.username'));

        $response->assertCookie($cookie);

        $issued = $response->getCookie($cookie, false);
        $this->assertTrue($issued->isHttpOnly());
        $this->assertSame('lax', $issued->getSameSite());
        $this->assertSame(1, AuthSession::query()->count());
    }

    #[Test]
    public function me_requires_a_session_and_resolves_the_signed_in_user(): void
    {
        $this->admin();

        $this->getJson('/auth/me')->assertStatus(401);

        $cookie = (string) config('fusion.session.cookie');
        $login = $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha('GGGGG'),
            'captchaText' => 'GGGGG',
        ]);

        $this->asSession((string) $login->getCookie($cookie, false)->getValue())
            ->getJson('/auth/me')
            ->assertSuccessful()
            ->assertJsonPath('user.username', config('fusion.admin.username'));
    }

    #[Test]
    public function logout_revokes_the_session(): void
    {
        $this->admin();

        $cookie = (string) config('fusion.session.cookie');
        $login = $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha('HHHHH'),
            'captchaText' => 'HHHHH',
        ]);

        $value = (string) $login->getCookie($cookie, false)->getValue();

        $this->asSession($value)->postJson('/auth/logout')->assertSuccessful();
        $this->assertNotNull(AuthSession::query()->firstOrFail()->getRevokedAt());

        $this->asSession($value)->getJson('/auth/me')->assertStatus(401);
    }

    #[Test]
    public function a_cross_origin_login_is_refused_while_the_dashboard_origin_passes(): void
    {
        $this->admin();

        $this->postJson('/auth/login', [], ['Origin' => 'http://evil.example'])
            ->assertStatus(403);

        $this->postJson('/auth/login', [], ['Origin' => (string) config('fusion.web_origin')])
            ->assertStatus(422);
    }

    #[Test]
    public function changing_the_password_revokes_other_sessions(): void
    {
        $this->admin();

        $cookie = (string) config('fusion.session.cookie');
        $first = (string) $this->loginAndGetToken();
        $second = (string) $this->loginAndGetToken();

        $response = $this->asSession($first)->postJson('/auth/change-password', [
            'currentPassword' => 'admin',
            'newPassword' => 'brand-new-password',
        ]);

        $response->assertSuccessful()->assertJsonPath('revokedSessions', 1);

        $this->asSession($second)->getJson('/auth/me')->assertStatus(401);
        $this->asSession($first)->getJson('/auth/me')->assertSuccessful();
    }

    private function admin(): User
    {
        return run(new EnsureDefaultAdminAction) ?? User::query()->firstOrFail();
    }

    /**
     * Sends the session cookie the way a browser does.
     *
     * JSON test requests only carry cookies when credentials are enabled, and the
     * api middleware group does not encrypt them, so the value is sent raw.
     */
    private function asSession(string $token): self
    {
        return $this->withCredentials()
            ->withUnencryptedCookie((string) config('fusion.session.cookie'), $token);
    }

    /** Creates a challenge whose answer is known, so the image can be skipped. */
    private function issueCaptcha(string $answer): string
    {
        $repository = new CaptchaRepository;

        return (string) $repository->create(
            $repository->hashAnswer($answer),
            '127.0.0.1',
            now()->addMinutes(10)
        )->getId();
    }

    private function loginAndGetToken(): string
    {
        $cookie = (string) config('fusion.session.cookie');
        $answer = 'JJJJJ';

        $response = $this->postJson('/auth/login', [
            'username' => (string) config('fusion.admin.username'),
            'password' => (string) config('fusion.admin.password'),
            'captchaId' => $this->issueCaptcha($answer),
            'captchaText' => $answer,
        ]);

        return (string) $response->getCookie($cookie, false)->getValue();
    }
}
