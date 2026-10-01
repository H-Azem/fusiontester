<?php

use App\Models\AutomationTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule that turns a GitLab push into a test run. Each rule has its own webhook
 * token — stored as a hash for lookup and encrypted for display — so a leaked URL
 * only reaches that one rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(AutomationTrigger::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(AutomationTrigger::PROJECT_ID);
            $table->string(AutomationTrigger::PROJECT_PATH);
            $table->string(AutomationTrigger::BRANCH_PATTERN);
            $table->json(AutomationTrigger::TESTS);
            $table->json(AutomationTrigger::RUN_KINDS);
            $table->json(AutomationTrigger::ENVIRONMENTS);
            $table->string(AutomationTrigger::ORIENTATION);
            $table->string(AutomationTrigger::PLATFORM);
            $table->boolean(AutomationTrigger::LIVE)->default(false);
            $table->text(AutomationTrigger::DART_DEFINES)->nullable();
            $table->boolean(AutomationTrigger::ENABLED)->default(true);
            $table->text(AutomationTrigger::WEBHOOK_TOKEN_CIPHERTEXT);
            $table->string(AutomationTrigger::WEBHOOK_TOKEN_HASH, 64);
            $table->string(AutomationTrigger::WEBHOOK_TOKEN_HINT);
            $table->timestamp(AutomationTrigger::LAST_FIRED_AT)->nullable();
            $table->string(AutomationTrigger::LAST_FIRED_SHA)->nullable();
            $table->string(AutomationTrigger::LAST_FIRED_BRANCH)->nullable();
            $table->unsignedInteger(AutomationTrigger::FIRE_COUNT)->default(0);
            $table->timestamps();

            $table->unique(AutomationTrigger::WEBHOOK_TOKEN_HASH);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(AutomationTrigger::TABLE);
    }
};
