<?php

namespace Tests\Fixtures;

use App\Modules\Users\Models\User;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Pagination\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Test-only endpoint that exercises every API convention: problem errors, request IDs,
 * cursor pagination and idempotency keys. Never registered outside tests.
 */
final class SampleController
{
    public static function routes(): void
    {
        Route::prefix('v1/samples')->group(function () {
            Route::get('/', [self::class, 'index']);
            Route::post('/', [self::class, 'store'])->middleware('idempotent');
            Route::get('/{id}', [self::class, 'show']);
            Route::get('/{id}/crash', [self::class, 'crash']);
        });
    }

    public function index(Request $request): JsonResponse
    {
        return CursorPage::response(CursorPage::paginate(User::query()->orderBy('id'), $request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:20']]);
        Cache::increment('sample-store-calls');

        if ($data['name'] === 'explode') {
            throw new RuntimeException('Simulated failure after side effect');
        }

        $id = (string) Str::uuid7();

        return response()->json(['id' => $id, 'name' => $data['name']], 201, ['Location' => "/v1/samples/{$id}"]);
    }

    public function show(string $id): never
    {
        throw new ApiProblem(404, 'SAMPLE_NOT_FOUND', 'Sample not found', "No sample with id {$id}.");
    }

    public function crash(): never
    {
        throw new RuntimeException('could not connect: password=hunter2');
    }
}
