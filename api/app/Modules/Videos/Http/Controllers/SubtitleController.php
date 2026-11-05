<?php

namespace App\Modules\Videos\Http\Controllers;

use App\Modules\Videos\Models\Subtitle;
use App\Modules\Videos\Services\Subtitles;
use App\Modules\Videos\Services\Videos;
use App\Platform\Api\Http\CallerId;
use App\Platform\Api\Http\KnownFields;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;

/**
 * Subtitle tracks of a video (S4-06), owner only. Adding one is two calls around a direct upload:
 * POST returns a presigned S3 form upload (`upload.url` + `upload.fields`); the client POSTs
 * `multipart/form-data` with every field, then the SRT or WebVTT file as `file` (last), and calls
 * `:complete`. S3 refuses any other key, type or size. The track is converted and added to the video's playlist (status `processing`, then
 * `ready`, or `failed` with the reason).
 */
final class SubtitleController
{
    public function __construct(private readonly Videos $videos, private readonly Subtitles $subtitles) {}

    #[Response(409, 'SUBTITLE_EXISTS (delete the old one first), SUBTITLE_LIMIT (20 per video), VIDEO_NOT_EDITABLE (blocked or deleted)', 'application/problem+json')]
    public function store(Request $request, string $video): JsonResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));
        KnownFields::assert($request, ['language', 'label', 'kind', 'format', 'size_bytes']);
        $input = $request->validate([
            /** BCP 47 language tag, e.g. `en`, `pt-BR`, `zh-Hant`. Normalized (`en-us` → `en-US`). */
            'language' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}([-_][A-Za-z]{4})?([-_]([A-Za-z]{2}|[0-9]{3}))?$/'],
            /** Shown in players' menus. Defaults to the language's own name (e.g. "Português (Brasil)"). */
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            /** `captions` also describe sounds and music, for deaf and hard-of-hearing viewers. Default `subtitles`. */
            'kind' => ['sometimes', 'nullable', Rule::in(['subtitles', 'captions'])],
            'format' => ['required', Rule::in(['srt', 'vtt'])],
            /** Exact size of the file, at most 2 MiB. The upload accepts only this size. */
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.Subtitles::MAX_BYTES],
        ]);

        [$subtitle, $upload] = $this->subtitles->create($owned, $input);

        return new JsonResponse([...self::present($subtitle), 'upload' => $upload], 201);
    }

    #[Response(409, 'SUBTITLE_NOT_UPLOADED: PUT the file first', 'application/problem+json')]
    #[Response(422, 'SUBTITLE_SIZE_MISMATCH: the file is not the declared size', 'application/problem+json')]
    public function complete(Request $request, string $video, string $subtitle): JsonResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));

        return new JsonResponse(self::present($this->subtitles->complete($owned, $subtitle)));
    }

    public function index(Request $request, string $video): JsonResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));

        return new JsonResponse(['items' => array_map(self::present(...), $this->subtitles->list($owned))]);
    }

    public function destroy(Request $request, string $video, string $subtitle): HttpResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));
        $this->subtitles->delete($owned, $subtitle);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private static function present(Subtitle $s): array
    {
        return [
            'id' => $s->id,
            'language' => $s->language,
            'label' => $s->label,
            'kind' => $s->kind,
            'format' => $s->format,
            'size_bytes' => $s->size_bytes,
            'status' => $s->status,
            // The converter's reason, e.g. CAPTIONS_INVALID "line 14: expected a cue timing line".
            'error' => $s->error_code === null ? null : ['code' => $s->error_code, 'detail' => $s->error_detail],
            'created_at' => $s->created_at?->toIso8601ZuluString(),
            'updated_at' => $s->updated_at?->toIso8601ZuluString(),
        ];
    }
}
