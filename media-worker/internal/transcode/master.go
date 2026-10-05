package transcode

import (
	"fmt"
	"strconv"
	"strings"

	"videoplatform/media-worker/internal/ladder"
)

// AudioBitrate is the AAC bitrate muxed into every rendition.
const AudioBitrate = 128_000

// MasterPlaylist lists the renditions (each in "<name>/playlist.m3u8") with the attributes
// players use to choose between them (RFC 8216 §4.3.4.2).
func MasterPlaylist(renditions []ladder.Rendition, hasAudio bool) string {
	var b strings.Builder
	b.WriteString("#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n")
	for _, r := range renditions {
		peak, avg, codecs := r.PeakBitrate, r.Bitrate, r.Codecs()
		if hasAudio {
			peak, avg, codecs = peak+AudioBitrate, avg+AudioBitrate, codecs+",mp4a.40.2"
		}
		fmt.Fprintf(&b, "#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,CODECS=\"%s\",RESOLUTION=%dx%d,FRAME-RATE=%s\n%s/%s\n",
			peak, avg, codecs, r.Width, r.Height, strconv.FormatFloat(r.FrameRate, 'f', 3, 64), r.Name(), PlaylistFile)
	}
	return b.String()
}
