package pipeline

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path"
	"path/filepath"

	"videoplatform/media-worker/internal/captions"
	"videoplatform/media-worker/internal/jobs"
	"videoplatform/media-worker/internal/results"
	"videoplatform/media-worker/internal/sqsworker"
	"videoplatform/media-worker/internal/storage"
)

// Captions implements jobs.CaptionsProcessor (S4-06): it publishes one captions revision of a
// processing version.
//
//	read the base master (as transcoding wrote it) and the version's duration →
//	for each track: download, convert to WebVTT, write subs/{subtitle_id}.vtt + .m3u8 →
//	write master.c{revision}.m3u8 = base master + the tracks that converted →
//	report VideoCaptionsPublished.
//
// Every file is new and named by the subtitle id or the revision, so nothing a CDN has cached is
// ever overwritten, and running the same request twice writes the same bytes. A track that
// can't be converted is reported as failed and left out; the others still publish. S3 errors are
// returned so SQS retries the whole request (after five receives it goes to the DLQ).
func (p *Pipeline) Captions(ctx context.Context, req jobs.CaptionsRequest) error {
	job := results.Job{VideoID: req.VideoID, JobID: req.JobID, ProcessingVersion: req.ProcessingVersion}
	log := p.Log.With("video_id", req.VideoID, "job_id", req.JobID, "captions_revision", req.Revision, "attempt", req.Attempt)

	if err := os.MkdirAll(p.Scratch, 0o750); err != nil {
		return err
	}
	dir, err := os.MkdirTemp(p.Scratch, jobDirPattern)
	if err != nil {
		return err
	}
	defer func() {
		if err := os.RemoveAll(dir); err != nil {
			log.Warn("could not remove the job's scratch directory; the next start cleans it", "dir", dir, "error", err)
		}
	}()

	bucket, prefix := req.Output.Bucket, req.Output.Prefix
	base, err := p.read(ctx, bucket, req.BaseMasterKey, filepath.Join(dir, "master.m3u8"))
	if errors.Is(err, storage.ErrNotFound) {
		// The version's outputs are gone (purged) or never existed: nothing to attach captions to.
		return sqsworker.Poison(fmt.Errorf("base master playlist %s: %w", req.BaseMasterKey, err))
	} else if err != nil {
		return err
	}
	variant, err := captions.FirstVariant(base)
	if err != nil {
		return sqsworker.Poison(err)
	}
	variantPlaylist, err := p.read(ctx, bucket, path.Join(path.Dir(req.BaseMasterKey), variant), filepath.Join(dir, "variant.m3u8"))
	if err != nil {
		return err
	}
	duration, err := captions.Duration(variantPlaylist)
	if err != nil {
		return sqsworker.Poison(err)
	}

	outcomes := make([]results.CaptionTrack, 0, len(req.Tracks))
	var tracks []captions.Track
	for _, t := range req.Tracks {
		vtt, rejection, err := p.convertTrack(ctx, t, filepath.Join(dir, t.SubtitleID))
		if err != nil {
			return err
		}
		if rejection != nil {
			log.Info("caption track rejected", "subtitle_id", t.SubtitleID, "code", rejection.Code, "reason", rejection.Message)
			outcomes = append(outcomes, results.CaptionTrack{SubtitleID: t.SubtitleID, Status: "failed",
				Error: &results.CaptionError{Code: rejection.Code, Message: rejection.Message}})
			continue
		}

		vttKey, playlistKey := prefix+"subs/"+t.SubtitleID+".vtt", prefix+"subs/"+t.SubtitleID+".m3u8"
		vttFile, playlistFile := filepath.Join(dir, t.SubtitleID+".vtt"), filepath.Join(dir, t.SubtitleID+".m3u8")
		if err := errors.Join(
			os.WriteFile(vttFile, vtt, 0o600),
			os.WriteFile(playlistFile, []byte(captions.MediaPlaylist(t.SubtitleID+".vtt", duration)), 0o600),
		); err != nil {
			return err
		}
		if err := p.Store.Upload(ctx, bucket, []storage.File{
			{Path: vttFile, Key: vttKey, CacheControl: storage.CacheImmutable},
			{Path: playlistFile, Key: playlistKey, CacheControl: storage.CacheImmutable},
		}); err != nil {
			return err
		}
		outcomes = append(outcomes, results.CaptionTrack{SubtitleID: t.SubtitleID, Status: "ready", PlaylistKey: &playlistKey, VttKey: &vttKey})
		tracks = append(tracks, captions.Track{Name: t.Label, Language: t.Language, Captions: t.Kind == "captions", URI: "subs/" + t.SubtitleID + ".m3u8"})
	}

	// The tracks are uploaded before the master that lists them, so it never points at a missing file.
	masterKey := fmt.Sprintf("%smaster.c%d.m3u8", prefix, req.Revision)
	masterFile := filepath.Join(dir, path.Base(masterKey))
	if err := os.WriteFile(masterFile, []byte(captions.WithSubtitles(base, tracks)), 0o600); err != nil {
		return err
	}
	if err := p.Store.Upload(ctx, bucket, []storage.File{{Path: masterFile, Key: masterKey, CacheControl: storage.CacheImmutable}}); err != nil {
		return err
	}
	log.Info("captions published", "master", masterKey, "tracks", len(tracks), "failed", len(req.Tracks)-len(tracks))
	return p.Results.CaptionsPublished(ctx, job, req.Revision, masterKey, outcomes)
}

// convertTrack downloads and converts one source. A bad file is a Rejection, not an error.
func (p *Pipeline) convertTrack(ctx context.Context, t jobs.CaptionTrack, dest string) ([]byte, *captions.Rejection, error) {
	if err := p.Store.Download(ctx, t.Source.Bucket, t.Source.Key, dest); errors.Is(err, storage.ErrNotFound) {
		return nil, &captions.Rejection{Code: "CAPTIONS_NOT_FOUND", Message: "the uploaded file is gone"}, nil
	} else if err != nil {
		return nil, nil, err
	}
	if info, err := os.Stat(dest); err != nil {
		return nil, nil, err
	} else if info.Size() > captions.MaxBytes {
		return nil, &captions.Rejection{Code: "CAPTIONS_TOO_LARGE", Message: fmt.Sprintf("the file is %d bytes; the limit is %d", info.Size(), captions.MaxBytes)}, nil
	}
	data, err := os.ReadFile(dest) // #nosec G304 -- a path inside the job's own scratch directory
	if err != nil {
		return nil, nil, err
	}
	vtt, err := captions.Convert(data, t.Format)
	var rejection *captions.Rejection
	if errors.As(err, &rejection) {
		return nil, rejection, nil
	}
	return vtt, nil, err
}

// read downloads a small text object.
func (p *Pipeline) read(ctx context.Context, bucket, key, dest string) (string, error) {
	if err := p.Store.Download(ctx, bucket, key, dest); err != nil {
		return "", err
	}
	data, err := os.ReadFile(dest) // #nosec G304 -- a path inside the job's own scratch directory
	return string(data), err
}
