package results

import (
	"context"
	"strings"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/propagation"
	"go.opentelemetry.io/otel/trace"

	"videoplatform/contracts"
)

type capture struct{ in *sqs.SendMessageInput }

func (c *capture) SendMessage(_ context.Context, in *sqs.SendMessageInput, _ ...func(*sqs.Options)) (*sqs.SendMessageOutput, error) {
	c.in = in
	return &sqs.SendMessageOutput{}, nil
}

func TestResultsCarryTheJobsTrace(t *testing.T) {
	previous := otel.GetTextMapPropagator()
	otel.SetTextMapPropagator(propagation.TraceContext{})
	t.Cleanup(func() { otel.SetTextMapPropagator(previous) })

	validator, err := contracts.NewValidator()
	if err != nil {
		t.Fatal(err)
	}
	sender := &capture{}
	traceID, _ := trace.TraceIDFromHex("0af7651916cd43dd8448eb211c80319c")
	spanID, _ := trace.SpanIDFromHex("00f067aa0ba902b7")
	ctx := trace.ContextWithSpanContext(context.Background(), trace.NewSpanContext(trace.SpanContextConfig{TraceID: traceID, SpanID: spanID, TraceFlags: trace.FlagsSampled}))
	job := Job{VideoID: "0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01", JobID: "0199b1f2-2222-7ccc-8ddd-0e0e0e0e0e01", ProcessingVersion: 1}

	if err := New(sender, "queue", validator).Failed(ctx, job, "probe", "NOT_A_VIDEO", "nope", false); err != nil {
		t.Fatal(err)
	}

	if got := aws.ToString(sender.in.MessageAttributes["traceparent"].StringValue); got != "00-0af7651916cd43dd8448eb211c80319c-00f067aa0ba902b7-01" {
		t.Errorf("traceparent attribute %q", got)
	}
	if !strings.Contains(aws.ToString(sender.in.MessageBody), `"trace_id":"0af7651916cd43dd8448eb211c80319c"`) {
		t.Errorf("envelope trace_id missing: %s", aws.ToString(sender.in.MessageBody))
	}
}
