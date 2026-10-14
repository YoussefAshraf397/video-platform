<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            // An admin retry is a new job (next processing version) pointing at the one that failed.
            $table->uuid('retry_of_job_id')->nullable();
            $table->foreign('retry_of_job_id')->references('id')->on('video_processing_jobs')->nullOnDelete();
            $table->dropUnique(['upload_session_id', 'profile']);
        });
        // Still one *dispatched* job per upload and ladder (the dispatcher's backstop); retries are extra.
        DB::statement('CREATE UNIQUE INDEX video_processing_jobs_one_dispatch_per_upload ON video_processing_jobs (upload_session_id, profile) WHERE retry_of_job_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX video_processing_jobs_one_dispatch_per_upload');
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            $table->dropForeign(['retry_of_job_id']);
            $table->dropColumn('retry_of_job_id');
            $table->unique(['upload_session_id', 'profile']);
        });
    }
};
