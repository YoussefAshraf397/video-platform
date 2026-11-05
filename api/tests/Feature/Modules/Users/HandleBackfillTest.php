<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('gives every user without a handle a unique one in created_at order, then requires handles', function () {
    $migration = require base_path('app/Modules/Users/Database/Migrations/2026_11_06_000001_backfill_user_handles.php');
    $migration->down();   // RefreshDatabase already ran it; reopen the column to seed legacy rows

    foreach (['second@example.com' => '2026-01-02', 'first@example.com' => '2026-01-01'] as $email => $createdAt) {
        DB::table('users')->insert([
            'id' => (string) Str::uuid7(), 'email' => $email, 'handle' => null, 'display_name' => 'Ada',
            'status' => 'active', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    $migration->up();

    expect(DB::table('users')->where('email', 'first@example.com')->value('handle'))->toBe('ada')
        ->and(DB::table('users')->where('email', 'second@example.com')->value('handle'))->toBe('ada.2')
        ->and(DB::selectOne("select is_nullable from information_schema.columns where table_name = 'users' and column_name = 'handle'")->is_nullable)->toBe('NO');
});

it('backfills more users than one chunk, leaving none without a handle', function () {
    $migration = require base_path('app/Modules/Users/Database/Migrations/2026_11_06_000001_backfill_user_handles.php');
    $migration->down();

    $rows = [];
    for ($i = 0; $i < 2500; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid7(), 'email' => "user{$i}@example.com", 'handle' => null, 'display_name' => 'Ada',
            'status' => 'active', 'created_at' => now()->addSeconds($i), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('users')->insert($chunk);
    }

    $migration->up();

    expect(DB::table('users')->whereNull('handle')->count())->toBe(0)
        ->and(DB::table('users')->distinct()->count(DB::raw('lower(handle)')))->toBe(2500)
        ->and(DB::selectOne("select is_nullable from information_schema.columns where table_name = 'users' and column_name = 'handle'")->is_nullable)->toBe('NO');
});
