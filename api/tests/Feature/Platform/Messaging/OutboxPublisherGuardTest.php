<?php

use App\Platform\Messaging\OutboxPublisher;
use Tests\Fixtures\TestEvent;

// Separate file without RefreshDatabase, whose wrapping transaction would hide this mistake.
it('refuses to publish outside a database transaction', function () {
    app(OutboxPublisher::class)->publish(new TestEvent('video-events'));
})->throws(LogicException::class, 'must run inside the transaction');
