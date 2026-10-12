<?php

use App\Models\ManualApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The APK a manual app was last built into, and how that went. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(ManualApp::TABLE, function (Blueprint $table) {
            $table->string(ManualApp::APK_PATH)->nullable();
            $table->timestamp(ManualApp::APK_BUILT_AT)->nullable();
            $table->string(ManualApp::BUILT_SHA)->nullable();
            $table->text(ManualApp::BUILD_ERROR)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(ManualApp::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                ManualApp::APK_PATH,
                ManualApp::APK_BUILT_AT,
                ManualApp::BUILT_SHA,
                ManualApp::BUILD_ERROR,
            ]);
        });
    }
};
