<?php

namespace App\Platform\Observability;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A SERVER span for every request, continuing the caller's trace when it sends a `traceparent`
 * (the ALB, the web app). Everything the request does, including outbox events it records, joins
 * this trace; the trace id is in every log line (Context) and in the `traceresponse` header.
 */
final class TraceRequests
{
    public function __construct(private readonly Tracing $tracing) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('health/*')) {
            return $next($request);   // load balancer probes every few seconds: not worth a trace
        }

        // Renamed to "METHOD /route" once routing has matched.
        $span = $this->tracing->tracer()->spanBuilder('HTTP '.$request->method())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($this->tracing->parentFrom($request->headers->get('traceparent')))
            ->setAttribute('http.request.method', $request->method())
            ->setAttribute('url.path', '/'.ltrim($request->path(), '/'))
            ->startSpan();
        $scope = $span->activate();
        Context::add('trace_id', $span->getContext()->getTraceId());

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            throw $e;
        } finally {
            // The matched route is only known now: "POST v1/videos/{video}/uploads", not the raw path.
            $route = $request->route()?->uri();
            if ($route !== null) {
                $span->updateName($request->method().' /'.ltrim($route, '/'))->setAttribute('http.route', '/'.ltrim($route, '/'));
            }
            $scope->detach();
        }

        $span->setAttribute('http.response.status_code', $response->getStatusCode());
        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
        $response->headers->set('traceresponse', (string) $this->tracing->traceparentOf($span->getContext()));
        $span->end();
        $this->tracing->flush();

        return $response;
    }
}
