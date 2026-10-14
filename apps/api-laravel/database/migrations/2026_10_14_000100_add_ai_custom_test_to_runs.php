<?php

use App\Models\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI lane's own test, typed into the run sheet: free text the operator wrote,
 * and whether it replaces the flows they selected in the picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->text(Run::AI_CUSTOM_TEST)->nullable();
            $table->boolean(Run::AI_OVERRIDE)->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(Run::TABLE, function (Blueprint $table) {
            $table->dropColumn([Run::AI_CUSTOM_TEST, Run::AI_OVERRIDE]);
        });
    }
};
