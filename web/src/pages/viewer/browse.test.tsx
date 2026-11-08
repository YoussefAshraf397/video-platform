import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { routes } from '../../router'
import { SessionProvider } from '../../auth/SessionProvider'
import { VideoTile } from '../../ui/VideoTile'
import { API, server } from '../../test/msw'

function at(path: string) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SessionProvider>
        <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
      </SessionProvider>
    </QueryClientProvider>,
  )
}

const video = (n: number) => ({
  id: `v${n}`,
  title: `Video ${n}`,
  duration_ms: 65_000,
  published_at: '2026-11-05T09:00:00Z',
  creator: { handle: 'mona.haddad', display_name: 'Mona Haddad' },
  thumbnails: [
    { url: `http://img/${n}-320.webp`, width: 320, height: 180 },
    { url: `http://img/${n}-640.webp`, width: 640, height: 360 },
  ],
})

const categories = { items: [{ slug: 'music', name: 'Music' }, { slug: 'gaming', name: 'Gaming' }] }

beforeEach(() => {
  server.use(
    http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })),
    http.get(`${API}/v1/categories`, () => HttpResponse.json(categories)),
  )
})

const problem = (status: number, title: string) =>
  HttpResponse.json({ type: 'about:blank', status, title }, { status, headers: { 'Content-Type': 'application/problem+json' } })

