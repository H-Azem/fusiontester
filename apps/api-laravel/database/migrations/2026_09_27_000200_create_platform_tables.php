<?php

use App\Models\AiConnection;
use App\Models\GitlabConnection;
use App\Models\Pin;
use App\Models\ProjectSetting;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the dashboard reads besides auth: the two connections, pins,
 * per-project preferences, and runs with their steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(GitlabConnection::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string(GitlabConnection::BASE_URL);
            $table->text(GitlabConnection::TOKEN_CIPHERTEXT);
            $table->string(GitlabConnection::TOKEN_HINT);
            $table->text(GitlabConnection::CA_CERTIFICATE)->nullable();
            $table->timestamp(GitlabConnection::LAST_VERIFIED_AT)->nullable();
            $table->boolean(GitlabConnection::LAST_VERIFY_OK)->nullable();
            $table->text(GitlabConnection::LAST_VERIFY_ERROR)->nullable();
            $table->timestamps();
        });

        Schema::create(AiConnection::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string(AiConnection::OPENAI_BASE_URL);
            $table->string(AiConnection::OPENAI_MODEL);
            $table->text(AiConnection::OPENAI_TOKEN_CIPHERTEXT);
            $table->string(AiConnection::OPENAI_TOKEN_HINT);
            $table->string(AiConnection::JEV_BASE_URL);
            $table->text(AiConnection::JEV_TOKEN_CIPHERTEXT);
            $table->string(AiConnection::JEV_TOKEN_HINT);
            $table->integer(AiConnection::MAX_STEPS)->default(25);
            $table->timestamp(AiConnection::LAST_VERIFIED_AT)->nullable();
            $table->boolean(AiConnection::LAST_VERIFY_OK)->nullable();
            $table->text(AiConnection::LAST_VERIFY_ERROR)->nullable();
            $table->timestamps();
        });

        Schema::create(Pin::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string(Pin::KIND);
            $table->integer(Pin::PROJECT_ID);
            $table->string(Pin::PROJECT_PATH);
            // Empty string for a repository pin: Postgres treats NULLs as distinct
            // in a unique index, so a sentinel keeps the constraint meaningful.
            $table->string(Pin::BRANCH)->default('');
            $table->timestamp(Pin::CREATED_AT)->nullable();

            $table->unique([Pin::KIND, Pin::PROJECT_ID, Pin::BRANCH]);
        });

        Schema::create(ProjectSetting::TABLE, function (Blueprint $table) {
            $table->id();
            $table->integer(ProjectSetting::PROJECT_ID)->unique();
            $table->string(ProjectSetting::ORIENTATION)->default(ProjectSetting::ORIENTATION_DEFAULT);
            $table->text(ProjectSetting::DART_DEFINES)->default('');
            $table->timestamps();
        });

        Schema::create(Run::TABLE, function (Blueprint $table) {
            $table->uuid(Run::ID)->primary();
            $table->integer(Run::PROJECT_ID);
            $table->string(Run::PROJECT_PATH);
            $table->string(Run::BRANCH);
            $table->json(Run::TESTS)->nullable();
            $table->json(Run::RUN_KINDS)->nullable();
            $table->json(Run::ENVIRONMENTS)->nullable();
            $table->string(Run::STATUS)->default(Run::STATUS_QUEUED);
            $table->string(Run::CURRENT_STEP)->default(Run::STEP_QUEUED);
            $table->string(Run::ORIENTATION)->default(Run::ORIENTATION_DEFAULT);
            $table->text(Run::DART_DEFINES)->default('');
            $table->text(Run::ERROR_MESSAGE)->nullable();
            $table->timestamp(Run::CREATED_AT)->nullable();
            $table->timestamp(Run::STARTED_AT)->nullable();
            $table->timestamp(Run::FINISHED_AT)->nullable();

            $table->index([Run::STATUS, Run::CREATED_AT]);
        });

        Schema::create(RunStep::TABLE, function (Blueprint $table) {
            $table->uuid(RunStep::ID)->primary();
            $table->uuid(RunStep::RUN_ID);
            $table->string(RunStep::KEY);
            $table->string(RunStep::LABEL);
            $table->string(RunStep::STATUS)->default(RunStep::STATUS_PENDING);
            $table->text(RunStep::OUTPUT)->nullable();
            $table->integer(RunStep::POSITION)->default(0);
            $table->timestamp(RunStep::STARTED_AT)->nullable();
            $table->timestamp(RunStep::FINISHED_AT)->nullable();

            $table->foreign(RunStep::RUN_ID)->references(Run::ID)->on(Run::TABLE)->cascadeOnDelete();
            $table->index([RunStep::RUN_ID, RunStep::POSITION]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(RunStep::TABLE);
        Schema::dropIfExists(Run::TABLE);
        Schema::dropIfExists(ProjectSetting::TABLE);
        Schema::dropIfExists(Pin::TABLE);
        Schema::dropIfExists(AiConnection::TABLE);
        Schema::dropIfExists(GitlabConnection::TABLE);
    }
};
