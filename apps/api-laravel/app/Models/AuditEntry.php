<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::ACTOR_USER_ID, self::ACTION, self::IP, self::USER_AGENT, self::METADATA, self::CREATED_AT])]
class AuditEntry extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'audit_log';

    const ACTOR_USER_ID = 'actor_user_id';

    const ACTION = 'action';

    const IP = 'ip';

    const USER_AGENT = 'user_agent';

    const METADATA = 'metadata';

    const ACTION_LOGIN_SUCCESS = 'login.success';

    const ACTION_LOGIN_FAILED = 'login.failed';

    const ACTION_LOGIN_BLOCKED = 'login.blocked';

    const ACTION_LOGIN_CAPTCHA_FAILED = 'login.captcha_failed';

    const ACTION_IP_BLOCKED = 'ip.blocked';

    const ACTION_LOGOUT = 'logout';

    const ACTION_PASSWORD_CHANGED = 'password.changed';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::CREATED_AT => 'datetime',
            self::METADATA => 'array',
        ];
    }
}
