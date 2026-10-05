// Package thumbs makes the automatic thumbnail candidates for a video (S3-09): frames at 25 %,
// 50 % and 75 % of the duration, moved off black frames (fade-ins, title cards), each as JPEG
// and WebP in three sizes.
package thumbs

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"math"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/transcode"
)

// Positions are where candidates are taken, as a percentage of the duration.
var Positions = []int{25, 50, 75}

// Box is a size thumbnails are fitted into (aspect ratio kept, never upscaled).
type Box struct{ Width, Height int }

// Boxes are the three sizes: large (watch page, share cards), medium (grids), small (lists).
var Boxes = []Box{{1280, 720}, {640, 360}, {320, 180}}

// Formats are written for every size: JPEG for compatibility, WebP for size.
var Formats = []string{"jpg", "webp"}

// blackLuma is the mean luma (0–255) below which a frame counts as black. Video black is 16;
// a dark but real scene is well above 30.
const blackLuma = 32.0

// searchOffsets are tried, in order, around a black candidate position.
var searchOffsets = []time.Duration{1, -1, 2, -2, 4, -4, 8, -8}

// Thumbnail is one written file.
type Thumbnail struct {
	File          string // path under the output directory
	Name          string // relative name, e.g. "25_1280x720.jpg"
	Width, Height int
	At            time.Duration
}

// Generator runs ffmpeg.
type Generator struct {
	Bin string // default "ffmpeg"
}

// Generate writes every candidate into dir and returns them in Positions × Boxes × Formats order.
func (g Generator) Generate(ctx context.Context, source string, probe *media.Result, dir string) ([]Thumbnail, error) {
	abs, err := filepath.Abs(source)
	if err != nil {
		return nil, err
	}
	var out []Thumbnail
	for _, pct := range Positions {
		at, err := g.pick(ctx, abs, probe, probe.Duration*time.Duration(pct)/100)
		if err != nil {
			return nil, err
		}
		thumbs, err := g.render(ctx, abs, probe, at, pct, dir)
		if err != nil {
			return nil, err
		}
		out = append(out, thumbs...)
	}
	return out, nil
}

// pick returns the first non-black time at or near want. If the whole neighbourhood is black
// (a black video), it keeps want: a black thumbnail is still a correct one.
func (g Generator) pick(ctx context.Context, source string, probe *media.Result, want time.Duration) (time.Duration, error) {
	last := probe.Duration - 100*time.Millisecond
	candidates := []time.Duration{want}
	for _, off := range searchOffsets {
		if t := want + off*time.Second; t >= 0 && t <= last {
			candidates = append(candidates, t)
		}
	}
	for _, t := range candidates {
		luma, err := g.meanLuma(ctx, source, probe, t)
		if err != nil {
			return 0, err
		}
		if luma >= blackLuma {
			return t, nil
		}
	}
	return want, nil
}

var yavg = regexp.MustCompile(`lavfi\.signalstats\.YAVG=([0-9.]+)`)

// meanLuma is the average luma (0–255) of the frame at t, measured on a small copy.
func (g Generator) meanLuma(ctx context.Context, source string, probe *media.Result, t time.Duration) (float64, error) {
	in, trim := seek(source, t)
	chain := append(append([]string{trim}, transcode.SourceFilters(probe)...), "scale=64:-2", "format=yuv420p", "signalstats",
		"metadata=print:key=lavfi.signalstats.YAVG:file=-")
	out, err := g.run(ctx, append(in, "-map", "0:"+strconv.Itoa(probe.Video.Index), "-frames:v", "1", "-vf", strings.Join(chain, ","), "-f", "null", "-")...)
	if err != nil {
		return 0, err
	}
	m := yavg.FindSubmatch(out)
	if m == nil {
		return 0, fmt.Errorf("no frame at %s", t)
	}
	return strconv.ParseFloat(string(m[1]), 64)
}

