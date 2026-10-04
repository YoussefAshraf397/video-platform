// Package media inspects uploaded source files with ffprobe and checks them against the
// platform's upload rules before any transcoding starts.
package media

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// AllowedFormats are the only demuxers ffprobe may use. Formats such as HLS playlists or
// concat lists make FFmpeg open other files and URLs, which an uploaded file could abuse to
// read local files or reach internal endpoints, so they are never allowed.
const AllowedFormats = "mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,avi,mpegts"

// Prober runs ffprobe.
type Prober struct {
	// FFprobe is the ffprobe binary (default "ffprobe" on PATH).
	FFprobe string
	// Timeout bounds one probe (default 60 s). Probing a local file should take well under a
	// second, so a timeout means the file is hostile or broken.
	Timeout time.Duration
}

// Result describes a source file.
type Result struct {
	FormatName string // ffprobe's demuxer name, e.g. "mov,mp4,m4a,3gp,3g2,mj2"
	Duration   time.Duration
	SizeBytes  int64
	BitRate    int64  // bits per second; 0 if unknown
	Video      *Video // primary video stream; nil if the file has none
	Audio      []Audio
}

// Video describes the primary video stream. Cover art (attached pictures) is not video.
type Video struct {
	Index       int
	Codec       string // e.g. h264, hevc, vp9
	Profile     string
	PixelFormat string
	BitDepth    int
	// Width and Height are the coded (stored) dimensions.
	Width, Height int
	// Rotation is the display rotation in degrees counterclockwise, as FFmpeg reports it:
	// 0, 90, 180 or 270. Phones record landscape frames and set 90 or 270 for portrait.
	Rotation int
	// DisplayWidth and DisplayHeight are what viewers see: corrected for non-square pixels
	// (SAR) and for rotation. The rendition ladder is chosen from these.
	DisplayWidth, DisplayHeight int
	SampleAspectRatio           string // e.g. "1:1", "32:27"
	FrameRate                   float64
	VariableFrameRate           bool
	Interlaced                  bool
	ColorTransfer               string
	ColorPrimaries              string
	HDR                         bool // PQ (HDR10) or HLG transfer
	Encrypted                   bool
}

// Audio describes one audio stream.
type Audio struct {
	Index      int
	Codec      string
	Channels   int
	SampleRate int
	Encrypted  bool
}

// Probe inspects file. Media problems are returned as *Rejection (never worth retrying);
// any other error (missing binary, I/O failure) is an infrastructure problem.
func (p Prober) Probe(ctx context.Context, file string) (*Result, error) {
	kind, err := sniffContainer(file)
	if err != nil {
		return nil, fmt.Errorf("read source: %w", err)
	}
	if kind == "" {
		return nil, reject(CodeNotAVideo, "file is not in a supported video container")
	}

	out, err := p.ffprobe(ctx, file, kind)
	if err != nil {
		return nil, err
	}
	return out.result(), nil
}

// ffprobe runs ffprobe on file, which sniffing identified as kind.
func (p Prober) ffprobe(ctx context.Context, file, kind string) (*ffprobeOutput, error) {
	timeout := p.Timeout
	if timeout == 0 {
		timeout = 60 * time.Second
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	abs, err := filepath.Abs(file)
	if err != nil {
		return nil, err
	}
	bin := p.FFprobe
	if bin == "" {
		bin = "ffprobe"
	}
	var stdout, stderr bytes.Buffer
	// #nosec G204 -- fixed arguments; the only variable is the file path, passed as a
	// "file:" URL so it can never be read as an option or another protocol.
	cmd := exec.CommandContext(ctx, bin,
		"-v", "error",
		"-format_whitelist", AllowedFormats,
		"-protocol_whitelist", "file",
		"-print_format", "json",
		"-show_format", "-show_streams",
		"file:"+abs,
	)
	cmd.Stdout, cmd.Stderr = &stdout, &stderr

	if err := cmd.Run(); err != nil {
		if ctx.Err() != nil {
			return nil, reject(CodeCorruptSource, fmt.Sprintf("ffprobe did not finish within %s", timeout))
		}
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			// The file looks like a video container (sniffed) but FFmpeg can't read it.
			return nil, reject(CodeCorruptSource, "unreadable "+kind+" file: "+firstLine(stderr.String()))
		}
		return nil, fmt.Errorf("run ffprobe: %w", err)
	}

	var out ffprobeOutput
	if err := json.Unmarshal(stdout.Bytes(), &out); err != nil {
		return nil, fmt.Errorf("parse ffprobe output: %w", err)
	}
	return &out, nil
}

