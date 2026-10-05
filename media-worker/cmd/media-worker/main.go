// Command media-worker consumes MediaProcessRequested jobs from SQS (ADR-006).
package main

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/sqs"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/config"
	"videoplatform/media-worker/internal/jobs"
	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/pipeline"
	"videoplatform/media-worker/internal/results"
	"videoplatform/media-worker/internal/sqsworker"
	"videoplatform/media-worker/internal/storage"
	"videoplatform/media-worker/internal/telemetry"
	"videoplatform/media-worker/internal/transcode"
)

// version is set at build time: -ldflags "-X main.version=<git sha>".
var version = "dev"

func main() {
	if err := run(); err != nil {
		slog.Error("media-worker failed", "error", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load(os.Getenv)
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}
	log := telemetry.NewLogger(os.Stdout, cfg.LogLevel, version)
	slog.SetDefault(log)

	// SIGTERM (ECS stop, Spot interruption) and SIGINT start a graceful shutdown.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	shutdownTracing, err := telemetry.SetupTracing(ctx, version)
	if err != nil {
		return fmt.Errorf("tracing: %w", err)
	}
	defer func() {
		flushCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = shutdownTracing(flushCtx)
	}()

	awsCfg, err := awsconfig.LoadDefaultConfig(ctx)
	if err != nil {
		return fmt.Errorf("aws config: %w", err)
	}
	client := sqs.NewFromConfig(awsCfg)
	queue, err := client.GetQueueUrl(ctx, &sqs.GetQueueUrlInput{QueueName: aws.String(cfg.ProcessQueue)})
	if err != nil {
		return fmt.Errorf("resolve queue %q: %w", cfg.ProcessQueue, err)
	}
	resultsQueue, err := client.GetQueueUrl(ctx, &sqs.GetQueueUrlInput{QueueName: aws.String(cfg.ResultsQueue)})
	if err != nil {
		return fmt.Errorf("resolve queue %q: %w", cfg.ResultsQueue, err)
	}
	// Path-style addressing works with both AWS and the local emulator (AWS_ENDPOINT_URL).
	s3Client := s3.NewFromConfig(awsCfg, func(o *s3.Options) { o.UsePathStyle = os.Getenv("AWS_ENDPOINT_URL") != "" })

	validator, err := contracts.NewValidator()
	if err != nil {
		return fmt.Errorf("load contracts: %w", err)
	}
	work := &pipeline.Pipeline{
		Store:       storage.New(s3Client),
		Results:     results.New(client, *resultsQueue.QueueUrl, validator),
		Encoder:     transcode.FFmpeg{Preset: cfg.X264Preset},
		Limits:      media.DefaultLimits(),
		Scratch:     cfg.ScratchDir,
		MaxAttempts: cfg.MaxAttempts,
		Log:         log,
	}
	if n, err := work.CleanScratch(); err != nil {
		return fmt.Errorf("clean scratch directory: %w", err)
	} else if n > 0 {
		log.Warn("removed work left by a previous process that didn't shut down cleanly", "directories", n)
	}
	handler := jobs.NewHandler(validator, work.Process)

	log.Info("media-worker started", "queue", cfg.ProcessQueue, "concurrency", cfg.Concurrency)
	sqsworker.New(client, *queue.QueueUrl, handler, sqsworker.Options{
		Concurrency:       cfg.Concurrency,
		VisibilityTimeout: cfg.VisibilityTimeout,
		HeartbeatInterval: cfg.HeartbeatInterval,
		ShutdownGrace:     cfg.ShutdownGrace,
	}, log).Run(ctx)
	return nil
}
