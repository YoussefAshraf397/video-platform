import { useEffect, useRef, useState } from 'react'
import Hls from 'hls.js'
import { ApiError } from '../api/client'
import type { Playback } from '../api/types'
import { ProblemMessage } from '../ui/ProblemMessage'
import { refreshDelayMs } from './refresh'
import styles from './Player.module.css'

type Props = {
  playback: Playback
  /** BCP 47 tag of the video's spoken language; the matching caption track is shown by default. */
  videoLanguage: string | null
  /**
   * Re-authorizes playback (the page refetches `/playback`, which renews the cookies). Called ahead of
   * expiry and, at most once per burst of 403s, when the media origin refuses a request. Rejects on failure.
   */
  onExpired: () => Promise<void>
}

type Track = { lang?: string; name?: string }
type Level = { height: number }

const NATIVE = 'application/vnd.apple.mpegurl'
const MIN_REFRESH_GAP_MS = 10_000
const primary = (tag: string) => tag.toLowerCase().split(/[-_]/)[0]
const MAX_NETWORK_RETRIES = 3

const problem = (title: string, detail: string | null, status = 0) =>
  new ApiError({ status, code: null, title, detail, errors: [] })
const refused = problem('Playback was refused', 'Your access to this video ended. Reload the page to try again.', 403)
const networkDown = problem('Playback stopped', 'The video could not be loaded. Check your connection and reload the page.')
const unplayable = problem("This browser can't play this video", null)

