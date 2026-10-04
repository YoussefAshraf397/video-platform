<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

it('shows a public profile by handle, case-insensitively, without private fields', function () {
    $user = Auth::createUser('ada@example.com');
    $user->update(['handle' => 'AdaLovelace', 'display_name' => 'Ada', 'bio' => 'First programmer.']);

    $this->getJson('/v1/users/adalovelace')
        ->assertOk()
        ->assertExactJson([
            'handle' => 'AdaLovelace',
            'display_name' => 'Ada',
            'bio' => 'First programmer.',
            'created_at' => $user->created_at->toIso8601ZuluString(),
        ]);
});

it('returns 404 for unknown handles', function () {
    $this->getJson('/v1/users/nobody')->assertStatus(404)->assertJsonPath('code', 'USER_NOT_FOUND');
});

it('hides suspended and deleted accounts', function (string $status) {
    Auth::createUser('ada@example.com', status: $status)->update(['handle' => 'ada']);

    $this->getJson('/v1/users/ada')->assertStatus(404)->assertJsonPath('code', 'USER_NOT_FOUND');
})->with(['suspended', 'deleted']);
