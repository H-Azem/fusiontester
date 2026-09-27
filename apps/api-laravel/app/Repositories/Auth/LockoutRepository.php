<?php

namespace App\Repositories\Auth;

use App\Models\IpBlock;
use App\Models\LoginAttempt;

/**
 * Everything the IP lockout needs: the attempt log it counts and the blocks it
 * raises. Captcha mistakes are recorded through here but never counted.
 */
class LockoutRepository
{
    public function recordAttempt(
        string $ip,
        ?string $username,
        bool $success,
        bool $counted,
        ?string $reason,
        ?string $userAgent
    ): LoginAttempt {
        return LoginAttempt::query()->create([
            LoginAttempt::IP => $ip,
            LoginAttempt::USERNAME => $username,
            LoginAttempt::SUCCESS => $success,
            LoginAttempt::COUNTED => $counted,
            LoginAttempt::REASON => $reason,
            LoginAttempt::USER_AGENT => $userAgent,
            LoginAttempt::CREATED_AT => now(),
        ]);
    }

    public function activeBlock(string $ip): ?IpBlock
    {
        return IpBlock::query()
            ->where(IpBlock::IP, $ip)
            ->whereNull(IpBlock::RELEASED_AT)
            ->where(IpBlock::EXPIRES_AT, '>', now())
            ->first();
    }

    /** Counted failures inside the window, used to decide whether to block. */
    public function countedFailuresSince(string $ip, \DateTimeInterface $since): int
    {
        return LoginAttempt::query()
            ->where(LoginAttempt::IP, $ip)
            ->where(LoginAttempt::COUNTED, true)
            ->where(LoginAttempt::CREATED_AT, '>=', $since)
            ->count();
    }

    public function block(string $ip, int $failedCount, string $reason, \DateTimeInterface $expiresAt): IpBlock
    {
        return IpBlock::query()->updateOrCreate(
            [IpBlock::IP => $ip],
            [
                IpBlock::FAILED_COUNT => $failedCount,
                IpBlock::REASON => $reason,
                IpBlock::BLOCKED_AT => now(),
                IpBlock::EXPIRES_AT => $expiresAt,
                IpBlock::RELEASED_AT => null,
                IpBlock::RELEASED_BY => null,
            ]
        );
    }

    /**
     * Lifts a block. Kept for the CLI helper the Node API exposed, and used by
     * tests to prove a released block no longer refuses a correct password.
     */
    public function release(string $ip, string $releasedBy): bool
    {
        return IpBlock::query()
            ->where(IpBlock::IP, $ip)
            ->whereNull(IpBlock::RELEASED_AT)
            ->update([
                IpBlock::RELEASED_AT => now(),
                IpBlock::RELEASED_BY => $releasedBy,
            ]) > 0;
    }
}
