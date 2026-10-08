<?php

use App\Models\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The one-time hand-off token for a run's built APK. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->string(Run::APK_TOKEN)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->dropColumn(Run::APK_TOKEN);
        });
    }
};
