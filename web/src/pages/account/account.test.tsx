import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { routes } from '../../router'
import { SessionProvider } from '../../auth/SessionProvider'
import { API, server } from '../../test/msw'

function at(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SessionProvider>
        <RouterProvider router={router} />
      </SessionProvider>
    </QueryClientProvider>,
  )
  return router
}

const problem = (status: number, body: Record<string, unknown>) =>
  HttpResponse.json({ type: 'about:blank', status, ...body }, { status, headers: { 'Content-Type': 'application/problem+json' } })

const user = {
  id: '1',
  email: 'mona@example.com',
  email_verified: true,
  handle: 'mona',
  display_name: 'Mona',
  bio: null,
  status: 'active',
  created_at: null,
}

const signedOut = () => server.use(http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })))
const signedIn = () =>
  server.use(
    http.post(`${API}/v1/auth/refresh`, () => HttpResponse.json({ access_token: 'tok', token_type: 'Bearer', expires_in: 900 })),
    http.get(`${API}/v1/me`, () => HttpResponse.json(user)),
  )

describe('sign up', () => {
  beforeEach(signedOut)

  it('shows a field error under the password and focuses the first invalid field', async () => {
    server.use(
      http.post(`${API}/v1/auth/register`, () =>
        problem(422, {
          title: 'Validation failed',
          code: 'VALIDATION_FAILED',
          errors: [{ field: 'password', code: 'MIN', message: 'The password must be at least 10 characters.' }],
        }),
      ),
    )
    at('/sign-up')
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('Display name'), 'Mona')
    await u.type(screen.getByLabelText('Email'), 'mona@example.com')
    await u.type(screen.getByLabelText('Password'), 'short')
    await u.click(screen.getByRole('button', { name: 'Create account' }))

    const password = await screen.findByLabelText('Password')
    await waitFor(() => expect(password).toHaveAttribute('aria-invalid', 'true'))
    const message = screen.getByText('The password must be at least 10 characters.')
    expect(password.getAttribute('aria-describedby')).toContain(message.id)
    await waitFor(() => expect(password).toHaveFocus())
  })

  it('shows the inbox message after a 202', async () => {
    server.use(http.post(`${API}/v1/auth/register`, () => HttpResponse.json({ message: 'ok' }, { status: 202 })))
    at('/sign-up')
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('Display name'), 'Mona')
    await u.type(screen.getByLabelText('Email'), 'mona@example.com')
    await u.type(screen.getByLabelText('Password'), 'long enough password')
    await u.click(screen.getByRole('button', { name: 'Create account' }))
    expect(await screen.findByText('Check your inbox to verify your email')).toBeInTheDocument()
  })
})

