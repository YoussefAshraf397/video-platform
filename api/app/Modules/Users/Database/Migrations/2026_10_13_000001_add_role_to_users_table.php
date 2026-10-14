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
            // Platform staff role (design doc §23). Creator/viewer rights come from ownership, not roles.
            $table->string('role', 16)->default('user');
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','moderator','admin'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
