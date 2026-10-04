package media

import (
	"context"
	"math"
	"os"
	"path/filepath"
	"slices"
	"testing"
	"time"

	"videoplatform/media-worker/internal/testcorpus"
)

// TestCorpus probes and validates every golden-corpus sample and checks the outcome
// declared in testcorpus (documented in internal/testcorpus/CORPUS.md).
func TestCorpus(t *testing.T) {
	for _, s := range testcorpus.Samples {
		t.Run(s.Name, func(t *testing.T) {
			want := s.Expect
			result, err := Prober{}.Probe(context.Background(), testcorpus.Path(t, s.Name))
			if err == nil {
				err = Validate(result, DefaultLimits())
			}

			if want.Rejection != "" {
				rejection, ok := AsRejection(err)
				if !ok {
					t.Fatalf("got %v, want rejection %s", err, want.Rejection)
				}
				if rejection.Code != want.Rejection {
					t.Errorf("code = %s (%s), want %s", rejection.Code, rejection.Message, want.Rejection)
				}
				return
			}
			if err != nil {
				t.Fatalf("rejected: %v", err)
			}
			assertMatches(t, result, want)
		})
	}
}

func assertMatches(t *testing.T, r *Result, want testcorpus.Expect) {
	t.Helper()
	v := r.Video
	if v == nil {
		t.Fatal("no video stream")
	}
	check := func(field string, got, expected any) {
		t.Helper()
		if got != expected {
			t.Errorf("%s = %v, want %v", field, got, expected)
		}
	}
	check("container", r.FormatName, want.Container)
	check("video codec", v.Codec, want.VideoCodec)
	check("display size", [2]int{v.DisplayWidth, v.DisplayHeight}, [2]int{want.Width, want.Height})
	check("rotation", v.Rotation, want.Rotation)
	check("variable frame rate", v.VariableFrameRate, want.VFR)
	check("HDR", v.HDR, want.HDR)
	check("interlaced", v.Interlaced, want.Interlaced)
	if want.FrameRate != 0 && math.Abs(v.FrameRate-want.FrameRate) > 0.01 {
		t.Errorf("frame rate = %v, want %v", v.FrameRate, want.FrameRate)
	}
	var channels []int
	for _, a := range r.Audio {
		channels = append(channels, a.Channels)
	}
	if !slices.Equal(channels, want.AudioChannels) {
		t.Errorf("audio channels = %v, want %v", channels, want.AudioChannels)
	}
	if r.Duration < time.Second || r.SizeBytes == 0 {
		t.Errorf("duration = %s, size = %d", r.Duration, r.SizeBytes)
	}
}

func TestDetailsTheCorpusTableDoesNotCover(t *testing.T) {
	ctx := context.Background()

	rotated, err := Prober{}.Probe(ctx, testcorpus.Path(t, "phone_rotated.mp4"))
	if err != nil {
		t.Fatal(err)
	}
	if rotated.Video.Width != 1920 || rotated.Video.Height != 1080 {
		t.Errorf("coded size = %dx%d, want 1920x1080 (portrait comes from rotation)", rotated.Video.Width, rotated.Video.Height)
	}

	hdr, err := Prober{}.Probe(ctx, testcorpus.Path(t, "hdr10_hevc_10bit.mp4"))
	if err != nil {
		t.Fatal(err)
	}
	if hdr.Video.BitDepth != 10 || hdr.Video.ColorTransfer != "smpte2084" || hdr.Video.ColorPrimaries != "bt2020" {
		t.Errorf("depth=%d transfer=%q primaries=%q", hdr.Video.BitDepth, hdr.Video.ColorTransfer, hdr.Video.ColorPrimaries)
	}

	dvd, err := Prober{}.Probe(ctx, testcorpus.Path(t, "anamorphic_dvd.mp4"))
	if err != nil {
		t.Fatal(err)
	}
	if dvd.Video.SampleAspectRatio != "32:27" {
		t.Errorf("SAR = %q, want 32:27", dvd.Video.SampleAspectRatio)
	}
}

func TestFFprobeRefusesFormatsThatOpenOtherFiles(t *testing.T) {
	// Defense in depth behind sniffing: even if a playlist reached ffprobe, the demuxer
	// allowlist stops FFmpeg from following it to other files (or, with more protocols, URLs).
	target := testcorpus.Path(t, "no_audio.mp4")
	playlist := filepath.Join(t.TempDir(), "follow.m3u8")
	content := "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXTINF:2,\nfile://" + target + "\n#EXT-X-ENDLIST\n"
	if err := os.WriteFile(playlist, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}

	_, err := Prober{}.ffprobe(context.Background(), playlist, "test")
	if r, ok := AsRejection(err); !ok || r.Code != CodeCorruptSource {
		t.Fatalf("ffprobe opened the playlist (err = %v); the format allowlist is not applied", err)
	}
}

func TestMissingFFprobeIsAnInfrastructureError(t *testing.T) {
	_, err := Prober{FFprobe: "/nonexistent/ffprobe"}.Probe(context.Background(), testcorpus.Path(t, "no_audio.mp4"))
	if err == nil {
		t.Fatal("expected an error")
	}
	if _, ok := AsRejection(err); ok {
		t.Error("a missing binary must not be reported as a problem with the creator's file")
	}
}

func TestMissingSourceIsAnInfrastructureError(t *testing.T) {
	_, err := Prober{}.Probe(context.Background(), filepath.Join(t.TempDir(), "gone.mp4"))
	if _, ok := AsRejection(err); err == nil || ok {
		t.Fatalf("got %v, want a non-rejection error", err)
	}
}
