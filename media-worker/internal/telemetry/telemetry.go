// Package telemetry sets up structured logging and OpenTelemetry tracing.
package telemetry

import (
	"context"
	"io"
	"log/slog"
	"os"

	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp"
	"go.opentelemetry.io/otel/exporters/stdout/stdouttrace"
	"go.opentelemetry.io/otel/propagation"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	semconv "go.opentelemetry.io/otel/semconv/v1.37.0"
)

// ServiceName identifies this service in logs and traces.
const ServiceName = "media-worker"

// NewLogger returns a JSON logger, the format CloudWatch Logs Insights queries expect.
func NewLogger(w io.Writer, level slog.Level, version string) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{Level: level})).
		With("service", ServiceName, "version", version)
}

// SetupTracing installs the global tracer provider. Spans are exported over OTLP/HTTP when
// OTEL_EXPORTER_OTLP_ENDPOINT (or OTEL_EXPORTER_OTLP_TRACES_ENDPOINT) is set, or printed to
// stderr with OTEL_TRACES_EXPORTER=console; otherwise they are created but not exported, so
// trace IDs still appear in logs. Call the returned
// function on shutdown to flush pending spans.
func SetupTracing(ctx context.Context, version string) (func(context.Context) error, error) {
	// resource.New (not Merge with resource.Default) avoids failing when the SDK's default
	// semantic-convention schema differs from the version of the semconv package used here.
	res, err := resource.New(ctx,
		resource.WithFromEnv(),
		resource.WithTelemetrySDK(),
		resource.WithAttributes(semconv.ServiceName(ServiceName), semconv.ServiceVersion(version)),
	)
	if err != nil {
		return nil, err
	}

	opts := []sdktrace.TracerProviderOption{sdktrace.WithResource(res)}
	switch {
	case os.Getenv("OTEL_TRACES_EXPORTER") == "console":
		// Local debugging: one JSON span per line on stderr, like the api's console exporter.
		exporter, err := stdouttrace.New(stdouttrace.WithWriter(os.Stderr))
		if err != nil {
			return nil, err
		}
		opts = append(opts, sdktrace.WithSyncer(exporter))
	case os.Getenv("OTEL_EXPORTER_OTLP_ENDPOINT") != "" || os.Getenv("OTEL_EXPORTER_OTLP_TRACES_ENDPOINT") != "":
		exporter, err := otlptracehttp.New(ctx)
		if err != nil {
			return nil, err
		}
		opts = append(opts, sdktrace.WithBatcher(exporter))
	}

	provider := sdktrace.NewTracerProvider(opts...)
	otel.SetTracerProvider(provider)
	otel.SetTextMapPropagator(propagation.TraceContext{})
	return provider.Shutdown, nil
}
