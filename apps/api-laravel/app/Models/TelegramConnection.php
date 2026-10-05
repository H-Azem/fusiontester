<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    self::BOT_TOKEN_CIPHERTEXT,
    self::BOT_TOKEN_HINT,
    self::CHAT_ID,
    self::NOTIFY_ON_PASS,
    self::MESSAGE_THREAD_ID,
    self::ENABLED,
])]
class TelegramConnection extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'telegram_connections';

    const BOT_TOKEN_CIPHERTEXT = 'bot_token_ciphertext';

    const BOT_TOKEN_HINT = 'bot_token_hint';

    /** The channel the bot posts to; its id, or an @name. */
    const CHAT_ID = 'chat_id';

    const NOTIFY_ON_PASS = 'notify_on_pass';

    /** The forum topic a group report goes to; empty posts to the group itself. */
    const MESSAGE_THREAD_ID = 'message_thread_id';

    /** Off means no report leaves the machine, whatever the other switches say. */
    const ENABLED = 'enabled';

    const LAST_VERIFIED_AT = 'last_verified_at';

    const LAST_VERIFY_OK = 'last_verify_ok';

    const LAST_VERIFY_ERROR = 'last_verify_error';

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::NOTIFY_ON_PASS => 'boolean',
            self::ENABLED => 'boolean',
            self::LAST_VERIFIED_AT => 'datetime',
            self::LAST_VERIFY_OK => 'boolean',
        ];
    }
}
