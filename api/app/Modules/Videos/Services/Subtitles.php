<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\SubtitleTrack;
use App\Modules\Videos\Contracts\VideoSubtitles;
use App\Modules\Videos\Events\SubtitlesChanged;
use App\Modules\Videos\Models\Subtitle;
use App\Modules\Videos\Models\Video;
use App\Platform\Api\Errors\ApiProblem;
use Aws\S3\Exception\S3Exception;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Locale;

/**
 * Subtitle tracks (design doc FR-16, §11 step 11, S4-06). The creator uploads the file straight to
 * S3 with a presigned POST (design doc §10.1: small files, size and type enforced), then completes; the Processing module converts it for the playable
 * version (it hears about every change through SubtitlesChanged).
 */
final class Subtitles implements VideoSubtitles
{
    public const MAX_BYTES = 2 * 1024 * 1024;   // same limit as the worker

    public const MAX_PER_VIDEO = 20;

    private const UPLOAD_TTL_MINUTES = 15;

    private const CONTENT_TYPES = ['srt' => 'application/x-subrip', 'vtt' => 'text/vtt'];

    public function __construct(private readonly S3Client $s3) {}

    /**
     * @param  array{language: string, label?: string|null, kind?: string|null, format: string, size_bytes: int}  $input  validated
     * @return array{0: Subtitle, 1: array{method: string, url: string, fields: array<string, string>, expires_at: string}}
     */
    public function create(Video $video, array $input): array
    {
        self::assertEditable($video);
        $language = self::normalizeLanguage($input['language']);
        $kind = $input['kind'] ?? 'subtitles';

        $subtitle = DB::transaction(function () use ($video, $input, $language, $kind) {
            // An upload that was never completed doesn't hold the language forever.
            Subtitle::query()->where('video_id', $video->id)->where('language', $language)->where('kind', $kind)
                ->where('status', 'awaiting_upload')->where('upload_expires_at', '<', now())
                ->update(['status' => 'deleted', 'updated_at' => now()]);

            if (Subtitle::query()->where('video_id', $video->id)->whereIn('status', Subtitle::LIVE)->count() >= self::MAX_PER_VIDEO) {
                throw new ApiProblem(409, 'SUBTITLE_LIMIT', 'This video has the most subtitle tracks allowed',
                    'A video can have '.self::MAX_PER_VIDEO.' tracks. Delete one to add another.');
            }

            $subtitle = new Subtitle([
                'video_id' => $video->id,
                'language' => $language,
                'label' => trim((string) ($input['label'] ?? '')) ?: self::defaultLabel($language),
                'kind' => $kind,
                'format' => $input['format'],
                'size_bytes' => $input['size_bytes'],
                'source_bucket' => (string) config('uploads.bucket'),
                'status' => 'awaiting_upload',
                'upload_expires_at' => now()->addMinutes(self::UPLOAD_TTL_MINUTES),
            ]);
            $subtitle->id = $subtitle->newUniqueId();
            $subtitle->source_key = "captions/{$video->id}/{$subtitle->id}/source";
            try {
                $subtitle->save();
            } catch (UniqueConstraintViolationException) {
                throw new ApiProblem(409, 'SUBTITLE_EXISTS', 'This video already has a track for this language and kind',
                    'Delete it first to upload a replacement.');
            }

            return $subtitle;
        });

        return [$subtitle, $this->presign($subtitle)];
    }

    /** The client PUT the file: check S3 has it, as declared, then publish it with the video. Idempotent. */
    public function complete(Video $video, string $subtitleId): Subtitle
    {
        return DB::transaction(function () use ($video, $subtitleId) {
            $subtitle = $this->owned($video, $subtitleId, lock: true);
            if ($subtitle->status !== 'awaiting_upload') {
                return $subtitle;   // completed before (or failed: the outcome stands)
            }
            self::assertEditable($video);

            $size = $this->objectSize($subtitle->source_bucket, $subtitle->source_key);
            if ($size === null) {
                throw new ApiProblem(409, 'SUBTITLE_NOT_UPLOADED', 'The file has not been uploaded',
                    $subtitle->upload_expires_at->isPast() ? 'The upload URL has expired: delete this track and add it again.' : 'PUT the file to the upload URL first.');
            }
            if ($size !== $subtitle->size_bytes) {
                throw new ApiProblem(422, 'SUBTITLE_SIZE_MISMATCH', 'The uploaded file is not the declared size',
                    "It is {$size} bytes, not {$subtitle->size_bytes}. PUT the right file and complete again.");
            }

            $subtitle->update(['status' => 'processing']);
            event(new SubtitlesChanged($video->id));

            return $subtitle;
        });
    }

