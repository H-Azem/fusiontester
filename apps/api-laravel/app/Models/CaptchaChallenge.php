<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::ANSWER_HASH, self::IP, self::CREATED_AT, self::EXPIRES_AT])]
class CaptchaChallenge extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'captcha_challenges';

    const ANSWER_HASH = 'answer_hash';

    const IP = 'ip';

    const EXPIRES_AT = 'expires_at';

    const CONSUMED_AT = 'consumed_at';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::CREATED_AT => 'datetime',
            self::EXPIRES_AT => 'datetime',
            self::CONSUMED_AT => 'datetime',
        ];
    }
}
