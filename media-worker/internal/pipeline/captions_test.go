package pipeline

import (
	"context"
	"slices"
	"strings"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/google/uuid"

	"videoplatform/media-worker/internal/jobs"
)

// processed runs a real transcode job, so captions have a version to attach to.
func (e *env) processed() jobs.ProcessRequest {
	e.t.Helper()
	req := e.request("sd_4x3_480p.mp4")
	if err := e.pipeline.Process(context.Background(), req); err != nil {
		e.t.Fatal(err)
	}
	e.messages() // the transcode's own results
	return req
}

func (e *env) caption(videoID, format, body string) jobs.CaptionTrack {
	e.t.Helper()
	id := uuid.NewString()
	key := "captions/" + videoID + "/" + id + "/source"
	if _, err := e.s3.PutObject(context.Background(), &s3.PutObjectInput{Bucket: aws.String(e.uploads), Key: aws.String(key), Body: strings.NewReader(body)}); err != nil {
		e.t.Fatal(err)
	}
	return jobs.CaptionTrack{SubtitleID: id, Language: "en", Label: "English", Kind: "subtitles", Format: format, Source: jobs.S3Object{Bucket: e.uploads, Key: key}}
}

func captionsRequest(job jobs.ProcessRequest, revision int, tracks ...jobs.CaptionTrack) jobs.CaptionsRequest {
	return jobs.CaptionsRequest{
		VideoID: job.VideoID, JobID: job.JobID, ProcessingVersion: job.ProcessingVersion, Attempt: 1,
		Output: job.Output, BaseMasterKey: job.Output.Prefix + "master.m3u8", Revision: revision, Tracks: tracks,
	}
}

func (e *env) object(key string) (string, string) {
	e.t.Helper()
	out, err := e.s3.GetObject(context.Background(), &s3.GetObjectInput{Bucket: aws.String(e.media), Key: aws.String(key)})
	if err != nil {
		e.t.Fatalf("get %s: %v", key, err)
	}
	defer func() { _ = out.Body.Close() }()
	var b strings.Builder
	buf := make([]byte, 4096)
	for {
		n, err := out.Body.Read(buf)
		b.Write(buf[:n])
		if err != nil {
			break
		}
	}
	return b.String(), aws.ToString(out.ContentType)
}

