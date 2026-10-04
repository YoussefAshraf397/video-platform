<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();              // = envelope event_id (UUIDv7, time-ordered)
            $table->string('topic', 256);
            $table->string('event_type', 128);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->unsignedBigInteger('aggregate_version');
            $table->json('envelope');                    // exact message body sent to SNS (json keeps bytes; jsonb would reorder keys)
            $table->timestampTz('created_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index('published_at');               // pruning published rows
        });

        // The relay only ever scans unpublished rows; keep that index tiny.
        DB::statement('CREATE INDEX outbox_messages_unpublished_idx ON outbox_messages (id) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
