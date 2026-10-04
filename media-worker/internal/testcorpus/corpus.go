// Package testcorpus is the golden corpus of source videos for media-worker tests.
//
// Each sample is a small file generated with ffmpeg on first use (nothing binary is
// committed) and represents a kind of upload real creators send. Its expected outcome is
// data, so every stage (probe and validation now, transcoding later) asserts against the
// same expectations, and CORPUS.md is generated from this file.
//
// Import it only from tests.
package testcorpus

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// Sample is one corpus file. Exactly one of FFmpeg, From or Content builds it.
type Sample struct {
	Name   string
	Covers string // the real-world upload this stands for

	// FFmpeg holds ffmpeg arguments before the output path. "{dir}" is replaced with the
	// corpus directory, so a sample can be derived from an earlier one.
	FFmpeg []string
	// From truncates an earlier sample (an upload cut off mid-transfer).
	From *Truncated
	// Content is written verbatim (files that aren't media at all).
	Content string

	Expect Expect
}

// Truncated is the first Bytes bytes of sample Of.
type Truncated struct {
	Of    string
	Bytes int
}

// Expect is what processing the sample must produce. Zero values mean "don't care".
type Expect struct {
	// Rejection is the media.Rejection code; "" means the file is accepted.
	Rejection string

	Container  string // ffprobe format_name
	VideoCodec string
	// Width and Height are display dimensions (after non-square pixels and rotation).
	Width, Height int
	Rotation      int // counterclockwise, as FFmpeg reports it
	FrameRate     float64
	VFR           bool
	HDR           bool
	Interlaced    bool
	// AudioChannels lists the channel count of each audio stream; empty means no audio.
	AudioChannels []int
	// MVPLadder is the rendition heights (short side) the MVP ladder must produce (ADR-004:
	// 360/480/720/1080, never above the source). Asserted by the transcoder tests (S2-10).
	MVPLadder []int
}

var (
	testPattern = func(size string, rate string, seconds string) []string {
		return []string{"-f", "lavfi", "-i", "testsrc2=s=" + size + ":r=" + rate + ":d=" + seconds}
	}
	tone     = []string{"-f", "lavfi", "-i", "sine=f=440:d=2"}
	x264     = []string{"-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p"}
	aacMono  = []string{"-c:a", "aac", "-shortest"}
	mp4      = "mov,mp4,m4a,3gp,3g2,mj2"
	allRungs = []int{360, 480, 720, 1080}
	upTo720  = []int{360, 480, 720}
	upTo480  = []int{360, 480}
)

// cat concatenates argument lists.
func cat(parts ...[]string) []string {
	var out []string
	for _, p := range parts {
		out = append(out, p...)
	}
	return out
}

