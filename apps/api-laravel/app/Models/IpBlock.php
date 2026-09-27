<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::IP, self::FAILED_COUNT, self::REASON, self::BLOCKED_AT, self::EXPIRES_AT])]
class IpBlock extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'ip_blocks';

    const IP = 'ip';

    const FAILED_COUNT = 'failed_count';

    const REASON = 'reason';

    const BLOCKED_AT = 'blocked_at';

    const EXPIRES_AT = 'expires_at';

    const RELEASED_AT = 'released_at';

    const RELEASED_BY = 'released_by';

    const UNBLOCK_REASON_ADMIN = 'admin';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::BLOCKED_AT => 'datetime',
            self::EXPIRES_AT => 'datetime',
            self::RELEASED_AT => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->getReleasedAt() === null && $this->getExpiresAt()->isFuture();
    }
}
