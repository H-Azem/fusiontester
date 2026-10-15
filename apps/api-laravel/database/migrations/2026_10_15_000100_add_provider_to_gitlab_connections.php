<?php

use App\Models\GitlabConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which forge the connection points at. The API surface differs (paths, auth
 * header, pagination), so the client needs to know before it can talk to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(GitlabConnection::TABLE, function (Blueprint $table) {
            $table->string(GitlabConnection::PROVIDER)->default(GitlabConnection::PROVIDER_GITLAB);
        });
    }

    public function down(): void
    {
        Schema::table(GitlabConnection::TABLE, function (Blueprint $table) {
            $table->dropColumn(GitlabConnection::PROVIDER);
        });
    }
};
