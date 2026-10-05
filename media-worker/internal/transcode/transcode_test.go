package transcode

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"math"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"testing"

	"videoplatform/media-worker/internal/ladder"
	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/testcorpus"
)

// superfast is the fastest x264 preset that still produces High profile (ultrafast drops CABAC
// and 8x8 transforms, which makes the stream Constrained Baseline).
var testEncoder = FFmpeg{Preset: "superfast"}

// TestCorpusLadders encodes every accepted corpus sample into its full ladder and checks each
// rendition against ADR-004 (S2-10/S2-11 acceptance).
func TestCorpusLadders(t *testing.T) {
	for _, s := range testcorpus.Samples {
		if s.Expect.Rejection != "" {
			continue
		}
		t.Run(s.Name, func(t *testing.T) {
			t.Parallel()
			source := testcorpus.Path(t, s.Name)
			probe, err := media.Prober{}.Probe(context.Background(), source)
			if err != nil {
				t.Fatal(err)
			}
			renditions := ladder.For(probe.Video.Width, probe.Video.Height, probe.Video.FrameRate)

			var rungs []int
			for _, r := range renditions {
				rungs = append(rungs, r.Short)
			}
			if !slices.Equal(rungs, s.Expect.MVPLadder) {
				t.Fatalf("rungs %v, want %v", rungs, s.Expect.MVPLadder)
			}

			for _, r := range renditions {
				dir := filepath.Join(t.TempDir(), r.Name())
				if err := testEncoder.Encode(context.Background(), source, probe, r, dir); err != nil {
					t.Fatalf("%s: %v", r.Name(), err)
				}
				checkRendition(t, dir, probe, r)
			}
		})
	}
}

func checkRendition(t *testing.T, dir string, source *media.Result, r ladder.Rendition) {
	t.Helper()
	out := ffprobe(t, filepath.Join(dir, PlaylistFile))
	v, a := out.stream("video"), out.stream("audio")
	if v == nil {
		t.Fatalf("%s: no video stream", r.Name())
	}

	if v.Width != r.Width || v.Height != r.Height {
		t.Errorf("%s: %dx%d, want %dx%d", r.Name(), v.Width, v.Height, r.Width, r.Height)
	}
	if max(v.Width, v.Height) > max(source.Video.Width, source.Video.Height) {
		t.Errorf("%s: upscaled beyond the source", r.Name())
	}
	if v.CodecName != "h264" || v.Profile != "High" || v.PixFmt != "yuv420p" {
		t.Errorf("%s: %s %s %s, want h264 High yuv420p", r.Name(), v.CodecName, v.Profile, v.PixFmt)
	}
	// The level the master playlist declares (CODECS) is the level actually in the stream.
	if _, idc := r.Level(); v.Level != idc {
		t.Errorf("%s: stream level %d, declared %d", r.Name(), v.Level, idc)
	}
	if v.SAR != "" && v.SAR != "1:1" && v.SAR != "0:1" {
		t.Errorf("%s: sample aspect ratio %s, want square pixels", r.Name(), v.SAR)
	}
	// Constant frame rate: the nominal rate is the rendition's, and the average equals it.
	if fps := rate(v.RFrameRate); math.Abs(fps-r.FrameRate) > 0.01 {
		t.Errorf("%s: frame rate %v, want %v", r.Name(), fps, r.FrameRate)
	}

	wantMs := float64(source.Duration.Milliseconds())
	if gotMs := seconds(out.Format.Duration) * 1000; math.Abs(gotMs-wantMs) > 100 {
		t.Errorf("%s: duration %.0f ms, source %.0f ms (want ±100 ms)", r.Name(), gotMs, wantMs)
	}

	if len(source.Audio) == 0 {
		if a != nil {
			t.Errorf("%s: audio appeared from nowhere", r.Name())
		}
		return
	}
	if a == nil {
		t.Fatalf("%s: audio was dropped", r.Name())
	}
	if a.CodecName != "aac" || a.Channels != 2 || a.SampleRate != "48000" {
		t.Errorf("%s: audio %s %dch %s Hz, want aac stereo 48000", r.Name(), a.CodecName, a.Channels, a.SampleRate)
	}
	// A/V sync: the streams start together, within one frame.
	frame := 1 / r.FrameRate
	if d := math.Abs(seconds(v.StartTime) - seconds(a.StartTime)); d > frame {
		t.Errorf("%s: audio and video start %.3f s apart (one frame is %.3f s)", r.Name(), d, frame)
	}
}

