// Package captions converts uploaded subtitle files (SRT or WebVTT) into the WebVTT players get,
// and builds the HLS playlists that reference them (design doc §11 step 11, S4-06).
//
// Uploaded files are untrusted: the output is rebuilt cue by cue from what was parsed, never
// copied through. Text keeps only the tags WebVTT players render (<b>, <i>, <u>, <v>); anything
// else that looks like markup is dropped or escaped.
package captions

import (
	"bytes"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"golang.org/x/text/encoding/unicode"
)

// MaxBytes is the largest caption file accepted (the API enforces the same limit on upload).
const MaxBytes = 2 << 20

// maxCues bounds the work a hostile file can cause (a 3-hour film has ~2,000).
const maxCues = 20_000

// Rejection is a file that can't become captions; retrying won't help.
type Rejection struct {
	Code    string
	Message string
}

func (r *Rejection) Error() string { return r.Code + ": " + r.Message }

func reject(code, format string, args ...any) *Rejection {
	return &Rejection{Code: code, Message: fmt.Sprintf(format, args...)}
}

// Cue is one caption.
type Cue struct {
	Start, End time.Duration
	Settings   string // WebVTT cue settings (position, line, align, …), already validated
	Text       string // WebVTT cue text, already sanitized
}

// Convert parses an SRT or WebVTT file and returns clean WebVTT.
func Convert(data []byte, format string) ([]byte, error) {
	cues, err := Parse(data, format)
	if err != nil {
		return nil, err
	}
	return Render(cues), nil
}

// Parse decodes and parses a file. format is "srt" or "vtt".
func Parse(data []byte, format string) ([]Cue, error) {
	if len(data) > MaxBytes {
		return nil, reject("CAPTIONS_TOO_LARGE", "the file is %d bytes; the limit is %d", len(data), MaxBytes)
	}
	text, err := decode(data)
	if err != nil {
		return nil, err
	}
	lines := strings.Split(strings.ReplaceAll(strings.ReplaceAll(text, "\r\n", "\n"), "\r", "\n"), "\n")

	var cues []Cue
	switch format {
	case "srt":
		cues, err = parseBlocks(lines, 0, false)
	case "vtt":
		if !vttHeader.MatchString(lines[0]) {
			return nil, reject("CAPTIONS_INVALID", "line 1: a WebVTT file starts with \"WEBVTT\"")
		}
		start := 1
		for start < len(lines) && strings.TrimSpace(lines[start]) != "" {
			start++ // header metadata (e.g. X-TIMESTAMP-MAP of another packager): not carried over
		}
		cues, err = parseBlocks(lines, start, true)
	default:
		return nil, reject("CAPTIONS_INVALID", "unknown format %q", format)
	}
	if err != nil {
		return nil, err
	}
	if len(cues) == 0 {
		return nil, reject("CAPTIONS_EMPTY", "the file has no captions with text")
	}
	return cues, nil
}

var (
	vttHeader = regexp.MustCompile(`^WEBVTT([ \t].*)?$`)
	// SRT uses a comma before the milliseconds and WebVTT a dot; files in the wild mix them up,
	// and WebVTT allows leaving out the hours.
	timing    = regexp.MustCompile(`^\s*((?:\d+:)?\d{1,2}:\d{2}[.,]\d{1,3})\s*-->\s*((?:\d+:)?\d{1,2}:\d{2}[.,]\d{1,3})(.*)$`)
	setting   = regexp.MustCompile(`^(vertical:(rl|lr)|line:-?\d+(\.\d+)?%?(,(start|center|end))?|position:\d+(\.\d+)?%(,(line-left|center|line-right))?|size:\d+(\.\d+)?%|align:(start|center|end|left|right))$`)
	srtIndex  = regexp.MustCompile(`^\s*\d+\s*$`)
	assTags   = regexp.MustCompile(`\{\\[^}]*\}`) // {\an8}, {\i1} …: ASS overrides some SRT files carry
	markup    = regexp.MustCompile(`<(/?)([A-Za-z]+)([^>]*)>`)
	entity    = regexp.MustCompile(`^&(amp|lt|gt|nbsp|lrm|rlm|#\d{1,7}|#x[0-9A-Fa-f]{1,6});`)
	blankLine = func(s string) bool { return strings.TrimSpace(s) == "" }
)

// parseBlocks reads cue blocks separated by blank lines, from lines[start:].
func parseBlocks(lines []string, start int, vtt bool) ([]Cue, error) {
	var cues []Cue
	for i := start; i < len(lines); {
		if blankLine(lines[i]) {
			i++
			continue
		}
		first := i
		for i < len(lines) && !blankLine(lines[i]) {
			i++
		}
		block := lines[first:i]

		if vtt && (strings.HasPrefix(block[0], "NOTE") || block[0] == "STYLE" || block[0] == "REGION") {
			continue // comments, styles and regions aren't carried over
		}
		t := 0 // the timing line: first or second (after a cue identifier / SRT index)
		if !timing.MatchString(block[0]) && len(block) > 1 && (vtt || srtIndex.MatchString(block[0])) {
			t = 1
		}
		m := timing.FindStringSubmatch(block[t])
		if m == nil {
			return nil, reject("CAPTIONS_INVALID", "line %d: expected a cue timing line (00:00:01.000 --> 00:00:02.000)", first+t+1)
		}
		startAt, err1 := parseTime(m[1])
		endAt, err2 := parseTime(m[2])
		if err := errors.Join(err1, err2); err != nil {
			return nil, reject("CAPTIONS_INVALID", "line %d: %v", first+t+1, err)
		}
		if endAt <= startAt {
			return nil, reject("CAPTIONS_INVALID", "line %d: the cue ends (%s) before it starts (%s)", first+t+1, m[2], m[1])
		}

		text := sanitize(strings.Join(block[t+1:], "\n"))
		if strings.TrimSpace(markup.ReplaceAllString(text, "")) == "" {
			continue // nothing a viewer would see
		}
		if len(cues) == maxCues {
			return nil, reject("CAPTIONS_TOO_LARGE", "more than %d cues", maxCues)
		}
		settings := ""
		if vtt {
			settings = cueSettings(m[3])
		}
		cues = append(cues, Cue{Start: startAt, End: endAt, Settings: settings, Text: text})
	}
	return cues, nil
}

