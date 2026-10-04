<?php

namespace App\Modules\Uploads\Http\Controllers;

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Uploads\Services\UploadSessions;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Platform\Api\Http\CallerId;
use App\Platform\Api\Http\KnownFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Direct-to-S3 multipart uploads (ADR-003). Every endpoint is owner-only. */
final class UploadController
{
    public function __construct(
        private readonly UploadSessions $sessions,
        private readonly VideoDirectory $videos,
    ) {}

    public function store(Request $request, string $video): JsonResponse
    {
        $callerId = CallerId::from($request);
        KnownFields::assert($request, ['size_bytes', 'content_type', 'sha256']);
        $input = $request->validate([
            'size_bytes' => ['bail', 'required', 'integer', 'min:1', 'max:'.config('uploads.max_size_bytes')],
            'content_type' => ['bail', 'required', 'string', Rule::in(config('uploads.content_types'))],
            'sha256' => ['bail', 'sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        $session = $this->sessions->create($callerId, $video, (int) $input['size_bytes'], $input['content_type'], $input['sha256'] ?? null);
        $first = range(1, min($session->total_parts, (int) config('uploads.url_batch_size')));

        return (new JsonResponse([
            ...$this->present($session),
            'uploaded_parts' => [],
            'next_parts' => $this->sessions->urls($session, $first),
        ], 201))->header('Location', url("/v1/uploads/{$session->id}"));
    }

    public function sign(Request $request, string $upload): JsonResponse
    {
        $callerId = CallerId::from($request);
        KnownFields::assert($request, ['part_numbers']);
        $input = $request->validate([
            'part_numbers' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.config('uploads.max_sign_batch')],
            'part_numbers.*' => ['bail', 'integer', 'min:1', 'distinct'],
        ]);

        return new JsonResponse(['parts' => $this->sessions->sign($callerId, $upload, array_values(array_map('intval', $input['part_numbers'])))]);
    }

    public function show(Request $request, string $upload): JsonResponse
    {
        $status = $this->sessions->status(CallerId::from($request), $upload);

        return new JsonResponse([
            ...$this->present($status['session']),
            'uploaded_parts' => $status['uploaded_parts'],
            'next_parts' => $status['next_parts'],
        ]);
    }

    public function destroy(Request $request, string $upload): Response
    {
        $this->sessions->abort(CallerId::from($request), $upload);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(UploadSession $session): array
    {
        return [
            'id' => $session->id,
            'video_id' => $this->videos->find($session->video_id)?->publicId,
            'status' => $session->status,
            'size_bytes' => $session->declared_size_bytes,
            'content_type' => $session->content_type,
            'part_size_bytes' => $session->part_size_bytes,
            'total_parts' => $session->total_parts,
            'expires_at' => $session->expires_at->toIso8601ZuluString(),
            'created_at' => $session->created_at?->toIso8601ZuluString(),
        ];
    }
}
