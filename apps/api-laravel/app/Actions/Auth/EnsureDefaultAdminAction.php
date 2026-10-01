<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Repositories\Auth\UserRepository;

/**
 * Creates the default admin on first boot if it does not exist. Idempotent by
 * design: running it on every deploy must not reset an existing password.
 *
 * It also renames a database that still carries the previous default username,
 * but only when that is the account a deployment was seeded with — a renamed
 * account is never invented out of a username somebody chose.
 */
class EnsureDefaultAdminAction
{
    /** The username this application shipped with before it was called `fusion`. */
    const PREVIOUS_DEFAULT_USERNAME = 'admin';

    public function __construct(private UserRepository $users = new UserRepository) {}

    public function handle(): ?User
    {
        $username = (string) config('fusion.admin.username');

        $existing = $this->users->findByUsername($username);

        if ($existing !== null) {
            return null;
        }

        $previous = $this->users->findByUsername(self::PREVIOUS_DEFAULT_USERNAME);

        if ($previous !== null) {
            $this->users->rename($previous, $username);

            return $previous;
        }

        return $this->users->create(
            $username,
            (string) config('fusion.admin.password'),
            (string) config('fusion.admin.role')
        );
    }
}