func parseTime(s string) (time.Duration, error) {
	s = strings.Replace(s, ",", ".", 1)
	parts := strings.Split(s, ":")
	if len(parts) == 2 {
		parts = append([]string{"0"}, parts...)
	}
	secParts := strings.SplitN(parts[2], ".", 2)
	h, err1 := strconv.Atoi(parts[0])
	m, err2 := strconv.Atoi(parts[1])
	sec, err3 := strconv.Atoi(secParts[0])
	if err := errors.Join(err1, err2, err3); err != nil || m > 59 || sec > 59 {
		return 0, fmt.Errorf("invalid time %q", s)
	}
	frac := (secParts[1] + "00")[:3] // "5" means 500 ms
	ms, _ := strconv.Atoi(frac)
	return time.Duration(h)*time.Hour + time.Duration(m)*time.Minute + time.Duration(sec)*time.Second + time.Duration(ms)*time.Millisecond, nil
}

// cueSettings keeps the WebVTT settings players understand and drops the rest (e.g. regions,
// which aren't carried over).
func cueSettings(raw string) string {
	var kept []string
	for _, s := range strings.Fields(raw) {
		if setting.MatchString(s) {
			kept = append(kept, s)
		}
	}
	return strings.Join(kept, " ")
}

// sanitize turns raw cue text into safe WebVTT cue text: <b>, <i>, <u> and <v Name> are kept
// (normalized); other tags (SRT's <font>, anything unknown) are dropped; ASS overrides are
// removed; every other <, > and & is escaped, so the text can't contain markup or "-->".
func sanitize(raw string) string {
	raw = assTags.ReplaceAllString(raw, "")
	var b strings.Builder
	last := 0
	for _, m := range markup.FindAllStringSubmatchIndex(raw, -1) {
		b.WriteString(escape(raw[last:m[0]]))
		last = m[1]
		closing, name, rest := raw[m[2]:m[3]] == "/", strings.ToLower(raw[m[4]:m[5]]), raw[m[6]:m[7]]
		switch name {
		case "b", "i", "u":
			if closing {
				b.WriteString("</" + name + ">")
			} else {
				b.WriteString("<" + name + ">")
			}
		case "v":
			if closing {
				b.WriteString("</v>")
			} else if speaker := strings.TrimSpace(rest); speaker != "" {
				b.WriteString("<v " + escape(speaker) + ">")
			}
		}
	}
	b.WriteString(escape(raw[last:]))

	var lines []string
	for _, line := range strings.Split(b.String(), "\n") {
		if line = strings.TrimSpace(line); line != "" {
			lines = append(lines, line)
		}
	}
	return strings.Join(lines, "\n")
}

// escape makes plain text safe as WebVTT cue text, keeping character references that are
// already valid (&amp;, &#233; …).
func escape(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); {
		switch s[i] {
		case '&':
			if m := entity.FindString(s[i:]); m != "" {
				b.WriteString(m)
				i += len(m)
				continue
			}
			b.WriteString("&amp;")
		case '<':
			b.WriteString("&lt;")
		case '>':
			b.WriteString("&gt;")
		default:
			b.WriteByte(s[i])
		}
		i++
	}
	return b.String()
}

// decode returns the file as UTF-8 text: UTF-8 (with or without a byte order mark) or UTF-16
// with one. Legacy 8-bit encodings can't be told apart reliably, so they're refused with a
// message that says what to do.
func decode(data []byte) (string, error) {
	switch {
	case bytes.HasPrefix(data, []byte{0xEF, 0xBB, 0xBF}):
		data = data[3:]
	case bytes.HasPrefix(data, []byte{0xFF, 0xFE}), bytes.HasPrefix(data, []byte{0xFE, 0xFF}):
		utf8Data, err := unicode.UTF16(unicode.LittleEndian, unicode.ExpectBOM).NewDecoder().Bytes(data)
		if err != nil {
			return "", reject("CAPTIONS_ENCODING", "the file is not valid UTF-16")
		}
		data = utf8Data
	}
	if !utf8.Valid(data) {
		return "", reject("CAPTIONS_ENCODING", "the file is not UTF-8 text; save it as UTF-8 and upload it again")
	}
	return string(data), nil
}

// Render writes cues as a WebVTT file.
func Render(cues []Cue) []byte {
	var b bytes.Buffer
	b.WriteString("WEBVTT\n")
	for _, c := range cues {
		fmt.Fprintf(&b, "\n%s --> %s", stamp(c.Start), stamp(c.End))
		if c.Settings != "" {
			b.WriteString(" " + c.Settings)
		}
		b.WriteString("\n" + c.Text + "\n")
	}
	return b.Bytes()
}

func stamp(d time.Duration) string {
	ms := d.Milliseconds()
	return fmt.Sprintf("%02d:%02d:%02d.%03d", ms/3_600_000, ms/60_000%60, ms/1000%60, ms%1000)
}
