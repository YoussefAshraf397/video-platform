import { http, HttpResponse } from 'msw'
import { API, server } from '../test/msw'
import { session } from '../auth/session'
import { api, ApiError } from './client'

const problem = (status: number, extra: object = {}) =>
  HttpResponse.json(
    { type: '/errors/x', title: 'Problem', status, code: 'SOME_CODE', ...extra },
    { status, headers: { 'Content-Type': 'application/problem+json' } },
  )

describe('api client', () => {
  it('turns a 422 problem body into an ApiError with field errors', async () => {
    server.use(
      http.post(`${API}/v1/videos`, () =>
        problem(422, {
          code: 'VALIDATION_FAILED',
          errors: [{ field: 'title', code: 'required', message: 'The title is required' }],
        }),
      ),
    )
    const err = await api('POST', '/v1/videos', { body: {} }).catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(422)
    expect(err.code).toBe('VALIDATION_FAILED')
    expect(err.errors[0].field).toBe('title')
  })

  it('builds an ApiError from a non-problem error body', async () => {
    server.use(http.get(`${API}/v1/x`, () => new HttpResponse('<html>', { status: 502, statusText: 'Bad Gateway' })))
    const err = await api('GET', '/v1/x').catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ status: 502, code: null, detail: null, errors: [] })
  })

  it('returns undefined data on 204 and sends credentials, auth and json', async () => {
    session.setToken('tok')
    let seen: Request | undefined
    server.use(
      http.post(`${API}/v1/things`, ({ request }) => {
        seen = request
        return new HttpResponse(null, { status: 204 })
      }),
    )
    const spy = vi.spyOn(globalThis, 'fetch')
    const res = await api('POST', '/v1/things', { body: { a: 1 } })
    expect(res.data).toBeUndefined()
    expect(spy.mock.calls[0][1]?.credentials).toBe('include')
    spy.mockRestore()
    expect(seen?.headers.get('Authorization')).toBe('Bearer tok')
    expect(seen?.headers.get('Content-Type')).toBe('application/json')
  })

  it('omits Authorization when auth is false', async () => {
    session.setToken('tok')
    let auth: string | null = 'unset'
    server.use(
      http.get(`${API}/v1/pub`, ({ request }) => {
        auth = request.headers.get('Authorization')
        return HttpResponse.json({ ok: true })
      }),
    )
    await api('GET', '/v1/pub', { auth: false })
    expect(auth).toBeNull()
  })

  it('maps idempotencyKey and ifMatch to headers', async () => {
    let seen: Request | undefined
    server.use(
      http.put(`${API}/v1/videos/1`, ({ request }) => {
        seen = request
        return HttpResponse.json({ ok: true })
      }),
    )
    await api('PUT', '/v1/videos/1', { body: {}, idempotencyKey: 'key-1', ifMatch: '"etag-1"' })
    expect(seen?.headers.get('Idempotency-Key')).toBe('key-1')
    expect(seen?.headers.get('If-Match')).toBe('"etag-1"')
  })

  it('refreshes once on 401 and retries with the new token', async () => {
    session.setToken('old')
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => HttpResponse.json({ access_token: 'new', token_type: 'Bearer', expires_in: 900 })),
      http.get(`${API}/v1/me`, ({ request }) =>
        request.headers.get('Authorization') === 'Bearer new' ? HttpResponse.json({ handle: 'mona' }) : problem(401),
      ),
    )
    const res = await api<{ handle: string }>('GET', '/v1/me')
    expect(res.data.handle).toBe('mona')
    expect(session.getToken()).toBe('new')
  })

  it('runs exactly one refresh for three concurrent 401s', async () => {
    session.setToken('old')
    let refreshes = 0
    server.use(
      http.post(`${API}/v1/auth/refresh`, async () => {
        refreshes++
        await new Promise((r) => setTimeout(r, 30))
        return HttpResponse.json({ access_token: 'new', token_type: 'Bearer', expires_in: 900 })
      }),
      http.get(`${API}/v1/q/:n`, ({ request, params }) =>
        request.headers.get('Authorization') === 'Bearer new' ? HttpResponse.json({ n: params.n }) : problem(401),
      ),
    )
    const results = await Promise.all([1, 2, 3].map((n) => api<{ n: string }>('GET', `/v1/q/${n}`)))
    expect(results.map((r) => r.data.n)).toEqual(['1', '2', '3'])
    expect(refreshes).toBe(1)
  })

  it('signs out when refresh fails and rejects with the 401', async () => {
    session.setToken('old')
    const heard: (string | null)[] = []
    session.subscribe((t) => heard.push(t))
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => problem(401)),
      http.get(`${API}/v1/me`, () => problem(401)),
    )
    const err = await api('GET', '/v1/me').catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(401)
    expect(session.getToken()).toBeNull()
    expect(heard).toEqual([null])
  })

  it('retries with the current token, without refreshing, when the token changed during a late 401', async () => {
    session.setToken('A')
    let refreshes = 0
    const used: (string | null)[] = []
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => {
        refreshes++
        return HttpResponse.json({ access_token: 'C', token_type: 'Bearer', expires_in: 900 })
      }),
      http.get(`${API}/v1/late`, async ({ request }) => {
        const auth = request.headers.get('Authorization')
        used.push(auth)
        if (auth === 'Bearer A') {
          await new Promise((r) => setTimeout(r, 30))
          return problem(401)
        }
        return HttpResponse.json({ ok: true })
      }),
    )
    const pending = api('GET', '/v1/late')
    await new Promise((r) => setTimeout(r, 5))
    session.setToken('B')
    await pending
    expect(used).toEqual(['Bearer A', 'Bearer B'])
    expect(refreshes).toBe(0)
  })

  it('does not refresh a late 401 when the session was signed out meanwhile', async () => {
    session.setToken('A')
    let refreshes = 0
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => {
        refreshes++
        return HttpResponse.json({ access_token: 'revived', token_type: 'Bearer', expires_in: 900 })
      }),
      http.get(`${API}/v1/late`, async () => {
        await new Promise((r) => setTimeout(r, 30))
        return problem(401)
      }),
    )
    const pending = api('GET', '/v1/late').catch((e: unknown) => e)
    await new Promise((r) => setTimeout(r, 5))
    session.setToken(null)
    const err = (await pending) as ApiError
    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(401)
    expect(refreshes).toBe(0)
    expect(session.getToken()).toBeNull()
  })

  it('does not retry a 401 more than once', async () => {
    let calls = 0
    server.use(
      http.post(`${API}/v1/auth/refresh`, () => HttpResponse.json({ access_token: 'new', token_type: 'Bearer', expires_in: 900 })),
      http.get(`${API}/v1/me`, () => {
        calls++
        return problem(401)
      }),
    )
    const err = await api('GET', '/v1/me').catch((e) => e)
    expect(err.status).toBe(401)
    expect(calls).toBe(2)
  })

  it('lets network failures propagate as TypeError', async () => {
    server.use(http.get(`${API}/v1/net`, () => HttpResponse.error()))
    await expect(api('GET', '/v1/net')).rejects.toBeInstanceOf(TypeError)
  })
})
