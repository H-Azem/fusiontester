<?php

use App\Models\ProjectSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Free-text data the AI lane may need to type — a PIN, a login, a setup value. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(ProjectSetting::TABLE, function (Blueprint $table) {
            $table->text(ProjectSetting::AI_CONTEXT)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(ProjectSetting::TABLE, function (Blueprint $table) {
            $table->dropColumn(ProjectSetting::AI_CONTEXT);
        });
    }
};
