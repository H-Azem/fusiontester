<?php

namespace App\Repositories\Auth;

use App\Models\Contracts\UserInterface;
use App\Models\User;

class UserRepository
{
    public function findByUsername(string $username): ?User
    {
        return User::query()
            ->where(UserInterface::USERNAME, mb_strtolower(trim($username)))
            ->first();
    }

    public function create(string $username, string $plainPassword, string $role): User
    {
        return User::query()->create([
            UserInterface::USERNAME => mb_strtolower(trim($username)),
            UserInterface::PASSWORD_HASH => $plainPassword,
            UserInterface::ROLE => $role,
        ]);
    }

    public function updatePassword(User $user, string $plainPassword): User
    {
        $user->setAttribute(UserInterface::PASSWORD_HASH, $plainPassword);
        $user->setAttribute(UserInterface::PASSWORD_CHANGED_AT, now());
        $user->save();

        return $user;
    }
}