// sniffContainer identifies the container from the first bytes of the file. It returns "" for
// anything that isn't one of the allowed video containers.
func sniffContainer(file string) (string, error) {
	f, err := os.Open(file) // #nosec G304 -- the worker's own download of the source object
	if err != nil {
		return "", err
	}
	defer func() { _ = f.Close() }() // read-only; nothing to flush
	head := make([]byte, 512)
	n, err := io.ReadFull(f, head)
	if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) && !errors.Is(err, io.EOF) {
		return "", err
	}
	head = head[:n]

	switch {
	case len(head) >= 8 && isQuickTimeAtom(string(head[4:8])):
		return "mp4/mov", nil
	case bytes.HasPrefix(head, []byte{0x1A, 0x45, 0xDF, 0xA3}):
		return "matroska/webm", nil
	case len(head) >= 12 && string(head[0:4]) == "RIFF" && string(head[8:12]) == "AVI ":
		return "avi", nil
	case len(head) > 188 && head[0] == 0x47 && head[188] == 0x47:
		return "mpegts", nil
	}
	return "", nil
}

func isQuickTimeAtom(atom string) bool {
	switch atom {
	case "ftyp", "moov", "mdat", "free", "wide", "skip":
		return true
	}
	return false
}

// --- ffprobe JSON -------------------------------------------------------------

type ffprobeOutput struct {
	Format struct {
		FormatName string `json:"format_name"`
		Duration   string `json:"duration"`
		Size       string `json:"size"`
		BitRate    string `json:"bit_rate"`
	} `json:"format"`
	Streams []ffprobeStream `json:"streams"`
}

type ffprobeStream struct {
	Index          int    `json:"index"`
	CodecType      string `json:"codec_type"`
	CodecName      string `json:"codec_name"`
	CodecTag       string `json:"codec_tag_string"`
	Profile        string `json:"profile"`
	Width          int    `json:"width"`
	Height         int    `json:"height"`
	PixFmt         string `json:"pix_fmt"`
	SAR            string `json:"sample_aspect_ratio"`
	RFrameRate     string `json:"r_frame_rate"`
	AvgFrameRate   string `json:"avg_frame_rate"`
	FieldOrder     string `json:"field_order"`
	ColorTransfer  string `json:"color_transfer"`
	ColorPrimaries string `json:"color_primaries"`
	BitsPerRaw     string `json:"bits_per_raw_sample"`
	Channels       int    `json:"channels"`
	SampleRate     string `json:"sample_rate"`
	Duration       string `json:"duration"`
	Disposition    struct {
		AttachedPic int `json:"attached_pic"`
	} `json:"disposition"`
	SideData []struct {
		Type     string  `json:"side_data_type"`
		Rotation float64 `json:"rotation"`
	} `json:"side_data_list"`
	Tags map[string]string `json:"tags"`
}

func (o ffprobeOutput) result() *Result {
	r := &Result{
		FormatName: o.Format.FormatName,
		Duration:   seconds(o.Format.Duration),
		SizeBytes:  parseInt(o.Format.Size),
		BitRate:    parseInt(o.Format.BitRate),
	}
	for _, s := range o.Streams {
		switch {
		case s.CodecType == "video" && s.Disposition.AttachedPic == 0 && r.Video == nil:
			r.Video = s.video()
			if r.Duration == 0 {
				r.Duration = seconds(s.Duration)
			}
		case s.CodecType == "audio":
			r.Audio = append(r.Audio, Audio{
				Index:      s.Index,
				Codec:      s.CodecName,
				Channels:   s.Channels,
				SampleRate: int(parseInt(s.SampleRate)),
				Encrypted:  s.CodecTag == "enca",
			})
		}
	}
	return r
}

