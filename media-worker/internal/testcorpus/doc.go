package testcorpus

import (
	"fmt"
	"strconv"
	"strings"
)

// Markdown renders the corpus as the CORPUS.md document.
func Markdown() string {
	var b strings.Builder
	b.WriteString(`# Golden test corpus

<!-- Generated from corpus.go by: UPDATE_CORPUS_DOC=1 go test ./internal/testcorpus/ — do not edit. -->

Source videos every media-worker stage is tested against. Files are generated with ffmpeg on
first use and cached in the OS temp directory (nothing binary is committed). Each row is the
outcome the worker must produce; tests read these expectations from ` + "`corpus.go`" + `.

**Display size** is what viewers see, after non-square pixels and rotation. **MVP ladder** is
the rendition heights ADR-004 requires (360/480/720/1080, never above the source); the
transcoder tests (S2-10) assert it.

## Accepted

| File | Covers | Container | Video | Display size | FPS | Notes | Audio | MVP ladder |
|---|---|---|---|---|---|---|---|---|
`)
	for _, s := range Samples {
		e := s.Expect
		if e.Rejection != "" {
			continue
		}
		fmt.Fprintf(&b, "| `%s` | %s | %s | %s | %dx%d | %s | %s | %s | %s |\n",
			s.Name, s.Covers, e.Container, e.VideoCodec, e.Width, e.Height, fps(e), notes(e), audio(e.AudioChannels), ladder(e.MVPLadder))
	}

	b.WriteString(`
## Rejected

| File | Covers | Rejection code |
|---|---|---|
`)
	for _, s := range Samples {
		if s.Expect.Rejection != "" {
			fmt.Fprintf(&b, "| `%s` | %s | `%s` |\n", s.Name, s.Covers, s.Expect.Rejection)
		}
	}

	b.WriteString(`
## Adding a sample

1. Add it to ` + "`Samples`" + ` in ` + "`corpus.go`" + ` with its expected outcome.
2. Run ` + "`UPDATE_CORPUS_DOC=1 go test ./internal/testcorpus/`" + ` to regenerate this file.
3. ` + "`go test ./...`" + ` checks the new sample in every stage. The cache key changes automatically.
`)
	return b.String()
}

func fps(e Expect) string {
	if e.VFR {
		return "variable"
	}
	return strconv.FormatFloat(e.FrameRate, 'f', -1, 64)
}

func notes(e Expect) string {
	var n []string
	if e.Rotation != 0 {
		n = append(n, fmt.Sprintf("rotated %d°", e.Rotation))
	}
	if e.HDR {
		n = append(n, "HDR")
	}
	if e.Interlaced {
		n = append(n, "interlaced")
	}
	if len(n) == 0 {
		return "—"
	}
	return strings.Join(n, ", ")
}

func audio(channels []int) string {
	if len(channels) == 0 {
		return "none"
	}
	var parts []string
	for _, c := range channels {
		switch c {
		case 1:
			parts = append(parts, "mono")
		case 2:
			parts = append(parts, "stereo")
		case 6:
			parts = append(parts, "5.1")
		default:
			parts = append(parts, strconv.Itoa(c)+" ch")
		}
	}
	return strings.Join(parts, ", ")
}

func ladder(heights []int) string {
	parts := make([]string, len(heights))
	for i, h := range heights {
		parts[i] = strconv.Itoa(h) + "p"
	}
	return strings.Join(parts, ", ")
}
