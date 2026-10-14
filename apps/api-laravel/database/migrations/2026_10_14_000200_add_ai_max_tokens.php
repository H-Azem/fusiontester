<?php

use App\Models\AiConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lane's budget is spent in tokens rather than turns: a long journey that is
 * still making progress should not be cut off at a fixed number of steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(AiConnection::TABLE, function (Blueprint $table) {
            $table->integer(AiConnection::MAX_TOKENS)->default(AiConnection::DEFAULT_MAX_TOKENS);
        });
    }

    public function down(): void
    {
        Schema::table(AiConnection::TABLE, function (Blueprint $table) {
            $table->dropColumn(AiConnection::MAX_TOKENS);
        });
    }
};
