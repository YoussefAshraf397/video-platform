// Package jobs turns MediaProcessRequested messages into processing requests.
package jobs

import (
	"context"
	"encoding/json"
	"fmt"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/sqsworker"
)

// MediaProcessRequestedSchema is the contract for messages on the media-process queue.
const MediaProcessRequestedSchema = "media-process-requested.v1"

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
	VideoID           string   `json:"video_id"`
	JobID             string   `json:"job_id"`
	ProcessingVersion int      `json:"processing_version"`
	Source            S3Object `json:"source"`
	Output            S3Prefix `json:"output"`
	Profile           string   `json:"profile"`
}

// Processor does the media work for one request. It must be safe to run more than once for
// the same request (deterministic output keys), because SQS delivers at least once.
type Processor func(ctx context.Context, req ProcessRequest) error

// Handler validates media-process messages and passes them to a Processor.
type Handler struct {
	validator *contracts.Validator
	process   Processor
}

// NewHandler creates a Handler.
func NewHandler(validator *contracts.Validator, process Processor) *Handler {
	return &Handler{validator: validator, process: process}
}

// Handle implements sqsworker.Handler. A message that breaks the contract is poison: no
// retry can fix it, so it goes to the DLQ for a human to inspect.
func (h *Handler) Handle(ctx context.Context, msg sqsworker.Message) error {
	if err := h.validator.Validate(MediaProcessRequestedSchema, []byte(msg.Body)); err != nil {
		return sqsworker.Poison(fmt.Errorf("message does not match %s: %w", MediaProcessRequestedSchema, err))
	}

	var envelope struct {
		EventID string         `json:"event_id"`
		Payload ProcessRequest `json:"payload"`
	}
	if err := json.Unmarshal([]byte(msg.Body), &envelope); err != nil {
		return sqsworker.Poison(fmt.Errorf("decode message: %w", err))
	}
	envelope.Payload.EventID = envelope.EventID

	return h.process(ctx, envelope.Payload)
}
