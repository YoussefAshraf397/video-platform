<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->text('password_hash');                  // Argon2id
            $table->timestampTz('password_changed_at');
            $table->timestampsTz();
        });

        // One row per signed-in device. Its refresh tokens form one rotation family.
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();                  // `sid` claim in access tokens
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('last_used_at');
            $table->timestampTz('expires_at');              // absolute lifetime, not extended by refreshes
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 32)->nullable();

            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();      // sha256; the token itself is never stored
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('auth_sessions')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('rotated_at')->nullable();   // set when exchanged; using it again = reuse

            $table->index('session_id');
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('credentials');
    }
};
