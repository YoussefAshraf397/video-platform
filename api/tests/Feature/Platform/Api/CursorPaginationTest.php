<?php

use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SampleController;

uses(RefreshDatabase::class);

beforeEach(fn () => SampleController::routes());

it('walks every row exactly once across pages', function () {
    $ids = User::factory()->count(5)->create()->pluck('id')->sort()->values()->all();   // the order the query uses

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $this->getJson('/v1/samples?limit=2'.($cursor ? "&cursor={$cursor}" : ''))->assertOk()->json();
        $seen = [...$seen, ...array_column($page['items'], 'id')];
        $cursor = $page['next_cursor'];
        $pages++;
    } while ($page['has_more']);

    expect($seen)->toBe($ids)
        ->and($pages)->toBe(3)
        ->and($cursor)->toBeNull();
});

it('defaults to 20 items per page', function () {
    User::factory()->count(21)->create();

    $this->getJson('/v1/samples')
        ->assertOk()
        ->assertJsonCount(20, 'items')
        ->assertJsonPath('has_more', true);
});

it('rejects a limit above the maximum', function () {
    $this->getJson('/v1/samples?limit=101')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'limit')
        ->assertJsonPath('errors.0.code', 'MAX');
});

it('rejects a malformed cursor instead of silently restarting', function () {
    $this->getJson('/v1/samples?cursor=not-a-cursor')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CURSOR');
});
