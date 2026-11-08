import { useState } from 'react'
import { Link } from 'react-router'
import type { VideoTileData } from '../api/types'
import { formatAgo, formatDuration } from '../format'
import styles from './VideoTile.module.css'

type Props = { video: VideoTileData; size: 'grid' | 'feature' }

export function VideoTile({ video, size }: Props) {
  const [broken, setBroken] = useState(false)
  const feature = size === 'feature'
  const srcSet = video.thumbnails.map((t) => `${t.url} ${t.width}w`).join(', ')
  const sizes = feature ? '(max-width: 900px) 100vw, 900px' : '(max-width: 600px) 100vw, (max-width: 1000px) 50vw, 25vw'
  const watch = `/watch/${video.id}`
  return (
    <article className={`${styles.tile} ${feature ? styles.feature : ''}`}>
      <Link to={watch} className={styles.thumbLink} tabIndex={-1} aria-hidden="true">
        <span className={styles.thumb}>
          {video.thumbnails.length > 0 && !broken && (
            <img
              className={styles.img}
              src={video.thumbnails[video.thumbnails.length - 1].url}
              srcSet={srcSet}
              sizes={sizes}
              alt=""
              loading={feature ? 'eager' : 'lazy'}
              onError={() => setBroken(true)}
            />
          )}
          {video.duration_ms != null && <span className={styles.duration}>{formatDuration(video.duration_ms)}</span>}
        </span>
      </Link>
      <h2 className={styles.title}>
        <Link to={watch}>
          {video.title}
        </Link>
      </h2>
      <p className={styles.meta}>
        {video.duration_ms != null && <span className="visually-hidden">Duration {formatDuration(video.duration_ms)}. </span>}
        <Link to={`/@${video.creator.handle}`} className={styles.creator}>
          {video.creator.display_name}
        </Link>
        {video.published_at && formatAgo(video.published_at) && <span> · {formatAgo(video.published_at)}</span>}
      </p>
    </article>
  )
}