describe('home', () => {
  it('shows the first video as the feature and the rest in the Latest grid', async () => {
    server.use(
      http.get(`${API}/v1/videos`, () =>
        HttpResponse.json({ items: [video(1), video(2), video(3)], next_cursor: null, has_more: false }),
      ),
    )
    at('/')
    const feature = await screen.findByRole('region', { name: 'Featured video' })
    expect(within(feature).getByRole('link', { name: 'Video 1' })).toHaveAttribute('href', '/watch/v1')
    expect(within(feature).getByRole('link', { name: 'Watch now' })).toHaveAttribute('href', '/watch/v1')
    const latest = screen.getByRole('region', { name: 'Latest' })
    expect(within(latest).getAllByRole('listitem')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'More videos' })).not.toBeInTheDocument()
  })

  it('loads more with the cursor', async () => {
    const cursors: Array<string | null> = []
    server.use(
      http.get(`${API}/v1/videos`, ({ request }) => {
        const cursor = new URL(request.url).searchParams.get('cursor')
        cursors.push(cursor)
        return cursor
          ? HttpResponse.json({ items: [video(4)], next_cursor: null, has_more: false })
          : HttpResponse.json({ items: [video(1), video(2)], next_cursor: 'abc', has_more: true })
      }),
    )
    at('/')
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: 'More videos' }))
    expect(await screen.findByRole('link', { name: 'Video 4' })).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Video 1' })).toHaveLength(1)
    expect(screen.getAllByRole('link', { name: 'Video 2' })).toHaveLength(1)
    expect(cursors).toEqual([null, 'abc'])
    expect(screen.queryByRole('button', { name: 'More videos' })).not.toBeInTheDocument()
  })

  it('keeps the page and offers a retry when the next page fails', async () => {
    let fail = true
    server.use(
      http.get(`${API}/v1/videos`, ({ request }) => {
        if (!new URL(request.url).searchParams.get('cursor'))
          return HttpResponse.json({ items: [video(1), video(2)], next_cursor: 'abc', has_more: true })
        return fail ? problem(500, 'Server error') : HttpResponse.json({ items: [video(4)], next_cursor: null, has_more: false })
      }),
    )
    at('/')
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: 'More videos' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Server error')
    expect(screen.getByRole('region', { name: 'Featured video' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Video 2' })).toBeInTheDocument()
    fail = false
    await u.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('link', { name: 'Video 4' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows an upload prompt when empty', async () => {
    server.use(http.get(`${API}/v1/videos`, () => HttpResponse.json({ items: [], next_cursor: null, has_more: false })))
    at('/')
    expect(await screen.findByText('No videos yet. Be the first to upload one')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Upload' }).some((a) => a.getAttribute('href') === '/studio/upload')).toBe(true)
  })

  it('shows a problem message when loading fails', async () => {
    server.use(http.get(`${API}/v1/videos`, () => problem(500, 'Server error')))
    at('/')
    expect(await screen.findByRole('alert')).toHaveTextContent('Server error')
  })
})

describe('category', () => {
  it('is titled with the category name and filters by slug', async () => {
    let seen: string | null = null
    server.use(
      http.get(`${API}/v1/videos`, ({ request }) => {
        seen = new URL(request.url).searchParams.get('category')
        return HttpResponse.json({ items: [video(1)], next_cursor: null, has_more: false })
      }),
    )
    at('/categories/music')
    expect(await screen.findByRole('heading', { name: 'Music' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'Video 1' })).toBeInTheDocument()
    expect(seen).toBe('music')
  })

  it('says so for an unknown category', async () => {
    at('/categories/nope')
    expect(await screen.findByText("There's no category called nope")).toBeInTheDocument()
  })
})

describe('profile', () => {
  it('shows the creator and their videos', async () => {
    server.use(
      http.get(`${API}/v1/users/mona.haddad`, () =>
        HttpResponse.json({ handle: 'mona.haddad', display_name: 'Mona Haddad', bio: 'Makes things.', created_at: '2026-03-12T10:00:00Z' }),
      ),
      http.get(`${API}/v1/users/mona.haddad/videos`, () =>
        HttpResponse.json({ items: [video(1)], next_cursor: null, has_more: false }),
      ),
    )
    at('/@mona.haddad')
    expect(await screen.findByRole('heading', { name: 'Mona Haddad' })).toBeInTheDocument()
    expect(screen.getByText('@mona.haddad')).toBeInTheDocument()
    expect(screen.getByText('Makes things.')).toBeInTheDocument()
    expect(screen.getByText('Joined March 2026')).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'Video 1' })).toBeInTheDocument()
  })

  it('shows a message for an unknown handle', async () => {
    server.use(
      http.get(`${API}/v1/users/x`, () => problem(404, 'User not found')),
      http.get(`${API}/v1/users/x/videos`, () => problem(404, 'User not found')),
    )
    at('/@x')
    expect(await screen.findByText("There's no one called @x here")).toBeInTheDocument()
  })
})

describe('VideoTile', () => {
  it('links the creator to their profile and builds a srcset', () => {
    const router = createMemoryRouter([{ path: '/', element: <VideoTile video={video(1)} size="grid" /> }])
    render(<RouterProvider router={router} />)
    expect(screen.getByRole('link', { name: 'Mona Haddad' })).toHaveAttribute('href', '/@mona.haddad')
    expect(screen.getByRole('link', { name: 'Video 1' })).toHaveAttribute('href', '/watch/v1')
    expect(screen.getByText('1:05')).toBeInTheDocument()
    const img = document.querySelector('img')!
    expect(img).toHaveAttribute('srcset', 'http://img/1-320.webp 320w, http://img/1-640.webp 640w')
    expect(img).toHaveAttribute('alt', '')
    expect(img).toHaveAttribute('loading', 'lazy')
  })

  it('falls back to the block when the image fails, keeping the duration', () => {
    const router = createMemoryRouter([{ path: '/', element: <VideoTile video={video(1)} size="grid" /> }])
    render(<RouterProvider router={router} />)
    fireEvent.error(document.querySelector('img')!)
    expect(document.querySelector('img')).toBeNull()
    expect(screen.getByText('1:05')).toBeInTheDocument()
  })

  it('renders the title as h2 and exposes the duration to screen readers', () => {
    const router = createMemoryRouter([{ path: '/', element: <VideoTile video={video(1)} size="grid" /> }])
    render(<RouterProvider router={router} />)
    expect(screen.getByRole('heading', { level: 2, name: 'Video 1' })).toBeInTheDocument()
    expect(screen.getByText(/Duration 1:05/)).toBeInTheDocument()
  })

  it('falls back to a block with no thumbnails', () => {
    const router = createMemoryRouter([
      { path: '/', element: <VideoTile video={{ ...video(1), thumbnails: [] }} size="feature" /> },
    ])
    render(<RouterProvider router={router} />)
    expect(document.querySelector('img')).toBeNull()
  })
})
