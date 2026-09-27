<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::USER_ID, self::TOKEN_HASH, self::IP, self::USER_AGENT, self::CREATED_AT, self::LAST_SEEN_AT, self::EXPIRES_AT])]
class AuthSession extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'auth_sessions';

    const USER_ID = 'user_id';

    const TOKEN_HASH = 'token_hash';

    const IP = 'ip';

    const USER_AGENT = 'user_agent';

    const LAST_SEEN_AT = 'last_seen_at';

    const EXPIRES_AT = 'expires_at';

    const REVOKED_AT = 'revoked_at';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::CREATED_AT => 'datetime',
            self::LAST_SEEN_AT => 'datetime',
            self::EXPIRES_AT => 'datetime',
            self::REVOKED_AT => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->getRevokedAt() === null && $this->getExpiresAt()->isFuture();
    }

    public function user()
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
