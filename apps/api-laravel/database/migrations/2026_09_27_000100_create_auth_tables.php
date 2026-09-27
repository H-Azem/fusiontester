<?php

use App\Models\AuditEntry;
use App\Models\AuthSession;
use App\Models\CaptchaChallenge;
use App\Models\IpBlock;
use App\Models\LoginAttempt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auth domain in one migration: sessions this app issues itself, the captcha
 * challenges it consumes, the attempt log that feeds IP blocking, the blocks
 * themselves, and the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(AuthSession::TABLE, function (Blueprint $table) {
            $table->uuid(AuthSession::ID)->primary();
            $table->foreignId(AuthSession::USER_ID)->constrained('users')->cascadeOnDelete();
            $table->string(AuthSession::TOKEN_HASH)->unique();
            $table->string(AuthSession::IP)->nullable();
            $table->text(AuthSession::USER_AGENT)->nullable();
            $table->timestamp(AuthSession::CREATED_AT)->nullable();
            $table->timestamp(AuthSession::LAST_SEEN_AT)->nullable();
            $table->timestamp(AuthSession::EXPIRES_AT);
            $table->timestamp(AuthSession::REVOKED_AT)->nullable();

            $table->index([AuthSession::USER_ID]);
        });

        Schema::create(LoginAttempt::TABLE, function (Blueprint $table) {
            $table->uuid(LoginAttempt::ID)->primary();
            $table->string(LoginAttempt::IP);
            $table->string(LoginAttempt::USERNAME)->nullable();
            $table->boolean(LoginAttempt::SUCCESS)->default(false);
            $table->boolean(LoginAttempt::COUNTED)->default(false);
            $table->string(LoginAttempt::REASON)->nullable();
            $table->text(LoginAttempt::USER_AGENT)->nullable();
            $table->timestamp(LoginAttempt::CREATED_AT)->nullable();

            $table->index([LoginAttempt::IP, LoginAttempt::CREATED_AT]);
        });

        Schema::create(IpBlock::TABLE, function (Blueprint $table) {
            $table->uuid(IpBlock::ID)->primary();
            $table->string(IpBlock::IP)->unique();
            $table->integer(IpBlock::FAILED_COUNT)->default(0);
            $table->string(IpBlock::REASON)->nullable();
            $table->timestamp(IpBlock::BLOCKED_AT)->nullable();
            $table->timestamp(IpBlock::EXPIRES_AT);
            $table->timestamp(IpBlock::RELEASED_AT)->nullable();
            $table->string(IpBlock::RELEASED_BY)->nullable();

            $table->index([IpBlock::EXPIRES_AT]);
        });

        Schema::create(CaptchaChallenge::TABLE, function (Blueprint $table) {
            $table->uuid(CaptchaChallenge::ID)->primary();
            $table->string(CaptchaChallenge::ANSWER_HASH);
            $table->string(CaptchaChallenge::IP)->nullable();
            $table->timestamp(CaptchaChallenge::CREATED_AT)->nullable();
            $table->timestamp(CaptchaChallenge::EXPIRES_AT);
            $table->timestamp(CaptchaChallenge::CONSUMED_AT)->nullable();

            $table->index([CaptchaChallenge::EXPIRES_AT]);
        });

        Schema::create(AuditEntry::TABLE, function (Blueprint $table) {
            $table->uuid(AuditEntry::ID)->primary();
            $table->foreignId(AuditEntry::ACTOR_USER_ID)->nullable()->constrained('users')->nullOnDelete();
            $table->string(AuditEntry::ACTION);
            $table->string(AuditEntry::IP)->nullable();
            $table->text(AuditEntry::USER_AGENT)->nullable();
            $table->json(AuditEntry::METADATA)->nullable();
            $table->timestamp(AuditEntry::CREATED_AT)->nullable();

            $table->index([AuditEntry::CREATED_AT]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(AuditEntry::TABLE);
        Schema::dropIfExists(CaptchaChallenge::TABLE);
        Schema::dropIfExists(IpBlock::TABLE);
        Schema::dropIfExists(LoginAttempt::TABLE);
        Schema::dropIfExists(AuthSession::TABLE);
    }
};
