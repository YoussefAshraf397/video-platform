package sqsworker

import (
	"context"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"github.com/aws/aws-sdk-go-v2/service/sqs/types"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/propagation"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/sdk/trace/tracetest"
	"go.opentelemetry.io/otel/trace"
)

// recordSpans installs a tracer provider that keeps finished spans in memory.
func recordSpans(t *testing.T) *tracetest.SpanRecorder {
	t.Helper()
	recorder := tracetest.NewSpanRecorder()
	previous, previousProp := otel.GetTracerProvider(), otel.GetTextMapPropagator()
	otel.SetTracerProvider(sdktrace.NewTracerProvider(sdktrace.WithSpanProcessor(recorder)))
	otel.SetTextMapPropagator(propagation.TraceContext{})
	t.Cleanup(func() {
		otel.SetTracerProvider(previous)
		otel.SetTextMapPropagator(previousProp)
	})
	return recorder
}

// TestContinuesTheProducersTrace is the worker's part of S3-10: the job runs in the trace the
// api started (traceparent arrives as an SQS message attribute from the outbox relay).
func TestContinuesTheProducersTrace(t *testing.T) {
	recorder := recordSpans(t)
	q := newQueue(t)
	const traceID, parentID = "0af7651916cd43dd8448eb211c80319c", "b7ad6b7169203331"
	if _, err := q.client.SendMessage(context.Background(), &sqs.SendMessageInput{
		QueueUrl: &q.url, MessageBody: aws.String("job"),
		MessageAttributes: map[string]types.MessageAttributeValue{
			"traceparent": {DataType: aws.String("String"), StringValue: aws.String("00-" + traceID + "-" + parentID + "-01")},
		},
	}); err != nil {
		t.Fatal(err)
	}

	handled := make(chan struct{})
	var inHandler trace.SpanContext
	shutdown := start(q, HandlerFunc(func(ctx context.Context, _ Message) error {
		inHandler = trace.SpanContextFromContext(ctx)
		close(handled)
		return nil
	}), testOptions())
	waitFor(t, handled)
	shutdown()

	spans := recorder.Ended()
	if len(spans) != 1 {
		t.Fatalf("got %d spans", len(spans))
	}
	span := spans[0]
	if span.SpanContext().TraceID().String() != traceID || span.Parent().SpanID().String() != parentID || span.SpanKind() != trace.SpanKindConsumer {
		t.Errorf("span trace %s parent %s kind %s; want trace %s, parent %s, consumer",
			span.SpanContext().TraceID(), span.Parent().SpanID(), span.SpanKind(), traceID, parentID)
	}
	if inHandler.SpanID() != span.SpanContext().SpanID() {
		t.Error("the handler doesn't run inside the consumer span, so work it does would start a new trace")
	}
}

func TestStartsANewTraceWithoutContext(t *testing.T) {
	recorder := recordSpans(t)
	q := newQueue(t)
	q.send("job")
	handled := make(chan struct{})

	shutdown := start(q, HandlerFunc(func(context.Context, Message) error { close(handled); return nil }), testOptions())
	waitFor(t, handled)
	shutdown()

	if spans := recorder.Ended(); len(spans) != 1 || spans[0].Parent().IsValid() || !spans[0].SpanContext().IsValid() {
		t.Fatalf("want one root span, got %+v", spans)
	}
}
