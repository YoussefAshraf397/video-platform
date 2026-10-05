package pipeline

// Integration tests against the local AWS emulator (make up): real S3 buckets and an SQS results
// queue per test, real ffmpeg, golden-corpus sources.

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"sort"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
	"github.com/aws/smithy-go/logging"
	"github.com/google/uuid"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/jobs"
	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/results"
	"videoplatform/media-worker/internal/storage"
	"videoplatform/media-worker/internal/testcorpus"
	"videoplatform/media-worker/internal/thumbs"
	"videoplatform/media-worker/internal/transcode"
)

type env struct {
	t                          *testing.T
	s3                         *s3.Client
	sqs                        *sqs.Client
	uploads, media, resultsURL string
	pipeline                   *Pipeline
}

func setup(t *testing.T) *env {
	t.Helper()
	if os.Getenv("AWS_ENDPOINT_URL") == "" {
		t.Skip("set AWS_ENDPOINT_URL to the local AWS emulator (make up) to run S3/SQS integration tests")
	}
	cfg, err := awsconfig.LoadDefaultConfig(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	// The emulator returns no response checksums, which the SDK would log on every download.
	cfg.Logger = logging.Nop{}
	e := &env{t: t, s3: s3.NewFromConfig(cfg, func(o *s3.Options) { o.UsePathStyle = true }), sqs: sqs.NewFromConfig(cfg)}
	ctx := context.Background()
	name := "test-" + strings.ToLower(uuid.NewString()[:12])
	e.uploads, e.media = name+"-uploads", name+"-media"
	for _, b := range []string{e.uploads, e.media} {
		if _, err := e.s3.CreateBucket(ctx, &s3.CreateBucketInput{Bucket: aws.String(b)}); err != nil {
			t.Fatal(err)
		}
	}
	q, err := e.sqs.CreateQueue(ctx, &sqs.CreateQueueInput{QueueName: aws.String(name)})
	if err != nil {
		t.Fatal(err)
	}
	e.resultsURL = *q.QueueUrl
	t.Cleanup(func() {
		for _, b := range []string{e.uploads, e.media} {
			for _, k := range e.keys(b, "") {
				_, _ = e.s3.DeleteObject(ctx, &s3.DeleteObjectInput{Bucket: aws.String(b), Key: aws.String(k)})
			}
			_, _ = e.s3.DeleteBucket(ctx, &s3.DeleteBucketInput{Bucket: aws.String(b)})
		}
		_, _ = e.sqs.DeleteQueue(ctx, &sqs.DeleteQueueInput{QueueUrl: aws.String(e.resultsURL)})
	})

	validator, err := contracts.NewValidator()
	if err != nil {
		t.Fatal(err)
	}
	e.pipeline = &Pipeline{
		Store:       storage.New(e.s3),
		Results:     results.New(e.sqs, e.resultsURL, validator),
		Encoder:     transcode.FFmpeg{Preset: "superfast"},
		Thumbs:      thumbs.Generator{},
		Limits:      media.DefaultLimits(),
		Scratch:     t.TempDir(),
		MaxAttempts: 3,
		Log:         slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
	return e
}

// request uploads a corpus sample as a source and returns the job for it.
func (e *env) request(sample string) jobs.ProcessRequest {
	e.t.Helper()
	videoID, jobID := uuid.NewString(), uuid.NewString()
	key := "uploads/" + videoID + "/" + uuid.NewString() + "/source"
	if sample != "" {
		f, err := os.Open(testcorpus.Path(e.t, sample))
		if err != nil {
			e.t.Fatal(err)
		}
		defer func() { _ = f.Close() }()
		if _, err := e.s3.PutObject(context.Background(), &s3.PutObjectInput{Bucket: aws.String(e.uploads), Key: aws.String(key), Body: f}); err != nil {
			e.t.Fatal(err)
		}
	}
	return jobs.ProcessRequest{
		VideoID: videoID, JobID: jobID, ProcessingVersion: 1, Attempt: 1, Profile: "h264-sdr-v1",
		Source: jobs.S3Object{Bucket: e.uploads, Key: key},
		Output: jobs.S3Prefix{Bucket: e.media, Prefix: "media/" + videoID + "/v1/"},
	}
}

func (e *env) keys(bucket, prefix string) []string {
	var keys []string
	p := s3.NewListObjectsV2Paginator(e.s3, &s3.ListObjectsV2Input{Bucket: aws.String(bucket), Prefix: aws.String(prefix)})
	for p.HasMorePages() {
		page, err := p.NextPage(context.Background())
		if err != nil {
			e.t.Fatal(err)
		}
		for _, o := range page.Contents {
			keys = append(keys, *o.Key)
		}
	}
	sort.Strings(keys)
	return keys
}

type message struct {
	EventID   string         `json:"event_id"`
	EventType string         `json:"event_type"`
	Version   int            `json:"aggregate_version"`
	Payload   map[string]any `json:"payload"`
}

// messages drains the results queue.
func (e *env) messages() []message {
	e.t.Helper()
	var out []message
	for {
		r, err := e.sqs.ReceiveMessage(context.Background(), &sqs.ReceiveMessageInput{QueueUrl: aws.String(e.resultsURL), MaxNumberOfMessages: 10, WaitTimeSeconds: 1})
		if err != nil {
			e.t.Fatal(err)
		}
		if len(r.Messages) == 0 {
			return out
		}
		for _, m := range r.Messages {
			var msg message
			if err := json.Unmarshal([]byte(*m.Body), &msg); err != nil {
				e.t.Fatal(err)
			}
			out = append(out, msg)
			_, _ = e.sqs.DeleteMessage(context.Background(), &sqs.DeleteMessageInput{QueueUrl: aws.String(e.resultsURL), ReceiptHandle: m.ReceiptHandle})
		}
	}
}

func types(msgs []message) []string {
	var t []string
	for _, m := range msgs {
		t = append(t, m.EventType)
	}
	return t
}

func TestProcessesAJobEndToEnd(t *testing.T) {
	e := setup(t)
	req := e.request("landscape_1080p30.mp4")

	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatal(err)
	}

	prefix := req.Output.Prefix
	keys := e.keys(e.media, prefix)
	for _, rung := range []string{"h264_360p30", "h264_480p30", "h264_720p30", "h264_1080p30"} {
		for _, f := range []string{"init.mp4", "playlist.m3u8", "seg_00000.m4s"} {
			if !slices.Contains(keys, prefix+rung+"/"+f) {
				t.Errorf("missing %s/%s", rung, f)
			}
		}
	}
	thumbCount := 0
	for _, k := range keys {
		if strings.Contains(k, "/thumbs/") {
			thumbCount++
		}
	}
	if thumbCount != 18 || !slices.Contains(keys, prefix+"master.m3u8") {
		t.Errorf("got %d thumbnails (want 18) and keys %v", thumbCount, keys)
	}

	// Headers players and the CDN depend on.
	for key, want := range map[string][2]string{
		prefix + "master.m3u8":                {"application/vnd.apple.mpegurl", storage.CacheMaster},
		prefix + "h264_720p30/seg_00000.m4s":  {"video/iso.segment", storage.CacheImmutable},
		prefix + "thumbs/50_1280x720.webp":    {"image/webp", storage.CacheImmutable},
		prefix + "h264_1080p30/playlist.m3u8": {"application/vnd.apple.mpegurl", storage.CacheImmutable},
	} {
		head, err := e.s3.HeadObject(context.Background(), &s3.HeadObjectInput{Bucket: aws.String(e.media), Key: aws.String(key)})
		if err != nil {
			t.Fatalf("%s: %v", key, err)
		}
		if aws.ToString(head.ContentType) != want[0] || aws.ToString(head.CacheControl) != want[1] {
			t.Errorf("%s: %s / %s, want %s / %s", key, aws.ToString(head.ContentType), aws.ToString(head.CacheControl), want[0], want[1])
		}
	}

	// One VideoRenditionReady per rung, lowest first, then VideoProcessingCompleted.
	msgs := e.messages()
	if want := []string{"VideoRenditionReady", "VideoRenditionReady", "VideoRenditionReady", "VideoRenditionReady", "VideoProcessingCompleted"}; !slices.Equal(types(msgs), want) {
		t.Fatalf("messages %v, want %v", types(msgs), want)
	}
	heights := []float64{}
	for _, m := range msgs[:4] {
		heights = append(heights, m.Payload["rendition"].(map[string]any)["height"].(float64))
	}
	if !slices.Equal(heights, []float64{360, 480, 720, 1080}) {
		t.Errorf("rendition order %v", heights)
	}
	done := msgs[4].Payload
	if len(done["renditions"].([]any)) != 4 || len(done["thumbnails"].([]any)) != 18 || done["master_playlist_key"] != prefix+"master.m3u8" {
		t.Errorf("completed payload: %v", done)
	}

	// The published tree plays: download it and let ffprobe open the master playlist.
	local := t.TempDir()
	for _, k := range keys {
		dest := filepath.Join(local, strings.TrimPrefix(k, prefix))
		if err := os.MkdirAll(filepath.Dir(dest), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := e.pipeline.Store.Download(context.Background(), e.media, k, dest); err != nil {
			t.Fatal(err)
		}
	}
	out, err := exec.CommandContext(t.Context(), "ffprobe", "-v", "error", "-show_entries", "program=program_id", "-of", "csv=p=0", filepath.Join(local, "master.m3u8")).Output()
	variants := 0
	for _, line := range strings.Split(string(out), "\n") {
		if strings.TrimSpace(line) != "" {
			variants++
		}
	}
	if err != nil || variants != 4 {
		t.Errorf("master playlist doesn't open as 4 variants: %v %q", err, out)
	}
}

func TestRejectedSourceFailsAtOnce(t *testing.T) {
	e := setup(t)
	req := e.request("audio_only.m4a")

	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatalf("a media rejection must not be retried, got %v", err)
	}

	msgs := e.messages()
	if len(msgs) != 1 || msgs[0].EventType != "VideoProcessingFailed" {
		t.Fatalf("messages %v", types(msgs))
	}
	if msgs[0].Payload["failed_step"] != "validate" || msgs[0].Payload["error"].(map[string]any)["code"] != "NO_VIDEO_STREAM" ||
		msgs[0].Payload["error"].(map[string]any)["retryable"] != false {
		t.Errorf("payload %v", msgs[0].Payload)
	}
	if keys := e.keys(e.media, req.Output.Prefix); len(keys) != 0 {
		t.Errorf("outputs written for a rejected source: %v", keys)
	}
}

func TestMissingSourceFailsAtOnce(t *testing.T) {
	e := setup(t)
	req := e.request("") // nothing uploaded

	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatal(err)
	}
	msgs := e.messages()
	if len(msgs) != 1 || msgs[0].Payload["error"].(map[string]any)["code"] != "SOURCE_NOT_FOUND" || msgs[0].Payload["failed_step"] != "download" {
		t.Fatalf("messages %+v", msgs)
	}
}

