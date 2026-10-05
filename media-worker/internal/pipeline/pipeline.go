// Package pipeline processes one MediaProcessRequested job (ADR-006, S3-08):
//
//	download → probe → validate → for each rung, lowest first: encode, upload, rewrite the master
//	playlist, report VideoRenditionReady → thumbnails → report VideoProcessingCompleted.
//
// Every output key is derived from the job (media/{video_id}/v{n}/…), so running a job again
// after a crash overwrites the same objects instead of adding new ones. The master playlist is
// always written after the renditions it lists, so it never points at a missing playlist.
//
// Media problems (a Rejection) fail the job at once with VideoProcessingFailed and the message
// is deleted. Anything else is returned so SQS retries it; on the last attempt the job is failed
// with RETRIES_EXHAUSTED (or ENCODER_FAILED) instead, so Laravel always hears the outcome.
package pipeline

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"

	"videoplatform/media-worker/internal/jobs"
	"videoplatform/media-worker/internal/ladder"
	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/results"
	"videoplatform/media-worker/internal/storage"
	"videoplatform/media-worker/internal/thumbs"
	"videoplatform/media-worker/internal/transcode"
)

// Storage is what the pipeline needs from S3.
type Storage interface {
	Download(ctx context.Context, bucket, key, dest string) error
	Upload(ctx context.Context, bucket string, files []storage.File) error
}

// Results is where outcomes are reported.
type Results interface {
	RenditionReady(ctx context.Context, job results.Job, r results.Rendition, masterKey string) error
	Completed(ctx context.Context, job results.Job, c results.Completed) error
	Failed(ctx context.Context, job results.Job, step, code, message string, retryable bool) error
}

// Pipeline holds the job's dependencies.
type Pipeline struct {
	Store   Storage
	Results Results
	Prober  media.Prober
	Encoder transcode.Encoder
	Thumbs  thumbs.Generator
	Limits  media.Limits
	// Scratch is the directory jobs work in (the only writable path in production).
	Scratch string
	// MaxAttempts is how many deliveries a job gets before it is failed for good.
	MaxAttempts int
	Log         *slog.Logger
}

// jobDirPattern names each job's working directory under Scratch.
const jobDirPattern = "job-*"

// CleanScratch removes job directories left by a previous process that was killed (kill -9, OOM,
// Spot interruption past the grace period) and so never cleaned up after itself. Call it before
// any job starts: each worker task has its own scratch volume, so at startup nothing else is
// using these directories.
func (p *Pipeline) CleanScratch() (int, error) {
	stale, err := filepath.Glob(filepath.Join(p.Scratch, jobDirPattern))
	if err != nil {
		return 0, err
	}
	for _, dir := range stale {
		if err := os.RemoveAll(dir); err != nil {
			return 0, err
		}
	}
	return len(stale), nil
}

// failure is a job ending for good at a step.
type failure struct {
	step, code, message string
	retryable           bool
}

func (f *failure) Error() string { return f.step + ": " + f.code + ": " + f.message }

// Process implements jobs.Processor.
func (p *Pipeline) Process(ctx context.Context, req jobs.ProcessRequest) error {
	job := results.Job{VideoID: req.VideoID, JobID: req.JobID, ProcessingVersion: req.ProcessingVersion}
	log := p.Log.With("video_id", req.VideoID, "job_id", req.JobID, "processing_version", req.ProcessingVersion, "attempt", req.Attempt)

	err := p.run(ctx, req, job, log)
	var fail *failure
	switch {
	case err == nil:
		return nil
	case ctx.Err() != nil:
		return err // shutting down: the message is released for another worker
	case errors.As(err, &fail):
	case req.Attempt >= p.MaxAttempts:
		code := "RETRIES_EXHAUSTED"
		var encErr *transcode.EncoderError
		if errors.As(err, &encErr) {
			code = "ENCODER_FAILED"
		}
		fail = &failure{step: stepOf(err), code: code, message: err.Error(), retryable: true}
	default:
		log.Warn("job failed; it will be retried", "error", err)
		return err
	}

	log.Error("job failed for good", "step", fail.step, "code", fail.code, "error", fail.message)
	if err := p.Results.Failed(ctx, job, fail.step, fail.code, fail.message, fail.retryable); err != nil {
		return err // couldn't report it: retry, so the failure isn't lost
	}
	return nil
}

