package sqsworker

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/rand/v2"
	"os"
	"strconv"
	"sync/atomic"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"github.com/aws/aws-sdk-go-v2/service/sqs/types"
)

// Integration tests against the local AWS emulator (`make up`). Each test uses its own queue.

type queue struct {
	t      *testing.T
	client *sqs.Client
	url    string
}

func newQueue(t *testing.T) *queue {
	t.Helper()
	if os.Getenv("AWS_ENDPOINT_URL") == "" {
		t.Skip("set AWS_ENDPOINT_URL to the local AWS emulator (make up) to run SQS integration tests")
	}
	cfg, err := awsconfig.LoadDefaultConfig(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	client := sqs.NewFromConfig(cfg)
	out, err := client.CreateQueue(context.Background(), &sqs.CreateQueueInput{
		QueueName: aws.String(fmt.Sprintf("worker-test-%d", rand.Int64())),
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = client.DeleteQueue(context.Background(), &sqs.DeleteQueueInput{QueueUrl: out.QueueUrl})
	})
	return &queue{t: t, client: client, url: *out.QueueUrl}
}

func (q *queue) send(body string) {
	q.t.Helper()
	if _, err := q.client.SendMessage(context.Background(), &sqs.SendMessageInput{QueueUrl: &q.url, MessageBody: &body}); err != nil {
		q.t.Fatal(err)
	}
}

// competitorReceives reports whether another consumer can receive a message right now.
func (q *queue) competitorReceives() (types.Message, bool) {
	q.t.Helper()
	out, err := q.client.ReceiveMessage(context.Background(), &sqs.ReceiveMessageInput{
		QueueUrl:                    &q.url,
		WaitTimeSeconds:             1,
		VisibilityTimeout:           0,
		MessageSystemAttributeNames: []types.MessageSystemAttributeName{types.MessageSystemAttributeNameApproximateReceiveCount},
	})
	if err != nil {
		q.t.Fatal(err)
	}
	if len(out.Messages) == 0 {
		return types.Message{}, false
	}
	return out.Messages[0], true
}

func (q *queue) isEmpty() bool {
	q.t.Helper()
	out, err := q.client.GetQueueAttributes(context.Background(), &sqs.GetQueueAttributesInput{
		QueueUrl:       &q.url,
		AttributeNames: []types.QueueAttributeName{types.QueueAttributeNameApproximateNumberOfMessages, types.QueueAttributeNameApproximateNumberOfMessagesNotVisible},
	})
	if err != nil {
		q.t.Fatal(err)
	}
	return out.Attributes["ApproximateNumberOfMessages"] == "0" && out.Attributes["ApproximateNumberOfMessagesNotVisible"] == "0"
}

func testOptions() Options {
	return Options{Concurrency: 1, VisibilityTimeout: 2 * time.Second, HeartbeatInterval: 500 * time.Millisecond, ShutdownGrace: 5 * time.Second, WaitTime: time.Second}
}

// start runs a worker and returns a function that shuts it down and waits for Run to return.
func start(q *queue, h Handler, opts Options) (shutdown func() time.Duration) {
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		New(q.client, q.url, h, opts, slog.New(slog.NewTextHandler(io.Discard, nil))).Run(ctx)
		close(done)
	}()
	return func() time.Duration {
		begin := time.Now()
		cancel()
		select {
		case <-done:
			return time.Since(begin)
		case <-time.After(30 * time.Second):
			q.t.Fatal("worker did not stop")
			return 0
		}
	}
}

func waitFor(t *testing.T, ch <-chan struct{}) {
	t.Helper()
	select {
	case <-ch:
	case <-time.After(10 * time.Second):
		t.Fatal("timed out waiting for the handler")
	}
}

func TestHandledMessageIsDeleted(t *testing.T) {
	q := newQueue(t)
	q.send("hello")
	handled := make(chan struct{})
	var body string

	shutdown := start(q, HandlerFunc(func(_ context.Context, m Message) error {
		body = m.Body
		close(handled)
		return nil
	}), testOptions())
	waitFor(t, handled)
	shutdown()

	if body != "hello" {
		t.Errorf("handler got %q", body)
	}
	if !q.isEmpty() {
		t.Error("message was not deleted")
	}
}

func TestHeartbeatKeepsALongJobAwayFromOtherWorkers(t *testing.T) {
	q := newQueue(t)
	q.send("long job")
	started, finish := make(chan struct{}), make(chan struct{})

	shutdown := start(q, HandlerFunc(func(context.Context, Message) error {
		close(started)
		<-finish
		return nil
	}), testOptions()) // visibility 2 s, heartbeat every 0.5 s
	waitFor(t, started)

	// The job runs for ~5 s, well past the 2 s visibility timeout.
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if _, ok := q.competitorReceives(); ok {
			t.Fatal("another worker received the message while the job was still running")
		}
	}
	close(finish)
	shutdown()

	if !q.isEmpty() {
		t.Error("message was not deleted after the job finished")
	}
}

