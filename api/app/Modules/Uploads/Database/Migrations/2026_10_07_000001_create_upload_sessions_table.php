<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->uuid('user_id');
            $table->string('bucket', 63);
            $table->string('object_key', 255);   // uploads/{video_id}/{session_id}/source, chosen by the server
            $table->string('s3_upload_id', 1024)->unique();
            $table->unsignedBigInteger('declared_size_bytes');
            $table->string('content_type', 100);
            $table->char('declared_sha256', 64)->nullable();
            $table->unsignedBigInteger('part_size_bytes');
            $table->unsignedInteger('total_parts');
            $table->string('status', 16)->default('initiated');
            $table->string('failure_reason', 64)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('video_id')->references('id')->on('videos')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('video_id');
            $table->index(['user_id', 'created_at']);   // daily quota
            $table->index(['status', 'expires_at']);    // expiry sweeper (S3-05)
        });

        DB::statement("ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_status_check
            CHECK (status IN ('initiated','in_progress','completing','completed','aborted','expired','failed'))");

        // At most one live session per video; a second concurrent create fails here.
        DB::statement("CREATE UNIQUE INDEX upload_sessions_one_active_per_video ON upload_sessions (video_id)
            WHERE status IN ('initiated','in_progress','completing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
