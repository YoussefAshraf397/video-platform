<?php

namespace App\Modules\Videos\Http\Controllers;

use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\Videos;
use App\Platform\Api\Http\CallerId;
use App\Platform\Api\Http\KnownFields;
use App\Platform\Api\Http\Preconditions;
use App\Platform\Api\Pagination\CursorPage;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;

/**
 * Video metadata. Writes are owner-only; reads follow the visibility rules in Video::isVisibleToPublic().
 * Responses carry an ETag; PATCH requires it back in If-Match.
 */
final class VideoController
{
    private const FIELDS = ['title', 'description', 'tags', 'category', 'language', 'visibility', 'age_restricted', 'made_for_kids', 'comments_enabled'];

    public function __construct(private readonly Videos $videos) {}

    public function store(Request $request): JsonResponse
    {
        $ownerId = CallerId::from($request);
        KnownFields::assert($request, self::FIELDS);
        $video = $this->videos->create($ownerId, $request->validate($this->rules(creating: true)));

        return $this->respond($video, 201)->header('Location', url("/v1/videos/{$video->public_id}"));
    }

    public function show(Request $request, string $video): JsonResponse
    {
        $found = $this->videos->findVisible($video, CallerId::tryFrom($request)) ?? throw Videos::notFound();

        return $this->respond($found);
    }

    #[HeaderParameter('If-Match', 'The ETag from your last read of this video.', required: true, example: '"3"')]
    #[Response(404, 'VIDEO_NOT_FOUND: no such video, or you cannot see it', 'application/problem+json')]
    #[Response(403, 'NOT_VIDEO_OWNER: you can see the video but it is not yours', 'application/problem+json')]
    #[Response(412, 'PRECONDITION_FAILED: the video changed since your read; fetch it again and reapply', 'application/problem+json')]
    #[Response(428, 'PRECONDITION_REQUIRED: If-Match is missing', 'application/problem+json')]
    public function update(Request $request, string $video): JsonResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));
        KnownFields::assert($request, self::FIELDS);
        $changes = $request->validate($this->rules(creating: false));

        return $this->respond($this->videos->update($owned, Preconditions::expectedVersion($request), $changes));
    }

    #[HeaderParameter('If-Match', 'Optional. When sent, the delete only happens if the video is still at this version.', required: false, example: '"3"')]
    #[Response(404, 'VIDEO_NOT_FOUND', 'application/problem+json')]
    #[Response(403, 'NOT_VIDEO_OWNER', 'application/problem+json')]
    #[Response(412, 'PRECONDITION_FAILED', 'application/problem+json')]
    public function destroy(Request $request, string $video): HttpResponse
    {
        $owned = $this->videos->findOwnedModel($video, CallerId::from($request));
        $this->videos->delete($owned, $request->hasHeader('If-Match') ? Preconditions::expectedVersion($request) : null);

        return response()->noContent();
    }

    /** The caller's own videos in every status, newest first. */
    public function mine(Request $request): JsonResponse
    {
        $query = Video::query()->with('category')
            ->where('uploader_user_id', CallerId::from($request))
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorPage::response(CursorPage::paginate($query, $request), function (array $videos) {
            /** @var list<Video> $videos */
            $tags = $this->videos->tagsFor(array_map(fn (Video $v) => $v->id, $videos));

            return array_map(fn (Video $v) => self::present($v, $tags[$v->id]), $videos);
        });
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $presence = $creating ? [] : ['sometimes'];
        $noControlChars = 'not_regex:/\p{Cc}/u';

        return [
            // bail: one problem per field
            'title' => ['bail', ...$presence, 'required', 'string', 'max:100', $noControlChars],
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['bail', 'sometimes', 'nullable', 'array', 'list', 'max:'.Videos::MAX_TAGS],
            'tags.*' => ['bail', 'required', 'string', 'max:'.Videos::MAX_TAG_LENGTH, $noControlChars],
            'category' => ['bail', 'sometimes', 'nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'language' => ['bail', 'sometimes', 'nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/'],
            'visibility' => ['bail', 'sometimes', 'required', Rule::in(Video::VISIBILITIES)],
            'age_restricted' => ['sometimes', 'required', 'boolean:strict'],
            'made_for_kids' => ['sometimes', 'required', 'boolean:strict'],
            'comments_enabled' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }

    private function respond(Video $video, int $status = 200): JsonResponse
    {
        return (new JsonResponse(self::present($video, $this->videos->tagsFor([$video->id])[$video->id]), $status))
            ->header('ETag', Preconditions::etag($video->state_version));
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private static function present(Video $video, array $tags): array
    {
        return [
            'id' => $video->public_id,
            'title' => $video->title,
            'description' => $video->description,
            'tags' => $tags,
            'category' => $video->category ? ['slug' => $video->category->slug, 'name' => $video->category->name] : null,
            'language' => $video->language,
            'visibility' => $video->visibility,
            'status' => $video->status,
            'age_restricted' => $video->age_restricted,
            'made_for_kids' => $video->made_for_kids,
            'comments_enabled' => $video->comments_enabled,
            'published_at' => $video->published_at?->toIso8601ZuluString(),
            'created_at' => $video->created_at?->toIso8601ZuluString(),
            'updated_at' => $video->updated_at?->toIso8601ZuluString(),
            'etag' => Preconditions::etag($video->state_version),
        ];
    }
}
