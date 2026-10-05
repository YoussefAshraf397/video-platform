# Golden test corpus

<!-- Generated from corpus.go by: UPDATE_CORPUS_DOC=1 go test ./internal/testcorpus/ — do not edit. -->

Source videos every media-worker stage is tested against. Files are generated with ffmpeg on
first use and cached in the OS temp directory (nothing binary is committed). Each row is the
outcome the worker must produce; tests read these expectations from `corpus.go`.

**Display size** is what viewers see, after non-square pixels and rotation. **MVP ladder** is
the rendition heights ADR-004 requires (360/480/720/1080, never above the source); the
transcoder tests (S2-10) assert it.

## Accepted

| File | Covers | Container | Video | Display size | FPS | Notes | Audio | MVP ladder |
|---|---|---|---|---|---|---|---|---|
| `landscape_1080p30.mp4` | Typical 16:9 upload: H.264 + AAC in MP4 | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1920x1080 | 30 | — | mono | 360p, 480p, 720p, 1080p |
| `vertical_1080x1920.mp4` | Vertical (9:16) video stored upright | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1080x1920 | 30 | — | none | 360p, 480p, 720p, 1080p |
| `phone_rotated.mp4` | Phone portrait recording: landscape frames + 90° display rotation | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1080x1920 | 30 | rotated 90° | mono | 360p, 480p, 720p, 1080p |
| `sd_4x3_480p.mp4` | Old 4:3 SD footage | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 640x480 | 30 | — | none | 360p, 480p |
| `uhd_4k_2160p24.mp4` | Source above 1080p (4K camera) | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 3840x2160 | 24 | — | none | 360p, 480p, 720p, 1080p |
| `gaming_720p60.mp4` | 60 fps gameplay capture | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1280x720 | 60 | — | none | 360p, 480p, 720p |
| `variable_frame_rate.mp4` | VFR phone/screen recording (30 fps, then 10 fps) | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1280x720 | variable | — | none | 360p, 480p, 720p |
| `no_audio.mp4` | Silent screen recording, no audio stream | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1920x1080 | 30 | — | none | 360p, 480p, 720p, 1080p |
| `surround_5_1.mov` | QuickTime export with 5.1 surround audio | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1920x1080 | 25 | — | 5.1 | 360p, 480p, 720p, 1080p |
| `webm_vp9_opus.webm` | Browser-recorded WebM (VP9 + Opus) | matroska,webm | vp9 | 1280x720 | 30 | — | mono | 360p, 480p, 720p |
| `hdr10_hevc_10bit.mp4` | HDR10 HEVC 10-bit (newer phones and cameras) | mov,mp4,m4a,3gp,3g2,mj2 | hevc | 1280x720 | 24 | HDR | none | 360p, 480p, 720p |
| `anamorphic_dvd.mp4` | DVD rip: 720x480 with non-square (32:27) pixels, 29.97 fps | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 853x480 | 29.97 | — | none | 360p, 480p |
| `interlaced_1080i.mkv` | Broadcast/camcorder 1080i (interlaced) in Matroska | matroska,webm | h264 | 1920x1080 | 25 | interlaced | none | 360p, 480p, 720p, 1080p |
| `legacy_mpeg4_avi.avi` | Old camera/AVI export with MPEG-4 Part 2 and MP3 | avi | mpeg4 | 640x480 | 25 | — | mono | 360p, 480p |
| `mpegts_broadcast.ts` | MPEG-TS from a capture card or recorder | mpegts | h264 | 1280x720 | 25 | — | mono | 360p, 480p, 720p |
| `black_intro.mp4` | Video that opens on black (fade-in, title card): black for the first 4.5 of 8 s | mov,mp4,m4a,3gp,3g2,mj2 | h264 | 1280x720 | 30 | — | none | 360p, 480p, 720p |

## Rejected

| File | Covers | Rejection code |
|---|---|---|
| `audio_only.m4a` | Podcast/music file uploaded as a video | `NO_VIDEO_STREAM` |
| `audio_with_cover_art.m4a` | Music file with album art (the art is a 'video' stream FFmpeg marks attached_pic) | `NO_VIDEO_STREAM` |
| `tiny_64x64.mp4` | Icon-sized clip below the 128 px minimum | `RESOLUTION_OUT_OF_RANGE` |
| `too_short_0.4s.mp4` | Clip shorter than the 1 s minimum | `DURATION_TOO_SHORT` |
| `flash_video.flv` | Real video in a container we don't accept (FLV) | `NOT_A_VIDEO` |
| `truncated.mp4` | Upload cut off before the moov atom | `CORRUPT_SOURCE` |
| `not_a_video.txt` | Text file renamed by a user | `NOT_A_VIDEO` |
| `hls_playlist_ssrf.m3u8` | Malicious playlist that would make FFmpeg fetch the cloud metadata endpoint | `NOT_A_VIDEO` |

## Adding a sample

1. Add it to `Samples` in `corpus.go` with its expected outcome.
2. Run `UPDATE_CORPUS_DOC=1 go test ./internal/testcorpus/` to regenerate this file.
3. `go test ./...` checks the new sample in every stage. The cache key changes automatically.
