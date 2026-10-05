// Package transcode encodes one rendition of a source and packages it as CMAF HLS (ADR-004):
// H.264 High + AAC-LC 128 kbps stereo, fMP4 segments of exactly 4 s, closed GOPs aligned to
// segment boundaries, constant frame rate.
//
// Encoder is the seam ADR-006 asks for, so a MediaConvert implementation could replace FFmpeg.
package transcode

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"

	"videoplatform/media-worker/internal/ladder"
	"videoplatform/media-worker/internal/media"
)

// Output files of one rendition, relative to its directory.
const (
	PlaylistFile = "playlist.m3u8"
	InitFile     = "init.mp4"
	segmentGlob  = "seg_*.m4s"
)

// Encoder produces one rendition directory from a source.
type Encoder interface {
	Encode(ctx context.Context, source string, probe *media.Result, r ladder.Rendition, dir string) error
}

// FFmpeg is the Encoder that runs ffmpeg locally.
type FFmpeg struct {
	Bin string // default "ffmpeg"
	// Preset is the x264 speed/quality trade-off. Default "veryfast"; tests use "ultrafast".
	Preset string
}

// EncoderError means ffmpeg failed on a source that probed fine. It is retried (it can be a
// resource problem); repeated failures end as ENCODER_FAILED.
type EncoderError struct{ Stderr string }

func (e *EncoderError) Error() string { return "ffmpeg failed: " + e.Stderr }

// Encode writes playlist.m3u8, init.mp4 and seg_00000.m4s… into dir.
func (f FFmpeg) Encode(ctx context.Context, source string, probe *media.Result, r ladder.Rendition, dir string) error {
	if probe.Video == nil {
		return errors.New("source has no video stream")
	}
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return err
	}
	abs, err := filepath.Abs(source)
	if err != nil {
		return err
	}
	bin, preset := f.Bin, f.Preset
	if bin == "" {
		bin = "ffmpeg"
	}
	if preset == "" {
		preset = "veryfast"
	}

	gop := strconv.Itoa(r.GOP())
	level, _ := r.Level()
	args := []string{
		"-nostdin", "-hide_banner", "-loglevel", "error", "-y",
		// The same guards as the probe (internal/media): only video demuxers, only local files.
		"-format_whitelist", media.AllowedFormats, "-protocol_whitelist", "file",
		"-i", "file:" + abs,
		// The primary video stream by absolute index (cover art is also a "video" stream), first audio if any.
		"-map", "0:" + strconv.Itoa(probe.Video.Index), "-map", "0:a:0?",
		"-vf", filters(probe, r),
		"-c:v", "libx264", "-profile:v", "high", "-level:v", level, "-preset", preset,
		"-b:v", strconv.Itoa(r.Bitrate), "-maxrate", strconv.Itoa(r.PeakBitrate), "-bufsize", strconv.Itoa(2 * r.Bitrate),
		// A keyframe exactly every segment, nowhere else, so all renditions switch on the same boundaries.
		"-g", gop, "-keyint_min", gop, "-sc_threshold", "0",
		"-force_key_frames", fmt.Sprintf("expr:gte(t,n_forced*%d)", ladder.SegmentSeconds),
		"-c:a", "aac", "-b:a", "128k", "-ac", "2", "-ar", "48000",
		"-f", "hls", "-hls_time", strconv.Itoa(ladder.SegmentSeconds), "-hls_playlist_type", "vod",
		"-hls_segment_type", "fmp4", "-hls_fmp4_init_filename", InitFile,
		"-hls_flags", "independent_segments",
		"-hls_segment_filename", filepath.Join(dir, "seg_%05d.m4s"),
		filepath.Join(dir, PlaylistFile),
	}

	var stderr bytes.Buffer
	// #nosec G204 -- fixed arguments; the source path is passed as a "file:" URL.
	cmd := exec.CommandContext(ctx, bin, args...)
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			return &EncoderError{Stderr: lastLines(stderr.String(), 5)}
		}
		return fmt.Errorf("run ffmpeg: %w", err)
	}
	return nil
}

// Files lists a finished rendition's files (playlist, init segment, media segments).
func Files(dir string) ([]string, error) {
	segments, err := filepath.Glob(filepath.Join(dir, segmentGlob))
	if err != nil {
		return nil, err
	}
	if len(segments) == 0 {
		return nil, fmt.Errorf("no segments in %s", dir)
	}
	return append([]string{filepath.Join(dir, InitFile), filepath.Join(dir, PlaylistFile)}, segments...), nil
}

// filters builds the video filter chain: SourceFilters, then scale to the rendition size with
// square pixels (FFmpeg has already applied the display rotation), then constant frame rate.
func filters(probe *media.Result, r ladder.Rendition) string {
	chain := append(SourceFilters(probe),
		fmt.Sprintf("scale=%d:%d:flags=lanczos", r.Width, r.Height), "setsar=1",
		"fps="+strconv.FormatFloat(r.FrameRate, 'f', -1, 64), "format=yuv420p")
	return strings.Join(chain, ",")
}

// SourceFilters turn any accepted source into progressive SDR frames: deinterlace, and tone-map
// HDR to SDR (ADR-004: no HDR at the MVP). Thumbnails use them too, so they match the video.
func SourceFilters(probe *media.Result) []string {
	var chain []string
	if probe.Video.Interlaced {
		chain = append(chain, "bwdif=mode=send_frame")
	}
	if probe.Video.HDR {
		chain = append(chain,
			"zscale=t=linear:npl=100", "format=gbrpf32le", "zscale=p=bt709",
			"tonemap=tonemap=hable:desat=0", "zscale=t=bt709:m=bt709:r=tv")
	}
	return chain
}

func lastLines(s string, n int) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " | ")
}
