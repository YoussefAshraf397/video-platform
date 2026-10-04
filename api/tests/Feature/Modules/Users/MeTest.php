<?php

use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Accounts;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Auth::createUser('ada@example.com');
    $this->auth = ['Authorization' => 'Bearer '.Auth::login($this)['access_token']];
});

it('returns the signed-in user', function () {
    $this->getJson('/v1/me', $this->auth)
        ->assertOk()
        ->assertExactJson([
            'id' => $this->user->id,
            'email' => 'ada@example.com',
            'email_verified' => true,
            'handle' => null,
            'display_name' => $this->user->display_name,
            'bio' => null,
            'status' => 'active',
            'created_at' => $this->user->created_at->toIso8601ZuluString(),
        ]);
});

it('requires authentication', function () {
    $this->getJson('/v1/me')->assertStatus(401);
    $this->patchJson('/v1/me', ['bio' => 'hi'])->assertStatus(401);
});

it('updates display name, handle and bio, keeping the handle as typed', function () {
    $this->patchJson('/v1/me', ['display_name' => 'Ada L.', 'handle' => 'AdaLovelace', 'bio' => 'First programmer.'], $this->auth)
        ->assertOk()
        ->assertJsonPath('display_name', 'Ada L.')
        ->assertJsonPath('handle', 'AdaLovelace')
        ->assertJsonPath('bio', 'First programmer.');

    $this->patchJson('/v1/me', ['bio' => null], $this->auth)->assertOk()->assertJsonPath('bio', null);
    expect(User::find($this->user->id)->handle)->toBe('AdaLovelace');
});

it('rejects a handle someone else has, in any case', function () {
    Auth::createUser('eve@example.com')->update(['handle' => 'evil']);

    $this->patchJson('/v1/me', ['handle' => 'EVIL'], $this->auth)
        ->assertStatus(422)
        ->assertJsonPath('errors', [['field' => 'handle', 'code' => 'UNIQUE_HANDLE', 'message' => 'This handle is already taken.']]);
});

it('lets you change the case of your own handle', function () {
    $this->patchJson('/v1/me', ['handle' => 'adalovelace'], $this->auth)->assertOk();

    $this->patchJson('/v1/me', ['handle' => 'AdaLovelace'], $this->auth)->assertOk()->assertJsonPath('handle', 'AdaLovelace');
});

it('treats reserved handles as taken', function (string $handle) {
    $this->patchJson('/v1/me', ['handle' => $handle], $this->auth)
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'UNIQUE_HANDLE');
})->with(['admin', 'Support', 'API', 'official', 'Users']);

it('accepts well-formed handles', function (string $handle) {
    $this->patchJson('/v1/me', ['handle' => $handle], $this->auth)->assertOk();
})->with(['ada', 'ada_l', 'ada.lovelace', 'Ada99', '9lives', 'ab_', str_repeat('a', 30)]);

it('rejects malformed handles', function (string $handle) {
    $this->patchJson('/v1/me', ['handle' => $handle], $this->auth)
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'handle')->assertJsonPath('errors.0.code', 'REGEX');
})->with([
    'too short' => 'ab',
    'too long' => str_repeat('a', 31),
    'leading dot' => '.ada',
    'trailing dot' => 'ada.',
    'double dot' => 'ada..l',
    'leading underscore' => '_ada',
    'space' => 'ada l',
    'dash' => 'ada-l',
    'non-ascii' => 'adá',
    'at sign' => '@ada',
]);

it('rejects fields that cannot be changed here', function () {
    $this->patchJson('/v1/me', ['email' => 'new@example.com', 'status' => 'active', 'bio' => 'x'], $this->auth)
        ->assertStatus(422)
        ->assertJsonPath('errors.*.code', ['UNKNOWN_FIELD', 'UNKNOWN_FIELD'])
        ->assertJsonPath('errors.*.field', ['email', 'status']);

    expect(User::find($this->user->id))->email->toBe('ada@example.com')->bio->toBeNull();
});

it('validates lengths', function () {
    $this->patchJson('/v1/me', ['display_name' => '', 'bio' => str_repeat('x', 501)], $this->auth)
        ->assertStatus(422)
        ->assertJsonPath('errors.*.field', ['display_name', 'bio'])
        ->assertJsonPath('errors.*.code', ['REQUIRED', 'MAX']);
});

it('reports a handle taken between validation and save as a validation error', function () {
    // Bypasses the validation rule to reach the database unique index, as a concurrent request would.
    DB::table('users')->where('email', '!=', 'ada@example.com')->delete();
    Auth::createUser('eve@example.com')->update(['handle' => 'Race']);

    try {
        app(Accounts::class)->updateProfile($this->user->id, ['handle' => 'race']);
        $this->fail('Expected a validation problem');
    } catch (ApiProblem $problem) {
        expect($problem->status)->toBe(422)
            ->and($problem->errors[0])->toMatchArray(['field' => 'handle', 'code' => 'UNIQUE_HANDLE']);
    }
});
