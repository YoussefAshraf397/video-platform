// Package ladder chooses the renditions to encode for a source (ADR-004).
//
// The MVP ladder is H.264 at 360p, 480p, 720p and 1080p, where "p" is the short side so vertical
// video gets the same quality as landscape. Only rungs at or below the source's short side are
// produced (never upscale). A source smaller than 360p gets one rung at its own size.
package ladder

import (
	"fmt"
	"math"
)

// Profile is the only ladder at the MVP; MediaProcessRequested names it.
const Profile = "h264-sdr-v1"

// SegmentSeconds is the HLS segment length; every rendition has a keyframe exactly on each boundary.
const SegmentSeconds = 4

// MaxFrameRate caps output frame rate; above it (e.g. 120 fps phone slow-motion) frames are dropped.
const MaxFrameRate = 60.0

type rung struct {
	short   int
	bitrate int // bits per second at ≤ 30 fps
}

var mvp = []rung{{360, 800_000}, {480, 1_400_000}, {720, 3_000_000}, {1080, 5_500_000}}

// Rendition is one rung to encode.
type Rendition struct {
	Width, Height int     // output frame size, both even
	Short         int     // the rung (short side)
	FrameRate     float64 // constant output frame rate
	Bitrate       int     // target average, bits per second
	PeakBitrate   int     // VBV maximum, bits per second
}

// Name is the rendition's directory under the output prefix, e.g. "h264_720p30".
func (r Rendition) Name() string {
	return fmt.Sprintf("h264_%dp%d", r.Short, int(math.Round(r.FrameRate)))
}

// GOP is the keyframe interval in frames: exactly one segment.
func (r Rendition) GOP() int {
	return int(math.Round(r.FrameRate * SegmentSeconds))
}

// For returns the renditions for a source with the given display size and frame rate, lowest
// first (the order they are encoded and published in).
func For(displayWidth, displayHeight int, frameRate float64) []Rendition {
	short, long := displayHeight, displayWidth
	if displayWidth < displayHeight {
		short, long = displayWidth, displayHeight
	}
	fps := outputFrameRate(frameRate)
	// 50/60 fps sources get 1.5x the bitrate (ADR-004).
	multiplier := 1.0
	if fps > 31 {
		multiplier = 1.5
	}

	var rungs []rung
	for _, r := range mvp {
		if r.short <= short {
			rungs = append(rungs, r)
		}
	}
	if len(rungs) == 0 {
		rungs = []rung{{evenBelow(short), mvp[0].bitrate}}
	}

	out := make([]Rendition, 0, len(rungs))
	for _, r := range rungs {
		outLong := nearestEven(float64(long) * float64(r.short) / float64(short))
		w, h := outLong, r.short
		if displayWidth < displayHeight {
			w, h = r.short, outLong
		}
		bitrate := int(float64(r.bitrate) * multiplier)
		out = append(out, Rendition{
			Width: w, Height: h, Short: r.short, FrameRate: fps,
			Bitrate: bitrate, PeakBitrate: bitrate * 11 / 8,
		})
	}
	return out
}

// outputFrameRate turns the source rate into a constant output rate: common rates are kept
// (29.97 stays 29.97), anything above 60 is capped, and an unknown rate becomes 30.
func outputFrameRate(fps float64) float64 {
	switch {
	case fps <= 0 || math.IsNaN(fps):
		return 30
	case fps > MaxFrameRate:
		return MaxFrameRate
	default:
		return math.Round(fps*1000) / 1000
	}
}

// evenBelow rounds down to an even size (H.264 with 4:2:0 needs even dimensions; down, so the
// short side is never upscaled).
func evenBelow(n int) int {
	return max(n-n%2, 2)
}

// nearestEven rounds a scaled long side to the closest even size: 1920 * 480/1080 = 853.3 → 854.
func nearestEven(x float64) int {
	return max(int(math.Round(x/2))*2, 2)
}

// h264Level is one row of the H.264 level table (Annex A): max frame size and macroblock rate,
// and the max bitrate for the High profile (1.25x the Baseline/Main value).
type h264Level struct {
	name     string
	idc      int // level_idc, e.g. 31 for 3.1
	maxFS    int // macroblocks per frame
	maxMBPS  int // macroblocks per second
	maxHighK int // kbit/s
}

var h264Levels = []h264Level{
	{"3.0", 30, 1620, 40500, 12500},
	{"3.1", 31, 3600, 108000, 17500},
	{"3.2", 32, 5120, 216000, 25000},
	{"4.0", 40, 8192, 245760, 25000},
	{"4.1", 41, 8192, 245760, 62500},
	{"4.2", 42, 8704, 522240, 62500},
	{"5.0", 50, 22080, 589824, 168750},
	{"5.1", 51, 36864, 983040, 300000},
}

// Level is the lowest H.264 level that fits the rendition's frame size, frame rate and peak
// bitrate, e.g. "3.1" for 720p30. It is passed to the encoder and declared in the master
// playlist's CODECS, so players can tell up front whether they can decode a rendition.
func (r Rendition) Level() (name string, idc int) {
	fs := ceilDiv(r.Width, 16) * ceilDiv(r.Height, 16)
	mbps := int(math.Ceil(float64(fs) * r.FrameRate))
	for _, l := range h264Levels {
		// A frame side may not exceed sqrt(8 * MaxFS) macroblocks (A.3.1).
		maxSide := int(math.Sqrt(float64(8 * l.maxFS)))
		if fs <= l.maxFS && mbps <= l.maxMBPS && r.PeakBitrate <= l.maxHighK*1000 &&
			ceilDiv(r.Width, 16) <= maxSide && ceilDiv(r.Height, 16) <= maxSide {
			return l.name, l.idc
		}
	}
	last := h264Levels[len(h264Levels)-1]
	return last.name, last.idc
}

// Codecs is the RFC 6381 codec string for the rendition's video: High profile, no constraint
// flags, then the level, e.g. "avc1.64001f".
func (r Rendition) Codecs() string {
	_, idc := r.Level()
	return fmt.Sprintf("avc1.6400%02x", idc)
}

func ceilDiv(a, b int) int { return (a + b - 1) / b }