// Samples is the corpus. Order matters: derived samples come after their source.
var Samples = []Sample{
	{
		Name: "landscape_1080p30.mp4", Covers: "Typical 16:9 upload: H.264 + AAC in MP4",
		FFmpeg: cat(testPattern("1920x1080", "30", "2"), tone, x264, aacMono),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1920, Height: 1080, FrameRate: 30, AudioChannels: []int{1}, MVPLadder: allRungs},
	},
	{
		Name: "vertical_1080x1920.mp4", Covers: "Vertical (9:16) video stored upright",
		FFmpeg: cat(testPattern("1080x1920", "30", "2"), x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1080, Height: 1920, FrameRate: 30, MVPLadder: allRungs},
	},
	{
		Name: "phone_rotated.mp4", Covers: "Phone portrait recording: landscape frames + 90° display rotation",
		FFmpeg: []string{"-display_rotation", "90", "-i", "{dir}/landscape_1080p30.mp4", "-c", "copy"},
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1080, Height: 1920, Rotation: 90, FrameRate: 30, AudioChannels: []int{1}, MVPLadder: allRungs},
	},
	{
		Name: "sd_4x3_480p.mp4", Covers: "Old 4:3 SD footage",
		FFmpeg: cat(testPattern("640x480", "30", "2"), x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 640, Height: 480, FrameRate: 30, MVPLadder: upTo480},
	},
	{
		Name: "uhd_4k_2160p24.mp4", Covers: "Source above 1080p (4K camera)",
		FFmpeg: cat(testPattern("3840x2160", "24", "1"), x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 3840, Height: 2160, FrameRate: 24, MVPLadder: allRungs},
	},
	{
		Name: "gaming_720p60.mp4", Covers: "60 fps gameplay capture",
		FFmpeg: cat(testPattern("1280x720", "60", "2"), x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1280, Height: 720, FrameRate: 60, MVPLadder: upTo720},
	},
	{
		Name: "variable_frame_rate.mp4", Covers: "VFR phone/screen recording (30 fps, then 10 fps)",
		FFmpeg: cat(testPattern("1280x720", "30", "3"), []string{"-vf", "setpts=if(lt(N\\,30)\\,N\\,30+(N-30)*3)/30/TB", "-fps_mode", "passthrough"}, x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1280, Height: 720, VFR: true, MVPLadder: upTo720},
	},
	{
		Name: "no_audio.mp4", Covers: "Silent screen recording, no audio stream",
		FFmpeg: cat(testPattern("1920x1080", "30", "2"), x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1920, Height: 1080, FrameRate: 30, MVPLadder: allRungs},
	},
	{
		Name: "surround_5_1.mov", Covers: "QuickTime export with 5.1 surround audio",
		FFmpeg: cat(testPattern("1920x1080", "25", "2"), []string{"-f", "lavfi", "-i", "sine=f=440:d=2", "-ac", "6"}, x264, []string{"-c:a", "aac", "-shortest", "-f", "mov"}),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 1920, Height: 1080, FrameRate: 25, AudioChannels: []int{6}, MVPLadder: allRungs},
	},
	{
		Name: "webm_vp9_opus.webm", Covers: "Browser-recorded WebM (VP9 + Opus)",
		FFmpeg: cat(testPattern("1280x720", "30", "2"), tone, []string{"-c:v", "libvpx-vp9", "-deadline", "realtime", "-cpu-used", "8", "-b:v", "500k", "-c:a", "libopus", "-shortest"}),
		Expect: Expect{Container: "matroska,webm", VideoCodec: "vp9", Width: 1280, Height: 720, FrameRate: 30, AudioChannels: []int{1}, MVPLadder: upTo720},
	},
	{
		Name: "hdr10_hevc_10bit.mp4", Covers: "HDR10 HEVC 10-bit (newer phones and cameras)",
		FFmpeg: cat(testPattern("1280x720", "24", "2"), []string{"-c:v", "libx265", "-preset", "ultrafast", "-x265-params", "log-level=error", "-pix_fmt", "yuv420p10le", "-color_primaries", "bt2020", "-color_trc", "smpte2084", "-colorspace", "bt2020nc"}),
		Expect: Expect{Container: mp4, VideoCodec: "hevc", Width: 1280, Height: 720, FrameRate: 24, HDR: true, MVPLadder: upTo720},
	},
	{
		Name: "anamorphic_dvd.mp4", Covers: "DVD rip: 720x480 with non-square (32:27) pixels, 29.97 fps",
		FFmpeg: cat(testPattern("720x480", "30000/1001", "2"), []string{"-vf", "setsar=32/27"}, x264),
		Expect: Expect{Container: mp4, VideoCodec: "h264", Width: 853, Height: 480, FrameRate: 29.97, MVPLadder: upTo480},
	},
	{
		Name: "interlaced_1080i.mkv", Covers: "Broadcast/camcorder 1080i (interlaced) in Matroska",
		FFmpeg: cat(testPattern("1920x1080", "25", "2"), []string{"-flags", "+ildct+ilme", "-top", "1"}, x264),
		Expect: Expect{Container: "matroska,webm", VideoCodec: "h264", Width: 1920, Height: 1080, FrameRate: 25, Interlaced: true, MVPLadder: allRungs},
	},
	{
		Name: "legacy_mpeg4_avi.avi", Covers: "Old camera/AVI export with MPEG-4 Part 2 and MP3",
		FFmpeg: cat(testPattern("640x480", "25", "2"), tone, []string{"-c:v", "mpeg4", "-q:v", "5", "-c:a", "libmp3lame", "-shortest"}),
		Expect: Expect{Container: "avi", VideoCodec: "mpeg4", Width: 640, Height: 480, FrameRate: 25, AudioChannels: []int{1}, MVPLadder: upTo480},
	},
	{
		Name: "mpegts_broadcast.ts", Covers: "MPEG-TS from a capture card or recorder",
		FFmpeg: cat(testPattern("1280x720", "25", "2"), tone, x264, []string{"-c:a", "aac", "-shortest", "-f", "mpegts"}),
		Expect: Expect{Container: "mpegts", VideoCodec: "h264", Width: 1280, Height: 720, FrameRate: 25, AudioChannels: []int{1}, MVPLadder: upTo720},
	},
	{
		Name: "audio_only.m4a", Covers: "Podcast/music file uploaded as a video",
		FFmpeg: cat(tone, []string{"-c:a", "aac"}),
		Expect: Expect{Rejection: "NO_VIDEO_STREAM"},
	},
	{
		Name: "audio_with_cover_art.m4a", Covers: "Music file with album art (the art is a 'video' stream FFmpeg marks attached_pic)",
		FFmpeg: cat(tone, []string{"-f", "lavfi", "-i", "testsrc2=s=300x300:d=0.04", "-map", "0", "-map", "1", "-c:a", "aac", "-c:v", "mjpeg", "-frames:v", "1", "-disposition:v", "attached_pic"}),
		Expect: Expect{Rejection: "NO_VIDEO_STREAM"},
	},
	{
		Name: "tiny_64x64.mp4", Covers: "Icon-sized clip below the 128 px minimum",
		FFmpeg: cat(testPattern("64x64", "30", "2"), x264),
		Expect: Expect{Rejection: "RESOLUTION_OUT_OF_RANGE"},
	},
	{
		Name: "too_short_0.4s.mp4", Covers: "Clip shorter than the 1 s minimum",
		FFmpeg: cat(testPattern("640x360", "30", "0.4"), x264),
		Expect: Expect{Rejection: "DURATION_TOO_SHORT"},
	},
	{
		Name: "flash_video.flv", Covers: "Real video in a container we don't accept (FLV)",
		FFmpeg: cat(testPattern("640x360", "25", "2"), []string{"-c:v", "flv"}),
		Expect: Expect{Rejection: "NOT_A_VIDEO"},
	},
	{
		Name: "truncated.mp4", Covers: "Upload cut off before the moov atom",
		From:   &Truncated{Of: "no_audio.mp4", Bytes: 4096},
		Expect: Expect{Rejection: "CORRUPT_SOURCE"},
	},
	{
		Name: "not_a_video.txt", Covers: "Text file renamed by a user",
		Content: "this is a text file renamed by a user\n",
		Expect:  Expect{Rejection: "NOT_A_VIDEO"},
	},
	{
		Name: "hls_playlist_ssrf.m3u8", Covers: "Malicious playlist that would make FFmpeg fetch the cloud metadata endpoint",
		Content: "#EXTM3U\n#EXTINF:1,\nhttp://169.254.169.254/latest/meta-data/\n",
		Expect:  Expect{Rejection: "NOT_A_VIDEO"},
	},
}