func (s ffprobeStream) video() *Video {
	v := &Video{
		Index:             s.Index,
		Codec:             s.CodecName,
		Profile:           s.Profile,
		PixelFormat:       s.PixFmt,
		BitDepth:          bitDepth(s.PixFmt, s.BitsPerRaw),
		Width:             s.Width,
		Height:            s.Height,
		Rotation:          s.rotation(),
		SampleAspectRatio: "1:1",
		FrameRate:         rate(s.AvgFrameRate),
		Interlaced:        s.FieldOrder == "tt" || s.FieldOrder == "bb" || s.FieldOrder == "tb" || s.FieldOrder == "bt",
		ColorTransfer:     s.ColorTransfer,
		ColorPrimaries:    s.ColorPrimaries,
		HDR:               s.ColorTransfer == "smpte2084" || s.ColorTransfer == "arib-std-b67",
		Encrypted:         s.CodecTag == "encv",
	}
	if v.FrameRate == 0 {
		v.FrameRate = rate(s.RFrameRate)
	}
	if r := rate(s.RFrameRate); r > 0 && v.FrameRate > 0 && math.Abs(r-v.FrameRate)/r > 0.01 {
		v.VariableFrameRate = true
	}

	displayWidth := float64(s.Width)
	if num, den, ok := ratio(s.SAR); ok && num > 0 && den > 0 && num != den {
		v.SampleAspectRatio = s.SAR
		displayWidth = displayWidth * float64(num) / float64(den)
	}
	v.DisplayWidth, v.DisplayHeight = int(math.Round(displayWidth)), s.Height
	if v.Rotation == 90 || v.Rotation == 270 {
		v.DisplayWidth, v.DisplayHeight = v.DisplayHeight, v.DisplayWidth
	}
	return v
}

// rotation reads the display matrix (FFmpeg 5+) or the legacy "rotate" tag, normalized to
// 0/90/180/270 counterclockwise.
func (s ffprobeStream) rotation() int {
	deg := 0.0
	for _, sd := range s.SideData {
		if sd.Type == "Display Matrix" {
			deg = sd.Rotation
		}
	}
	if deg == 0 && s.Tags["rotate"] != "" {
		// The legacy tag is clockwise.
		deg = -float64(parseInt(s.Tags["rotate"]))
	}
	normalized := int(math.Round(deg/90)) * 90 % 360
	if normalized < 0 {
		normalized += 360
	}
	return normalized
}

var pixFmtDepth = regexp.MustCompile(`p(9|10|12|14|16)(le|be)$`)

func bitDepth(pixFmt, bitsPerRaw string) int {
	if n := parseInt(bitsPerRaw); n > 0 {
		return int(n)
	}
	if m := pixFmtDepth.FindStringSubmatch(pixFmt); m != nil {
		return int(parseInt(m[1]))
	}
	return 8
}

func ratio(s string) (num, den int64, ok bool) {
	a, b, found := strings.Cut(s, ":")
	if !found {
		a, b, found = strings.Cut(s, "/")
	}
	if !found {
		return 0, 0, false
	}
	num, err1 := strconv.ParseInt(a, 10, 64)
	den, err2 := strconv.ParseInt(b, 10, 64)
	return num, den, err1 == nil && err2 == nil
}

func rate(s string) float64 {
	num, den, ok := ratio(s)
	if !ok || den == 0 {
		return 0
	}
	return float64(num) / float64(den)
}

func seconds(s string) time.Duration {
	f, err := strconv.ParseFloat(s, 64)
	if err != nil || f <= 0 {
		return 0
	}
	return time.Duration(f * float64(time.Second))
}

func parseInt(s string) int64 {
	n, _ := strconv.ParseInt(s, 10, 64)
	return n
}

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}
