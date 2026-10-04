# ADR-004: HLS with CMAF segments as the streaming format

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
Playback has to work on every browser, iOS/Safari and smart TVs, with adaptive bitrate.

## Decision
- **HLS** is the only delivery format at the MVP. Segments are **CMAF fMP4** (not MPEG-TS).
- **Segment length:** 4 s for the MVP (we may raise it to 6 s after load testing). Use fixed, closed GOPs aligned to segment boundaries across all renditions.
- **MVP ladder:** H.264 High profile + AAC-LC 128 kbps stereo. Rungs are 360p, 480p, 720p and 1080p, and **only rungs ≤ source height are generated**. Height is measured on the short side, so vertical video is handled correctly.
- **Bitrates (30 fps):**

  | Rung | Bitrate |
  |---|---|
  | 360p | 0.8 Mbps |
  | 480p | 1.4 Mbps |
  | 720p | 3.0 Mbps |
  | 1080p | 5.5 Mbps |

  Sources at 50/60 fps get ×1.5.
- **Output layout:** `media/{video_id}/v{processing_version}/…`. Paths include the version and are immutable, so segments can be cached for 1 year and the master playlist for 1–5 min.
- **DASH is not generated at the MVP.** CMAF lets us add it later without re-encoding.

## Alternatives considered
- **HLS with MPEG-TS:** legacy only; no path to HEVC/AV1 or DASH.
- **DASH only:** not native on iOS/Safari.

## Consequences
- ➕ Universal playback. Future codecs (HEVC/AV1) and DASH reuse the same packaging.
- ➖ No 1440p/4K/HDR at the MVP. HDR sources are tone-mapped to SDR.

## Revisit when
Egress cost justifies per-title encoding or AV1 (growth stage), or Android TV/DRM partners need DASH.