func TestUnknownProfileFails(t *testing.T) {
	e := setup(t)
	req := e.request("landscape_1080p30.mp4")
	req.Profile = "av1-hdr-v9"

	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatal(err)
	}
	if msgs := e.messages(); len(msgs) != 1 || msgs[0].Payload["error"].(map[string]any)["code"] != "UNSUPPORTED_PROFILE" {
		t.Fatalf("messages %+v", msgs)
	}
}

// flakyStore fails uploads, like S3 being unavailable.
type flakyStore struct{ Storage }

func (flakyStore) Upload(context.Context, string, []storage.File) error {
	return errors.New("503 Slow Down")
}

func TestTransientErrorsAreRetriedThenReported(t *testing.T) {
	e := setup(t)
	e.pipeline.Store = flakyStore{e.pipeline.Store}
	req := e.request("sd_4x3_480p.mp4")

	for attempt := 1; attempt <= 2; attempt++ {
		req.Attempt = attempt
		if err := e.pipeline.Process(context.Background(), req); err == nil {
			t.Fatalf("attempt %d: want an error so SQS retries", attempt)
		}
	}
	if msgs := e.messages(); len(msgs) != 0 {
		t.Fatalf("nothing should be reported before the last attempt, got %v", types(msgs))
	}

	req.Attempt = 3
	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatalf("last attempt: want the failure reported and the message deleted, got %v", err)
	}
	msgs := e.messages()
	if len(msgs) != 1 || msgs[0].Payload["failed_step"] != "upload" ||
		msgs[0].Payload["error"].(map[string]any)["code"] != "RETRIES_EXHAUSTED" || msgs[0].Payload["error"].(map[string]any)["retryable"] != true {
		t.Fatalf("messages %+v", msgs)
	}
}

