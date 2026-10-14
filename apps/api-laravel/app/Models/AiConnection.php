<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    self::OPENAI_BASE_URL,
    self::OPENAI_MODEL,
    self::OPENAI_TOKEN_CIPHERTEXT,
    self::OPENAI_TOKEN_HINT,
    self::JEV_BASE_URL,
    self::JEV_TOKEN_CIPHERTEXT,
    self::JEV_TOKEN_HINT,
    self::MAX_STEPS,
    self::AI_LANE_ENABLED,
    self::SHARE_REPORT_TO_TELEGRAM,
    self::MAX_TOKENS,
])]
class AiConnection extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'ai_connections';

    const OPENAI_BASE_URL = 'openai_base_url';

    const OPENAI_MODEL = 'openai_model';

    const OPENAI_TOKEN_CIPHERTEXT = 'openai_token_ciphertext';

    const OPENAI_TOKEN_HINT = 'openai_token_hint';

    const JEV_BASE_URL = 'jev_base_url';

    const JEV_TOKEN_CIPHERTEXT = 'jev_token_ciphertext';

    const JEV_TOKEN_HINT = 'jev_token_hint';

    const MAX_STEPS = 'max_steps';

    /** Off keeps the AI stage out of every run, whatever the run sheet asks for. */
    const AI_LANE_ENABLED = 'ai_lane_enabled';

    /** Whether the lane's own report is sent to the configured Telegram channel. */
    const SHARE_REPORT_TO_TELEGRAM = 'share_report_to_telegram';

    /**
     * The ceiling the lane may spend on one test, in tokens (prompt + completion).
     * The lane stops when it passes this, so a run cannot quietly cost a fortune.
     */
    const MAX_TOKENS = 'max_tokens';

    const LAST_VERIFIED_AT = 'last_verified_at';

    const LAST_VERIFY_OK = 'last_verify_ok';

    const LAST_VERIFY_ERROR = 'last_verify_error';

    const DEFAULT_MAX_STEPS = 25;

    const DEFAULT_MAX_TOKENS = 10000000;

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::MAX_STEPS => 'integer',
            self::MAX_TOKENS => 'integer',
            self::AI_LANE_ENABLED => 'boolean',
            self::SHARE_REPORT_TO_TELEGRAM => 'boolean',
            self::LAST_VERIFIED_AT => 'datetime',
            self::LAST_VERIFY_OK => 'boolean',
        ];
    }
}
