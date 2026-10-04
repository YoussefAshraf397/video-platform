<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 254)->unique();   // stored normalized (trimmed, lowercase)
            $table->string('display_name', 100);
            $table->string('status', 32)->default('active');   // active | suspended | deleted
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
