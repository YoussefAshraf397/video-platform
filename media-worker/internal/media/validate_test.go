package media

import (
	"testing"
	"time"
)

// Rules that are impractical to hit with generated files (hours-long, 8K+, DRM) are tested
// on constructed results.

func validResult() *Result {
	return &Result{
		Duration: 10 * time.Minute,
		Video:    &Video{Codec: "h264", Width: 1920, Height: 1080, DisplayWidth: 1920, DisplayHeight: 1080},
		Audio:    []Audio{{Codec: "aac"}},
	}
}

func TestValidateRules(t *testing.T) {
	cases := map[string]struct {
		change func(r *Result)
		want   string
	}{
		"valid":                   {func(*Result) {}, ""},
		"exactly 4h":              {func(r *Result) { r.Duration = 4 * time.Hour }, ""},
		"exactly 8K":              {func(r *Result) { r.Video.DisplayWidth, r.Video.DisplayHeight = 7680, 4320 }, ""},
		"pcm audio":               {func(r *Result) { r.Audio[0].Codec = "pcm_s24le" }, ""},
		"no audio":                {func(r *Result) { r.Audio = nil }, ""},
		"longer than 4h":          {func(r *Result) { r.Duration = 4*time.Hour + time.Second }, CodeDurationTooLong},
		"wider than 8K":           {func(r *Result) { r.Video.DisplayWidth = 8192 }, CodeResolutionOutOfRange},
		"vertical taller than 8K": {func(r *Result) { r.Video.DisplayWidth, r.Video.DisplayHeight = 4320, 7700 }, CodeResolutionOutOfRange},
		"short side under 128px":  {func(r *Result) { r.Video.DisplayWidth, r.Video.DisplayHeight = 1920, 100 }, CodeResolutionOutOfRange},
		"unsupported video codec": {func(r *Result) { r.Video.Codec = "cinepak" }, CodeUnsupportedCodec},
		"unsupported audio codec": {func(r *Result) { r.Audio[0].Codec = "wmav2" }, CodeUnsupportedCodec},
		"encrypted video":         {func(r *Result) { r.Video.Encrypted = true }, CodeEncryptedSource},
		"encrypted audio":         {func(r *Result) { r.Audio[0].Encrypted = true }, CodeEncryptedSource},
		"no duration":             {func(r *Result) { r.Duration = 0 }, CodeCorruptSource},
		"no dimensions":           {func(r *Result) { r.Video.DisplayWidth, r.Video.DisplayHeight = 0, 0 }, CodeCorruptSource},
		"no video":                {func(r *Result) { r.Video = nil }, CodeNoVideoStream},
		"under 1s":                {func(r *Result) { r.Duration = 999 * time.Millisecond }, CodeDurationTooShort},
		"encryption beats codec":  {func(r *Result) { r.Video.Encrypted, r.Video.Codec = true, "cinepak" }, CodeEncryptedSource},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			r := validResult()
			c.change(r)
			err := Validate(r, DefaultLimits())
			if c.want == "" {
				if err != nil {
					t.Fatalf("rejected: %v", err)
				}
				return
			}
			rejection, ok := AsRejection(err)
			if !ok || rejection.Code != c.want {
				t.Fatalf("got %v, want %s", err, c.want)
			}
		})
	}
}

func TestRotationNormalization(t *testing.T) {
	cases := []struct {
		displayMatrix float64
		legacyTag     string
		want          int
	}{
		{90, "", 90}, {-90, "", 270}, {180, "", 180}, {-180, "", 180}, {270, "", 270}, {0, "", 0},
		{0, "90", 270}, // legacy tag is clockwise
		{0, "270", 90},
	}
	for _, c := range cases {
		s := ffprobeStream{Tags: map[string]string{"rotate": c.legacyTag}}
		if c.displayMatrix != 0 {
			s.SideData = append(s.SideData, struct {
				Type     string  `json:"side_data_type"`
				Rotation float64 `json:"rotation"`
			}{"Display Matrix", c.displayMatrix})
		}
		if got := s.rotation(); got != c.want {
			t.Errorf("matrix %v / tag %q: got %d, want %d", c.displayMatrix, c.legacyTag, got, c.want)
		}
	}
}

func TestVariableFrameRateDetection(t *testing.T) {
	v := ffprobeStream{Width: 1280, Height: 720, RFrameRate: "60/1", AvgFrameRate: "24000/1001"}.video()
	if !v.VariableFrameRate {
		t.Error("r_frame_rate 60 vs average 23.976 should be variable")
	}
	v = ffprobeStream{Width: 1280, Height: 720, RFrameRate: "30000/1001", AvgFrameRate: "30000/1001"}.video()
	if v.VariableFrameRate {
		t.Error("equal rates are constant")
	}
}
