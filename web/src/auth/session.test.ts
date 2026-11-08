import { http, HttpResponse } from 'msw'
import { API, server } from '../test/msw'
import { session } from './session'

const ok = (t: string) => HttpResponse.json({ access_token: t, token_type: 'Bearer', expires_in: 900 })

describe('session', () => {
  it('stores the token in memory and notifies on setToken', () => {
    const heard: (string | null)[] = []
    session.subscribe((t) => heard.push(t))
    session.setToken('a')
    expect(session.getToken()).toBe('a')
    expect(heard).toEqual(['a'])
    expect(localStorage.length).toBe(0)
  })

  it('stops notifying after unsubscribe', () => {
    const heard: (string | null)[] = []
    const off = session.subscribe((t) => heard.push(t))
    session.setToken('a')
    off()
    session.setToken('b')
    expect(heard).toEqual(['a'])
  })

  it('shares one in-flight refresh and starts a new one after it settles', async () => {
    let calls = 0
    server.use(
      http.post(`${API}/v1/auth/refresh`, async () => {
        calls++
        await new Promise((r) => setTimeout(r, 20))
        return ok(`t${calls}`)
      }),
    )
    const [a, b] = await Promise.all([session.refresh(), session.refresh()])
    expect([a, b]).toEqual(['t1', 't1'])
    expect(calls).toBe(1)
    expect(await session.refresh()).toBe('t2')
    expect(calls).toBe(2)
  })

  it('resolves null and clears the token when refresh fails', async () => {
    session.setToken('old')
    server.use(http.post(`${API}/v1/auth/refresh`, () => new HttpResponse(null, { status: 401 })))
    expect(await session.refresh()).toBeNull()
    expect(session.getToken()).toBeNull()
  })

  it('resolves null on a network failure', async () => {
    server.use(http.post(`${API}/v1/auth/refresh`, () => HttpResponse.error()))
    expect(await session.refresh()).toBeNull()
  })

  it('discards a refresh that resolves after the token was cleared', async () => {
    server.use(
      http.post(`${API}/v1/auth/refresh`, async () => {
        await new Promise((r) => setTimeout(r, 30))
        return ok('revived')
      }),
    )
    const heard: (string | null)[] = []
    session.subscribe((t) => heard.push(t))
    const pending = session.refresh()
    session.setToken(null)
    expect(await pending).toBeNull()
    expect(session.getToken()).toBeNull()
    expect(heard).toEqual([null])
  })
})
