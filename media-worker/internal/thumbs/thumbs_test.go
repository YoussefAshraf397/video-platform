package thumbs

import (
	"context"
	"encoding/json"
	"os/exec"
	"regexp"
	"strconv"
	"testing"
	"time"

	"videoplatform/media-worker/internal/media"
	"videoplatform/media-worker/internal/testcorpus"
)

// TestCorpusThumbnails is S3-09's acceptance: every accepted corpus sample gets all candidates,
// in every size and format, and none of them is black.
func TestCorpusThumbnails(t *testing.T) {
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

			thumbs, err := Generator{}.Generate(context.Background(), source, probe, t.TempDir())
			if err != nil {
				t.Fatal(err)
			}
			if len(thumbs) != len(Positions)*len(Boxes)*len(Formats) {
				t.Fatalf("got %d thumbnails, want %d", len(thumbs), len(Positions)*len(Boxes)*len(Formats))
			}
			for _, th := range thumbs {
				w, h, luma := inspect(t, th.File)
				if w != th.Width || h != th.Height {
					t.Errorf("%s is %dx%d, reported %dx%d", th.Name, w, h, th.Width, th.Height)
				}
				if luma < blackLuma {
					t.Errorf("%s (at %s) is black: mean luma %.1f", th.Name, th.At, luma)
				}
				if th.At < 0 || th.At >= probe.Duration {
					t.Errorf("%s taken at %s, outside the %s video", th.Name, th.At, probe.Duration)
				}
			}
		})
	}
}

func TestSkipsTheBlackIntro(t *testing.T) {
	source := testcorpus.Path(t, "black_intro.mp4")
	probe, err := media.Prober{}.Probe(context.Background(), source)
	if err != nil {
		t.Fatal(err)
	}
	thumbs, err := Generator{}.Generate(context.Background(), source, probe, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}

	// 25 % (2 s) and 50 % (4 s) fall in the black first 4.5 s and must move past it; 75 % (6 s) doesn't move.
	at := map[string]time.Duration{}
	for _, th := range thumbs {
		at[th.Name[:2]] = th.At
	}
	if at["25"] < 4500*time.Millisecond || at["50"] < 4500*time.Millisecond {
		t.Errorf("candidates at %v; 25 %% and 50 %% should have moved past the black intro (4.5 s)", at)
	}
	if at["75"] != 6*time.Second {
		t.Errorf("75 %% candidate at %s, want 6s (it isn't black, so it stays)", at["75"])
	}
}

func TestFit(t *testing.T) {
	cases := []struct {
		w, h  int
		box   Box
		wantW int
		wantH int
	}{
		{1920, 1080, Box{1280, 720}, 1280, 720},
		{1080, 1920, Box{1280, 720}, 405, 720}, // vertical fits by height
		{640, 480, Box{1280, 720}, 640, 480},   // never upscaled
		{640, 480, Box{320, 180}, 240, 180},
		{3840, 2160, Box{320, 180}, 320, 180},
		{853, 480, Box{640, 360}, 640, 360},
	}
	for _, c := range cases {
		if w, h := Fit(c.w, c.h, c.box); w != c.wantW || h != c.wantH {
			t.Errorf("Fit(%dx%d, %v) = %dx%d, want %dx%d", c.w, c.h, c.box, w, h, c.wantW, c.wantH)
		}
	}
}

var yavgLine = regexp.MustCompile(`YAVG=([0-9.]+)`)

// inspect decodes an output image (proving the file is valid) and returns its size and mean luma.
func inspect(t *testing.T, file string) (int, int, float64) {
	t.Helper()
	out, err := exec.CommandContext(t.Context(), "ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries", "stream=width,height", "-of", "json", file).Output()
	if err != nil {
		t.Fatalf("ffprobe %s: %v", file, err)
	}
	var probe struct {
		Streams []struct{ Width, Height int } `json:"streams"`
	}
	if err := json.Unmarshal(out, &probe); err != nil || len(probe.Streams) != 1 {
		t.Fatalf("%s: unreadable image (%v)", file, err)
	}
	stats, err := exec.CommandContext(t.Context(), "ffmpeg", "-v", "error", "-i", file, "-vf", "format=yuv420p,signalstats,metadata=print:key=lavfi.signalstats.YAVG:file=-", "-f", "null", "-").Output()
	if err != nil {
		t.Fatal(err)
	}
	m := yavgLine.FindSubmatch(stats)
	if m == nil {
		t.Fatalf("%s: no luma measurement", file)
	}
	luma, _ := strconv.ParseFloat(string(m[1]), 64)
	return probe.Streams[0].Width, probe.Streams[0].Height, luma
}