// TestSegmentsAreFourSecondsWithAlignedKeyframes uses a 10 s clip, long enough for several
// segments, and two rungs: both must cut at exactly 4 s and 8 s, each segment starting with a
// keyframe, so players can switch rungs at any boundary.
func TestSegmentsAreFourSecondsWithAlignedKeyframes(t *testing.T) {
	source := filepath.Join(t.TempDir(), "ten_seconds.mp4")
	gen := exec.CommandContext(t.Context(), "ffmpeg", "-nostdin", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc2=s=1280x720:r=30:d=10",
		"-f", "lavfi", "-i", "sine=f=440:d=10", "-c:v", "libx264", "-preset", "ultrafast", "-g", "250", "-c:a", "aac", source)
	if out, err := gen.CombinedOutput(); err != nil {
		t.Fatalf("generate clip: %v %s", err, out)
	}
	probe, err := media.Prober{}.Probe(context.Background(), source)
	if err != nil {
		t.Fatal(err)
	}

	for _, r := range ladder.For(1280, 720, 30)[:2] {
		dir := filepath.Join(t.TempDir(), r.Name())
		if err := testEncoder.Encode(context.Background(), source, probe, r, dir); err != nil {
			t.Fatal(err)
		}
		if got := extinf(t, filepath.Join(dir, PlaylistFile)); !slices.Equal(got, []float64{4, 4, 2}) {
			t.Errorf("%s: segment durations %v, want [4 4 2]", r.Name(), got)
		}
		files, err := Files(dir)
		if err != nil {
			t.Fatal(err)
		}
		if len(files) != 5 {
			t.Errorf("%s: files %v, want init, playlist and 3 segments", r.Name(), files)
		}
		// Keyframes exactly 0, 4 and 8 s after the first frame and nowhere else (closed, fixed GOP).
		// The whole stream starts at a small constant offset (encoder delay), so times are relative.
		if got := keyframeTimes(t, filepath.Join(dir, PlaylistFile)); !slices.Equal(got, []float64{0, 4, 8}) {
			t.Errorf("%s: keyframes at %v s after the start, want [0 4 8]", r.Name(), got)
		}
	}
}

func TestEncodeReportsFFmpegFailures(t *testing.T) {
	probe := &media.Result{Video: &media.Video{Index: 0, Width: 640, Height: 360, FrameRate: 30}}
	notAVideo := filepath.Join(t.TempDir(), "garbage.mp4")
	if err := os.WriteFile(notAVideo, []byte("not a video"), 0o600); err != nil {
		t.Fatal(err)
	}

	err := testEncoder.Encode(context.Background(), notAVideo, probe, ladder.For(640, 360, 30)[0], t.TempDir())
	var encErr *EncoderError
	if !asEncoderError(err, &encErr) || encErr.Stderr == "" {
		t.Fatalf("got %v, want *EncoderError with ffmpeg's message", err)
	}
}

func TestEncodeStopsWhenCancelled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	source := testcorpus.Path(t, "landscape_1080p30.mp4")
	probe, err := media.Prober{}.Probe(context.Background(), source)
	if err != nil {
		t.Fatal(err)
	}

	if err := testEncoder.Encode(ctx, source, probe, ladder.For(1920, 1080, 30)[0], t.TempDir()); !errors.Is(err, context.Canceled) {
		t.Fatalf("got %v, want context.Canceled", err)
	}
}

func asEncoderError(err error, target **EncoderError) bool {
	return errors.As(err, target)
}

// --- ffprobe helpers (test-only; the playlist is our own output, so no whitelist is needed) ---

type probeStream struct {
	CodecType  string `json:"codec_type"`
	CodecName  string `json:"codec_name"`
	Profile    string `json:"profile"`
	Level      int    `json:"level"`
	Width      int    `json:"width"`
	Height     int    `json:"height"`
	PixFmt     string `json:"pix_fmt"`
	SAR        string `json:"sample_aspect_ratio"`
	RFrameRate string `json:"r_frame_rate"`
	Channels   int    `json:"channels"`
	SampleRate string `json:"sample_rate"`
	StartTime  string `json:"start_time"`
}

type probeOutput struct {
	Streams []probeStream `json:"streams"`
	Format  struct {
		Duration string `json:"duration"`
	} `json:"format"`
}

func (o probeOutput) stream(kind string) *probeStream {
	for i := range o.Streams {
		if o.Streams[i].CodecType == kind {
			return &o.Streams[i]
		}
	}
	return nil
}

func ffprobe(t *testing.T, playlist string) probeOutput {
	t.Helper()
	out, err := exec.CommandContext(t.Context(), "ffprobe", "-v", "error", "-print_format", "json", "-show_format", "-show_streams", playlist).Output()
	if err != nil {
		t.Fatalf("ffprobe %s: %v", playlist, err)
	}
	var o probeOutput
	if err := json.Unmarshal(out, &o); err != nil {
		t.Fatal(err)
	}
	return o
}

func keyframeTimes(t *testing.T, playlist string) []float64 {
	t.Helper()
	out, err := exec.CommandContext(t.Context(), "ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries", "frame=pts_time,key_frame",
		"-of", "csv=p=0", playlist).Output()
	if err != nil {
		t.Fatal(err)
	}
	var times []float64
	first := math.NaN()
	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		fields := strings.Split(line, ",") // key_frame,pts_time[,side data…]
		key, ts := fields[0], fields[1]
		if math.IsNaN(first) {
			first = seconds(ts)
		}
		if key == "1" {
			times = append(times, math.Round((seconds(ts)-first)*100)/100)
		}
	}
	return times
}

func extinf(t *testing.T, playlist string) []float64 {
	t.Helper()
	f, err := os.Open(playlist)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = f.Close() }()
	var durations []float64
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if v, ok := strings.CutPrefix(sc.Text(), "#EXTINF:"); ok {
			d, _ := strconv.ParseFloat(strings.TrimSuffix(v, ","), 64)
			durations = append(durations, math.Round(d*100)/100)
		}
	}
	return durations
}

func seconds(s string) float64 {
	f, _ := strconv.ParseFloat(s, 64)
	return f
}

func rate(s string) float64 {
	num, den, ok := strings.Cut(s, "/")
	if !ok {
		return seconds(s)
	}
	n, d := seconds(num), seconds(den)
	if d == 0 {
		return 0
	}
	return n / d
}
