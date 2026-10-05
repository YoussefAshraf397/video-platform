// Package config loads the worker's settings from environment variables.
//
// AWS settings (AWS_REGION, credentials, and AWS_ENDPOINT_URL for the local emulator) are
// read directly by the AWS SDK and are not repeated here.
package config

import (
	"errors"
	"fmt"
	"log/slog"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config holds the worker's settings.
type Config struct {
	// ProcessQueue is the name of the SQS queue with MediaProcessRequested jobs.
	ProcessQueue string
	// ResultsQueue is where VideoRenditionReady/Completed/Failed go.
	ResultsQueue string
	// ScratchDir is where jobs download and encode (in ECS: the only writable volume).
	ScratchDir string
	// MaxAttempts is how many deliveries a job gets before it is reported as failed (ADR-006: 3).
	// Keep it below the queue's maxReceiveCount (5), so jobs are reported, not silently dead-lettered.
	MaxAttempts int
	// X264Preset trades encode speed for size at the same quality.
	X264Preset string
	// Concurrency is how many jobs one worker process runs at a time. Transcoding uses every
	// core, so the default is 1 and capacity comes from running more tasks.
	Concurrency int
	// VisibilityTimeout is how long each heartbeat hides the message from other workers.
	VisibilityTimeout time.Duration
	// HeartbeatInterval is how often a running job extends its message's visibility.
	HeartbeatInterval time.Duration
	// ShutdownGrace is how long a running job may continue after SIGTERM before it is
	// cancelled and its message released. Keep it below the ECS stopTimeout (max 120 s).
	ShutdownGrace time.Duration
	LogLevel      slog.Level
}

// Load reads the configuration using getenv (os.Getenv in production).
func Load(getenv func(string) string) (Config, error) {
	var errs []error
	get := func(key, def string) string {
		if v := strings.TrimSpace(getenv(key)); v != "" {
			return v
		}
		return def
	}
	duration := func(key, def string) time.Duration {
		d, err := time.ParseDuration(get(key, def))
		if err != nil || d <= 0 {
			errs = append(errs, fmt.Errorf("%s must be a positive duration like 90s or 5m", key))
		}
		return d
	}

	cfg := Config{
		ProcessQueue:      get("MEDIA_PROCESS_QUEUE", "media-process"),
		ResultsQueue:      get("MEDIA_RESULTS_QUEUE", "media-results"),
		ScratchDir:        get("SCRATCH_DIR", os.TempDir()),
		X264Preset:        get("X264_PRESET", "veryfast"),
		VisibilityTimeout: duration("VISIBILITY_TIMEOUT", "5m"),
		HeartbeatInterval: duration("HEARTBEAT_INTERVAL", "1m"),
		ShutdownGrace:     duration("SHUTDOWN_GRACE", "90s"),
	}

	concurrency, err := strconv.Atoi(get("WORKER_CONCURRENCY", "1"))
	if err != nil || concurrency < 1 {
		errs = append(errs, errors.New("WORKER_CONCURRENCY must be a positive integer"))
	}
	cfg.Concurrency = concurrency

	attempts, err := strconv.Atoi(get("MAX_ATTEMPTS", "3"))
	if err != nil || attempts < 1 || attempts > 4 {
		errs = append(errs, errors.New("MAX_ATTEMPTS must be 1-4 (below the queue's maxReceiveCount of 5)"))
	}
	cfg.MaxAttempts = attempts

	if err := cfg.LogLevel.UnmarshalText([]byte(get("LOG_LEVEL", "info"))); err != nil {
		errs = append(errs, errors.New("LOG_LEVEL must be debug, info, warn or error"))
	}

	// A heartbeat may be late or fail once without the message reappearing to other workers.
	if cfg.HeartbeatInterval > 0 && cfg.VisibilityTimeout > 0 && 2*cfg.HeartbeatInterval > cfg.VisibilityTimeout {
		errs = append(errs, errors.New("HEARTBEAT_INTERVAL must be at most half of VISIBILITY_TIMEOUT"))
	}
	// SQS maximum visibility timeout.
	if cfg.VisibilityTimeout > 12*time.Hour {
		errs = append(errs, errors.New("VISIBILITY_TIMEOUT must be at most 12h"))
	}

	return cfg, errors.Join(errs...)
}
