<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Seeded here, not in a seeder, so every environment (including production) has them. */
    private const CATEGORIES = [
        'film-animation' => 'Film & Animation',
        'autos-vehicles' => 'Autos & Vehicles',
        'music' => 'Music',
        'pets-animals' => 'Pets & Animals',
        'sports' => 'Sports',
        'travel-events' => 'Travel & Events',
        'gaming' => 'Gaming',
        'people-blogs' => 'People & Blogs',
        'comedy' => 'Comedy',
        'entertainment' => 'Entertainment',
        'news-politics' => 'News & Politics',
        'howto-style' => 'Howto & Style',
        'education' => 'Education',
        'science-technology' => 'Science & Technology',
        'nonprofits-activism' => 'Nonprofits & Activism',
    ];

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->smallInteger('sort_order');
            $table->boolean('is_active')->default(true);
        });

        $order = 0;
        DB::table('categories')->insert(array_map(
            function (string $slug, string $name) use (&$order) {
                return ['slug' => $slug, 'name' => $name, 'sort_order' => ++$order * 10];
            },
            array_keys(self::CATEGORIES),
            self::CATEGORIES,
        ));

        Schema::create('videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('public_id', 11)->unique();   // opaque, non-enumerable id used in URLs and the API
            $table->uuid('uploader_user_id');
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->string('language', 35)->nullable();   // BCP 47
            $table->unsignedSmallInteger('category_id')->nullable();
            $table->string('visibility', 16)->default('private');   // public | unlisted | private
            $table->string('status', 32)->default('draft');   // design doc §12; transitions arrive with S3-02
            $table->string('moderation_status', 32)->default('none');
            $table->boolean('age_restricted')->default(false);
            $table->boolean('made_for_kids')->default(false);
            $table->boolean('comments_enabled')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('state_version')->default(1);   // bumped by every write; the ETag
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('uploader_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        // "My videos", newest first (cursor pagination orders by created_at, id).
        DB::statement('CREATE INDEX videos_uploader_created_idx ON videos (uploader_user_id, created_at DESC, id DESC) WHERE deleted_at IS NULL');

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_name', 50)->unique();   // NFKC, whitespace collapsed, lowercase
        });

        Schema::create('video_tags', function (Blueprint $table) {
            $table->uuid('video_id');
            $table->unsignedBigInteger('tag_id');
            $table->unsignedSmallInteger('position');
            $table->string('label', 50);   // as the creator typed it

            $table->primary(['video_id', 'tag_id']);
            $table->index(['tag_id', 'video_id']);
            $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('categories');
    }
};
