<?php

namespace App\Platform\Observability;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SemConv\ResourceAttributes;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TracerProviderInterface::class, function () {
            $provider = new TracerProvider(
                array_filter([$this->processor((string) config('observability.exporter'))]),
                resource: ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
                    ResourceAttributes::SERVICE_NAME => (string) config('observability.service_name'),
                    ResourceAttributes::SERVICE_VERSION => (string) config('observability.service_version'),
                    ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => (string) config('app.env'),
                ]))),
            );
            // Long-lived processes (Octane, consumers) flush per unit of work; this catches the rest.
            register_shutdown_function(fn () => $provider->shutdown());

            return $provider;
        });
        $this->app->singleton(Tracing::class);
    }

    private function processor(string $exporter): ?SpanProcessorInterface
    {
        return match ($exporter) {
            'none' => null,
            'otlp' => new BatchSpanProcessor((new SpanExporterFactory)->create(), Clock::getDefault()),
            'console' => new SimpleSpanProcessor(new ConsoleSpanExporter(
                (new StreamTransportFactory)->create('php://stderr', 'application/json'),
            )),
            default => throw new InvalidArgumentException("OTEL_TRACES_EXPORTER must be otlp, console or none, not [{$exporter}]"),
        };
    }
}