// render writes one candidate in every box and format with a single ffmpeg run.
func (g Generator) render(ctx context.Context, source string, probe *media.Result, at time.Duration, pct int, dir string) ([]Thumbnail, error) {
	in, trim := seek(source, at)
	chain := append(append([]string{trim}, transcode.SourceFilters(probe)...), "format=yuv420p")
	graph := fmt.Sprintf("[0:%d]%s,split=%d", probe.Video.Index, strings.Join(chain, ","), len(Boxes))
	for i := range Boxes {
		graph += fmt.Sprintf("[s%d]", i)
	}

	args := append(in, "-filter_complex", "")
	var out []Thumbnail
	var outputs []string
	for i, box := range Boxes {
		w, h := Fit(probe.Video.Width, probe.Video.Height, box)
		graph += fmt.Sprintf(";[s%d]scale=%d:%d:flags=lanczos,setsar=1,split=%d", i, w, h, len(Formats))
		for _, f := range Formats {
			label := fmt.Sprintf("o%d%s", i, f)
			graph += "[" + label + "]"
			name := fmt.Sprintf("%d_%dx%d.%s", pct, box.Width, box.Height, f)
			file := filepath.Join(dir, name)
			outputs = append(outputs, "-map", "["+label+"]", "-frames:v", "1", "-update", "1")
			if f == "jpg" {
				outputs = append(outputs, "-c:v", "mjpeg", "-q:v", "3", "-pix_fmt", "yuvj420p")
			} else {
				outputs = append(outputs, "-c:v", "libwebp", "-quality", "80")
			}
			outputs = append(outputs, file)
			out = append(out, Thumbnail{File: file, Name: name, Width: w, Height: h, At: at})
		}
	}
	args[len(args)-1] = graph
	if _, err := g.run(ctx, append(args, outputs...)...); err != nil {
		return nil, err
	}
	return out, nil
}

// Fit scales a display size into box, keeping the aspect ratio and never enlarging. A vertical
// video in the 1280x720 box becomes 405x720.
func Fit(width, height int, box Box) (int, int) {
	scale := math.Min(1, math.Min(float64(box.Width)/float64(width), float64(box.Height)/float64(height)))
	return max(1, int(math.Round(float64(width)*scale))), max(1, int(math.Round(float64(height)*scale)))
}

// seek opens the source at t, with the same guards as the probe. It returns the input arguments
// and a filter that must start the filter chain. It seeks in two steps: a fast jump to 10 s
// before t, then a trim filter that decodes forward to exactly t. A single fast seek lands on the
// next keyframe *after* the target in containers without a seek index (MPEG-TS), which can be
// past the end of a short video; the 10 s run-up is longer than any accepted GOP. (An output-side
// -ss would drop frames only after the filters ran, so measurements would see the wrong frame.)
func seek(source string, t time.Duration) ([]string, string) {
	jump := max(0, t-10*time.Second)
	return []string{
		"-nostdin", "-hide_banner", "-loglevel", "error", "-y",
		"-ss", seconds(jump),
		"-format_whitelist", media.AllowedFormats, "-protocol_whitelist", "file",
		"-i", "file:" + source,
	}, "trim=start=" + seconds(t-jump)
}

func seconds(d time.Duration) string {
	return strconv.FormatFloat(d.Seconds(), 'f', 3, 64)
}

func (g Generator) run(ctx context.Context, args ...string) ([]byte, error) {
	bin := g.Bin
	if bin == "" {
		bin = "ffmpeg"
	}
	var stdout, stderr bytes.Buffer
	// #nosec G204 -- fixed arguments; the source path is passed as a "file:" URL.
	cmd := exec.CommandContext(ctx, bin, args...)
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			return nil, &transcode.EncoderError{Stderr: strings.TrimSpace(stderr.String())}
		}
		return nil, fmt.Errorf("run ffmpeg: %w", err)
	}
	return stdout.Bytes(), nil
}
