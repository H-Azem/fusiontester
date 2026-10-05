<?php

use App\Models\TelegramConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which topic a report goes to, and whether reports go anywhere at all. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TelegramConnection::TABLE, function (Blueprint $table) {
            $table->string(TelegramConnection::MESSAGE_THREAD_ID)->nullable();
            $table->boolean(TelegramConnection::ENABLED)->default(true);
        });
    }

    public function down(): void
    {
        Schema::table(TelegramConnection::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                TelegramConnection::MESSAGE_THREAD_ID,
                TelegramConnection::ENABLED,
            ]);
        });
    }
};
