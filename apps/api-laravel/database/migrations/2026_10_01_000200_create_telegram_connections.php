<?php

use App\Models\TelegramConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where run reports are sent. The bot token is a credential like any other, so it is
 * stored as ciphertext and only ever shown as a hint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TelegramConnection::TABLE, function (Blueprint $table) {
            $table->id();
            $table->text(TelegramConnection::BOT_TOKEN_CIPHERTEXT);
            $table->string(TelegramConnection::BOT_TOKEN_HINT);
            $table->string(TelegramConnection::CHAT_ID);
            $table->boolean(TelegramConnection::NOTIFY_ON_PASS)->default(true);
            $table->timestamp(TelegramConnection::LAST_VERIFIED_AT)->nullable();
            $table->boolean(TelegramConnection::LAST_VERIFY_OK)->nullable();
            $table->text(TelegramConnection::LAST_VERIFY_ERROR)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TelegramConnection::TABLE);
    }
};