// interrupting stops the job right after its first rendition is reported, like a worker killed mid-job.
type interrupting struct {
	Results
	cancel context.CancelFunc
	calls  atomic.Int32
}

func (r *interrupting) RenditionReady(ctx context.Context, job results.Job, rd results.Rendition, master string) error {
	err := r.Results.RenditionReady(ctx, job, rd, master)
	if r.calls.Add(1) == 1 {
		r.cancel()
	}
	return err
}

// TestRerunAfterInterruptionLeavesNoDuplicates is S3-08's acceptance in-process: a job stopped
// mid-way and run again ends with exactly the outputs of one clean run, and every message it
// sent twice carries the same event_id (so consumers drop the repeat).
func TestRerunAfterInterruptionLeavesNoDuplicates(t *testing.T) {
	e := setup(t)
	req := e.request("gaming_720p60.mp4")

	ctx, cancel := context.WithCancel(context.Background())
	results := e.pipeline.Results
	e.pipeline.Results = &interrupting{Results: results, cancel: cancel}
	if err := e.pipeline.Process(ctx, req); !errors.Is(err, context.Canceled) {
		t.Fatalf("interrupted run: got %v, want context.Canceled (message released, nothing reported as failed)", err)
	}
	partial := e.keys(e.media, req.Output.Prefix)

	e.pipeline.Results = results
	req.Attempt = 2
	if err := e.pipeline.Process(context.Background(), req); err != nil {
		t.Fatal(err)
	}
	keys := e.keys(e.media, req.Output.Prefix)

	// A clean run of the same job elsewhere is the reference.
	clean := req
	clean.Output.Prefix = "media/clean/v1/"
	if err := e.pipeline.Process(context.Background(), clean); err != nil {
		t.Fatal(err)
	}
	var want []string
	for _, k := range e.keys(e.media, clean.Output.Prefix) {
		want = append(want, strings.Replace(k, clean.Output.Prefix, req.Output.Prefix, 1))
	}
	if !slices.Equal(keys, want) {
		t.Errorf("after rerun:\n got  %v\n want %v", keys, want)
	}
	if len(partial) == 0 || len(partial) >= len(keys) {
		t.Errorf("the interruption should have left a partial output (%d of %d keys)", len(partial), len(keys))
	}

	// Messages of the interrupted run and the rerun (the clean run uses the same job id, so drop it).
	var mine []message
	for _, m := range e.messages() {
		if key, _ := m.Payload["master_playlist_key"].(string); strings.HasPrefix(key, req.Output.Prefix) {
			mine = append(mine, m)
		}
	}
	ids := map[string][]string{}
	for _, m := range mine {
		ids[m.EventID] = append(ids[m.EventID], m.EventType)
	}
	// 3 rungs + completed = 4 distinct events; the first rendition was sent twice with one id.
	if len(mine) != 5 || len(ids) != 4 {
		t.Errorf("got %d messages with %d distinct event ids, want 5 and 4: %v", len(mine), len(ids), ids)
	}
}

func TestCleanScratchRemovesLeftoversOfAKilledProcess(t *testing.T) {
	scratch := t.TempDir()
	for _, d := range []string{"job-123", "job-456/h264_360p30", "keep-me"} {
		if err := os.MkdirAll(filepath.Join(scratch, d), 0o750); err != nil {
			t.Fatal(err)
		}
	}

	n, err := (&Pipeline{Scratch: scratch}).CleanScratch()
	if err != nil || n != 2 {
		t.Fatalf("removed %d, err %v; want 2", n, err)
	}
	left, _ := os.ReadDir(scratch)
	if len(left) != 1 || left[0].Name() != "keep-me" {
		t.Errorf("left %v, want only keep-me", left)
	}
}
