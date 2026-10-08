<?php

use App\Models\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a run's one-time APK was taken, so the page can say it is gone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->timestamp(Run::APK_DOWNLOADED_AT)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->dropColumn(Run::APK_DOWNLOADED_AT);
        });
    }
};