func TestPublishesCaptionsWithANewMasterPlaylist(t *testing.T) {
	e := setup(t)
	job := e.processed()
	srt := e.caption(job.VideoID, "srt", "1\n00:00:01,000 --> 00:00:03,000\nHello <font color=red>there</font>\n")
	vtt := e.caption(job.VideoID, "vtt", "WEBVTT\n\n00:02.000 --> 00:04.000\nOlá\n")
	vtt.Language, vtt.Label, vtt.Kind = "pt-BR", "Português", "captions"
	broken := e.caption(job.VideoID, "srt", "this is not a subtitle file\n")
	missing := jobs.CaptionTrack{SubtitleID: uuid.NewString(), Language: "fr", Label: "Français", Kind: "subtitles", Format: "srt",
		Source: jobs.S3Object{Bucket: e.uploads, Key: "captions/" + job.VideoID + "/gone/source"}}
	prefix := job.Output.Prefix

	if err := e.pipeline.Captions(context.Background(), captionsRequest(job, 1, srt, vtt, broken, missing)); err != nil {
		t.Fatal(err)
	}

	// Each converted track: a WebVTT file and its media playlist.
	text, contentType := e.object(prefix + "subs/" + srt.SubtitleID + ".vtt")
	if text != "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello there\n" || contentType != "text/vtt; charset=utf-8" {
		t.Errorf("vtt: %q (%s)", text, contentType)
	}
	playlist, _ := e.object(prefix + "subs/" + srt.SubtitleID + ".m3u8")
	if !strings.Contains(playlist, "#EXTINF:") || !strings.Contains(playlist, "\n"+srt.SubtitleID+".vtt\n") {
		t.Errorf("playlist: %s", playlist)
	}

	// The new master lists the two good tracks; the base master is untouched.
	master, _ := e.object(prefix + "master.c1.m3u8")
	base, _ := e.object(prefix + "master.m3u8")
	if strings.Contains(base, "SUBTITLES") {
		t.Error("the base master was changed")
	}
	if strings.Count(master, "#EXT-X-MEDIA:TYPE=SUBTITLES") != 2 || strings.Count(master, `SUBTITLES="subs"`) != strings.Count(base, "#EXT-X-STREAM-INF") ||
		!strings.Contains(master, `LANGUAGE="pt-BR"`) || !strings.Contains(master, "public.accessibility.describes-music-and-sound") {
		t.Errorf("master:\n%s", master)
	}

	// The result lists every track, failures with their reason.
	msgs := e.messages()
	if len(msgs) != 1 || msgs[0].EventType != "VideoCaptionsPublished" {
		t.Fatalf("messages: %v", types(msgs))
	}
	p := msgs[0].Payload
	if p["master_playlist_key"] != prefix+"master.c1.m3u8" || p["captions_revision"] != float64(1) {
		t.Errorf("payload: %v", p)
	}
	status := map[string]string{}
	for _, tr := range p["tracks"].([]any) {
		m := tr.(map[string]any)
		code := ""
		if m["error"] != nil {
			code = m["error"].(map[string]any)["code"].(string)
		}
		status[m["subtitle_id"].(string)] = m["status"].(string) + " " + code
	}
	want := map[string]string{srt.SubtitleID: "ready ", vtt.SubtitleID: "ready ", broken.SubtitleID: "failed CAPTIONS_INVALID", missing.SubtitleID: "failed CAPTIONS_NOT_FOUND"}
	for id, s := range want {
		if status[id] != s {
			t.Errorf("track %s: %q, want %q", id, status[id], s)
		}
	}
}

func TestPublishingAgainWritesTheSameFilesAndAnEmptySetRemovesTheGroup(t *testing.T) {
	e := setup(t)
	job := e.processed()
	track := e.caption(job.VideoID, "srt", "1\n00:00:01,000 --> 00:00:02,000\nHi\n")
	prefix := job.Output.Prefix

	for range 2 { // a redelivered message
		if err := e.pipeline.Captions(context.Background(), captionsRequest(job, 3, track)); err != nil {
			t.Fatal(err)
		}
	}
	msgs := e.messages()
	if len(msgs) != 2 || msgs[0].EventID != msgs[1].EventID {
		t.Errorf("a redelivery must repeat the same event id: %v", msgs)
	}

	// The creator deleted their only track: revision 4 has no subtitle group at all.
	if err := e.pipeline.Captions(context.Background(), captionsRequest(job, 4)); err != nil {
		t.Fatal(err)
	}
	master, _ := e.object(prefix + "master.c4.m3u8")
	base, _ := e.object(prefix + "master.m3u8")
	if master != base {
		t.Errorf("an empty set should publish the base master, got:\n%s", master)
	}
	if !slices.Contains(e.keys(e.media, prefix), prefix+"master.c3.m3u8") {
		t.Error("an older revision's master must stay (players may still be using it)")
	}
	if p := e.messages()[0].Payload; len(p["tracks"].([]any)) != 0 {
		t.Errorf("tracks = %v, want []", p["tracks"])
	}
}

func TestCaptionsForAVersionWithoutOutputsArePoison(t *testing.T) {
	e := setup(t)
	job := e.request("") // never processed: no master playlist

	err := e.pipeline.Captions(context.Background(), captionsRequest(job, 1))

	// Poison: straight to the DLQ (retrying can't create the outputs).
	if err == nil || !strings.HasPrefix(err.Error(), "poison message: ") {
		t.Fatalf("expected a poison error, got %v", err)
	}
}
