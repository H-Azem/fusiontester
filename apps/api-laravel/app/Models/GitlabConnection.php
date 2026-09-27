<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::BASE_URL, self::TOKEN_CIPHERTEXT, self::TOKEN_HINT, self::CA_CERTIFICATE])]
class GitlabConnection extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'gitlab_connections';

    const BASE_URL = 'base_url';

    const TOKEN_CIPHERTEXT = 'token_ciphertext';

    const TOKEN_HINT = 'token_hint';

    const CA_CERTIFICATE = 'ca_certificate';

    const LAST_VERIFIED_AT = 'last_verified_at';

    const LAST_VERIFY_OK = 'last_verify_ok';

    const LAST_VERIFY_ERROR = 'last_verify_error';

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::LAST_VERIFIED_AT => 'datetime',
            self::LAST_VERIFY_OK => 'boolean',
        ];
    }
}
