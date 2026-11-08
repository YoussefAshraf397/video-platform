import { useQuery } from '@tanstack/react-query'
import { api, ApiError } from '../../api/client'
import type { PublicUser } from '../../api/types'
import { formatMonthYear } from '../../format'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useVideoPages, VideoGrid } from '../../ui/VideoGrid'
import styles from './Viewer.module.css'

export function Profile({ handle }: { handle: string }) {
  const user = useQuery({
    queryKey: ['user', handle],
    queryFn: async () => (await api<PublicUser>('GET', `/v1/users/${encodeURIComponent(handle)}`)).data,
  })
  if (user.isPending) return <p className={styles.status}>Loading profile…</p>
  if (user.isError) {
    if (user.error instanceof ApiError && user.error.status === 404) {
      return (
        <div className={styles.page}>
          <h1 className={styles.heading}>There's no one called @{handle} here</h1>
        </div>
      )
    }
    return <ProblemMessage problem={toProblem(user.error)} />
  }
  const u = user.data
  return (
    <div className={styles.page}>
      <header className={styles.profileHead}>
        <h1 className={styles.heading}>{u.display_name}</h1>
        <p className={styles.handle}>@{u.handle}</p>
        {u.bio && <p className={styles.bio}>{u.bio}</p>}
        {u.created_at && <p className={styles.joined}>Joined {formatMonthYear(u.created_at)}</p>}
      </header>
      <ProfileVideos handle={u.handle} />
    </div>
  )
}

function ProfileVideos({ handle }: { handle: string }) {
  const q = useVideoPages(`/v1/users/${encodeURIComponent(handle)}/videos`)
  return <VideoGrid query={q} label="Videos" empty={<p className={styles.status}>No videos yet.</p>} />
}
