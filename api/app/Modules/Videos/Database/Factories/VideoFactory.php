<?php

namespace App\Modules\Videos\Database\Factories;

use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\Videos;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Video> */
final class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return [
            'public_id' => Videos::newPublicId(),
            'title' => fake()->sentence(4),
        ];
    }

    /** Publishing arrives with S3-02/S4; tests use this state to exercise public reads. */
    public function published(string $visibility = 'public'): static
    {
        return $this->state(['status' => 'published', 'visibility' => $visibility, 'published_at' => now()]);
    }
}
