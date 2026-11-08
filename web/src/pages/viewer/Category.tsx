import { useParams } from 'react-router'
import { useCategories } from '../../api/useCategories'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useVideoPages, VideoGrid } from '../../ui/VideoGrid'
import styles from './Viewer.module.css'

export function Category() {
  const { slug = '' } = useParams()
  const categories = useCategories()
  if (categories.isPending) return <p className={styles.status}>Loading…</p>
  if (categories.isError) return <ProblemMessage problem={toProblem(categories.error)} />
  const category = categories.data.find((c) => c.slug === slug)
  if (!category) {
    return (
      <div className={styles.page}>
        <h1 className={styles.heading}>There's no category called {slug}</h1>
      </div>
    )
  }
  return <CategoryVideos slug={category.slug} name={category.name} />
}

function CategoryVideos({ slug, name }: { slug: string; name: string }) {
  const q = useVideoPages('/v1/videos', { category: slug })
  return (
    <div className={styles.page}>
      <h1 className={styles.heading}>{name}</h1>
      <VideoGrid query={q} label={name} empty={<p className={styles.status}>No videos in {name} yet.</p>} />
    </div>
  )
}
