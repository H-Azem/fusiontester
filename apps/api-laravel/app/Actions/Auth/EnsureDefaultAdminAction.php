<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Repositories\Auth\UserRepository;

/**
 * Creates the default admin on first boot if it does not exist. Idempotent by
 * design: running it on every deploy must not reset an existing password.
 */
class EnsureDefaultAdminAction
{
    public function __construct(private UserRepository $users = new UserRepository) {}

    public function handle(): ?User
    {
        $username = (string) config('fusion.admin.username');

        if ($this->users->findByUsername($username) !== null) {
            return null;
        }

        return $this->users->create(
            $username,
            (string) config('fusion.admin.password'),
            (string) config('fusion.admin.role')
        );
    }
}
