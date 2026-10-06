<?php

namespace App\Platform\Messaging;

use App\Platform\Observability\Tracing;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Feeds SQS messages (raw JSON bodies; envelopes per ADR-005 for domain events) to a MessageConsumer. A message is
 * deleted only after it was handled. On failure it is left in the queue, becomes visible again
 * after the visibility timeout, and moves to the queue's DLQ after 5 receives.
 */
final class SqsConsumerRunner
{
    public function __construct(
        private readonly SqsClient $sqs,
        private readonly Tracing $tracing,
    ) {}

    public function queueUrl(string $queueName): string
    {
        return $this->sqs->getQueueUrl(['QueueName' => $queueName])->get('QueueUrl');
    }

    /** @return int how many messages were received */
    public function pollOnce(MessageConsumer $consumer, string $queueUrl, int $waitSeconds = 20): int
    {
        $messages = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => $waitSeconds,
            'MessageAttributeNames' => ['traceparent'],
        ])->get('Messages') ?? [];

        foreach ($messages as $message) {
            // A CONSUMER span continuing the producer's trace (the traceparent message attribute);
            // anything handle() records, including new outbox events, joins the same trace.
            $span = $this->tracing->tracer()->spanBuilder("process {$consumer->name()}")
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($this->tracing->parentFrom($message['MessageAttributes']['traceparent']['StringValue'] ?? null))
                ->setAttribute('messaging.system', 'aws_sqs')
                ->setAttribute('messaging.destination.name', $consumer->name())
                ->setAttribute('messaging.message.id', (string) $message['MessageId'])
                ->startSpan();
            $scope = $span->activate();

            try {
                $envelope = json_decode($message['Body'], true, flags: JSON_THROW_ON_ERROR);
                Context::add(['event_id' => $envelope['event_id'] ?? null, 'trace_id' => $span->getContext()->getTraceId()]);

                $consumer->handle($envelope);
                $this->sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
            } catch (Throwable $e) {
                $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
                Log::error('Message processing failed; SQS will redeliver it, then move it to the DLQ', [
                    'consumer' => $consumer->name(),
                    'sqs_message_id' => $message['MessageId'],
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Context::forget(['event_id', 'trace_id']);
                $scope->detach();
                $span->end();
            }
        }

        $this->tracing->flush();

        return count($messages);
    }
}
