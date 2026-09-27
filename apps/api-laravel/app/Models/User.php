<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable([UserInterface::USERNAME, UserInterface::PASSWORD_HASH, UserInterface::ROLE])]
#[Hidden([UserInterface::PASSWORD_HASH])]
class User extends Authenticatable implements UserInterface
{
    use HasConstantGetters;

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::PASSWORD_HASH => 'hashed',
            self::PASSWORD_CHANGED_AT => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->getRole() === self::ROLE_ADMIN;
    }
}
