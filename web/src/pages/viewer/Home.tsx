import { Link } from 'react-router'
import { buttonClass } from '../../ui/Button'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useVideoPages, VideoGrid } from '../../ui/VideoGrid'
import { VideoTile } from '../../ui/VideoTile'
import styles from './Viewer.module.css'

export function Home() {
  const q = useVideoPages('/v1/videos')
  if (q.isPending) return <p className={styles.status}>Loading videos…</p>
  if (!q.data) return <ProblemMessage problem={toProblem(q.error)} />
  const first = q.data.pages[0]?.items[0]

  if (!first) {
    return (
      <div className={styles.empty}>
        <h1 className={styles.heading}>Home</h1>
        <p>No videos yet. Be the first to upload one</p>
        <Link to="/studio/upload" className={buttonClass('primary')}>
          Upload
        </Link>
      </div>
    )
  }

  return (
    <div className={styles.page}>
      <h1 className="visually-hidden">Home</h1>
      <section aria-label="Featured video" className={styles.feature}>
        <VideoTile key={first.id} video={first} size="feature" />
        <Link to={`/watch/${first.id}`} className={`${buttonClass('primary')} ${styles.watch}`}>
          Watch now
        </Link>
      </section>
      <h2 className={styles.sectionHeading}>Latest</h2>
      <VideoGrid query={q} label="Latest" skip={1} empty={<p className={styles.status}>That is everything so far.</p>} />
    </div>
  )
}