func (p *Pipeline) run(ctx context.Context, req jobs.ProcessRequest, job results.Job, log *slog.Logger) error {
	if req.Profile != ladder.Profile {
		return &failure{step: "validate", code: "UNSUPPORTED_PROFILE", message: "unknown encoding profile " + req.Profile}
	}
	if err := os.MkdirAll(p.Scratch, 0o750); err != nil {
		return at("download", err)
	}
	dir, err := os.MkdirTemp(p.Scratch, jobDirPattern)
	if err != nil {
		return at("download", err)
	}
	defer func() {
		if err := os.RemoveAll(dir); err != nil {
			log.Warn("could not remove the job's scratch directory; the next start cleans it", "dir", dir, "error", err)
		}
	}()

	started := time.Now()
	source := filepath.Join(dir, "source")
	if err := p.Store.Download(ctx, req.Source.Bucket, req.Source.Key, source); err != nil {
		if errors.Is(err, storage.ErrNotFound) {
			return &failure{step: "download", code: "SOURCE_NOT_FOUND", message: err.Error()}
		}
		return at("download", err)
	}

	probe, err := p.Prober.Probe(ctx, source)
	if err != nil {
		return mediaStep("probe", err)
	}
	if err := media.Validate(probe, p.Limits); err != nil {
		return mediaStep("validate", err)
	}

	prefix := req.Output.Prefix
	masterKey := prefix + "master.m3u8"
	hasAudio := len(probe.Audio) > 0
	renditions := ladder.For(probe.Video.Width, probe.Video.Height, probe.Video.FrameRate)
	var ready []ladder.Rendition
	var reported []results.Rendition

	for _, r := range renditions {
		out := filepath.Join(dir, r.Name())
		if err := p.Encoder.Encode(ctx, source, probe, r, out); err != nil {
			return at("transcode", err)
		}
		files, err := transcode.Files(out)
		if err != nil {
			return at("package", err)
		}
		upload := make([]storage.File, 0, len(files))
		for _, f := range files {
			upload = append(upload, storage.File{Path: f, Key: prefix + r.Name() + "/" + filepath.Base(f), CacheControl: storage.CacheImmutable})
		}
		if err := p.Store.Upload(ctx, req.Output.Bucket, upload); err != nil {
			return at("upload", err)
		}
		if err := os.RemoveAll(out); err != nil { // keep scratch use to about one rendition
			return at("upload", err)
		}

		// Renditions first, then the master that lists them.
		ready = append(ready, r)
		if err := p.writeMaster(ctx, req.Output.Bucket, masterKey, dir, ready, hasAudio); err != nil {
			return at("upload", err)
		}
		rendition := results.Rendition{
			Codec: "h264", Width: r.Width, Height: r.Height, FrameRate: r.FrameRate,
			BitrateAvg: r.Bitrate, BitratePeak: r.PeakBitrate,
			PlaylistKey: prefix + path.Join(r.Name(), transcode.PlaylistFile), SegmentDurationMs: ladder.SegmentSeconds * 1000,
		}
		reported = append(reported, rendition)
		if err := p.Results.RenditionReady(ctx, job, rendition, masterKey); err != nil {
			return at("upload", err)
		}
		log.Info("rendition ready", "rendition", r.Name())
	}

	thumbDir := filepath.Join(dir, "thumbs")
	if err := os.MkdirAll(thumbDir, 0o750); err != nil {
		return at("thumbnails", err)
	}
	made, err := p.Thumbs.Generate(ctx, source, probe, thumbDir)
	if err != nil {
		return at("thumbnails", err)
	}
	thumbFiles := make([]storage.File, 0, len(made))
	thumbnails := make([]results.Thumbnail, 0, len(made))
	for _, t := range made {
		key := prefix + "thumbs/" + t.Name
		thumbFiles = append(thumbFiles, storage.File{Path: t.File, Key: key, CacheControl: storage.CacheImmutable})
		thumbnails = append(thumbnails, results.Thumbnail{Key: key, Width: t.Width, Height: t.Height, TimeOffsetMs: int(t.At.Milliseconds())})
	}
	if err := p.Store.Upload(ctx, req.Output.Bucket, thumbFiles); err != nil {
		return at("upload", err)
	}

	if err := p.Results.Completed(ctx, job, results.Completed{
		DurationMs:        max(1, int(probe.Duration.Milliseconds())),
		Source:            results.Source{Width: probe.Video.Width, Height: probe.Video.Height, FrameRate: sourceFrameRate(probe, renditions), HasAudio: hasAudio},
		Renditions:        reported,
		MasterPlaylistKey: masterKey,
		Thumbnails:        thumbnails,
	}); err != nil {
		return at("upload", err)
	}
	log.Info("job completed", "renditions", len(reported), "thumbnails", len(thumbnails), "took", time.Since(started).Round(time.Millisecond))
	return nil
}

// sourceFrameRate is the probed rate, or the output rate when the probe has none (some VFR files).
func sourceFrameRate(probe *media.Result, renditions []ladder.Rendition) float64 {
	if probe.Video.FrameRate > 0 {
		return probe.Video.FrameRate
	}
	return renditions[0].FrameRate
}

func (p *Pipeline) writeMaster(ctx context.Context, bucket, key, dir string, ready []ladder.Rendition, hasAudio bool) error {
	file := filepath.Join(dir, "master.m3u8")
	if err := os.WriteFile(file, []byte(transcode.MasterPlaylist(ready, hasAudio)), 0o600); err != nil {
		return err
	}
	return p.Store.Upload(ctx, bucket, []storage.File{{Path: file, Key: key, CacheControl: storage.CacheMaster}})
}

// stepError remembers which step a retryable error came from, for the final failure report.
type stepError struct {
	step string
	err  error
}

func (e *stepError) Error() string { return e.step + ": " + e.err.Error() }
func (e *stepError) Unwrap() error { return e.err }

func at(step string, err error) error { return &stepError{step: step, err: err} }

func stepOf(err error) string {
	var se *stepError
	if errors.As(err, &se) {
		return se.step
	}
	return "transcode"
}

// mediaStep turns a Rejection into a failure at step; other errors stay retryable.
func mediaStep(step string, err error) error {
	if r, ok := media.AsRejection(err); ok {
		return &failure{step: step, code: r.Code, message: strings.TrimSpace(r.Message)}
	}
	return at(step, fmt.Errorf("%s: %w", step, err))
}
