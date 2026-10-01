<?php

use App\Models\AuthSession;
use App\Models\Contracts\UserInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings hold credentials that reach the machine under test, so they sit behind
 * their own password: one hash on the user, and a timestamp on the session that
 * says the person already proved it during this sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(UserInterface::TABLE, function (Blueprint $table) {
            $table->string(UserInterface::SETTINGS_PASSWORD_HASH)->nullable();
        });

        Schema::table(AuthSession::TABLE, function (Blueprint $table) {
            $table->timestamp(AuthSession::SETTINGS_UNLOCKED_AT)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(UserInterface::TABLE, function (Blueprint $table) {
            $table->dropColumn(UserInterface::SETTINGS_PASSWORD_HASH);
        });

        Schema::table(AuthSession::TABLE, function (Blueprint $table) {
            $table->dropColumn(AuthSession::SETTINGS_UNLOCKED_AT);
        });
    }
};
