package captions

import (
	"bufio"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"
)

// GroupID is the subtitle group every variant in the master playlist refers to.
const GroupID = "subs"

// Track is one subtitle rendition in the master playlist.
type Track struct {
	Name     string // shown in players' menus
	Language string // BCP 47
	Captions bool   // also describes sounds and music (SDH)
	URI      string // the track's media playlist, relative to the master
}

// MediaPlaylist is the HLS playlist of one track: the whole WebVTT file as a single segment
// spanning the video. hls.js, Safari and ExoPlayer all accept this for VOD.
func MediaPlaylist(vttFile string, duration time.Duration) string {
	seconds := duration.Seconds()
	return fmt.Sprintf("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:%d\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:%.3f,\n%s\n#EXT-X-ENDLIST\n",
		int(math.Ceil(seconds)), seconds, vttFile)
}

// FirstVariant returns the URI of the first variant stream in a master playlist.
func FirstVariant(master string) (string, error) {
	sc := bufio.NewScanner(strings.NewReader(master))
	for sc.Scan() {
		if strings.HasPrefix(sc.Text(), "#EXT-X-STREAM-INF:") && sc.Scan() {
			return strings.TrimSpace(sc.Text()), nil
		}
	}
	return "", fmt.Errorf("the master playlist lists no variant streams")
}

// Duration adds up the segment durations of a media playlist.
func Duration(playlist string) (time.Duration, error) {
	var total float64
	sc := bufio.NewScanner(strings.NewReader(playlist))
	for sc.Scan() {
		if rest, ok := strings.CutPrefix(sc.Text(), "#EXTINF:"); ok {
			seconds, err := strconv.ParseFloat(strings.TrimSpace(strings.SplitN(rest, ",", 2)[0]), 64)
			if err != nil {
				return 0, fmt.Errorf("bad #EXTINF %q", rest)
			}
			total += seconds
		}
	}
	if total == 0 {
		return 0, fmt.Errorf("the media playlist has no segments")
	}
	return time.Duration(total * float64(time.Second)), nil
}

// WithSubtitles returns the master playlist with the tracks added as a subtitle group
// (RFC 8216 §4.3.4.1) that every variant refers to. Without tracks it is returned unchanged.
func WithSubtitles(master string, tracks []Track) string {
	if len(tracks) == 0 {
		return master
	}
	var media strings.Builder
	seen := map[string]int{}
	for _, t := range tracks {
		// NAME must be unique within the group, and quoted strings can't hold quotes or line breaks.
		name := strings.NewReplacer("\"", "'", "\n", " ", "\r", " ").Replace(t.Name)
		if seen[name]++; seen[name] > 1 {
			name = fmt.Sprintf("%s (%d)", name, seen[name])
		}
		fmt.Fprintf(&media, "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=%q,NAME=\"%s\",LANGUAGE=%q,DEFAULT=NO,AUTOSELECT=YES,FORCED=NO", GroupID, name, t.Language)
		if t.Captions {
			media.WriteString(`,CHARACTERISTICS="public.accessibility.transcribes-spoken-dialog,public.accessibility.describes-music-and-sound"`)
		}
		fmt.Fprintf(&media, ",URI=%q\n", t.URI)
	}

	var out strings.Builder
	inserted := false
	for _, line := range strings.SplitAfter(master, "\n") {
		if strings.HasPrefix(line, "#EXT-X-STREAM-INF:") {
			if !inserted {
				out.WriteString(media.String())
				inserted = true
			}
			line = strings.TrimRight(line, "\n") + fmt.Sprintf(",SUBTITLES=%q\n", GroupID)
		}
		out.WriteString(line)
	}
	return out.String()
}
