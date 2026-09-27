<?php

namespace App\Actions\Auth;

use App\Models\AuditEntry;
use App\Models\IpBlock;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Repositories\Auth\CaptchaRepository;
use App\Repositories\Auth\LockoutRepository;
use App\Repositories\Auth\UserRepository;
use App\Repositories\Auth\AuthSessionRepository;
use Illuminate\Support\Facades\Hash;

/**
 * The whole login decision, in order: is this IP blocked, is the captcha right,
 * are the credentials right, and did that failure cross the blocking threshold.
 *
 * Captcha mistakes are deliberately not counted towards a block, so a legitimate
 * user mistyping a code cannot be locked out for hours.
 */
class AttemptLoginAction
{
    private static ?string $dummyHash = null;

    public function __construct(
        private string $username,
        private string $password,
        private string $captchaId,
        private string $captchaText,
        private ?string $ip,
        private ?string $userAgent,
        private CaptchaRepository $captchas = new CaptchaRepository,
        private LockoutRepository $lockout = new LockoutRepository,
        private UserRepository $users = new UserRepository,
        private AuthSessionRepository $sessions = new AuthSessionRepository,
    ) {}

    public function handle(): LoginOutcome
    {
        $ip = (string) $this->ip;

        $block = $this->lockout->activeBlock($ip);
        if ($block) {
            $this->audit(AuditEntry::ACTION_LOGIN_BLOCKED);

            return LoginOutcome::blocked($block->getExpiresAt());
        }

        if (! $this->captchaAccepted()) {
            // Recorded, but never counted: see the class docblock.
            $this->lockout->recordAttempt(
                $ip,
                $this->username,
                success: false,
                counted: false,
                reason: LoginAttempt::REASON_CAPTCHA,
                userAgent: $this->userAgent
            );
            $this->audit(AuditEntry::ACTION_LOGIN_CAPTCHA_FAILED);

            return LoginOutcome::invalid();
        }

        $user = $this->users->findByUsername($this->username);
        $passwordOk = $user
            ? Hash::check($this->password, (string) $user->getPasswordHash())
            : Hash::check($this->password, $this->dummyHash());

        if (! $user || ! $passwordOk) {
            return $this->failedCredentials($user);
        }

        $this->lockout->recordAttempt(
            $ip,
            (string) $user->getUsername(),
            success: true,
            counted: false,
            reason: null,
            userAgent: $this->userAgent
        );

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours((int) config('fusion.session.ttl_hours'));
        $this->sessions->create((int) $user->getId(), $token, $ip, $this->userAgent, $expiresAt);

        $this->audit(AuditEntry::ACTION_LOGIN_SUCCESS, $user);

        return LoginOutcome::succeeded($user, $token, $expiresAt);
    }

    private function captchaAccepted(): bool
    {
        if (! $this->captchas->consume($this->captchaId)) {
            return false;
        }

        $challenge = $this->captchas->find($this->captchaId);

        return $challenge !== null
            && $challenge->getAnswerHash() === $this->captchas->hashAnswer($this->captchaText);
    }

    private function failedCredentials(?User $user): LoginOutcome
    {
        $ip = (string) $this->ip;

        $this->lockout->recordAttempt(
            $ip,
            $this->username,
            success: false,
            counted: true,
            reason: $user ? LoginAttempt::REASON_BAD_PASSWORD : LoginAttempt::REASON_UNKNOWN_USER,
            userAgent: $this->userAgent
        );

        $since = now()->subMinutes((int) config('fusion.lockout.failed_attempt_window_minutes'));
        $failedCount = $this->lockout->countedFailuresSince($ip, $since);
        $blocked = $failedCount >= (int) config('fusion.lockout.max_failed_attempts');

        if (! $blocked) {
            $this->audit(AuditEntry::ACTION_LOGIN_FAILED, metadata: ['failedCount' => $failedCount]);

            return LoginOutcome::invalid();
        }

        $until = now()->addHours((int) config('fusion.lockout.block_hours'));
        $this->lockout->block($ip, $failedCount, 'too_many_failures', $until);
        $this->audit(AuditEntry::ACTION_IP_BLOCKED, metadata: ['failedCount' => $failedCount]);

        return LoginOutcome::blocked($until);
    }

    /**
     * Verifying against a real hash for unknown usernames keeps the response time
     * comparable, so the endpoint cannot be used to enumerate users.
     */
    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('timing-equalisation-placeholder');
    }

    private function audit(string $action, ?User $user = null, array $metadata = []): void
    {
        run(new WriteAuditAction(
            $action,
            $user === null ? null : (int) $user->getId(),
            $this->ip,
            $this->userAgent,
            $metadata
        ));
    }
}
