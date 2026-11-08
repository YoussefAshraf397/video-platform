import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { routes } from './router'
import { SessionProvider } from './auth/SessionProvider'
import { API, server } from './test/msw'

function at(path: string) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SessionProvider>
        <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
      </SessionProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  server.use(http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })))
})

describe('routes', () => {
  it('renders the profile for /@handle, including dots', async () => {
    server.use(
      http.get(`${API}/v1/users/mona.makes`, () =>
        HttpResponse.json({ handle: 'mona.makes', display_name: 'Mona Makes', bio: null, created_at: null }),
      ),
      http.get(`${API}/v1/users/mona.makes/videos`, () => HttpResponse.json({ items: [], next_cursor: null, has_more: false })),
    )
    at('/@mona.makes')
    expect(await screen.findByText('@mona.makes')).toBeInTheDocument()
  })

  it('shows Not found for a bare segment', () => {
    at('/mona')
    expect(screen.getByRole('heading', { name: 'Not found' })).toBeInTheDocument()
  })

  it('shows Not found for an empty handle', () => {
    at('/@')
    expect(screen.getByRole('heading', { name: 'Not found' })).toBeInTheDocument()
  })

  it('renders /verify-email?token=', async () => {
    server.use(http.post(`${API}/v1/auth/email/verify`, () => HttpResponse.json({})))
    at('/verify-email?token=abc')
    expect(screen.getByRole('heading', { name: 'Verify email' })).toBeInTheDocument()
    expect(await screen.findByText('Email verified')).toBeInTheDocument()
  })

  it('renders /reset-password?token=', () => {
    at('/reset-password?token=abc')
    expect(screen.getByRole('heading', { name: 'Reset password' })).toBeInTheDocument()
  })
})
