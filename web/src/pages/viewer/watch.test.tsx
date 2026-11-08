import { render, screen, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { vi } from 'vitest'
import { routes } from '../../router'
import { SessionProvider } from '../../auth/SessionProvider'
import { API, server } from '../../test/msw'

vi.mock('hls.js', () => import('../../test/fakeHls'))

function at(path: string) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SessionProvider>
        <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
      </SessionProvider>
    </QueryClientProvider>,
  )
}

const tile = (n: number) => ({
  id: `v${n}`,
  title: `Video ${n}`,
  duration_ms: 65_000,
  published_at: '2026-11-05T09:00:00Z',
  creator: { handle: 'mona.haddad', display_name: 'Mona Haddad' },
  thumbnails: [],
})

const detail = {
  id: 'v1',
  title: 'Video 1',
  description: 'Line one\nLine two',
  tags: ['jazz', 'live'],
  category: { slug: 'music', name: 'Music' },
  language: 'en',
  visibility: 'public',
  status: 'published',
  duration_ms: 20000,
  creator: { handle: 'mona.haddad', display_name: 'Mona Haddad' },
  age_restricted: false,
  made_for_kids: false,
  comments_enabled: true,
  publish_on_ready: false,
  published_at: '2026-11-05T09:00:00Z',
  created_at: null,
  updated_at: null,
  etag: '"1"',
}

const playback = {
  video: { id: 'v1', title: 'Video 1', duration_ms: 20000, width: 1280, height: 720 },
  streams: { hls: 'http://media/master.m3u8' },
  auth: { type: 'cookie', expires_at: new Date(Date.now() + 3600_000).toISOString(), cookies: {} },
  thumbnails: { primary: [] },
  captions: [],
  resume_position_ms: null,
  playback_session_id: 's',
}

const problem = (status: number, code: string, title: string, detail?: string) =>
  HttpResponse.json({ type: 'about:blank', status, title, code, detail }, { status, headers: { 'Content-Type': 'application/problem+json' } })

beforeEach(() => {
  server.use(
    http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })),
    http.get(`${API}/v1/categories`, () => HttpResponse.json({ items: [] })),
  )
})

describe('watch page', () => {
  it('shows the video details and up to 6 other videos from the creator', async () => {
    let limit: string | null = null
    server.use(
      http.get(`${API}/v1/videos/v1`, () => HttpResponse.json(detail)),
      http.get(`${API}/v1/videos/v1/playback`, () => HttpResponse.json(playback)),
      http.get(`${API}/v1/users/mona.haddad/videos`, ({ request }) => {
        limit = new URL(request.url).searchParams.get('limit')
        return HttpResponse.json({ items: [1, 2, 3, 4, 5, 6, 7, 8].map(tile), next_cursor: null, has_more: false })
      }),
    )
    at('/watch/v1')
    expect(await screen.findByRole('heading', { level: 1, name: 'Video 1' })).toBeInTheDocument()
    const creator = screen.getAllByRole('link', { name: 'Mona Haddad' }).find((a) => a.getAttribute('href') === '/@mona.haddad')
    expect(creator).toBeTruthy()
    expect(screen.getByText(/Line one/).textContent).toBe('Line one\nLine two')
    expect(screen.getByRole('link', { name: 'Music' })).toHaveAttribute('href', '/categories/music')
    expect(screen.getByText('jazz')).toBeInTheDocument()
    const more = await screen.findByRole('region', { name: 'More from Mona Haddad' })
    expect(limit).toBe('6')
    expect(within(more).getAllByRole('listitem')).toHaveLength(6)
    expect(within(more).queryByRole('link', { name: 'Video 1' })).not.toBeInTheDocument()
    expect(document.querySelector('video')).not.toBeNull()
  })

  it('shows the age-restricted message in place of the player', async () => {
    server.use(
      http.get(`${API}/v1/videos/v1`, () => HttpResponse.json(detail)),
      http.get(`${API}/v1/videos/v1/playback`, () => problem(403, 'AGE_RESTRICTED', 'Forbidden')),
      http.get(`${API}/v1/users/mona.haddad/videos`, () => HttpResponse.json({ items: [], next_cursor: null, has_more: false })),
    )
    at('/watch/v1')
    expect(await screen.findByText("This video is age-restricted and can't be played here yet.")).toBeInTheDocument()
    expect(document.querySelector('video')).toBeNull()
    expect(screen.getByRole('heading', { level: 1, name: 'Video 1' })).toBeInTheDocument()
  })

  it('says a processing video is not ready', async () => {
    server.use(
      http.get(`${API}/v1/videos/v1`, () => HttpResponse.json(detail)),
      http.get(`${API}/v1/videos/v1/playback`, () => problem(409, 'VIDEO_NOT_PLAYABLE', 'Conflict')),
      http.get(`${API}/v1/users/mona.haddad/videos`, () => HttpResponse.json({ items: [], next_cursor: null, has_more: false })),
    )
    at('/watch/v1')
    expect(await screen.findByText('This video is still processing. Try again in a few minutes.')).toBeInTheDocument()
  })

  it('shows the API problem for other refusals', async () => {
    server.use(
      http.get(`${API}/v1/videos/v1`, () => HttpResponse.json(detail)),
      http.get(`${API}/v1/videos/v1/playback`, () => problem(429, 'RATE_LIMITED', 'Too many requests', 'Slow down.')),
      http.get(`${API}/v1/users/mona.haddad/videos`, () => HttpResponse.json({ items: [], next_cursor: null, has_more: false })),
    )
    at('/watch/v1')
    expect(await screen.findByText('Too many requests')).toBeInTheDocument()
    expect(screen.getByText('Slow down.')).toBeInTheDocument()
  })

  it('says so for a video that does not exist', async () => {
    server.use(
      http.get(`${API}/v1/videos/nope`, () => problem(404, 'NOT_FOUND', 'Not found')),
      http.get(`${API}/v1/videos/nope/playback`, () => problem(404, 'NOT_FOUND', 'Not found')),
    )
    at('/watch/nope')
    expect(await screen.findByText("This video doesn't exist or isn't available.")).toBeInTheDocument()
  })
})
