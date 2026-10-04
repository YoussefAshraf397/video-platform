package media

import (
	"context"
	"math"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// outcome is what probing + validating a sample must produce.
type outcome struct {
	rejection string // "" = accepted
	check     func(t *testing.T, r *Result)
}

var expected = map[string]outcome{
	"landscape_1080p30.mp4": {check: func(t *testing.T, r *Result) {
		assertVideo(t, r, "h264", 1920, 1080, 0)
		assertFloat(t, "frame rate", r.Video.FrameRate, 30)
		if len(r.Audio) != 1 || r.Audio[0].Codec != "aac" || r.Audio[0].Channels != 1 {
			t.Errorf("audio = %+v, want one mono aac stream", r.Audio)
		}
		if r.Duration < 1900*time.Millisecond || r.Duration > 2100*time.Millisecond {
			t.Errorf("duration = %s, want ~2s", r.Duration)
		}
		if r.FormatName != "mov,mp4,m4a,3gp,3g2,mj2" || r.SizeBytes == 0 || r.BitRate == 0 {
			t.Errorf("format = %q size=%d bitrate=%d", r.FormatName, r.SizeBytes, r.BitRate)
		}
	}},
	"vertical_1080x1920.mp4": {check: func(t *testing.T, r *Result) {
		assertVideo(t, r, "h264", 1080, 1920, 0)
	}},
	"phone_rotated.mp4": {check: func(t *testing.T, r *Result) {
		// Stored 1920x1080, displayed portrait.
		if r.Video.Width != 1920 || r.Video.Height != 1080 {
			t.Errorf("coded = %dx%d, want 1920x1080", r.Video.Width, r.Video.Height)
		}
		assertVideo(t, r, "h264", 1080, 1920, 90)
	}},
	"no_audio.mp4": {check: func(t *testing.T, r *Result) {
		assertVideo(t, r, "h264", 1920, 1080, 0)
		if len(r.Audio) != 0 {
			t.Errorf("audio = %+v, want none", r.Audio)
		}
	}},
	"audio_only.m4a":           {rejection: CodeNoVideoStream},
	"audio_with_cover_art.m4a": {rejection: CodeNoVideoStream}, // cover art is not video
	"hdr10_hevc_10bit.mp4": {check: func(t *testing.T, r *Result) {
		assertVideo(t, r, "hevc", 1280, 720, 0)
		if !r.Video.HDR || r.Video.BitDepth != 10 || r.Video.ColorTransfer != "smpte2084" || r.Video.ColorPrimaries != "bt2020" {
			t.Errorf("HDR=%v depth=%d transfer=%q primaries=%q", r.Video.HDR, r.Video.BitDepth, r.Video.ColorTransfer, r.Video.ColorPrimaries)
		}
	}},
	"anamorphic_dvd.mp4": {check: func(t *testing.T, r *Result) {
		// 720x480 with 32:27 pixels is shown as 16:9.
		assertVideo(t, r, "h264", 853, 480, 0)
		if r.Video.SampleAspectRatio != "32:27" {
			t.Errorf("SAR = %q", r.Video.SampleAspectRatio)
		}
		assertFloat(t, "frame rate", r.Video.FrameRate, 29.97)
	}},
	"interlaced_1080i.mkv": {check: func(t *testing.T, r *Result) {
		assertVideo(t, r, "h264", 1920, 1080, 0)
		if !r.Video.Interlaced {
			t.Error("not detected as interlaced")
		}
		if r.FormatName != "matroska,webm" {
			t.Errorf("format = %q", r.FormatName)
		}
	}},
	"tiny_64x64.mp4":         {rejection: CodeResolutionOutOfRange},
	"too_short_0.4s.mp4":     {rejection: CodeDurationTooShort},
	"truncated.mp4":          {rejection: CodeCorruptSource},
	"not_a_video.txt":        {rejection: CodeNotAVideo},
	"hls_playlist_ssrf.m3u8": {rejection: CodeNotAVideo},
}

func TestSamples(t *testing.T) {
	prober := Prober{}
	for _, s := range sampleFiles {
		t.Run(s.name, func(t *testing.T) {
			want, ok := expected[s.name]
			if !ok {
				t.Fatalf("no expected outcome for %s", s.name)
			}
			result, err := prober.Probe(context.Background(), samplePath(t, s.name))
			if err == nil {
				err = Validate(result, DefaultLimits())
			}

			if want.rejection == "" {
				if err != nil {
					t.Fatalf("rejected: %v", err)
				}
				want.check(t, result)
				return
			}
			rejection, ok := AsRejection(err)
			if !ok {
				t.Fatalf("got %v, want rejection %s", err, want.rejection)
			}
			if rejection.Code != want.rejection {
				t.Errorf("code = %s (%s), want %s", rejection.Code, rejection.Message, want.rejection)
			}
		})
	}
}

func TestFFprobeRefusesFormatsThatOpenOtherFiles(t *testing.T) {
	// Defense in depth behind sniffing: even if a playlist reached ffprobe, the demuxer
	// allowlist stops FFmpeg from following it to other files (or, with more protocols, URLs).
	target := samplePath(t, "no_audio.mp4")
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
	_, err := Prober{FFprobe: "/nonexistent/ffprobe"}.Probe(context.Background(), samplePath(t, "no_audio.mp4"))
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

func assertVideo(t *testing.T, r *Result, codec string, displayW, displayH, rotation int) {
	t.Helper()
	if r.Video == nil {
		t.Fatal("no video stream")
	}
	v := r.Video
	if v.Codec != codec || v.DisplayWidth != displayW || v.DisplayHeight != displayH || v.Rotation != rotation {
		t.Errorf("video = %s %dx%d rot %d, want %s %dx%d rot %d",
			v.Codec, v.DisplayWidth, v.DisplayHeight, v.Rotation, codec, displayW, displayH, rotation)
	}
}

func assertFloat(t *testing.T, name string, got, want float64) {
	t.Helper()
	if math.Abs(got-want) > 0.01 {
		t.Errorf("%s = %v, want %v", name, got, want)
	}
}
