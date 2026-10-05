<?php

namespace App\Platform\Messaging;

/**
 * Anything `messages:consume` can feed SQS messages to. Domain events use IdempotentConsumer;
 * implement this directly only for foreign message formats (e.g. S3 event notifications) whose
 * handling is idempotent by itself.
 */
interface MessageConsumer
{
    /** Stable consumer name. It is also the name of the SQS queue it reads. */
    public function name(): string;

    /**
     * @param  array<string, mixed>  $message  the decoded SQS message body
     * @return bool false when there was nothing to do (e.g. a duplicate)
     */
    public function handle(array $message): bool;
}
