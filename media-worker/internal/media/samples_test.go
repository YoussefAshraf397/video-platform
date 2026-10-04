package media

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

// Sample files are generated with ffmpeg at test time (small, deterministic, nothing binary
// in git). Each one targets an edge case real uploads have. S1-12 extends this into the
// golden corpus.
type sample struct {
	name string
	// make writes the file into dir.
	make func(dir, path string) error
}

func ffmpeg(args ...string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	base := []string{"-v", "error", "-y"}
	out, err := exec.CommandContext(ctx, "ffmpeg", append(base, args...)...).CombinedOutput() // #nosec G204 -- test fixtures
	if err != nil {
		return fmt.Errorf("%w: %s", err, out)
	}
	return nil
}

func encode(input []string, output ...string) func(dir, path string) error {
	return func(_, path string) error {
		return ffmpeg(append(append(input, output...), path)...)
	}
}

var (
	video1080p  = []string{"-f", "lavfi", "-i", "testsrc2=s=1920x1080:r=30:d=2"}
	tone        = []string{"-f", "lavfi", "-i", "sine=f=440:d=2"}
	x264        = []string{"-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p"}
	aac         = []string{"-c:a", "aac", "-shortest"}
	sampleFiles = []sample{
		{"landscape_1080p30.mp4", encode(append(video1080p, tone...), append(x264, aac...)...)},
		{"vertical_1080x1920.mp4", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=1080x1920:r=30:d=2"}, x264...)},
		{"phone_rotated.mp4", func(dir, path string) error {
			// Phones store landscape frames plus a display rotation.
			src := filepath.Join(dir, "landscape_1080p30.mp4")
			return ffmpeg("-display_rotation", "90", "-i", src, "-c", "copy", path)
		}},
		{"no_audio.mp4", encode(video1080p, x264...)},
		{"audio_only.m4a", encode(tone, "-c:a", "aac")},
		{"audio_with_cover_art.m4a", encode(
			append(tone, "-f", "lavfi", "-i", "testsrc2=s=300x300:d=0.04"),
			"-map", "0", "-map", "1", "-c:a", "aac", "-c:v", "mjpeg", "-frames:v", "1", "-disposition:v", "attached_pic")},
		{"hdr10_hevc_10bit.mp4", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=1280x720:r=24:d=2"},
			"-c:v", "libx265", "-preset", "ultrafast", "-x265-params", "log-level=error", "-pix_fmt", "yuv420p10le",
			"-color_primaries", "bt2020", "-color_trc", "smpte2084", "-colorspace", "bt2020nc")},
		{"anamorphic_dvd.mp4", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=720x480:r=30000/1001:d=2"},
			append([]string{"-vf", "setsar=32/27"}, x264...)...)},
		{"interlaced_1080i.mkv", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=1920x1080:r=25:d=2"},
			append([]string{"-flags", "+ildct+ilme", "-top", "1"}, x264...)...)},
		{"tiny_64x64.mp4", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=64x64:r=30:d=2"}, x264...)},
		{"too_short_0.4s.mp4", encode([]string{"-f", "lavfi", "-i", "testsrc2=s=640x360:r=30:d=0.4"}, x264...)},
		{"truncated.mp4", func(dir, path string) error {
			// An upload cut off before the moov atom.
			data, err := os.ReadFile(filepath.Join(dir, "no_audio.mp4"))
			if err != nil {
				return err
			}
			return os.WriteFile(path, data[:4096], 0o600)
		}},
		{"not_a_video.txt", func(_, path string) error {
			return os.WriteFile(path, []byte("this is a text file renamed by a user\n"), 0o600)
		}},
		{"hls_playlist_ssrf.m3u8", func(_, path string) error {
			// A playlist that would make FFmpeg fetch the cloud metadata endpoint.
			return os.WriteFile(path, []byte("#EXTM3U\n#EXTINF:1,\nhttp://169.254.169.254/latest/meta-data/\n"), 0o600)
		}},
	}

	samplesOnce sync.Once
	samplesDir  string
	samplesErr  error
)

// samplePath returns the path of a generated sample, generating all samples on first use.
func samplePath(t *testing.T, name string) string {
	t.Helper()
	if _, err := exec.LookPath("ffmpeg"); err != nil {
		if os.Getenv("CI") != "" {
			t.Fatal("ffmpeg is required in CI")
		}
		t.Skip("ffmpeg not installed; skipping tests that need sample files")
	}
	samplesOnce.Do(func() {
		samplesDir, samplesErr = os.MkdirTemp("", "media-samples-")
		for _, s := range sampleFiles {
			if err := s.make(samplesDir, filepath.Join(samplesDir, s.name)); err != nil {
				samplesErr = fmt.Errorf("generate %s: %w", s.name, err)
				return
			}
		}
	})
	if samplesErr != nil {
		t.Fatal(samplesErr)
	}
	return filepath.Join(samplesDir, name)
}

func TestMain(m *testing.M) {
	code := m.Run()
	if samplesDir != "" {
		_ = os.RemoveAll(samplesDir)
	}
	os.Exit(code)
}
