import { useInfiniteQuery, type UseInfiniteQueryResult, type InfiniteData } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { api } from '../api/client'
import type { VideoPage } from '../api/types'
import { buttonClass } from './Button'
import { ProblemMessage, toProblem } from './ProblemMessage'
import { VideoTile } from './VideoTile'
import styles from './VideoGrid.module.css'

export type VideoQuery = UseInfiniteQueryResult<InfiniteData<VideoPage, string | null>>

/** Cursor-paginated video list. `path` is the API path without a query string. */
export function useVideoPages(path: string, params?: Record<string, string>): VideoQuery {
  return useInfiniteQuery({
    queryKey: ['videos', path, params ?? {}],
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) => {
      const qs = new URLSearchParams({ limit: '24', ...params })
      if (pageParam) qs.set('cursor', pageParam)
      return (await api<VideoPage>('GET', `${path}?${qs}`)).data
    },
    getNextPageParam: (last) => (last.has_more ? last.next_cursor : undefined),
  })
}

type Props = {
  query: VideoQuery
  label: string
  /** Items to leave out at the start, e.g. one already shown as a feature. */
  skip?: number
  /** Shown when the query succeeded with nothing to list. */
  empty?: ReactNode
}

/** Handles loading, error and empty states, the tile grid and the "More videos" button. */
export function VideoGrid({ query: q, label, skip = 0, empty }: Props) {
  if (q.isPending) return <p className={styles.status}>Loading videos…</p>
  if (!q.data) return <ProblemMessage problem={toProblem(q.error)} />
  const items = q.data.pages.flatMap((p) => p.items).slice(skip)
  if (items.length === 0 && !q.hasNextPage) return <>{empty ?? <p className={styles.status}>No videos here yet.</p>}</>

  return (
    <section aria-label={label}>
      <ul className={styles.grid}>
        {items.map((v) => (
          <li key={v.id}>
            <VideoTile video={v} size="grid" />
          </li>
        ))}
      </ul>
      {q.hasNextPage && (
        <div className={styles.more}>
          {q.isFetchNextPageError && (
            <div className={styles.moreError}>
              <ProblemMessage problem={toProblem(q.error)} />
            </div>
          )}
          <button
            type="button"
            className={buttonClass('secondary')}
            disabled={q.isFetchingNextPage}
            onClick={() => void q.fetchNextPage()}
          >
            {q.isFetchingNextPage ? 'Loading…' : q.isFetchNextPageError ? 'Try again' : 'More videos'}
          </button>
        </div>
      )}
    </section>
  )
}