describe('verify email', () => {
  beforeEach(signedOut)

  it('posts the token from the query string', async () => {
    let body: unknown
    server.use(
      http.post(`${API}/v1/auth/email/verify`, async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({})
      }),
    )
    at('/verify-email?token=abc')
    expect(await screen.findByText('Email verified')).toBeInTheDocument()
    expect(body).toEqual({ token: 'abc' })
    expect(within(screen.getByRole('main')).getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/sign-in')
  })

  it('shows the resend form when the token has expired', async () => {
    let resent: unknown
    server.use(
      http.post(`${API}/v1/auth/email/verify`, () =>
        problem(422, { title: 'This verification link is invalid or has expired', code: 'INVALID_VERIFICATION_TOKEN' }),
      ),
      http.post(`${API}/v1/auth/email/resend`, async ({ request }) => {
        resent = await request.json()
        return HttpResponse.json({ message: 'ok' }, { status: 202 })
      }),
    )
    at('/verify-email?token=old')
    expect(await screen.findByText('This link has expired. Send a new one')).toBeInTheDocument()
    const u = userEvent.setup()
    await u.type(screen.getByLabelText('Email'), 'mona@example.com')
    await u.click(screen.getByRole('button', { name: 'Send a new link' }))
    expect(await screen.findByText(/we've sent a new link/)).toBeInTheDocument()
    expect(resent).toEqual({ email: 'mona@example.com' })
  })
})

describe('sign in', () => {
  beforeEach(() => {
    signedOut()
    server.use(
      http.post(`${API}/v1/auth/login`, () =>
        HttpResponse.json({ access_token: 'tok', token_type: 'Bearer', expires_in: 900, user: {} }),
      ),
      http.get(`${API}/v1/me`, () => HttpResponse.json(user)),
    )
  })

  async function submit() {
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('Email'), 'mona@example.com')
    await u.type(screen.getByLabelText('Password'), 'long enough password')
    await u.click(screen.getByRole('button', { name: 'Sign in' }))
  }

  it('goes to the next path after signing in', async () => {
    const router = at('/sign-in?next=/categories/jazz')
    await submit()
    await waitFor(() => expect(router.state.location.pathname).toBe('/categories/jazz'))
  })

  it('goes to the studio by default', async () => {
    const router = at('/sign-in')
    await submit()
    await waitFor(() => expect(router.state.location.pathname).toBe('/studio'))
  })

  it.each(['//evil.com', '/\\evil.com', '/%09/evil.com', '/\t/evil.com', 'https://evil.com', '/a\nb'])(
    'ignores the unsafe next value %j',
    async (next) => {
      const router = at(`/sign-in?next=${encodeURIComponent(next)}`)
      await submit()
      await waitFor(() => expect(router.state.location.pathname).toBe('/studio'))
    },
  )

  it('keeps a safe next value with its query', async () => {
    const router = at('/sign-in?next=' + encodeURIComponent('/watch/abc?t=1'))
    await submit()
    await waitFor(() => expect(router.state.location.pathname).toBe('/watch/abc'))
    expect(router.state.location.search).toBe('?t=1')
  })

  it('shows wrong credentials as a form message', async () => {
    server.use(http.post(`${API}/v1/auth/login`, () => problem(401, { title: 'Invalid email or password', code: 'INVALID_CREDENTIALS' })))
    at('/sign-in')
    await submit()
    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid email or password')
  })
})

describe('settings', () => {
  it('redirects to sign in when signed out', async () => {
    signedOut()
    const router = at('/settings')
    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(router.state.location.search).toBe('?next=/settings')
  })

  it('shows a taken handle on the handle field and sends only the changed fields', async () => {
    signedIn()
    let body: unknown
    server.use(
      http.get(`${API}/v1/auth/sessions`, () => HttpResponse.json({ items: [] })),
      http.patch(`${API}/v1/me`, async ({ request }) => {
        body = await request.json()
        return problem(422, {
          title: 'Validation failed',
          code: 'VALIDATION_FAILED',
          errors: [{ field: 'handle', code: 'UNIQUE_HANDLE', message: 'This handle is already taken.' }],
        })
      }),
    )
    at('/settings')
    const u = userEvent.setup()
    const handle = await screen.findByLabelText('Handle')
    expect(handle).toHaveValue('mona')
    await u.clear(handle)
    await u.type(handle, 'taken')
    await u.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('This handle is already taken.')).toBeInTheDocument()
    expect(handle).toHaveAttribute('aria-invalid', 'true')
    expect(body).toEqual({ handle: 'taken' })
  })

  it('lists sessions, marks the current one and signs another out', async () => {
    signedIn()
    let items = [
      { id: 's1', user_agent: 'Firefox on Linux', ip_address: '10.0.0.1', created_at: '2026-11-01T10:00:00Z', last_used_at: '2026-11-05T10:00:00Z', current: true },
      { id: 's2', user_agent: 'Safari on iPhone', ip_address: '10.0.0.2', created_at: '2026-11-01T10:00:00Z', last_used_at: '2026-11-04T10:00:00Z', current: false },
    ]
    server.use(
      http.get(`${API}/v1/auth/sessions`, () => HttpResponse.json({ items })),
      http.delete(`${API}/v1/auth/sessions/s2`, () => {
        items = items.filter((s) => s.id !== 's2')
        return new HttpResponse(null, { status: 204 })
      }),
    )
    at('/settings')
    expect(await screen.findByText('Firefox on Linux')).toBeInTheDocument()
    expect(screen.getByText('This device')).toBeInTheDocument()
    const u = userEvent.setup()
    await u.click(screen.getByRole('button', { name: 'Sign out Safari on iPhone' }))
    await waitFor(() => expect(screen.queryByText('Safari on iPhone')).not.toBeInTheDocument())
  })

  it('signs out everywhere and goes home', async () => {
    signedIn()
    server.use(
      http.get(`${API}/v1/auth/sessions`, () => HttpResponse.json({ items: [] })),
      http.post(`${API}/v1/auth/logout-all`, () => new HttpResponse(null, { status: 204 })),
    )
    const router = at('/settings')
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: 'Sign out everywhere' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })
})

describe('forgot and reset password', () => {
  beforeEach(signedOut)

  it('shows an alert for a 422 on a field the form does not show', async () => {
    server.use(
      http.post(`${API}/v1/auth/password/forgot`, () =>
        problem(422, {
          title: 'Validation failed',
          code: 'VALIDATION_FAILED',
          errors: [{ field: 'surprise', code: 'X', message: 'Something unexpected is wrong.' }],
        }),
      ),
    )
    at('/forgot-password')
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('Email'), 'a@example.com')
    await u.click(screen.getByRole('button', { name: 'Send reset link' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Something unexpected is wrong.')
  })

  it('shows a missing-token state without sending a request', async () => {
    at('/reset-password')
    expect(await screen.findByText('This link is missing its token. Request a new one')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Request a new one|new link/ })).toHaveAttribute('href', '/forgot-password')
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it('shows a neutral message after requesting a reset link', async () => {
    server.use(http.post(`${API}/v1/auth/password/forgot`, () => HttpResponse.json({ message: 'ok' }, { status: 202 })))
    at('/forgot-password')
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('Email'), 'nobody@example.com')
    await u.click(screen.getByRole('button', { name: 'Send reset link' }))
    expect(await screen.findByText(/If that email has an account, we've sent a reset link/)).toBeInTheDocument()
  })

  it('posts the token and new password, then links to sign in', async () => {
    let body: unknown
    server.use(
      http.post(`${API}/v1/auth/password/reset`, async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({})
      }),
    )
    at('/reset-password?token=tok1')
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText('New password'), 'a brand new password')
    await u.click(screen.getByRole('button', { name: 'Set new password' }))
    expect(await screen.findByText('Password updated')).toBeInTheDocument()
    expect(body).toEqual({ token: 'tok1', password: 'a brand new password' })
    expect(within(screen.getByRole('main')).getByRole('link', { name: 'Sign in' })).toBeInTheDocument()
  })
})
