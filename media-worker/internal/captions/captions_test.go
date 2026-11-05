package captions

import (
	"errors"
	"strings"
	"testing"
	"time"
)

func convert(t *testing.T, input, format string) string {
	t.Helper()
	out, err := Convert([]byte(input), format)
	if err != nil {
		t.Fatalf("Convert: %v", err)
	}
	return string(out)
}

func rejection(t *testing.T, input []byte, format string) *Rejection {
	t.Helper()
	_, err := Convert(input, format)
	var r *Rejection
	if !errors.As(err, &r) {
		t.Fatalf("expected a Rejection, got %v", err)
	}
	return r
}

func TestConvertsSRTToWebVTT(t *testing.T) {
	srt := "1\r\n00:00:01,000 --> 00:00:03,500\r\nHello, <i>world</i>!\r\n\r\n2\r\n00:00:04,250 --> 00:00:06,000\r\nTwo\r\nlines\r\n"

	want := "WEBVTT\n\n00:00:01.000 --> 00:00:03.500\nHello, <i>world</i>!\n\n00:00:04.250 --> 00:00:06.000\nTwo\nlines\n"
	if got := convert(t, srt, "srt"); got != want {
		t.Errorf("got\n%q\nwant\n%q", got, want)
	}
}

func TestAcceptsWhatRealSRTFilesLookLike(t *testing.T) {
	// UTF-8 byte order mark, no index lines, a dot instead of a comma, extra blank lines,
	// one-digit milliseconds, and an hour past 99.
	srt := "\xEF\xBB\xBF00:00:01.5 --> 00:00:02,000\nNo index\n\n\n\n100:00:00,000 --> 100:00:01,000\nLong film\n"

	got := convert(t, srt, "srt")
	for _, want := range []string{"00:00:01.500 --> 00:00:02.000\nNo index", "100:00:00.000 --> 100:00:01.000\nLong film"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
}

func TestDecodesUTF16WithAByteOrderMark(t *testing.T) {
	text := "1\n00:00:01,000 --> 00:00:02,000\nCafé\n"
	le := []byte{0xFF, 0xFE}
	for _, r := range text {
		le = append(le, byte(r), byte(r>>8))
	}

	if got := convert(t, string(le), "srt"); !strings.Contains(got, "Café") {
		t.Errorf("got %q", got)
	}
}

func TestRefusesTextThatIsNotUTF8(t *testing.T) {
	latin1 := []byte("1\n00:00:01,000 --> 00:00:02,000\nCaf\xe9\n")

	if r := rejection(t, latin1, "srt"); r.Code != "CAPTIONS_ENCODING" {
		t.Errorf("code = %s", r.Code)
	}
}

func TestKeepsWebVTTCuesAndValidSettings(t *testing.T) {
	vtt := "WEBVTT - with a title\nX-TIMESTAMP-MAP=MPEGTS:900000,LOCAL:00:00:00.000\n\nSTYLE\n::cue { color: red }\n\nNOTE a comment\nspanning lines\n\nintro\n00:01.000 --> 00:02.000 align:start position:10% region:r1 bogus:1\n<v Narrator>Once upon a time</v>\n"

	want := "WEBVTT\n\n00:00:01.000 --> 00:00:02.000 align:start position:10%\n<v Narrator>Once upon a time</v>\n"
	if got := convert(t, vtt, "vtt"); got != want {
		t.Errorf("got\n%q\nwant\n%q", got, want)
	}
}

func TestStripsMarkupThatIsNotCaptionFormatting(t *testing.T) {
	srt := "1\n00:00:01,000 --> 00:00:02,000\n{\\an8}<font color=\"#ff0000\"><B>Loud</B></font> <script>alert(1)</script> & <img src=x onerror=1> --> a&amp;b &#233;\n"

	got := convert(t, srt, "srt")
	want := "<b>Loud</b> alert(1) &amp;  --&gt; a&amp;b &#233;"
	if !strings.Contains(got, "\n"+want+"\n") {
		t.Errorf("cue text: got\n%s\nwant line %q", got, want)
	}
	for _, bad := range []string{"<script", "<img", "<font", "{\\an8}"} {
		if strings.Contains(got, bad) {
			t.Errorf("output still contains %q", bad)
		}
	}
}

func TestDropsCuesWithNoText(t *testing.T) {
	srt := "1\n00:00:01,000 --> 00:00:02,000\n<i></i>\n\n2\n00:00:03,000 --> 00:00:04,000\nKept\n"

	if got := convert(t, srt, "srt"); strings.Count(got, "-->") != 1 || !strings.Contains(got, "Kept") {
		t.Errorf("got %q", got)
	}
}

func TestRejectsBrokenFilesWithTheLine(t *testing.T) {
	cases := []struct {
		name, input, format, code, message string
	}{
		{"not webvtt", "1\n00:00:01.000 --> 00:00:02.000\nx\n", "vtt", "CAPTIONS_INVALID", "line 1"},
		{"garbage timing", "1\n00:00:01,000 -> 00:00:02,000\nx\n", "srt", "CAPTIONS_INVALID", "line 2"},
		{"ends before it starts", "1\n00:00:01,000 --> 00:00:01,000\nx\n\n2\n00:00:05,000 --> 00:00:04,000\ny\n", "srt", "CAPTIONS_INVALID", "line 2"},
		{"sixty minutes", "1\n00:60:00,000 --> 01:00:01,000\nx\n", "srt", "CAPTIONS_INVALID", "invalid time"},
		{"no text at all", "WEBVTT\n\nNOTE only a comment\n", "vtt", "CAPTIONS_EMPTY", ""},
		{"empty file", "", "srt", "CAPTIONS_EMPTY", ""},
		{"unknown format", "x", "ass", "CAPTIONS_INVALID", "unknown format"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			r := rejection(t, []byte(c.input), c.format)
			if r.Code != c.code || !strings.Contains(r.Message, c.message) {
				t.Errorf("got %s %q, want %s containing %q", r.Code, r.Message, c.code, c.message)
			}
		})
	}
}

