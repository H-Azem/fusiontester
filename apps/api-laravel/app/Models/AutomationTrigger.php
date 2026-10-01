<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    self::PROJECT_ID,
    self::PROJECT_PATH,
    self::BRANCH_PATTERN,
    self::TESTS,
    self::RUN_KINDS,
    self::ENVIRONMENTS,
    self::ORIENTATION,
    self::PLATFORM,
    self::LIVE,
    self::DART_DEFINES,
    self::ENABLED,
    self::WEBHOOK_TOKEN_CIPHERTEXT,
    self::WEBHOOK_TOKEN_HASH,
    self::WEBHOOK_TOKEN_HINT,
])]
class AutomationTrigger extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'automation_triggers';

    const PROJECT_ID = 'project_id';

    const PROJECT_PATH = 'project_path';

    /** Glob: `main`, `release/*`, `*`. */
    const BRANCH_PATTERN = 'branch_pattern';

    const TESTS = 'tests';

    const RUN_KINDS = 'run_kinds';

    const ENVIRONMENTS = 'environments';

    const ORIENTATION = 'orientation';

    const PLATFORM = 'platform';

    const LIVE = 'live';

    const DART_DEFINES = 'dart_defines';

    const ENABLED = 'enabled';

    const WEBHOOK_TOKEN_CIPHERTEXT = 'webhook_token_ciphertext';

    const WEBHOOK_TOKEN_HASH = 'webhook_token_hash';

    const WEBHOOK_TOKEN_HINT = 'webhook_token_hint';

    const LAST_FIRED_AT = 'last_fired_at';

    const LAST_FIRED_SHA = 'last_fired_sha';

    const LAST_FIRED_BRANCH = 'last_fired_branch';

    const FIRE_COUNT = 'fire_count';

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::TESTS => 'array',
            self::RUN_KINDS => 'array',
            self::ENVIRONMENTS => 'array',
            self::LIVE => 'boolean',
            self::ENABLED => 'boolean',
            self::LAST_FIRED_AT => 'datetime',
            self::FIRE_COUNT => 'integer',
        ];
    }
}
