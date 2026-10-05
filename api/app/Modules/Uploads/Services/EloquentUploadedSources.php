<?php

namespace App\Modules\Uploads\Services;

use App\Modules\Uploads\Contracts\UploadedSource;
use App\Modules\Uploads\Contracts\UploadedSources;
use App\Modules\Uploads\Models\UploadSession;

final class EloquentUploadedSources implements UploadedSources
{
    public function latestCompletedFor(string $videoId): ?UploadedSource
    {
        $session = UploadSession::query()->where('video_id', $videoId)->where('status', 'completed')
            ->orderByDesc('completed_at')->orderByDesc('id')->first();

        return $session === null ? null : new UploadedSource(
            $session->id, $session->video_id, $session->bucket, $session->object_key, $session->declared_size_bytes, $session->content_type,
        );
    }
}