    /** @return list<Subtitle> newest first, deleted ones left out */
    public function list(Video $video): array
    {
        return array_values(Subtitle::query()->where('video_id', $video->id)->where('status', '!=', 'deleted')
            ->orderByDesc('created_at')->orderByDesc('id')->get()->all());
    }

    /** Removes the track from the video (players stop offering it once the new revision is published). */
    public function delete(Video $video, string $subtitleId): void
    {
        DB::transaction(function () use ($video, $subtitleId) {
            $subtitle = $this->owned($video, $subtitleId, lock: true);
            $wasPublishable = in_array($subtitle->status, Subtitle::PUBLISHABLE, true);
            $subtitle->update(['status' => 'deleted']);
            if ($wasPublishable) {
                event(new SubtitlesChanged($video->id));
            }
        });
    }

    public function publishable(string $videoId): array
    {
        return array_values(Subtitle::query()->where('video_id', $videoId)->whereIn('status', Subtitle::PUBLISHABLE)
            ->orderBy('created_at')->orderBy('id')->get()->map(self::track(...))->all());
    }

    public function find(string $videoId, array $ids): array
    {
        return Subtitle::query()->where('video_id', $videoId)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Subtitle $s) => [$s->id => self::track($s)])->all();
    }

    public function recordConverted(string $videoId, array $outcomes): void
    {
        foreach ($outcomes as $o) {
            Subtitle::query()->whereKey($o['subtitle_id'])->where('video_id', $videoId)->whereIn('status', Subtitle::PUBLISHABLE)
                ->update([
                    'status' => $o['status'],
                    'error_code' => $o['error_code'],
                    'error_detail' => $o['error_detail'] === null ? null : mb_substr($o['error_detail'], 0, 1000),
                    'updated_at' => now(),
                ]);
            if ($o['status'] === 'failed') {
                Log::info('subtitles.conversion_failed', ['subtitle_id' => $o['subtitle_id'], 'code' => $o['error_code']]);
            }
        }
    }

    /** "en-us" → "en-US", "ZH-hant" → "zh-Hant". */
    public static function normalizeLanguage(string $tag): string
    {
        $parts = explode('-', str_replace('_', '-', trim($tag)));
        foreach ($parts as $i => $part) {
            $parts[$i] = match (true) {
                $i === 0 => strtolower($part),
                strlen($part) === 4 => ucfirst(strtolower($part)),
                default => strtoupper($part),
            };
        }

        return implode('-', $parts);
    }

    /** "pt-BR" → "Português (Brasil)": the language's own name, as players show it. */
    private static function defaultLabel(string $language): string
    {
        $name = Locale::getDisplayName($language, $language);
        if ($name === false) {
            return $language;
        }

        return mb_substr(mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1), 0, 80);
    }

    /**
     * A presigned POST policy, not a PUT: S3 enforces its conditions itself (this exact key and
     * Content-Type, and exactly the declared size), whereas a presigned PUT signs only the host.
     *
     * @return array{method: string, url: string, fields: array<string, string>, expires_at: string}
     */
    private function presign(Subtitle $subtitle): array
    {
        $contentType = self::CONTENT_TYPES[$subtitle->format];
        $expiresAt = CarbonImmutable::instance($subtitle->upload_expires_at);
        $post = new PostObjectV4($this->s3, $subtitle->source_bucket, ['key' => $subtitle->source_key, 'Content-Type' => $contentType], [
            ['bucket' => $subtitle->source_bucket],
            ['eq', '$key', $subtitle->source_key],
            ['eq', '$Content-Type', $contentType],
            ['content-length-range', $subtitle->size_bytes, $subtitle->size_bytes],
        ], $expiresAt->toIso8601ZuluString());

        return [
            'method' => 'POST',
            'url' => $post->getFormAttributes()['action'],
            'fields' => $post->getFormInputs(),
            'expires_at' => $expiresAt->toIso8601ZuluString(),
        ];
    }

    private function objectSize(string $bucket, string $key): ?int
    {
        try {
            return (int) $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key])['ContentLength'];
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    private function owned(Video $video, string $subtitleId, bool $lock = false): Subtitle
    {
        $query = Subtitle::query()->whereKey($subtitleId)->where('video_id', $video->id)->where('status', '!=', 'deleted');

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new ApiProblem(404, 'SUBTITLE_NOT_FOUND', 'Subtitle track not found');
    }

    private static function assertEditable(Video $video): void
    {
        if (in_array($video->status, ['blocked', 'rejected', 'deleted', 'purged'], true)) {
            throw new ApiProblem(409, 'VIDEO_NOT_EDITABLE', 'Subtitles cannot be changed on this video', "The video is {$video->status}.");
        }
    }

    private static function track(Subtitle $s): SubtitleTrack
    {
        return new SubtitleTrack($s->id, $s->language, $s->label, $s->kind, $s->format, $s->source_bucket, $s->source_key);
    }
}
