const LEAD_MS = 5 * 60_000

/** How long to wait before refreshing playback: 5 minutes before the cookies expire, never negative. */
export function refreshDelayMs(expiresAt: string, now: number): number {
  const at = Date.parse(expiresAt)
  if (Number.isNaN(at)) return 0
  return Math.max(0, at - now - LEAD_MS)
}
