<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\VideoMedia;
use App\Modules\Videos\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessedMedia implements VideoMedia
{
    public function recordProcessed(string $videoId, int $processingVersion, int $durationMs, int $width, int $height, array $thumbnails): void
    {
        // Only a newer (or the same) version may overwrite what is recorded: results can arrive late.
        $updated = Video::query()->whereKey($videoId)
            ->where(fn ($q) => $q->whereNull('processing_version')->orWhere('processing_version', '<=', $processingVersion))
            ->update([
                'duration_ms' => $durationMs, 'source_width' => $width, 'source_height' => $height,
                'processing_version' => $processingVersion, 'updated_at' => now(),
            ]);
        if ($updated === 0) {
            return;
        }

        // One candidate per distinct frame; its sizes and formats are its files.
        $candidates = [];
        foreach ($thumbnails as $t) {
            $candidates[$t['time_offset_ms']][] = [
                'key' => $t['key'], 'width' => $t['width'], 'height' => $t['height'],
                'format' => strtolower(pathinfo($t['key'], PATHINFO_EXTENSION)),
            ];
        }
        ksort($candidates);
        foreach ($candidates as $offset => $files) {
            DB::table('thumbnails')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'video_id' => $videoId, 'processing_version' => $processingVersion,
                'source' => 'auto', 'time_offset_ms' => $offset, 'files' => json_encode($files, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }
        $this->choosePrimary($videoId, $processingVersion, $durationMs);
    }

    /**
     * The new version's candidate nearest the middle becomes primary, replacing an auto primary
     * from an older version. A custom thumbnail the creator chose is never replaced.
     */
    private function choosePrimary(string $videoId, int $version, int $durationMs): void
    {
        $current = DB::table('thumbnails')->where('video_id', $videoId)->where('is_primary', true)->first(['source', 'processing_version']);
        if ($current !== null && ($current->source === 'custom' || (int) $current->processing_version === $version)) {
            return;
        }
        $middle = DB::table('thumbnails')->where('video_id', $videoId)->where('processing_version', $version)->where('source', 'auto')
            ->orderByRaw('abs(time_offset_ms - ?)', [intdiv($durationMs, 2)])->value('id');
        if ($middle === null) {
            return;
        }

        DB::table('thumbnails')->where('video_id', $videoId)->where('is_primary', true)->update(['is_primary' => false]);
        DB::table('thumbnails')->where('id', $middle)->update(['is_primary' => true]);
    }
}
