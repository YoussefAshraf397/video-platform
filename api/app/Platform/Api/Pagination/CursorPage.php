<?php

namespace App\Platform\Api\Pagination;

use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;

/**
 * Cursor pagination for list endpoints: `?limit=` (1-100, default 20) and `?cursor=`, answered
 * as `{items, next_cursor, has_more}`.
 *
 * Built on Laravel's cursorPaginate(). The query's ORDER BY must end with a unique column
 * (normally `id`) and must be backed by an index, or pages can skip or repeat rows.
 */
final class CursorPage
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /** @return CursorPaginator<int, mixed> */
    public static function paginate(Builder $query, Request $request, int $defaultLimit = self::DEFAULT_LIMIT): CursorPaginator
    {
        $params = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ])->validate();

        if (isset($params['cursor']) && Cursor::fromEncoded($params['cursor']) === null) {
            throw new ApiProblem(422, 'INVALID_CURSOR', 'The cursor is invalid', 'Start again from the first page.');
        }

        return $query->cursorPaginate(
            perPage: (int) ($params['limit'] ?? $defaultLimit),
            cursor: $params['cursor'] ?? null,
        );
    }

    /**
     * @param  CursorPaginator<int, mixed>  $page
     * @param  class-string<JsonResource>|null  $resource
     */
    public static function response(CursorPaginator $page, ?string $resource = null): JsonResponse
    {
        return new JsonResponse([
            'items' => $resource ? $resource::collection($page->items())->resolve() : $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
        ]);
    }
}
