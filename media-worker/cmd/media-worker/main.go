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
	"github.com/aws/aws-sdk-go-v2/service/sqs"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/config"
	"videoplatform/media-worker/internal/jobs"
	"videoplatform/media-worker/internal/sqsworker"
	"videoplatform/media-worker/internal/telemetry"
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

	validator, err := contracts.NewValidator()
	if err != nil {
		return fmt.Errorf("load contracts: %w", err)
	}
	handler := jobs.NewHandler(validator, func(_ context.Context, req jobs.ProcessRequest) error {
		// Probe, transcode, package and result events arrive in S1-11, S2-10, S2-11 and S3-08.
		log.Info("job received; processing is not implemented yet", "video_id", req.VideoID, "job_id", req.JobID, "event_id", req.EventID)
		return nil
	})

	log.Info("media-worker started", "queue", cfg.ProcessQueue, "concurrency", cfg.Concurrency)
	sqsworker.New(client, *queue.QueueUrl, handler, sqsworker.Options{
		Concurrency:       cfg.Concurrency,
		VisibilityTimeout: cfg.VisibilityTimeout,
		HeartbeatInterval: cfg.HeartbeatInterval,
		ShutdownGrace:     cfg.ShutdownGrace,
	}, log).Run(ctx)
	return nil
}
