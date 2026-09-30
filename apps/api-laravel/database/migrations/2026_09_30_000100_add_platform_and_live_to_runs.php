<?php

use App\Models\ProjectSetting;
use App\Models\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run can now target either lane: the browser build it has always used, or a
 * real Android device (the redroid container). `live` switches the periodic
 * screenshot stream on for that run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->string(Run::PLATFORM)->default(Run::PLATFORM_DEFAULT)->after(Run::ENVIRONMENTS);
            $table->boolean(Run::LIVE)->default(false)->after(Run::PLATFORM);
        });

        Schema::table(ProjectSetting::TABLE, function (Blueprint $table) {
            $table->string(ProjectSetting::PLATFORM)->default(ProjectSetting::PLATFORM_DEFAULT)->after(ProjectSetting::ORIENTATION);
        });
    }

    public function down(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->dropColumn([Run::PLATFORM, Run::LIVE]);
        });

        Schema::table(ProjectSetting::TABLE, function (Blueprint $table) {
            $table->dropColumn(ProjectSetting::PLATFORM);
        });
    }
};
