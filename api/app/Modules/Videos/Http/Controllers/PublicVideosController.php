<?php

namespace App\Modules\Videos\Http\Controllers;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Videos\Models\Category;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoTiles;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Pagination\CursorPage;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public video listings, no sign-in needed. Only published, public, non-age-restricted,
 * unblocked videos appear, newest first.
 */
final class PublicVideosController
{
    public function __construct(private readonly VideoTiles $tiles, private readonly UserDirectory $users) {}

    /** Latest videos, optionally within one category (an unknown category lists nothing). */
    public function latest(Request $request): JsonResponse
    {
        $query = self::listable();
        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $query->whereIn('category_id', Category::query()->where('slug', $category)->select('id'));
        }

        return $this->respond($query, $request);
    }

    /** One creator's videos, by handle (case-insensitive). */
    #[Response(404, 'USER_NOT_FOUND: no such creator', 'application/problem+json')]
    public function byCreator(Request $request, string $handle): JsonResponse
    {
        $user = $this->users->findByHandle($handle) ?? throw new ApiProblem(404, 'USER_NOT_FOUND', 'User not found');

        return $this->respond(self::listable()->where('uploader_user_id', $user->id), $request);
    }

    private static function listable(): Builder
    {
        return Video::query()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereNull('deleted_at')
            ->where('age_restricted', false)
            ->where('moderation_status', '!=', 'blocked')
            ->orderByDesc('published_at')->orderByDesc('id');
    }

    private function respond(Builder $query, Request $request): JsonResponse
    {
        return CursorPage::response(CursorPage::paginate($query, $request), function (array $videos) {
            /** @var list<Video> $videos */
            return $this->tiles->present($videos);
        });
    }
}
