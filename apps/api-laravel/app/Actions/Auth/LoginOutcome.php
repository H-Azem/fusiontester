<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * What a login attempt produced. The controller only maps this onto the HTTP
 * contract; every decision lives in the action.
 */
class LoginOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly ?string $token = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
        public readonly ?\DateTimeInterface $blockedUntil = null,
    ) {}

    public static function succeeded(User $user, string $token, \DateTimeInterface $expiresAt): self
    {
        return new self('succeeded', $user, $token, $expiresAt);
    }

    public static function invalid(): self
    {
        return new self('invalid');
    }

    public static function blocked(\DateTimeInterface $blockedUntil): self
    {
        return new self('blocked', blockedUntil: $blockedUntil);
    }
}
