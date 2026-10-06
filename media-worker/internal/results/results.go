// Package results publishes the worker's outcome messages to the media-results queue (ADR-006):
// VideoRenditionReady after each rendition, then VideoProcessingCompleted, or
// VideoProcessingFailed. Laravel applies the state changes; the worker never touches the database.
//
// Event IDs are derived from the job, so a retried job (after a crash or a lost delete) sends
// the same IDs again and consumers' event_id deduplication drops the repeats.
package results

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"github.com/aws/aws-sdk-go-v2/service/sqs/types"
	"github.com/google/uuid"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/propagation"
	"go.opentelemetry.io/otel/trace"

	"videoplatform/contracts"
)

// Sender is the subset of the SQS client the publisher uses.
type Sender interface {
	SendMessage(ctx context.Context, in *sqs.SendMessageInput, opts ...func(*sqs.Options)) (*sqs.SendMessageOutput, error)
}

// Job identifies the processing job a message is about.
type Job struct {
	VideoID           string
	JobID             string
	ProcessingVersion int
}

// Rendition is one playable rung (contract common.v1#/rendition).
type Rendition struct {
	Codec             string  `json:"codec"`
	Width             int     `json:"width"`
	Height            int     `json:"height"`
	FrameRate         float64 `json:"frame_rate"`
	BitrateAvg        int     `json:"bitrate_avg"`
	BitratePeak       int     `json:"bitrate_peak"`
	PlaylistKey       string  `json:"playlist_key"`
	SegmentDurationMs int     `json:"segment_duration_ms"`
}

// Thumbnail is one thumbnail file (contract common.v1#/thumbnail).
type Thumbnail struct {
	Key          string `json:"key"`
	Width        int    `json:"width"`
	Height       int    `json:"height"`
	TimeOffsetMs int    `json:"time_offset_ms"`
}

// Source is what the probe found in the original.
type Source struct {
	Width     int     `json:"width"`
	Height    int     `json:"height"`
	FrameRate float64 `json:"frame_rate"`
	HasAudio  bool    `json:"has_audio"`
}

// Completed is the payload of VideoProcessingCompleted, minus the job reference.
type Completed struct {
	DurationMs        int         `json:"duration_ms"`
	Source            Source      `json:"source"`
	Renditions        []Rendition `json:"renditions"`
	MasterPlaylistKey string      `json:"master_playlist_key"`
	Thumbnails        []Thumbnail `json:"thumbnails"`
}

// Publisher sends result messages to one queue.
type Publisher struct {
	sqs       Sender
	queueURL  string
	validator *contracts.Validator
	now       func() time.Time
}

// New creates a Publisher.
func New(sender Sender, queueURL string, validator *contracts.Validator) *Publisher {
	return &Publisher{sqs: sender, queueURL: queueURL, validator: validator, now: time.Now}
}

// RenditionReady reports one more playable rendition; the master playlist already lists it.
func (p *Publisher) RenditionReady(ctx context.Context, job Job, r Rendition, masterKey string) error {
	return p.send(ctx, job, "VideoRenditionReady", "video-rendition-ready.v1", "rendition:"+r.PlaylistKey, map[string]any{
		"rendition": r, "master_playlist_key": masterKey,
	})
}

// Completed reports that every output is written.
func (p *Publisher) Completed(ctx context.Context, job Job, c Completed) error {
	return p.send(ctx, job, "VideoProcessingCompleted", "video-processing-completed.v1", "completed", map[string]any{
		"duration_ms": c.DurationMs, "source": c.Source, "renditions": c.Renditions,
		"master_playlist_key": c.MasterPlaylistKey, "thumbnails": c.Thumbnails,
	})
}

// Failed reports that processing stopped for good.
func (p *Publisher) Failed(ctx context.Context, job Job, step, code, message string, retryable bool) error {
	if len(message) > 1000 {
		message = message[:1000]
	}
	return p.send(ctx, job, "VideoProcessingFailed", "video-processing-failed.v1", "failed", map[string]any{
		"failed_step": step, "error": map[string]any{"code": code, "message": message, "retryable": retryable},
	})
}

func (p *Publisher) send(ctx context.Context, job Job, eventType, schema, idSeed string, fields map[string]any) error {
	payload := map[string]any{"video_id": job.VideoID, "job_id": job.JobID, "processing_version": job.ProcessingVersion}
	for k, v := range fields {
		payload[k] = v
	}
	var traceID any
	if sc := trace.SpanContextFromContext(ctx); sc.HasTraceID() {
		traceID = sc.TraceID().String()
	}

	body, err := json.Marshal(map[string]any{
		"event_id":       EventID(job, idSeed),
		"event_type":     eventType,
		"schema_version": 1,
		"occurred_at":    p.now().UTC().Format("2006-01-02T15:04:05.000Z"),
		"producer":       "media-worker",
		"aggregate_type": "video",
		"aggregate_id":   job.VideoID,
		// Consumers drop results of an older processing version than the one they're on.
		"aggregate_version": job.ProcessingVersion,
		"trace_id":          traceID,
		"payload":           payload,
	})
	if err != nil {
		return err
	}
	// A message that breaks its contract is a bug here; never let it reach Laravel.
	if err := p.validator.Validate(schema, body); err != nil {
		return fmt.Errorf("%s does not match %s: %w", eventType, schema, err)
	}

	attributes := map[string]types.MessageAttributeValue{
		"event_type": {DataType: aws.String("String"), StringValue: aws.String(eventType)},
	}
	// The job's trace continues in whoever consumes the result (Laravel, S4).
	carrier := propagation.MapCarrier{}
	otel.GetTextMapPropagator().Inject(ctx, carrier)
	if tp := carrier.Get("traceparent"); tp != "" {
		attributes["traceparent"] = types.MessageAttributeValue{DataType: aws.String("String"), StringValue: aws.String(tp)}
	}
	_, err = p.sqs.SendMessage(ctx, &sqs.SendMessageInput{
		QueueUrl:          aws.String(p.queueURL),
		MessageBody:       aws.String(string(body)),
		MessageAttributes: attributes,
	})
	if err != nil {
		return fmt.Errorf("send %s: %w", eventType, err)
	}
	return nil
}

// EventID is the same for the same job and message, however many times the job runs.
func EventID(job Job, seed string) string {
	ns, err := uuid.Parse(job.JobID)
	if err != nil {
		ns = uuid.NameSpaceURL
	}
	return uuid.NewSHA1(ns, []byte(fmt.Sprintf("%d:%s", job.ProcessingVersion, seed))).String()
}
