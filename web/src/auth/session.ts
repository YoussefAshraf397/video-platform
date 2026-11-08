export const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

type Listener = (token: string | null) => void

// The access token lives in memory only. The refresh token is an httpOnly cookie the browser manages.
let token: string | null = null
// Bumped whenever the token is cleared, so a refresh started before sign-out can't revive the session.
let epoch = 0
let inflight: Promise<string | null> | null = null
const listeners = new Set<Listener>()

function notify() {
  for (const l of [...listeners]) l(token)
}

function setToken(t: string | null) {
  if (t === null) epoch++
  token = t
  notify()
}

async function doRefresh(): Promise<string | null> {
  const started = epoch
  try {
    // Plain fetch, not the api client: the client retries 401s by calling refresh().
    const res = await fetch(`${API_URL}/v1/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    if (!res.ok) {
      if (epoch === started) setToken(null)
      return null
    }
    const body = (await res.json()) as { access_token?: unknown }
    if (epoch !== started) return null
    if (typeof body.access_token !== 'string') {
      setToken(null)
      return null
    }
    setToken(body.access_token)
    return body.access_token
  } catch {
    if (epoch === started) setToken(null)
    return null
  }
}

export const session = {
  getToken: () => token,
  setToken,
  /** Single-flight: concurrent callers share one request. Resolves null when signed out; never throws. */
  refresh(): Promise<string | null> {
    inflight ??= doRefresh().finally(() => {
      inflight = null
    })
    return inflight
  },
  subscribe(listener: Listener): () => void {
    listeners.add(listener)
    return () => {
      listeners.delete(listener)
    }
  },
}

/** Test helper: drops the token, listeners and any in-flight refresh. */
export function resetSession() {
  token = null
  epoch++
  inflight = null
  listeners.clear()
}
