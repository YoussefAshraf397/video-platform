package ladder

import (
	"testing"
)

func TestForPicksRungsAtOrBelowTheSourceShortSide(t *testing.T) {
	cases := []struct {
		name          string
		width, height int
		fps           float64
		want          []Rendition
	}{
		{"1080p landscape", 1920, 1080, 30, []Rendition{
			{640, 360, 360, 30, 800_000, 1_100_000}, {854, 480, 480, 30, 1_400_000, 1_925_000},
			{1280, 720, 720, 30, 3_000_000, 4_125_000}, {1920, 1080, 1080, 30, 5_500_000, 7_562_500},
		}},
		{"vertical 1080x1920 gets the same rungs on its short side", 1080, 1920, 30, []Rendition{
			{360, 640, 360, 30, 800_000, 1_100_000}, {480, 854, 480, 30, 1_400_000, 1_925_000},
			{720, 1280, 720, 30, 3_000_000, 4_125_000}, {1080, 1920, 1080, 30, 5_500_000, 7_562_500},
		}},
		{"4K is capped at 1080p", 3840, 2160, 24, []Rendition{
			{640, 360, 360, 24, 800_000, 1_100_000}, {854, 480, 480, 24, 1_400_000, 1_925_000},
			{1280, 720, 720, 24, 3_000_000, 4_125_000}, {1920, 1080, 1080, 24, 5_500_000, 7_562_500},
		}},
		{"60 fps gets 1.5x bitrate and never upscales", 1280, 720, 60, []Rendition{
			{640, 360, 360, 60, 1_200_000, 1_650_000}, {854, 480, 480, 60, 2_100_000, 2_887_500},
			{1280, 720, 720, 60, 4_500_000, 6_187_500},
		}},
		{"4:3 SD", 640, 480, 30, []Rendition{
			{480, 360, 360, 30, 800_000, 1_100_000}, {640, 480, 480, 30, 1_400_000, 1_925_000},
		}},
		{"anamorphic DVD at its display size", 853, 480, 29.97, []Rendition{
			{640, 360, 360, 29.97, 800_000, 1_100_000}, {854, 480, 480, 29.97, 1_400_000, 1_925_000},
		}},
		{"smaller than 360p: one rung at its own size", 320, 240, 30, []Rendition{
			{320, 240, 240, 30, 800_000, 1_100_000},
		}},
		{"odd sizes are made even", 500, 281, 30, []Rendition{
			{498, 280, 280, 30, 800_000, 1_100_000},
		}},
		{"120 fps slow motion is capped at 60", 1920, 1080, 120, nil},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := For(tc.width, tc.height, tc.fps)
			if tc.want == nil {
				for _, r := range got {
					if r.FrameRate != MaxFrameRate {
						t.Fatalf("frame rate %v, want %v", r.FrameRate, MaxFrameRate)
					}
				}
				return
			}
			if len(got) != len(tc.want) {
				t.Fatalf("got %d renditions %+v, want %d", len(got), got, len(tc.want))
			}
			for i := range got {
				if got[i] != tc.want[i] {
					t.Errorf("rendition %d = %+v, want %+v", i, got[i], tc.want[i])
				}
			}
		})
	}
}

func TestNameAndGOP(t *testing.T) {
	r := For(1920, 1080, 29.97)[2]
	if r.Name() != "h264_720p30" {
		t.Errorf("Name() = %q", r.Name())
	}
	if r.GOP() != 120 {
		t.Errorf("GOP() = %d, want 120 (4 s at 29.97 fps)", r.GOP())
	}
	if (Rendition{FrameRate: 25}).GOP() != 100 {
		t.Error("GOP at 25 fps should be 100")
	}
}

func TestUnknownFrameRateBecomes30(t *testing.T) {
	if fps := For(1280, 720, 0)[0].FrameRate; fps != 30 {
		t.Errorf("frame rate %v, want 30", fps)
	}
}

func TestLevelAndCodecs(t *testing.T) {
	cases := []struct {
		w, h       int
		fps        float64
		rung       int
		wantLevel  string
		wantCodecs string
	}{
		{1920, 1080, 30, 0, "3.0", "avc1.64001e"},   // 360p30
		{1920, 1080, 30, 1, "3.1", "avc1.64001f"},   // 480p30: over 3.0's macroblock rate
		{1920, 1080, 30, 2, "3.1", "avc1.64001f"},   // 720p30
		{1920, 1080, 30, 3, "4.0", "avc1.640028"},   // 1080p30
		{1920, 1080, 60, 2, "3.2", "avc1.640020"},   // 720p60
		{1920, 1080, 60, 3, "4.2", "avc1.64002a"},   // 1080p60
		{1080, 1920, 30, 3, "4.0", "avc1.640028"},   // vertical 1080p30: same macroblocks
		{1280, 720, 29.97, 2, "3.1", "avc1.64001f"}, // 720p29.97
	}
	for _, c := range cases {
		r := For(c.w, c.h, c.fps)[c.rung]
		if level, _ := r.Level(); level != c.wantLevel || r.Codecs() != c.wantCodecs {
			t.Errorf("%s (%dx%d@%v): level %s %s, want %s %s", r.Name(), r.Width, r.Height, r.FrameRate, level, r.Codecs(), c.wantLevel, c.wantCodecs)
		}
	}
}
