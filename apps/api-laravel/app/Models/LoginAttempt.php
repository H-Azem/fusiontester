<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::IP, self::USERNAME, self::SUCCESS, self::COUNTED, self::REASON, self::USER_AGENT, self::CREATED_AT])]
class LoginAttempt extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'login_attempts';

    const IP = 'ip';

    const USERNAME = 'username';

    const SUCCESS = 'success';

    const COUNTED = 'counted';

    const REASON = 'reason';

    const USER_AGENT = 'user_agent';

    /** Reasons that are recorded but never count towards an IP block. */
    const REASON_CAPTCHA = 'captcha';

    const REASON_UNKNOWN_USER = 'unknown_user';

    const REASON_BAD_PASSWORD = 'bad_password';

    const REASON_BAD_CURRENT_PASSWORD = 'bad_current_password';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::CREATED_AT => 'datetime',
            self::SUCCESS => 'boolean',
            self::COUNTED => 'boolean',
        ];
    }
}
