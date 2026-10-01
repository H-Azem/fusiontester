<?php

namespace App\Repositories\Auth;

use App\Models\AuthSession;

class AuthSessionRepository
{
    /**
     * Sessions are looked up by the hash of the presented token, so the token
     * itself is never stored and a leaked database cannot be replayed.
     */
    public function findActiveByToken(string $token): ?AuthSession
    {
        return AuthSession::query()
            ->with('user')
            ->where(AuthSession::TOKEN_HASH, $this->hash($token))
            ->whereNull(AuthSession::REVOKED_AT)
            ->where(AuthSession::EXPIRES_AT, '>', now())
            ->first();
    }

    public function create(int $userId, string $token, string $ip, ?string $userAgent, \DateTimeInterface $expiresAt): AuthSession
    {
        return AuthSession::query()->create([
            AuthSession::USER_ID => $userId,
            AuthSession::TOKEN_HASH => $this->hash($token),
            AuthSession::IP => $ip,
            AuthSession::USER_AGENT => $userAgent,
            AuthSession::CREATED_AT => now(),
            AuthSession::LAST_SEEN_AT => now(),
            AuthSession::EXPIRES_AT => $expiresAt,
        ]);
    }

    public function touch(AuthSession $session): void
    {
        $session->setAttribute(AuthSession::LAST_SEEN_AT, now());
        $session->save();
    }

    /** Settings stay open for the rest of this sign-in, not for every request. */
    public function unlockSettings(AuthSession $session): void
    {
        $session->setAttribute(AuthSession::SETTINGS_UNLOCKED_AT, now());
        $session->save();
    }

    public function revoke(AuthSession $session): void
    {
        $session->setAttribute(AuthSession::REVOKED_AT, now());
        $session->save();
    }

    /** @return int the number of other sessions that were signed out */
    public function revokeAllFor(int $userId, ?string $exceptSessionId = null): int
    {
        return AuthSession::query()
            ->where(AuthSession::USER_ID, $userId)
            ->whereNull(AuthSession::REVOKED_AT)
            ->when($exceptSessionId, fn ($query) => $query->where(AuthSession::ID, '!=', $exceptSessionId))
            ->update([AuthSession::REVOKED_AT => now()]);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
