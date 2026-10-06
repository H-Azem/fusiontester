<?php

use App\Models\AiConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The AI lane's own master switch, and whether its report goes to Telegram. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(AiConnection::TABLE, function (Blueprint $table) {
            $table->boolean(AiConnection::AI_LANE_ENABLED)->default(true);
            $table->boolean(AiConnection::SHARE_REPORT_TO_TELEGRAM)->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(AiConnection::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                AiConnection::AI_LANE_ENABLED,
                AiConnection::SHARE_REPORT_TO_TELEGRAM,
            ]);
        });
    }
};
