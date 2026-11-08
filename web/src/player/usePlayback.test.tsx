import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { http, HttpResponse } from 'msw'
import { API, server } from '../test/msw'
import { usePlayback } from './usePlayback'

const wrapper = ({ children }: { children: ReactNode }) => (
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{children}</QueryClientProvider>
)

const body = { streams: { hls: 'http://m/x.m3u8' }, auth: { expires_at: '2026-11-05T11:00:00Z' }, captions: [], thumbnails: { primary: [] } }

describe('usePlayback', () => {
  it('keeps the playback and reports no error when a refresh fails', async () => {
    let fail = false
    server.use(
      http.get(`${API}/v1/videos/v1/playback`, () =>
        fail ? HttpResponse.json({ title: 'Boom' }, { status: 500, headers: { 'Content-Type': 'application/problem+json' } }) : HttpResponse.json(body),
      ),
    )
    const { result } = renderHook(() => usePlayback('v1'), { wrapper })
    await waitFor(() => expect(result.current.playback).not.toBeNull())
    fail = true
    await expect(result.current.refresh()).rejects.toMatchObject({ status: 500 })
    await waitFor(() => expect(result.current.playback).not.toBeNull())
    expect(result.current.error).toBeNull()
  })

  it('reports the error when there is no playback at all', async () => {
    server.use(http.get(`${API}/v1/videos/v1/playback`, () => HttpResponse.json({ title: 'Nope', code: 'AGE_RESTRICTED' }, { status: 403, headers: { 'Content-Type': 'application/problem+json' } })))
    const { result } = renderHook(() => usePlayback('v1'), { wrapper })
    await waitFor(() => expect(result.current.error).toMatchObject({ code: 'AGE_RESTRICTED' }))
    expect(result.current.playback).toBeNull()
  })
})
