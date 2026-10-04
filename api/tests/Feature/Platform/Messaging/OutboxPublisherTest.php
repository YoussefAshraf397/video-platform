<?php

use App\Platform\Messaging\OutboxMessage;
use App\Platform\Messaging\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TestEvent;

uses(RefreshDatabase::class);

it('stores the full envelope in the outbox', function () {
    Context::add('trace_id', 'trace-123');

    $id = DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent('video-events', 'vid-9', 4)));

    $row = OutboxMessage::findOrFail($id);
    expect($row->topic)->toBe('video-events')
        ->and($row->published_at)->toBeNull()
        ->and(json_decode($row->envelope, true))->toMatchArray([
            'event_id' => $id,
            'event_type' => 'TestHappened',
            'schema_version' => 1,
            'producer' => 'api',
            'aggregate_type' => 'test',
            'aggregate_id' => 'vid-9',
            'aggregate_version' => 4,
            'trace_id' => 'trace-123',
            'payload' => ['hello' => 'world'],
        ])
        ->and(json_decode($row->envelope, true)['occurred_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/');
});

it('discards the event when the business transaction rolls back', function () {
    try {
        DB::transaction(function () {
            app(OutboxPublisher::class)->publish(new TestEvent('video-events'));
            throw new RuntimeException('business write failed');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::count())->toBe(0);
});

it('refuses events too large for SNS', function () {
    DB::transaction(fn () => app(OutboxPublisher::class)->publish(
        new TestEvent('video-events', payload: ['blob' => str_repeat('x', 262_144)]),
    ));
})->throws(LengthException::class);
