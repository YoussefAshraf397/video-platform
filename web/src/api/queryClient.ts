import { QueryClient } from '@tanstack/react-query'
import { ApiError } from './client'

/** Don't retry client errors (4xx); otherwise allow up to 2 retries. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false
  return failureCount < 2
}

export function createQueryClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: shouldRetry } } })
}
