import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { Playback } from '../api/types'
import { toProblem } from '../ui/ProblemMessage'

/**
 * Playback authorization for a video. The API's response sets the signed media cookies (HttpOnly,
 * Set-Cookie) as a side effect, so calling `refresh()` renews them. It rejects if the refresh fails.
 * `error` is only the failure to get playback at all: a failed refresh keeps the player running.
 */
export function usePlayback(videoId: string) {
  const q = useQuery({
    queryKey: ['playback', videoId],
    queryFn: async () => (await api<Playback>('GET', `/v1/videos/${encodeURIComponent(videoId)}/playback`)).data,
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
  return {
    playback: q.data ?? null,
    error: q.data || !q.error ? null : toProblem(q.error),
    isPending: q.isPending,
    async refresh(): Promise<void> {
      await q.refetch({ throwOnError: true })
    },
  }
}
