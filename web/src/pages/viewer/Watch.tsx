import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router'
import { api, ApiError } from '../../api/client'
import type { VideoDetail, VideoPage } from '../../api/types'
import { formatAgo } from '../../format'
import { Player } from '../../player/Player'
import { usePlayback } from '../../player/usePlayback'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { VideoTile } from '../../ui/VideoTile'
import styles from './Watch.module.css'

const NOT_FOUND = "This video doesn't exist or isn't available."
const KNOWN: Record<string, string> = {
  AGE_RESTRICTED: "This video is age-restricted and can't be played here yet.",
  VIDEO_NOT_PLAYABLE: 'This video is still processing. Try again in a few minutes.',
}

function playbackProblem(e: ApiError): ApiError {
  const title = (e.code && KNOWN[e.code]) || (e.status === 404 ? NOT_FOUND : null)
  return title ? new ApiError({ status: e.status, code: e.code, title, detail: null, errors: [] }) : e
}

export function Watch() {
  const { videoId = '' } = useParams()
  const video = useQuery({
    queryKey: ['video', videoId],
    queryFn: async () => (await api<VideoDetail>('GET', `/v1/videos/${encodeURIComponent(videoId)}`)).data,
  })
  // Started alongside the detail request, not after it.
  const playback = usePlayback(videoId)

  if (video.isPending) return <p className={styles.status}>Loading video…</p>
  if (video.isError) {
    const notFound = video.error instanceof ApiError && video.error.status === 404
    return notFound ? (
      <div className={styles.page}>
        <h1 className={styles.notFound}>{NOT_FOUND}</h1>
      </div>
    ) : (
      <ProblemMessage problem={toProblem(video.error)} />
    )
  }
  return <WatchVideo video={video.data} playback={playback} />
}

function WatchVideo({ video, playback: pb }: { video: VideoDetail; playback: ReturnType<typeof usePlayback> }) {
  const { playback, error, isPending, refresh } = pb
  const handle = video.creator.handle
  const ago = video.published_at ? formatAgo(video.published_at) : ''

  return (
    <div className={styles.page}>
      <div className={styles.main}>
        <div className={styles.stage}>
          {error ? (
            <ProblemMessage problem={playbackProblem(error)} />
          ) : isPending || !playback ? (
            <p className={styles.status}>Loading player…</p>
          ) : (
            <Player key={video.id} playback={playback} videoLanguage={video.language} onExpired={refresh} />
          )}
        </div>
        <h1 className={styles.title}>{video.title}</h1>
        <p className={styles.meta}>
          <Link to={`/@${handle}`} className={styles.creator}>
            {video.creator.display_name}
          </Link>
          {ago && <span> · {ago}</span>}
          {video.category && (
            <span>
              {' · '}
              <Link to={`/categories/${video.category.slug}`} className={styles.category}>
                {video.category.name}
              </Link>
            </span>
          )}
        </p>
        {video.description && <p className={styles.description}>{video.description}</p>}
        {video.tags.length > 0 && (
          <ul className={styles.tags} aria-label="Tags">
            {video.tags.map((t) => (
              <li key={t}>{t}</li>
            ))}
          </ul>
        )}
      </div>
      <MoreFrom videoId={video.id} handle={handle} name={video.creator.display_name} />
    </div>
  )
}

function MoreFrom({ videoId, handle, name }: { videoId: string; handle: string; name: string }) {
  const q = useQuery({
    queryKey: ['more-from', handle],
    queryFn: async () => (await api<VideoPage>('GET', `/v1/users/${encodeURIComponent(handle)}/videos?limit=6`)).data,
  })
  const items = (q.data?.items ?? []).filter((v) => v.id !== videoId).slice(0, 6)
  if (items.length === 0) return null
  return (
    <section aria-label={`More from ${name}`} className={styles.more}>
      <h2 className={styles.moreHeading}>More from {name}</h2>
      <ul className={styles.moreList}>
        {items.map((v) => (
          <li key={v.id}>
            <VideoTile video={v} size="grid" />
          </li>
        ))}
      </ul>
    </section>
  )
}
