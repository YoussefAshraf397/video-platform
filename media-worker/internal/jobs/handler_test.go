package jobs

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"videoplatform/contracts"
	"videoplatform/media-worker/internal/sqsworker"
)

func newHandler(t *testing.T, process Processor) *Handler {
	t.Helper()
	v, err := contracts.NewValidator()
	if err != nil {
		t.Fatal(err)
	}
	return NewHandler(v, process, func(context.Context, CaptionsRequest) error {
		t.Error("a MediaProcessRequested message reached the captions processor")
		return nil
	})
}

func readExample(t *testing.T, name string) string {
	t.Helper()
	data, err := os.ReadFile("../../../contracts/examples/" + name + ".json")
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func exampleMessage(t *testing.T) string { return readExample(t, MediaProcessRequestedSchema) }

func TestValidMessageIsDecodedAndProcessed(t *testing.T) {
	var got ProcessRequest
	h := newHandler(t, func(_ context.Context, req ProcessRequest) error {
		got = req
		return nil
	})

	if err := h.Handle(context.Background(), sqsworker.Message{Body: exampleMessage(t)}); err != nil {
		t.Fatal(err)
	}

	want := ProcessRequest{
		EventID:           "0199b1f2-6a10-7c3e-9d41-2f6b8a1c0e01",
		VideoID:           "0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01",
		JobID:             "0199b1f2-2222-7ccc-8ddd-0e0e0e0e0e01",
		ProcessingVersion: 1,
		Source:            S3Object{Bucket: "uploads", Key: "uploads/0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01/0199b1f1-3333-7eee-8fff-0a0a0a0a0a01/source"},
		Output:            S3Prefix{Bucket: "media", Prefix: "media/0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01/v1/"},
		Profile:           "h264-sdr-v1",
	}
	if got != want {
		t.Errorf("got %+v\nwant %+v", got, want)
	}
}

func TestMessageBreakingTheContractIsPoison(t *testing.T) {
	called := false
	h := newHandler(t, func(context.Context, ProcessRequest) error { called = true; return nil })

	body := strings.Replace(exampleMessage(t), `"profile": "h264-sdr-v1"`, `"profile": "H264 SDR"`, 1)
	err := h.Handle(context.Background(), sqsworker.Message{Body: body})

	if err == nil || !strings.Contains(err.Error(), "poison") {
		t.Fatalf("expected a poison error, got %v", err)
	}
	if called {
		t.Error("processor must not run for an invalid message")
	}
}

func TestProcessorErrorsAreReturnedForRetry(t *testing.T) {
	boom := errors.New("transient")
	h := newHandler(t, func(context.Context, ProcessRequest) error { return boom })

	if err := h.Handle(context.Background(), sqsworker.Message{Body: exampleMessage(t)}); !errors.Is(err, boom) {
		t.Fatalf("got %v, want %v", err, boom)
	}
}

func TestCaptionsRequestGoesToTheCaptionsProcessor(t *testing.T) {
	v, err := contracts.NewValidator()
	if err != nil {
		t.Fatal(err)
	}
	var got CaptionsRequest
	h := NewHandler(v,
		func(context.Context, ProcessRequest) error { t.Error("went to the transcoder"); return nil },
		func(_ context.Context, req CaptionsRequest) error { got = req; return nil })

	if err := h.Handle(context.Background(), sqsworker.Message{Body: readExample(t, MediaCaptionsRequestedSchema), ReceiveCount: 2}); err != nil {
		t.Fatal(err)
	}

	if got.EventID != "0199b1f3-0004-7c3e-9d41-2f6b8a1c0e05" || got.Attempt != 2 || got.Revision != 2 ||
		got.BaseMasterKey != "media/0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01/v1/master.m3u8" || len(got.Tracks) != 2 {
		t.Fatalf("got %+v", got)
	}
	if want := (CaptionTrack{
		SubtitleID: "0199b1f4-3333-7eee-8fff-0a0a0a0a0a02", Language: "pt-BR", Label: "Português (Brasil)", Kind: "subtitles", Format: "vtt",
		Source: S3Object{Bucket: "uploads", Key: "captions/0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01/0199b1f4-3333-7eee-8fff-0a0a0a0a0a02/source"},
	}); got.Tracks[1] != want {
		t.Errorf("track: got %+v\nwant %+v", got.Tracks[1], want)
	}
}

func TestUnknownCommandIsPoison(t *testing.T) {
	h := newHandler(t, func(context.Context, ProcessRequest) error { t.Error("processed"); return nil })

	body := strings.Replace(exampleMessage(t), `"MediaProcessRequested"`, `"MediaExplodeRequested"`, 1)
	if err := h.Handle(context.Background(), sqsworker.Message{Body: body}); err == nil || !strings.Contains(err.Error(), "poison") {
		t.Fatalf("expected a poison error, got %v", err)
	}
}
