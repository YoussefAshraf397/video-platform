// Package sqsworker runs a Handler for every message in an SQS queue.
//
// While a message is being handled its visibility timeout is extended periodically
// (heartbeat), so long jobs are never given to a second worker. A message is deleted only
// after the handler succeeds. On shutdown the worker stops receiving at once, lets running
// jobs finish within a grace period, and otherwise cancels them and releases their messages
// so another worker can pick them up immediately.
package sqsworker

import (
	"context"
	"errors"
	"log/slog"
	"strconv"
	"sync"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"github.com/aws/aws-sdk-go-v2/service/sqs/types"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/trace"
)

// SQS is the subset of the SQS client the worker uses.
type SQS interface {
	ReceiveMessage(ctx context.Context, in *sqs.ReceiveMessageInput, opts ...func(*sqs.Options)) (*sqs.ReceiveMessageOutput, error)
	DeleteMessage(ctx context.Context, in *sqs.DeleteMessageInput, opts ...func(*sqs.Options)) (*sqs.DeleteMessageOutput, error)
	ChangeMessageVisibility(ctx context.Context, in *sqs.ChangeMessageVisibilityInput, opts ...func(*sqs.Options)) (*sqs.ChangeMessageVisibilityOutput, error)
}

// Message is one SQS message handed to a Handler.
type Message struct {
	ID           string
	Body         string
	ReceiveCount int // 1 on first delivery
}

// Handler processes one message. Returning nil deletes the message. Returning an error
// leaves it in the queue to be retried (and moved to the DLQ after the queue's
// maxReceiveCount); wrap the error with Poison when retrying cannot help.
type Handler interface {
	Handle(ctx context.Context, msg Message) error
}

// HandlerFunc adapts a function to Handler.
type HandlerFunc func(ctx context.Context, msg Message) error

// Handle calls f.
func (f HandlerFunc) Handle(ctx context.Context, msg Message) error { return f(ctx, msg) }

type poisonError struct{ err error }

func (e poisonError) Error() string { return "poison message: " + e.err.Error() }
func (e poisonError) Unwrap() error { return e.err }

// Poison marks a message that can never be processed (e.g. it breaks its contract). It is
// released immediately instead of after a backoff, so it reaches the DLQ quickly.
func Poison(err error) error { return poisonError{err} }

// Options configures a Worker.
type Options struct {
	Concurrency       int
	VisibilityTimeout time.Duration
	HeartbeatInterval time.Duration
	ShutdownGrace     time.Duration
	// WaitTime is the long-poll duration of each receive call (max 20 s).
	WaitTime time.Duration
}

// Worker consumes one queue.
type Worker struct {
	sqs      SQS
	queueURL string
	handler  Handler
	opts     Options
	log      *slog.Logger
	tracer   trace.Tracer
}

// New creates a Worker.
func New(client SQS, queueURL string, handler Handler, opts Options, log *slog.Logger) *Worker {
	if opts.WaitTime <= 0 || opts.WaitTime > maxWaitTime {
		opts.WaitTime = maxWaitTime
	}
	return &Worker{
		sqs:      client,
		queueURL: queueURL,
		handler:  handler,
		opts:     opts,
		log:      log.With("queue_url", queueURL),
		tracer:   otel.Tracer("videoplatform/media-worker/sqsworker"),
	}
}

// Run consumes messages until ctx is cancelled, then shuts down gracefully and returns.
func (w *Worker) Run(ctx context.Context) {
	// Jobs outlive ctx by up to ShutdownGrace.
	jobCtx, cancelJobs := context.WithCancel(context.WithoutCancel(ctx))
	defer cancelJobs()
	stopGraceTimer := context.AfterFunc(ctx, func() {
		w.log.Info("shutting down: no new messages; running jobs have a grace period", "grace", w.opts.ShutdownGrace.String())
		time.AfterFunc(w.opts.ShutdownGrace, cancelJobs)
	})
	defer stopGraceTimer()

	var wg sync.WaitGroup
	for range w.opts.Concurrency {
		wg.Go(func() { w.loop(ctx, jobCtx) })
	}
	wg.Wait()
	w.log.Info("worker stopped")
}

func (w *Worker) loop(ctx, jobCtx context.Context) {
	failures := 0
	for ctx.Err() == nil {
		out, err := w.sqs.ReceiveMessage(ctx, &sqs.ReceiveMessageInput{
			QueueUrl:                    &w.queueURL,
			MaxNumberOfMessages:         1,
			WaitTimeSeconds:             sqsSeconds(w.opts.WaitTime),
			VisibilityTimeout:           sqsSeconds(w.opts.VisibilityTimeout),
			MessageSystemAttributeNames: []types.MessageSystemAttributeName{types.MessageSystemAttributeNameApproximateReceiveCount},
		})
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			failures++
			delay := backoff(failures, time.Second, 30*time.Second)
			w.log.Error("receive failed", "error", err, "retry_in", delay.String())
			sleep(ctx, delay)
			continue
		}
		failures = 0
		for _, m := range out.Messages {
			w.process(jobCtx, m)
		}
	}
}

