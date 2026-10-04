<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('reports liveness without checking dependencies', function () {
    DB::shouldReceive('connection')->never();

    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('reports ready when the database and redis are reachable', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
});

it('reports not ready when the database is unreachable', function () {
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'checks' => ['database' => 'fail', 'redis' => 'ok']]);
});

it('reports not ready when redis is unreachable', function () {
    config(['database.redis.default.port' => 1]);
    Redis::purge('default');

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'checks' => ['database' => 'ok', 'redis' => 'fail']]);
});
