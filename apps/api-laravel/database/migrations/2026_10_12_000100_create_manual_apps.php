<?php

use App\Models\ManualApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Apps whose APK is built for hand-testing on the device. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(ManualApp::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(ManualApp::PROJECT_ID);
            $table->string(ManualApp::PROJECT_PATH);
            $table->string(ManualApp::BRANCH);
            $table->string(ManualApp::TITLE);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ManualApp::TABLE);
    }
};
