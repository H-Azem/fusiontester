<?php

use App\Models\Contracts\UserInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(UserInterface::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string(UserInterface::USERNAME)->unique();
            $table->string(UserInterface::PASSWORD_HASH);
            $table->string(UserInterface::ROLE)->default(UserInterface::ROLE_ADMIN);
            $table->timestamp(UserInterface::PASSWORD_CHANGED_AT)->nullable();
            $table->timestamps();
        });

        // Framework sessions stay separate from the auth sessions this app issues
        // as its own cookie, so the two can never be confused.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists(UserInterface::TABLE);
    }
};
