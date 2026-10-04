package telemetry

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"testing"

	"go.opentelemetry.io/otel"
)

func TestSetupTracingInstallsAProviderAndShutsDown(t *testing.T) {
	shutdown, err := SetupTracing(context.Background(), "test")
	if err != nil {
		t.Fatalf("SetupTracing: %v", err)
	}
	_, span := otel.Tracer("test").Start(context.Background(), "span")
	if !span.SpanContext().TraceID().IsValid() {
		t.Error("spans have no trace ID; the SDK provider was not installed")
	}
	span.End()
	if err := shutdown(context.Background()); err != nil {
		t.Errorf("shutdown: %v", err)
	}
}

func TestLoggerWritesJSONWithServiceFields(t *testing.T) {
	var buf bytes.Buffer
	NewLogger(&buf, slog.LevelInfo, "v1").Info("hello", "video_id", "abc")

	var line map[string]any
	if err := json.Unmarshal(buf.Bytes(), &line); err != nil {
		t.Fatalf("not JSON: %s", buf.String())
	}
	for key, want := range map[string]string{"service": ServiceName, "version": "v1", "msg": "hello", "video_id": "abc"} {
		if line[key] != want {
			t.Errorf("%s = %v, want %s", key, line[key], want)
		}
	}
}
