import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { API, server } from '../test/msw'
import userEvent from '@testing-library/user-event'
import { SessionProvider, useSession } from './SessionProvider'
import { session } from './session'

function Probe() {
  const { status, me, signIn, signOut } = useSession()
  return (
    <>
      <p>
        {status} {me?.handle ?? 'nobody'}
      </p>
      <button onClick={() => void signIn('a@b.c', 'pw')}>in</button>
      <button onClick={() => void signOut()}>out</button>
    </>
  )
}

const mount = () =>
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SessionProvider>
        <Probe />
      </SessionProvider>
    </QueryClientProvider>,
  )

describe('SessionProvider', () => {
  it('refreshes then loads /me on mount', async () => {
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => HttpResponse.json({ access_token: 'tok', token_type: 'Bearer', expires_in: 900 })),
      http.get(`${API}/v1/me`, ({ request }) =>
        request.headers.get('Authorization') === 'Bearer tok'
          ? HttpResponse.json({ id: '1', handle: 'mona', display_name: 'Mona' })
          : new HttpResponse(null, { status: 401 }),
      ),
    )
    mount()
    expect(screen.getByText('loading nobody')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('signed-in mona')).toBeInTheDocument())
  })

  it('is signed out when refresh returns 401', async () => {
    server.use(http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })))
    mount()
    await waitFor(() => expect(screen.getByText('signed-out nobody')).toBeInTheDocument())
  })

  it('signIn ends signed-in with me', async () => {
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })),
      http.post(`${API}/v1/auth/login`, () =>
        HttpResponse.json({ access_token: 'tok', token_type: 'Bearer', expires_in: 900, user: {} }),
      ),
      http.get(`${API}/v1/me`, () => HttpResponse.json({ id: '1', handle: 'mona', display_name: 'Mona' })),
    )
    mount()
    await waitFor(() => expect(screen.getByText('signed-out nobody')).toBeInTheDocument())
    await userEvent.click(screen.getByRole('button', { name: 'in' }))
    await waitFor(() => expect(screen.getByText('signed-in mona')).toBeInTheDocument())
    expect(session.getToken()).toBe('tok')
  })

  it('signOut calls logout once and ends signed-out with no token', async () => {
    let logouts = 0
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => HttpResponse.json({ access_token: 'tok', token_type: 'Bearer', expires_in: 900 })),
      http.get(`${API}/v1/me`, () => HttpResponse.json({ id: '1', handle: 'mona', display_name: 'Mona' })),
      http.post(`${API}/v1/auth/logout`, () => {
        logouts++
        return new HttpResponse(null, { status: 204 })
      }),
    )
    mount()
    await waitFor(() => expect(screen.getByText('signed-in mona')).toBeInTheDocument())
    await userEvent.click(screen.getByRole('button', { name: 'out' }))
    await waitFor(() => expect(screen.getByText('signed-out nobody')).toBeInTheDocument())
    expect(session.getToken()).toBeNull()
    expect(logouts).toBe(1)
  })
})
