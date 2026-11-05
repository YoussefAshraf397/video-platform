<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backs the public listings: newest published first, same order as the cursor.
        DB::statement("CREATE INDEX videos_public_listing_idx ON videos (published_at DESC, id DESC) WHERE status = 'published' AND visibility = 'public' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS videos_public_listing_idx');
    }
};