func (w *Worker) process(jobCtx context.Context, m types.Message) {
	msg := Message{ID: aws.ToString(m.MessageId), Body: aws.ToString(m.Body), ReceiveCount: 1}
	if n, err := strconv.Atoi(m.Attributes[string(types.MessageSystemAttributeNameApproximateReceiveCount)]); err == nil {
		msg.ReceiveCount = n
	}

	ctx, span := w.tracer.Start(jobCtx, "process sqs message", trace.WithSpanKind(trace.SpanKindConsumer),
		trace.WithAttributes(
			attribute.String("messaging.system", "aws_sqs"),
			attribute.String("messaging.message.id", msg.ID),
			attribute.Int("messaging.receive_count", msg.ReceiveCount),
		))
	defer span.End()
	log := w.log.With("sqs_message_id", msg.ID, "receive_count", msg.ReceiveCount, "trace_id", span.SpanContext().TraceID().String())

	heartbeatCtx, stopHeartbeat := context.WithCancel(ctx)
	heartbeatDone := make(chan struct{})
	go func() {
		defer close(heartbeatDone)
		w.heartbeat(heartbeatCtx, m.ReceiptHandle, log)
	}()

	started := time.Now()
	err := w.handler.Handle(ctx, msg)
	stopHeartbeat()
	<-heartbeatDone

	// Acknowledge even if the job context was cancelled by shutdown.
	ackCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
	defer cancel()

	switch {
	case err == nil:
		if _, err := w.sqs.DeleteMessage(ackCtx, &sqs.DeleteMessageInput{QueueUrl: &w.queueURL, ReceiptHandle: m.ReceiptHandle}); err != nil {
			// The job succeeded; the message will reappear and be handled again, which the
			// handler must tolerate (deterministic outputs, idempotent result events).
			log.Error("handled but delete failed; message will be redelivered", "error", err)
			span.SetStatus(codes.Error, "delete failed")
			return
		}
		log.Info("message handled", "duration", time.Since(started).String())

	case jobCtx.Err() != nil:
		span.SetStatus(codes.Error, "cancelled by shutdown")
		w.release(ackCtx, m.ReceiptHandle, 0, log)
		log.Warn("job cancelled by shutdown; message released for another worker", "error", err)

	default:
		span.RecordError(err)
		span.SetStatus(codes.Error, err.Error())
		retryIn := backoff(msg.ReceiveCount, 30*time.Second, 15*time.Minute)
		var poison poisonError
		if errors.As(err, &poison) {
			retryIn = 0
		}
		w.release(ackCtx, m.ReceiptHandle, retryIn, log)
		log.Error("message failed; it will be retried, then moved to the DLQ", "error", err, "retry_in", retryIn.String())
	}
}

// heartbeat keeps the message invisible to other workers until ctx is done.
func (w *Worker) heartbeat(ctx context.Context, receipt *string, log *slog.Logger) {
	ticker := time.NewTicker(w.opts.HeartbeatInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			_, err := w.sqs.ChangeMessageVisibility(ctx, &sqs.ChangeMessageVisibilityInput{
				QueueUrl:          &w.queueURL,
				ReceiptHandle:     receipt,
				VisibilityTimeout: sqsSeconds(w.opts.VisibilityTimeout),
			})
			if err != nil && ctx.Err() == nil {
				log.Warn("heartbeat failed; another worker may receive this message", "error", err)
			}
		}
	}
}

func (w *Worker) release(ctx context.Context, receipt *string, after time.Duration, log *slog.Logger) {
	_, err := w.sqs.ChangeMessageVisibility(ctx, &sqs.ChangeMessageVisibilityInput{
		QueueUrl:          &w.queueURL,
		ReceiptHandle:     receipt,
		VisibilityTimeout: sqsSeconds(after),
	})
	if err != nil {
		log.Error("release failed; message reappears when its visibility timeout ends", "error", err)
	}
}

// SQS limits: long polls last at most 20 s; visibility timeouts are 0 to 12 h.
const (
	maxWaitTime   = 20 * time.Second
	maxVisibility = 12 * time.Hour
)

// sqsSeconds converts d to whole seconds clamped to SQS's 0..12 h range.
func sqsSeconds(d time.Duration) int32 {
	d = min(max(d, 0), maxVisibility)
	return int32(d / time.Second) //nolint:gosec // clamped above to at most 43200
}

// backoff returns base*2^(attempt-1), capped.
func backoff(attempt int, base, limit time.Duration) time.Duration {
	d := base
	for i := 1; i < attempt && d < limit; i++ {
		d *= 2
	}
	return min(d, limit)
}

func sleep(ctx context.Context, d time.Duration) {
	select {
	case <-ctx.Done():
	case <-time.After(d):
	}
}
