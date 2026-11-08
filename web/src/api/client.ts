import { API_URL, session } from '../auth/session'

export type ApiFieldError = { field: string; code: string; message: string }

export class ApiError extends Error {
  status: number
  code: string | null
  title: string
  detail: string | null
  errors: ApiFieldError[]

  constructor(init: { status: number; code: string | null; title: string; detail: string | null; errors: ApiFieldError[] }) {
    super(init.detail ?? init.title)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.title = init.title
    this.detail = init.detail
    this.errors = init.errors
  }
}

export type ApiOptions = {
  body?: unknown
  headers?: Record<string, string>
  idempotencyKey?: string
  ifMatch?: string
  /** Set false to send no Authorization header and skip the 401 refresh and retry. */
  auth?: boolean
}

async function toError(res: Response): Promise<ApiError> {
  const type = res.headers.get('Content-Type') ?? ''
  if (type.includes('application/problem+json')) {
    try {
      const p = await res.json()
      return new ApiError({
        status: res.status,
        code: typeof p.code === 'string' ? p.code : null,
        title: typeof p.title === 'string' ? p.title : res.statusText || 'Request failed',
        detail: typeof p.detail === 'string' ? p.detail : null,
        errors: Array.isArray(p.errors) ? p.errors : [],
      })
    } catch {
      // fall through to the generic error
    }
  }
  return new ApiError({ status: res.status, code: null, title: res.statusText || 'Request failed', detail: null, errors: [] })
}

function send(method: string, path: string, o: ApiOptions): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json', ...o.headers }
  if (o.body !== undefined) headers['Content-Type'] = 'application/json'
  if (o.idempotencyKey) headers['Idempotency-Key'] = o.idempotencyKey
  if (o.ifMatch) headers['If-Match'] = o.ifMatch
  const token = session.getToken()
  if (token && o.auth !== false) headers.Authorization = `Bearer ${token}`
  return fetch(`${API_URL}${path}`, {
    method,
    headers,
    credentials: 'include',
    body: o.body !== undefined ? JSON.stringify(o.body) : undefined,
  })
}

export async function api<T = unknown>(
  method: string,
  path: string,
  options: ApiOptions = {},
): Promise<{ data: T; headers: Headers }> {
  const sentWith = session.getToken()
  let res = await send(method, path, options)
  if (res.status === 401 && options.auth !== false) {
    const current = session.getToken()
    // Token changed while in flight: retry with it instead of refreshing again.
    // Token cleared while in flight (signed out): don't refresh, or the session comes back.
    const signedOutMeanwhile = sentWith !== null && current === null
    if (
      (current !== null && current !== sentWith) ||
      (!signedOutMeanwhile && (await session.refresh()))
    ) {
      res = await send(method, path, options)
    }
  }
  if (!res.ok) throw await toError(res)
  if (res.status === 204) return { data: undefined as T, headers: res.headers }
  return { data: (await res.json()) as T, headers: res.headers }
}