var (
	once        sync.Once
	dir         string
	genErr      error
	errNoFFmpeg = errors.New("ffmpeg not installed")
)

// Path returns the path of a sample, generating the corpus on first use. Without ffmpeg the
// test is skipped, except in CI, where it fails.
func Path(t testing.TB, name string) string {
	t.Helper()
	return filepath.Join(Dir(t), name)
}

// Dir returns the corpus directory, generating it on first use.
//
// The corpus is cached in the OS temp directory under a hash of the sample definitions, so
// it is generated once and shared by every package and run until a definition changes.
func Dir(t testing.TB) string {
	t.Helper()
	once.Do(func() { dir, genErr = ensure() })
	if errors.Is(genErr, errNoFFmpeg) {
		if os.Getenv("CI") != "" {
			t.Fatal("ffmpeg is required in CI")
		}
		t.Skip("ffmpeg not installed; skipping corpus tests")
	}
	if genErr != nil {
		t.Fatal(genErr)
	}
	return dir
}

func ensure() (string, error) {
	if _, err := exec.LookPath("ffmpeg"); err != nil {
		return "", errNoFFmpeg
	}
	definition, err := json.Marshal(Samples)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(definition)
	final := filepath.Join(os.TempDir(), "videoplatform-corpus-"+hex.EncodeToString(sum[:8]))
	if _, err := os.Stat(final); err == nil {
		return final, nil
	}

	// Generate into a private directory, then rename it into place: concurrent test
	// processes (go test runs packages in parallel) never see a half-built corpus.
	tmp, err := os.MkdirTemp(os.TempDir(), "videoplatform-corpus-build-")
	if err != nil {
		return "", err
	}
	defer func() { _ = os.RemoveAll(tmp) }()
	for _, s := range Samples {
		if err := s.build(tmp); err != nil {
			return "", fmt.Errorf("generate %s: %w", s.Name, err)
		}
	}
	if err := os.Rename(tmp, final); err != nil {
		if _, statErr := os.Stat(final); statErr == nil {
			return final, nil // another process won the race
		}
		return "", err
	}
	return final, nil
}

func (s Sample) build(dir string) error {
	out := filepath.Join(dir, s.Name)
	switch {
	case s.FFmpeg != nil:
		args := []string{"-v", "error", "-y"}
		for _, a := range s.FFmpeg {
			args = append(args, strings.ReplaceAll(a, "{dir}", dir))
		}
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		if output, err := exec.CommandContext(ctx, "ffmpeg", append(args, out)...).CombinedOutput(); err != nil { // #nosec G204 -- fixed test definitions
			return fmt.Errorf("%w: %s", err, output)
		}
		return nil
	case s.From != nil:
		data, err := os.ReadFile(filepath.Join(dir, s.From.Of)) // #nosec G304 -- corpus file
		if err != nil {
			return err
		}
		return os.WriteFile(out, data[:min(s.From.Bytes, len(data))], 0o600)
	default:
		return os.WriteFile(out, []byte(s.Content), 0o600)
	}
}
