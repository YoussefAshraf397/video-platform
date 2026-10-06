<?php

namespace App\Platform\Observability;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Tracing for every module: one tracer, and W3C trace context in and out of carriers (HTTP
 * headers, outbox rows, SQS message attributes).
 */
final class Tracing
{
    public function __construct(private readonly TracerProviderInterface $provider) {}

    public function tracer(): TracerInterface
    {
        return $this->provider->getTracer('videoplatform/api');
    }

    /**
     * The current span's `traceparent`, or null when there is no valid span.
     */
    public function currentTraceparent(): ?string
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return $carrier['traceparent'] ?? null;
    }

    /** The `traceparent` of a given span context (e.g. for the `traceresponse` header). */
    public function traceparentOf(SpanContextInterface $context): ?string
    {
        if (! $context->isValid()) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $context->getTraceId(), $context->getSpanId(), $context->isSampled() ? '01' : '00');
    }

    /** The current span's trace id (32 hex), for logs and envelopes; null outside a span. */
    public function currentTraceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    /** A parent context from a `traceparent` (an invalid or missing one gives a new root). */
    public function parentFrom(?string $traceparent): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract($traceparent ? ['traceparent' => $traceparent] : []);
    }

    /** Sends buffered spans now (end of a request or a CLI batch). */
    public function flush(): void
    {
        $this->provider->forceFlush();
    }
}
