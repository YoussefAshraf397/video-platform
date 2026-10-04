<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 30)->nullable()->after('email');   // as the user typed it
            $table->string('bio', 500)->nullable()->after('display_name');
        });
        // Handles are unique regardless of case: "Ada" and "ada" can't both exist.
        DB::statement('CREATE UNIQUE INDEX users_handle_lower_unique ON users (lower(handle))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_handle_lower_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['handle', 'bio']);
        });
    }
};
