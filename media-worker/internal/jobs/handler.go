// Package jobs turns the commands on the media-process queue into requests: MediaProcessRequested
// (transcode a source) and MediaCaptionsRequested (publish a version's subtitle tracks).
package jobs

import (
	"context"
	"encoding/json"
	"fmt"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/sqsworker"
)

// The contracts of the commands on the media-process queue.
const (
	MediaProcessRequestedSchema  = "media-process-requested.v1"
	MediaCaptionsRequestedSchema = "media-captions-requested.v1"
)

// S3Object is a location in S3.
type S3Object struct {
	Bucket string `json:"bucket"`
	Key    string `json:"key"`
}

// S3Prefix is a "directory" in S3.
type S3Prefix struct {
	Bucket string `json:"bucket"`
	Prefix string `json:"prefix"`
}

// ProcessRequest is the payload of MediaProcessRequested (contracts/schemas/media-process-requested.v1.json).
type ProcessRequest struct {
	EventID           string   `json:"-"`
	Attempt           int      `json:"-"` // 1 on the first delivery of the message
	VideoID           string   `json:"video_id"`
	JobID             string   `json:"job_id"`
	ProcessingVersion int      `json:"processing_version"`
	Source            S3Object `json:"source"`
	Output            S3Prefix `json:"output"`
	Profile           string   `json:"profile"`
}

// CaptionTrack is one subtitle track of MediaCaptionsRequested.
type CaptionTrack struct {
	SubtitleID string   `json:"subtitle_id"`
	Language   string   `json:"language"`
	Label      string   `json:"label"`
	Kind       string   `json:"kind"`   // subtitles or captions
	Format     string   `json:"format"` // srt or vtt
	Source     S3Object `json:"source"`
}

// CaptionsRequest is the payload of MediaCaptionsRequested (contracts/schemas/media-captions-requested.v1.json):
// the complete set of tracks a processing version should have.
type CaptionsRequest struct {
	EventID           string         `json:"-"`
	Attempt           int            `json:"-"`
	VideoID           string         `json:"video_id"`
	JobID             string         `json:"job_id"`
	ProcessingVersion int            `json:"processing_version"`
	Output            S3Prefix       `json:"output"`
	BaseMasterKey     string         `json:"base_master_key"`
	Revision          int            `json:"captions_revision"`
	Tracks            []CaptionTrack `json:"tracks"`
}

// Processor does the media work for one request. It must be safe to run more than once for
// the same request (deterministic output keys), because SQS delivers at least once.
type Processor func(ctx context.Context, req ProcessRequest) error

// CaptionsProcessor publishes one captions revision. Same rule: safe to run more than once.
type CaptionsProcessor func(ctx context.Context, req CaptionsRequest) error

// Handler validates media-process messages and passes each to the processor for its type.
type Handler struct {
	validator *contracts.Validator
	process   Processor
	captions  CaptionsProcessor
}

// NewHandler creates a Handler.
func NewHandler(validator *contracts.Validator, process Processor, captions CaptionsProcessor) *Handler {
	return &Handler{validator: validator, process: process, captions: captions}
}

// Handle implements sqsworker.Handler. A message of an unknown type, or one that breaks its
// contract, is poison: no retry can fix it, so it goes to the DLQ for a human to inspect.
func (h *Handler) Handle(ctx context.Context, msg sqsworker.Message) error {
	var head struct {
		EventType string `json:"event_type"`
	}
	if err := json.Unmarshal([]byte(msg.Body), &head); err != nil {
		return sqsworker.Poison(fmt.Errorf("decode message: %w", err))
	}

	switch head.EventType {
	case "MediaProcessRequested":
		req, eventID, err := decode[ProcessRequest](h.validator, MediaProcessRequestedSchema, msg.Body)
		if err != nil {
			return err
		}
		req.EventID, req.Attempt = eventID, msg.ReceiveCount
		return h.process(ctx, req)
	case "MediaCaptionsRequested":
		req, eventID, err := decode[CaptionsRequest](h.validator, MediaCaptionsRequestedSchema, msg.Body)
		if err != nil {
			return err
		}
		req.EventID, req.Attempt = eventID, msg.ReceiveCount
		return h.captions(ctx, req)
	default:
		return sqsworker.Poison(fmt.Errorf("unknown command %q on the media-process queue", head.EventType))
	}
}

// decode checks a message against its contract and returns its payload and event id.
func decode[P any](validator *contracts.Validator, schema, body string) (P, string, error) {
	var envelope struct {
		EventID string `json:"event_id"`
		Payload P      `json:"payload"`
	}
	if err := validator.Validate(schema, []byte(body)); err != nil {
		return envelope.Payload, "", sqsworker.Poison(fmt.Errorf("message does not match %s: %w", schema, err))
	}
	if err := json.Unmarshal([]byte(body), &envelope); err != nil {
		return envelope.Payload, "", sqsworker.Poison(fmt.Errorf("decode message: %w", err))
	}
	return envelope.Payload, envelope.EventID, nil
}