func TestRejectsOversizedFiles(t *testing.T) {
	if r := rejection(t, make([]byte, MaxBytes+1), "srt"); r.Code != "CAPTIONS_TOO_LARGE" {
		t.Errorf("code = %s", r.Code)
	}
}

func TestOutputParsesBackToTheSameCues(t *testing.T) {
	srt := "1\n00:00:01,000 --> 00:00:02,000\nA &amp; B <b>bold</b>\n\n2\n01:02:03,004 --> 01:02:05,000\nLast\n"
	first, err := Parse([]byte(srt), "srt")
	if err != nil {
		t.Fatal(err)
	}

	again, err := Parse(Render(first), "vtt")
	if err != nil {
		t.Fatalf("our own output doesn't parse: %v", err)
	}
	if len(again) != len(first) {
		t.Fatalf("%d cues, then %d", len(first), len(again))
	}
	for i := range first {
		if first[i] != again[i] {
			t.Errorf("cue %d changed: %+v → %+v", i, first[i], again[i])
		}
	}
}

func TestMediaPlaylistCoversTheVideo(t *testing.T) {
	got := MediaPlaylist("abc.vtt", 20100*time.Millisecond)

	want := "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:21\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:20.100,\nabc.vtt\n#EXT-X-ENDLIST\n"
	if got != want {
		t.Errorf("got\n%s\nwant\n%s", got, want)
	}
}

func TestDurationAddsUpSegments(t *testing.T) {
	playlist := "#EXTM3U\n#EXT-X-TARGETDURATION:4\n#EXTINF:4.000000,\na.m4s\n#EXTINF:4.000000,\nb.m4s\n#EXTINF:0.100000,\nc.m4s\n#EXT-X-ENDLIST\n"

	d, err := Duration(playlist)
	if err != nil || d != 8100*time.Millisecond {
		t.Errorf("got %v, %v", d, err)
	}
	if _, err := Duration("#EXTM3U\n"); err == nil {
		t.Error("a playlist without segments has no duration")
	}
}

const baseMaster = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n" +
	"#EXT-X-STREAM-INF:BANDWIDTH=1228000,CODECS=\"avc1.64001e,mp4a.40.2\",RESOLUTION=640x360\nh264_360p30/playlist.m3u8\n" +
	"#EXT-X-STREAM-INF:BANDWIDTH=4253000,CODECS=\"avc1.64001f,mp4a.40.2\",RESOLUTION=1280x720\nh264_720p30/playlist.m3u8\n"

func TestWithSubtitlesAddsAGroupEveryVariantUses(t *testing.T) {
	got := WithSubtitles(baseMaster, []Track{
		{Name: "English", Language: "en", Captions: true, URI: "subs/a.m3u8"},
		{Name: "Português (Brasil)", Language: "pt-BR", URI: "subs/b.m3u8"},
		{Name: "English", Language: "en", URI: "subs/c.m3u8"},
	})

	want := "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n" +
		`#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="English",LANGUAGE="en",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO,CHARACTERISTICS="public.accessibility.transcribes-spoken-dialog,public.accessibility.describes-music-and-sound",URI="subs/a.m3u8"` + "\n" +
		`#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="Português (Brasil)",LANGUAGE="pt-BR",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO,URI="subs/b.m3u8"` + "\n" +
		`#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="English (2)",LANGUAGE="en",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO,URI="subs/c.m3u8"` + "\n" +
		"#EXT-X-STREAM-INF:BANDWIDTH=1228000,CODECS=\"avc1.64001e,mp4a.40.2\",RESOLUTION=640x360,SUBTITLES=\"subs\"\nh264_360p30/playlist.m3u8\n" +
		"#EXT-X-STREAM-INF:BANDWIDTH=4253000,CODECS=\"avc1.64001f,mp4a.40.2\",RESOLUTION=1280x720,SUBTITLES=\"subs\"\nh264_720p30/playlist.m3u8\n"
	if got != want {
		t.Errorf("got\n%s\nwant\n%s", got, want)
	}
}

func TestWithSubtitlesKeepsNamesInsideTheirQuotes(t *testing.T) {
	got := WithSubtitles(baseMaster, []Track{{Name: "Say \"hi\"\nthere", Language: "en", URI: "subs/a.m3u8"}})

	if !strings.Contains(got, `NAME="Say 'hi' there"`) {
		t.Errorf("got %s", got)
	}
}

func TestWithoutTracksTheMasterIsUnchanged(t *testing.T) {
	if got := WithSubtitles(baseMaster, nil); got != baseMaster {
		t.Errorf("got %s", got)
	}
	if uri, err := FirstVariant(baseMaster); err != nil || uri != "h264_360p30/playlist.m3u8" {
		t.Errorf("FirstVariant = %q, %v", uri, err)
	}
}