export function Player({ playback, videoLanguage, onExpired }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const hlsRef = useRef<Hls | null>(null)
  const latest = useRef({ onExpired, videoLanguage })
  latest.current = { onExpired, videoLanguage }

  const [levels, setLevels] = useState<Level[]>([])
  const [level, setLevel] = useState(-1)
  const [tracks, setTracks] = useState<Track[]>([])
  const [track, setTrack] = useState(-1)
  const [unsupported, setUnsupported] = useState(false)
  const [stopped, setStopped] = useState<ApiError | null>(null)
  // What the viewer has picked (or the default we chose), re-applied after a recovery reload. null track = not decided yet.
  const chosen = useRef<{ track: number | null; level: number }>({ track: null, level: -1 })

  const url = playback.streams.hls
  const expiresAt = playback.auth.expires_at

  // The hls instance lives for one stream URL. A refresh changes `playback` but not the URL.
  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    setLevels([])
    setLevel(-1)
    setTracks([])
    setTrack(-1)
    setUnsupported(false)
    setStopped(null)
    chosen.current = { track: null, level: -1 }

    if (Hls.isSupported()) {
      const hls = new Hls({
        xhrSetup: (xhr) => {
          // The signed cookies belong to the media domain (cross-origin): send them.
          xhr.withCredentials = true
        },
      })
      hlsRef.current = hls
      let dead = false
      let refreshing = false
      // Set when a 403 triggered a refresh; cleared once media loads again. While set, further 403s don't refresh.
      let spent = false
      let networkRetries = 0
      // True from a 403 recovery reload until its manifest is parsed: don't autoplay, restore the viewer's choices.
      let recovering = false
      let wasPlaying = false

      hls.on(Hls.Events.MANIFEST_PARSED, (_, data) => {
        setLevels(data.levels)
        if (recovering) {
          recovering = false
          // loadSource put hls back on Auto.
          if (chosen.current.level !== -1) hls.currentLevel = chosen.current.level
          if (wasPlaying) video.play?.()?.catch?.(() => {})
          return
        }
        const p = video.play?.()
        p?.catch?.(() => {}) // autoplay may be blocked: the viewer presses play
      })
      hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, (_, data) => {
        const list = data.subtitleTracks as Track[]
        setTracks(list)
        const want = latest.current.videoLanguage
        const i =
          chosen.current.track ??
          (want ? list.findIndex((t) => primary(t.lang ?? '') === primary(want)) : -1)
        chosen.current.track = i
        // hls.js's subtitle controller picks its own track (none, unless DEFAULT=YES) right after this event
        // returns, which would override us. Apply once that has run.
        queueMicrotask(() => {
          if (dead) return
          hls.subtitleDisplay = i >= 0
          hls.subtitleTrack = i
          setTrack(i)
        })
      })
      hls.on(Hls.Events.SUBTITLE_TRACK_SWITCH, (_, data) => setTrack(data.id))
      hls.on(Hls.Events.LEVEL_SWITCHED, () => setLevel(hls.autoLevelEnabled ? -1 : hls.currentLevel))
      hls.on(Hls.Events.FRAG_LOADED, () => {
        spent = false
        networkRetries = 0
      })
      hls.on(Hls.Events.ERROR, (_, data) => {
        if (dead) return
        if (data.response?.code === 403) {
          if (refreshing) return // the rest of the same burst
          if (spent) {
            // Fresh cookies were refused too: stop here instead of looping.
            hls.stopLoad()
            setStopped(refused)
            return
          }
          spent = true
          refreshing = true
          const at = video.currentTime
          wasPlaying = !video.paused
          latest.current
            .onExpired()
            .then(() => {
              if (dead) return
              recovering = true
              hls.loadSource(url)
              hls.startLoad(at)
            })
            .catch(() => {
              if (dead) return
              hls.stopLoad()
              setStopped(refused)
            })
            .finally(() => {
              refreshing = false
            })
          return
        }
        if (!data.fatal) return
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
          if (networkRetries >= MAX_NETWORK_RETRIES) {
            hls.stopLoad()
            setStopped(networkDown)
          } else {
            networkRetries += 1
            hls.startLoad()
          }
        } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError()
        else setStopped(networkDown)
      })
      hls.loadSource(url)
      hls.attachMedia(video)
      return () => {
        dead = true
        hls.destroy()
        if (hlsRef.current === hls) hlsRef.current = null
      }
    }

    if (video.canPlayType(NATIVE)) {
      // Safari and iOS: native HLS sends the cookies itself and picks the rendition.
      video.src = url
      const p = video.play?.()
      p?.catch?.(() => {})
      return () => {
        video.removeAttribute('src')
      }
    }
    setUnsupported(true)
  }, [url])

  // Refresh the cookies before they expire. A new `expires_at` (after any refresh) reschedules.
  useEffect(() => {
    const delay = Math.max(MIN_REFRESH_GAP_MS, refreshDelayMs(expiresAt, Date.now()))
    const timer = setTimeout(() => {
      latest.current.onExpired().catch(() => {})
    }, delay)
    return () => clearTimeout(timer)
  }, [expiresAt])

  const poster = [...playback.thumbnails.primary].filter((t) => t.format === 'jpg').sort((a, b) => b.width - a.width)[0]?.url

  return (
    <div className={styles.player}>
      {stopped || unsupported ? (
        <ProblemMessage problem={stopped ?? unplayable} />
      ) : (
        <video ref={videoRef} className={styles.video} controls playsInline crossOrigin="use-credentials" poster={poster} />
      )}
      {(tracks.length > 0 || levels.length > 1) && (
        <div className={styles.pickers}>
          {tracks.length > 0 && (
            <label className={styles.picker}>
              <span>Captions</span>
              <select
                value={track}
                onChange={(e) => {
                  const id = Number(e.target.value)
                  const hls = hlsRef.current
                  chosen.current.track = id
                  if (hls) {
                    hls.subtitleDisplay = id >= 0
                    hls.subtitleTrack = id
                  }
                  setTrack(id)
                }}
              >
                <option value={-1}>Off</option>
                {tracks.map((t, i) => (
                  <option key={i} value={i}>
                    {t.name || t.lang}
                  </option>
                ))}
              </select>
            </label>
          )}
          {levels.length > 1 && (
            <label className={styles.picker}>
              <span>Quality</span>
              <select
                value={level}
                onChange={(e) => {
                  const i = Number(e.target.value)
                  chosen.current.level = i
                  if (hlsRef.current) hlsRef.current.currentLevel = i
                  setLevel(i)
                }}
              >
                <option value={-1}>Auto</option>
                {levels.map((l, i) => (
                  <option key={i} value={i}>
                    {l.height}p
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>
      )}
    </div>
  )
}
