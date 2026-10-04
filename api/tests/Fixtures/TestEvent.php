<?php

namespace Tests\Fixtures;

use App\Platform\Messaging\OutboxEvent;

final class TestEvent implements OutboxEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly string $topic,
        private readonly string $aggregateId = 'agg-1',
        private readonly int $version = 1,
        private readonly array $payload = ['hello' => 'world'],
    ) {}

    public function topic(): string
    {
        return $this->topic;
    }

    public function eventType(): string
    {
        return 'TestHappened';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'test';
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function aggregateVersion(): int
    {
        return $this->version;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
