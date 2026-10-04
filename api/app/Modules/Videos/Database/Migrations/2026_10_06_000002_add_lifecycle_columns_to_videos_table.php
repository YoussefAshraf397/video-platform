<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('pre_block_status', 32)->nullable();   // where lifting a block returns to (§12.5)
            $table->string('blocked_reason', 64)->nullable();
        });

        // Listed literally, not read from VideoStatus: a new state needs a new migration, and this
        // one must keep meaning the same thing. A test checks the list matches the enum.
        DB::statement("ALTER TABLE videos ADD CONSTRAINT videos_status_check CHECK (status IN (
            'draft','upload_pending','uploading','uploaded','validating','queued_for_processing','processing',
            'ready','published','unpublished','upload_failed','processing_failed','rejected','blocked','deleted','purged'
        ))");
        DB::statement("ALTER TABLE videos ADD CONSTRAINT videos_visibility_check CHECK (visibility IN ('public','unlisted','private'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE videos DROP CONSTRAINT videos_status_check');
        DB::statement('ALTER TABLE videos DROP CONSTRAINT videos_visibility_check');
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['pre_block_status', 'blocked_reason']);
        });
    }
};
