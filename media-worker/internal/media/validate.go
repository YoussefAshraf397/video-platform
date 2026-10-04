package media

import (
	"errors"
	"fmt"
	"strings"
	"time"
)

// Rejection codes, reported in VideoProcessingFailed.error.code (contracts/). Media
// rejections are never retried: the same file will fail the same way.
const (
	CodeNotAVideo            = "NOT_A_VIDEO"
	CodeCorruptSource        = "CORRUPT_SOURCE"
	CodeNoVideoStream        = "NO_VIDEO_STREAM"
	CodeUnsupportedCodec     = "UNSUPPORTED_CODEC"
	CodeEncryptedSource      = "ENCRYPTED_SOURCE"
	CodeDurationTooShort     = "DURATION_TOO_SHORT"
	CodeDurationTooLong      = "DURATION_TOO_LONG"
	CodeResolutionOutOfRange = "RESOLUTION_OUT_OF_RANGE"
)

// Rejection means the source file can't be processed. Message is for logs and the admin
// console; creators see a friendly text chosen by Code.
type Rejection struct {
	Code    string
	Message string
}

func (r *Rejection) Error() string { return r.Code + ": " + r.Message }

func reject(code, message string) *Rejection { return &Rejection{Code: code, Message: message} }

// AsRejection reports whether err is (or wraps) a Rejection.
func AsRejection(err error) (*Rejection, bool) {
	var r *Rejection
	ok := errors.As(err, &r)
	return r, ok
}

// Limits are the upload rules (design doc §10.6, §11.3).
type Limits struct {
	MinDuration, MaxDuration time.Duration
	// MinShortSide and MaxLongSide bound the display dimensions in pixels.
	MinShortSide, MaxLongSide int
}

// DefaultLimits are the MVP rules: 1 s to 4 h, at least 128 px on the short side, at most
// 8K (7680 px) on the long side.
func DefaultLimits() Limits {
	return Limits{MinDuration: time.Second, MaxDuration: 4 * time.Hour, MinShortSide: 128, MaxLongSide: 7680}
}

// Codec allowlists keep the set of decoders that ever see untrusted input small.
var (
	allowedVideoCodecs = set("h264", "hevc", "vp8", "vp9", "av1", "mpeg4", "mpeg2video", "prores", "dnxhd", "mjpeg")
	allowedAudioCodecs = set("aac", "mp3", "opus", "vorbis", "ac3", "eac3", "flac", "alac")
)

// Validate checks a probed file against the limits and returns a *Rejection, or nil when
// the file can be processed.
func Validate(r *Result, l Limits) error {
	v := r.Video
	switch {
	case v == nil:
		return reject(CodeNoVideoStream, fmt.Sprintf("no video stream (%d audio stream(s))", len(r.Audio)))
	case v.Encrypted || anyEncryptedAudio(r.Audio):
		return reject(CodeEncryptedSource, "source is DRM-encrypted")
	case !allowedVideoCodecs[v.Codec]:
		return reject(CodeUnsupportedCodec, fmt.Sprintf("video codec %q is not supported", v.Codec))
	}
	for _, a := range r.Audio {
		if !allowedAudioCodecs[a.Codec] && !strings.HasPrefix(a.Codec, "pcm_") {
			return reject(CodeUnsupportedCodec, fmt.Sprintf("audio codec %q is not supported", a.Codec))
		}
	}

	short, long := min(v.DisplayWidth, v.DisplayHeight), max(v.DisplayWidth, v.DisplayHeight)
	switch {
	case r.Duration <= 0 || short <= 0:
		return reject(CodeCorruptSource, "duration or dimensions are missing")
	case r.Duration < l.MinDuration:
		return reject(CodeDurationTooShort, fmt.Sprintf("duration %s is below the minimum %s", r.Duration, l.MinDuration))
	case r.Duration > l.MaxDuration:
		return reject(CodeDurationTooLong, fmt.Sprintf("duration %s exceeds the maximum %s", r.Duration.Round(time.Second), l.MaxDuration))
	case short < l.MinShortSide || long > l.MaxLongSide:
		return reject(CodeResolutionOutOfRange, fmt.Sprintf("%dx%d is outside %dpx (short side) to %dpx (long side)",
			v.DisplayWidth, v.DisplayHeight, l.MinShortSide, l.MaxLongSide))
	}
	return nil
}

func anyEncryptedAudio(audio []Audio) bool {
	for _, a := range audio {
		if a.Encrypted {
			return true
		}
	}
	return false
}

func set(items ...string) map[string]bool {
	m := make(map[string]bool, len(items))
	for _, i := range items {
		m[i] = true
	}
	return m
}