func TestPoisonMessageIsReleasedImmediately(t *testing.T) {
	q := newQueue(t)
	q.send("bad")
	failed := make(chan struct{})

	shutdown := start(q, HandlerFunc(func(context.Context, Message) error {
		close(failed)
		return Poison(errors.New("breaks the contract"))
	}), testOptions())
	waitFor(t, failed)
	shutdown()

	m, ok := q.competitorReceives()
	if !ok {
		t.Fatal("poison message was not released for redelivery (and, eventually, the DLQ)")
	}
	if n, _ := strconv.Atoi(m.Attributes["ApproximateReceiveCount"]); n != 2 {
		t.Errorf("receive count = %d, want 2", n)
	}
}

func TestTransientFailureIsRetriedAfterABackoff(t *testing.T) {
	q := newQueue(t)
	q.send("flaky")
	failed := make(chan struct{})

	shutdown := start(q, HandlerFunc(func(context.Context, Message) error {
		close(failed)
		return errors.New("S3 timed out")
	}), testOptions())
	waitFor(t, failed)
	shutdown()

	if _, ok := q.competitorReceives(); ok {
		t.Error("message reappeared immediately; expected a ~30 s backoff")
	}
	if q.isEmpty() {
		t.Error("failed message was deleted")
	}
}

func TestShutdownLetsARunningJobFinish(t *testing.T) {
	q := newQueue(t)
	q.send("job")
	started := make(chan struct{})
	var cancelled atomic.Bool

	shutdown := start(q, HandlerFunc(func(ctx context.Context, _ Message) error {
		close(started)
		select {
		case <-time.After(time.Second):
			return nil
		case <-ctx.Done():
			cancelled.Store(true)
			return ctx.Err()
		}
	}), testOptions()) // grace 5 s > job 1 s
	waitFor(t, started)
	shutdown()

	if cancelled.Load() {
		t.Error("job was cancelled although it finished within the grace period")
	}
	if !q.isEmpty() {
		t.Error("finished job's message was not deleted")
	}
}

func TestShutdownReleasesAJobThatOutlivesTheGracePeriod(t *testing.T) {
	q := newQueue(t)
	q.send("very long job")
	started := make(chan struct{})
	opts := testOptions()
	opts.VisibilityTimeout = time.Minute
	opts.HeartbeatInterval = 30 * time.Second
	opts.ShutdownGrace = 500 * time.Millisecond

	shutdown := start(q, HandlerFunc(func(ctx context.Context, _ Message) error {
		close(started)
		<-ctx.Done()
		return ctx.Err()
	}), opts)
	waitFor(t, started)
	if took := shutdown(); took > 5*time.Second {
		t.Errorf("shutdown took %s; expected about the grace period", took)
	}

	// Released with visibility 0: available now, not after the 1-minute timeout.
	if _, ok := q.competitorReceives(); !ok {
		t.Error("cancelled job's message was not released to other workers")
	}
}

func TestShutdownInterruptsAnIdleLongPoll(t *testing.T) {
	q := newQueue(t)
	opts := testOptions()
	opts.WaitTime = 20 * time.Second

	shutdown := start(q, HandlerFunc(func(context.Context, Message) error { return nil }), opts)
	time.Sleep(500 * time.Millisecond)
	if took := shutdown(); took > 2*time.Second {
		t.Errorf("idle worker took %s to stop; the long poll should be cancelled", took)
	}
}

func TestBackoff(t *testing.T) {
	cases := []struct {
		attempt int
		want    time.Duration
	}{{1, 30 * time.Second}, {2, time.Minute}, {3, 2 * time.Minute}, {10, 15 * time.Minute}}
	for _, c := range cases {
		if got := backoff(c.attempt, 30*time.Second, 15*time.Minute); got != c.want {
			t.Errorf("backoff(%d) = %s, want %s", c.attempt, got, c.want)
		}
	}
}

func TestSQSSecondsIsClampedToSQSLimits(t *testing.T) {
	cases := map[time.Duration]int32{-time.Second: 0, 0: 0, 1500 * time.Millisecond: 1, 5 * time.Minute: 300, 24 * time.Hour: 43200}
	for in, want := range cases {
		if got := sqsSeconds(in); got != want {
			t.Errorf("sqsSeconds(%s) = %d, want %d", in, got, want)
		}
	}
}
