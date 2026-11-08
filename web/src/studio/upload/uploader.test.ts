import { http, HttpResponse } from 'msw'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { API, server } from '../../test/msw'
import { fakeS3, installFakeXhr } from '../../test/fakeXhr'
import { ApiError } from '../../api/client'
import { FileMismatchError, PartUploadError, SignError, Upload, UploadAbortedError } from './uploader'
import { store, type SavedUpload } from './store'

const PART = 10
const problem = (status: number, code: string, detail?: string) =>
  HttpResponse.json(
    { type: '/errors/x', title: code, status, code, detail },
    { status, headers: { 'Content-Type': 'application/problem+json' } },
  )

const farFuture = () => new Date(Date.now() + 30 * 60_000).toISOString()
const partEntry = (n: number, size: number, expires = farFuture()) => ({
  part_number: n,
  size_bytes: Math.min(PART, size - (n - 1) * PART),
  url: `https://s3.test/bucket/key?partNumber=${n}&sig=x`,
  expires_at: expires,
})

const makeFile = (size: number, name = 'clip.mp4') => new File([new Uint8Array(size)], name, { type: 'video/mp4', lastModified: 1000 })

type Opts = { size: number; uploadedParts?: number[]; firstExpires?: string }
function mockApi(o: Opts) {
  const total = Math.ceil(o.size / PART)
  const calls = { sign: [] as number[][], complete: [] as { key: string | null; parts: unknown }[], deleted: 0, started: 0, get: 0 }
  const session = (next: number[]) => ({
    id: 'up-1',
    video_id: 'vid1',
    status: 'in_progress',
    size_bytes: o.size,
    content_type: 'video/mp4',
    part_size_bytes: PART,
    total_parts: total,
    expires_at: farFuture(),
    uploaded_parts: (o.uploadedParts ?? []).map((n) => ({ part_number: n, size_bytes: PART, etag: `"s3-${n}"` })),
    next_parts: next.map((n) => partEntry(n, o.size, o.firstExpires)),
  })
  const missingNext = () =>
    Array.from({ length: total }, (_, i) => i + 1).filter((n) => !(o.uploadedParts ?? []).includes(n)).slice(0, 20)
  server.use(
    http.post(`${API}/v1/videos/:id/uploads`, () => {
      calls.started++
      return HttpResponse.json(session(Array.from({ length: Math.min(total, 20) }, (_, i) => i + 1)), { status: 201 })
    }),
    http.get(`${API}/v1/uploads/:id`, () => {
      calls.get++
      return HttpResponse.json(session(missingNext()))
    }),
    http.post(`${API}/v1/uploads/:id/parts\\:sign`, async ({ request }) => {
      const { part_numbers } = (await request.json()) as { part_numbers: number[] }
      calls.sign.push(part_numbers)
      return HttpResponse.json({ parts: part_numbers.map((n) => partEntry(n, o.size)) })
    }),
    http.post(`${API}/v1/uploads/:id\\:complete`, async ({ request }) => {
      calls.complete.push({ key: request.headers.get('Idempotency-Key'), parts: (await request.json() as { parts: unknown }).parts })
      return HttpResponse.json({ id: 'up-1', status: 'completed', video_status: 'uploaded' })
    }),
    http.delete(`${API}/v1/uploads/:id`, () => {
      calls.deleted++
      return new HttpResponse(null, { status: 204 })
    }),
  )
  return calls
}

const opts = (extra = {}) => ({ concurrency: 2, sleep: vi.fn(async () => {}), random: () => 0.5, ...extra })

beforeEach(() => {
  installFakeXhr()
  store.clear()
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('Upload', () => {
  it('uploads every part and completes with all ETags', async () => {
    const calls = mockApi({ size: 25 })
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await expect(up.run()).resolves.toBe('completed')
    expect(fakeS3.puts.map((p) => p.partNumber).sort()).toEqual([1, 2, 3])
    expect(fakeS3.puts.find((p) => p.partNumber === 3)!.size).toBe(5)
    expect(calls.complete).toHaveLength(1)
    expect(calls.complete[0].parts).toEqual([
      { part_number: 1, etag: '"etag-1"' },
      { part_number: 2, etag: '"etag-2"' },
      { part_number: 3, etag: '"etag-3"' },
    ])
    expect(calls.complete[0].key).toBeTruthy()
    expect(store.get('vid1')).toBeNull()
  })

  it('persists ETags as they arrive', async () => {
    mockApi({ size: 25 })
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts({ concurrency: 1 }))
    fakeS3.handler = (r) => (r.partNumber === 2 ? { status: 400 } : { status: 200, etag: `"etag-${r.partNumber}"` })
    await expect(up.run()).rejects.toThrow()
    expect(store.get('vid1')?.etags).toEqual({ 1: '"etag-1"' })
  })

  it('never exceeds the configured concurrency', async () => {
    mockApi({ size: 80 })
    const up = await Upload.start('vid1', makeFile(80))
    Object.assign(up.options, opts({ concurrency: 3 }))
    await up.run()
    expect(fakeS3.puts).toHaveLength(8)
    expect(fakeS3.maxInFlight).toBe(3)
  })

  it('defaults to 2 on a slow connection and 4 otherwise', async () => {
    mockApi({ size: 80 })
    vi.stubGlobal('navigator', { connection: { effectiveType: '3g' } })
    let up = await Upload.start('vid1', makeFile(80))
    Object.assign(up.options, { sleep: async () => {} })
    await up.run()
    expect(fakeS3.maxInFlight).toBe(2)
    vi.stubGlobal('navigator', { connection: { effectiveType: '4g' } })
    fakeS3.reset()
    up = await Upload.start('vid1', makeFile(80))
    await up.run()
    expect(fakeS3.maxInFlight).toBe(4)
  })

  it('re-signs a part after a 403 and retries it', async () => {
    const calls = mockApi({ size: 25 })
    fakeS3.handler = (r) =>
      r.partNumber === 2 && r.attempt === 1 ? { status: 403 } : { status: 200, etag: `"etag-${r.partNumber}"` }
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await up.run()
    expect(calls.sign).toEqual([[2]])
    expect(fakeS3.puts.filter((p) => p.partNumber === 2)).toHaveLength(2)
  })

  it('refreshes a URL with under 2 minutes left before using it', async () => {
    const calls = mockApi({ size: 10, firstExpires: new Date(Date.now() + 60_000).toISOString() })
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    await up.run()
    expect(calls.sign).toEqual([[1]])
  })

  it('retries 503s with jittered backoff', async () => {
    mockApi({ size: 10 })
    fakeS3.handler = (r) => (r.attempt <= 2 ? { status: 503 } : { status: 200, etag: '"ok"' })
    const o = opts({ random: () => 0.5 })
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    await up.run()
    expect(fakeS3.puts).toHaveLength(3)
    expect(o.sleep.mock.calls.map((c) => (c as unknown[])[0])).toEqual([500, 1000])
  })

  it('retries network errors, and gives up after 8 attempts', async () => {
    mockApi({ size: 10 })
    fakeS3.handler = () => 'network'
    const o = opts({ random: () => 1 })
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    await expect(up.run()).rejects.toThrow()
    expect(fakeS3.puts).toHaveLength(8)
    const waits = o.sleep.mock.calls.map((c) => (c as unknown[])[0])
    expect(waits).toEqual([1000, 2000, 4000, 8000, 16000, 30000, 30000])
  })

  it('does not retry other 4xx responses', async () => {
    mockApi({ size: 10 })
    fakeS3.handler = () => ({ status: 400 })
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    await expect(up.run()).rejects.toThrow()
    expect(fakeS3.puts).toHaveLength(1)
  })

  it('stops with the status of the upload when S3 says NoSuchUpload', async () => {
    mockApi({ size: 10 })
    fakeS3.handler = () => ({ status: 404 })
    server.use(http.get(`${API}/v1/uploads/:id`, () => problem(404, 'NOT_FOUND')))
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    const err = await up.run().catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(404)
  })

  it('resumes from the server: takes its ETags and only sends the missing part', async () => {
    const calls = mockApi({ size: 25, uploadedParts: [1, 2] })
    const saved: SavedUpload = {
      uploadId: 'up-1',
      videoId: 'vid1',
      file: { name: 'clip.mp4', size: 25, lastModified: 1000 },
      etags: { 1: '"stale-1"' },
      completeKey: 'ck-1',
    }
    const up = await Upload.resume(saved, makeFile(25))
    Object.assign(up.options, opts())
    await up.run()
    expect(fakeS3.puts.map((p) => p.partNumber)).toEqual([3])
    expect(calls.complete[0].key).toBe('ck-1')
    expect(calls.complete[0].parts).toEqual([
      { part_number: 1, etag: '"s3-1"' },
      { part_number: 2, etag: '"s3-2"' },
      { part_number: 3, etag: '"etag-3"' },
    ])
  })

  it('rejects resuming with a different file', async () => {
    mockApi({ size: 25 })
    const saved: SavedUpload = {
      uploadId: 'up-1',
      videoId: 'vid1',
      file: { name: 'clip.mp4', size: 25, lastModified: 1000 },
      etags: {},
      completeKey: 'ck',
    }
    await expect(Upload.resume(saved, makeFile(26))).rejects.toBeInstanceOf(FileMismatchError)
    await expect(Upload.resume(saved, makeFile(25, 'other.mp4'))).rejects.toBeInstanceOf(FileMismatchError)
  })

  it('uploads parts named by 409 PARTS_MISSING and completes again', async () => {
    const calls = mockApi({ size: 25 })
    let n = 0
    server.use(
      http.post(`${API}/v1/uploads/:id\\:complete`, async ({ request }) => {
        calls.complete.push({ key: request.headers.get('Idempotency-Key'), parts: null })
        return ++n === 1
          ? problem(409, 'PARTS_MISSING', 'Missing parts: 2. Upload them and complete again.')
          : HttpResponse.json({ id: 'up-1', status: 'completed', video_status: 'uploaded' })
      }),
    )
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await up.run()
    expect(fakeS3.puts.filter((p) => p.partNumber === 2)).toHaveLength(2)
    expect(calls.complete).toHaveLength(2)
    expect(calls.complete[0].key).toBe(calls.complete[1].key)
  })

  it('waits 3 seconds on 409 UPLOAD_COMPLETING', async () => {
    mockApi({ size: 10 })
    let n = 0
    server.use(
      http.post(`${API}/v1/uploads/:id\\:complete`, () =>
        ++n === 1 ? problem(409, 'UPLOAD_COMPLETING') : HttpResponse.json({ id: 'up-1', status: 'completed' }),
      ),
    )
    const o = opts()
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    await up.run()
    expect(o.sleep).toHaveBeenCalledWith(3000)
  })

  it('rejects with an ApiError on 410 and 422 UPLOAD_FAILED', async () => {
    mockApi({ size: 10 })
    server.use(http.post(`${API}/v1/uploads/:id\\:complete`, () => problem(422, 'UPLOAD_FAILED')))
    let up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    let err = await up.run().catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.code).toBe('UPLOAD_FAILED')
    server.use(http.post(`${API}/v1/uploads/:id\\:complete`, () => problem(410, 'UPLOAD_EXPIRED')))
    up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    err = await up.run().catch((e) => e)
    expect(err.status).toBe(410)
  })

  it('resumes the named upload on 409 UPLOAD_IN_PROGRESS', async () => {
    const calls = mockApi({ size: 25, uploadedParts: [1] })
    server.use(
      http.post(`${API}/v1/videos/:id/uploads`, () =>
        problem(409, 'UPLOAD_IN_PROGRESS', 'Resume upload up-1 with GET /v1/uploads/up-1, or cancel it.'),
      ),
    )
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await up.run()
    expect(calls.get).toBeGreaterThan(0)
    expect(fakeS3.puts.map((p) => p.partNumber).sort()).toEqual([2, 3])
  })

  it('reports progress up to (size, size)', async () => {
    mockApi({ size: 25 })
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    const seen: Array<[number, number]> = []
    up.onProgress((s, t) => seen.push([s, t]))
    await up.run()
    expect(seen.at(-1)).toEqual([25, 25])
    expect(seen.every(([s, t]) => s <= t && t === 25)).toBe(true)
    const sent = seen.map(([s]) => s)
    expect(sent).toEqual([...sent].sort((a, b) => a - b))
  })

  it('pauses by aborting in-flight parts and continues from the saved ETags', async () => {
    mockApi({ size: 40 })
    fakeS3.delayMs = 20
    const up = await Upload.start('vid1', makeFile(40))
    Object.assign(up.options, opts({ concurrency: 2 }))
    let settled = false
    const done = up.run().then(() => (settled = true))
    await new Promise((r) => setTimeout(r, 5))
    up.pause()
    expect(fakeS3.inFlight).toBe(0)
    await new Promise((r) => setTimeout(r, 60))
    expect(settled).toBe(false)
    expect(fakeS3.puts).toHaveLength(2)
    up.resumeRun()
    await done
    expect(settled).toBe(true)
    expect(fakeS3.puts.filter((p) => p.partNumber === 1)).toHaveLength(2)
  })

  it('abort deletes the upload and forgets it', async () => {
    const calls = mockApi({ size: 25 })
    const up = await Upload.start('vid1', makeFile(25))
    expect(store.get('vid1')).not.toBeNull()
    await up.abort()
    expect(calls.deleted).toBe(1)
    expect(store.get('vid1')).toBeNull()
  })
})


const delay = (ms: number) => new Promise((r) => setTimeout(r, ms))
const completedBody = { id: 'up-1', status: 'completed', video_status: 'uploaded' }
const signHandler = (size: number, onCall?: (nums: number[]) => Promise<Response | undefined> | Response | undefined) =>
  http.post(`${API}/v1/uploads/:id/parts\\:sign`, async ({ request }) => {
    const { part_numbers } = (await request.json()) as { part_numbers: number[] }
    const early = await onCall?.(part_numbers)
    if (early) return early
    return HttpResponse.json({ parts: part_numbers.map((n) => partEntry(n, size)) })
  })
const soon = () => new Date(Date.now() + 60_000).toISOString()

describe('Upload fix round 1', () => {
  it('caps PARTS_MISSING at 5 rounds, then rejects with the last ApiError', async () => {
    const calls = mockApi({ size: 25 })
    server.use(
      http.post(`${API}/v1/uploads/:id\\:complete`, () => {
        calls.complete.push({ key: null, parts: null })
        return problem(409, 'PARTS_MISSING', 'Missing parts: 2. Upload them.')
      }),
    )
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    const err = await up.run().catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.code).toBe('PARTS_MISSING')
    expect(calls.complete).toHaveLength(6)
    expect(fakeS3.puts.filter((p) => p.partNumber === 2)).toHaveLength(6)
  })

  it('caps UPLOAD_COMPLETING at 20 waits', async () => {
    mockApi({ size: 10 })
    let n = 0
    server.use(http.post(`${API}/v1/uploads/:id\\:complete`, () => (n++, problem(409, 'UPLOAD_COMPLETING'))))
    const o = opts()
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    const err = await up.run().catch((e) => e)
    expect(err.code).toBe('UPLOAD_COMPLETING')
    expect(n).toBe(21)
    expect(o.sleep).toHaveBeenCalledTimes(20)
  })

  it('retries a needed parts:sign on 503', async () => {
    mockApi({ size: 10, firstExpires: soon() })
    let n = 0
    server.use(signHandler(10, () => (++n === 1 ? problem(503, 'BUSY') : undefined)))
    const o = opts()
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    await expect(up.run()).resolves.toBe('completed')
    expect(n).toBe(2)
    expect(o.sleep).toHaveBeenCalledWith(500)
  })

  it('does not fail a part when the low-water prefetch fails', async () => {
    const size = 100 // 10 parts, but the start response only carries URLs for parts 1-3
    const calls = mockApi({ size })
    server.use(
      http.post(`${API}/v1/videos/:id/uploads`, () =>
        HttpResponse.json(
          {
            id: 'up-1', video_id: 'vid1', status: 'initiated', size_bytes: size, content_type: 'video/mp4',
            part_size_bytes: PART, total_parts: 10, expires_at: farFuture(), uploaded_parts: [],
            next_parts: [1, 2, 3].map((n) => partEntry(n, size)),
          },
          { status: 201 },
        ),
      ),
    )
    const signs: number[][] = []
    server.use(signHandler(size, (nums) => (signs.push(nums), signs.length === 1 ? problem(503, 'BUSY') : undefined)))
    const up = await Upload.start('vid1', makeFile(size))
    Object.assign(up.options, opts({ concurrency: 1 }))
    await expect(up.run()).resolves.toBe('completed')
    // The first sign call is the failed prefetch (fewer than 5 fresh URLs, part 1 still fresh).
    expect(signs[0]).toEqual([4, 5, 6, 7, 8, 9, 10])
    expect(fakeS3.puts.filter((p) => p.partNumber === 1)).toHaveLength(1)
    expect(calls.complete).toHaveLength(1)
  })

  it('does not start a PUT while paused during a slow sign', async () => {
    mockApi({ size: 10, firstExpires: soon() })
    server.use(signHandler(10, async () => (await delay(40), undefined)))
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts({ concurrency: 1 }))
    const done = up.run()
    await delay(10)
    up.pause()
    await delay(80)
    expect(fakeS3.puts).toHaveLength(0)
    up.resumeRun()
    await done
    expect(fakeS3.puts).toHaveLength(1)
  })

  it('re-signs once for a 403 storm when a part URL was refreshed meanwhile', async () => {
    const calls = mockApi({ size: 20 })
    // Part 2's URL is fresh now but will count as stale after the clock jumps.
    server.use(
      http.post(`${API}/v1/videos/:id/uploads`, () =>
        HttpResponse.json(
          {
            id: 'up-1', video_id: 'vid1', status: 'initiated', size_bytes: 20, content_type: 'video/mp4',
            part_size_bytes: PART, total_parts: 2, expires_at: farFuture(), uploaded_parts: [],
            next_parts: [1, 2].map((n) => partEntry(n, 20, new Date(Date.now() + 3 * 60_000).toISOString())),
          },
          { status: 201 },
        ),
      ),
    )
    const up = await Upload.start('vid1', makeFile(20))
    Object.assign(up.options, opts())
    vi.useFakeTimers({ toFake: ['Date'] })
    let jumped = false
    fakeS3.handler = (r) => {
      if (r.attempt > 1) return { status: 200, etag: `"e${r.partNumber}"` }
      if (!jumped) {
        jumped = true
        vi.setSystemTime(Date.now() + 2 * 60_000)
      }
      return { status: 403 }
    }
    await up.run()
    expect(calls.sign).toEqual([[1, 2]])
  })

  it('stops sending :complete once aborted', async () => {
    const calls = mockApi({ size: 10 })
    server.use(
      http.post(`${API}/v1/uploads/:id\\:complete`, async () => {
        calls.complete.push({ key: null, parts: null })
        await delay(30)
        return problem(409, 'PARTS_MISSING', 'Missing parts: 1.')
      }),
    )
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    const run = up.run().catch((e) => e)
    while (calls.complete.length === 0) await delay(2)
    await up.abort()
    expect(await run).toBeInstanceOf(UploadAbortedError)
    expect(calls.complete).toHaveLength(1)
    expect(fakeS3.puts).toHaveLength(1)
    expect(calls.deleted).toBe(1)
  })

  it('abort while parts are uploading rejects run() and cancels the XHRs', async () => {
    mockApi({ size: 40 })
    fakeS3.delayMs = 30
    const up = await Upload.start('vid1', makeFile(40))
    Object.assign(up.options, opts())
    const run = up.run().catch((e) => e)
    await delay(5)
    await up.abort()
    expect(await run).toBeInstanceOf(UploadAbortedError)
    expect(fakeS3.inFlight).toBe(0)
    expect(store.get('vid1')).toBeNull()
  })

  it('lets the same instance run() again after a failure', async () => {
    mockApi({ size: 25 })
    let fail = true
    fakeS3.handler = (r) => (fail && r.partNumber === 2 ? { status: 400 } : { status: 200, etag: `"e${r.partNumber}"` })
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await expect(up.run()).rejects.toBeInstanceOf(PartUploadError)
    fail = false
    await expect(up.run()).resolves.toBe('completed')
  })

  it('treats an XHR timeout as retryable and sets a timeout', async () => {
    mockApi({ size: 10 })
    let timeouts: number[] = []
    fakeS3.handler = (r) => (r.attempt === 1 ? 'timeout' : { status: 200, etag: '"ok"' })
    const orig = globalThis.XMLHttpRequest
    vi.stubGlobal('XMLHttpRequest', class extends orig {
      constructor() {
        super()
        queueMicrotask(() => timeouts.push((this as unknown as { timeout: number }).timeout))
      }
    })
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    await up.run()
    expect(fakeS3.puts).toHaveLength(2)
    expect(timeouts[0]).toBeGreaterThan(0)
  })

  it('fails clearly when parts:sign omits a needed part, after one retry', async () => {
    mockApi({ size: 10, firstExpires: soon() })
    let n = 0
    server.use(http.post(`${API}/v1/uploads/:id/parts\\:sign`, () => (n++, HttpResponse.json({ parts: [] }))))
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    await expect(up.run()).rejects.toBeInstanceOf(SignError)
    expect(n).toBe(2)
  })

  it('retries :complete on 503 and network errors with the same key', async () => {
    const calls = mockApi({ size: 10 })
    let n = 0
    server.use(
      http.post(`${API}/v1/uploads/:id\\:complete`, ({ request }) => {
        calls.complete.push({ key: request.headers.get('Idempotency-Key'), parts: null })
        n++
        if (n === 1) return problem(503, 'BUSY')
        if (n === 2) return HttpResponse.error()
        return HttpResponse.json(completedBody)
      }),
    )
    const o = opts()
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, o)
    await up.run()
    expect(calls.complete).toHaveLength(3)
    expect(new Set(calls.complete.map((c) => c.key)).size).toBe(1)
    expect(o.sleep).toHaveBeenCalledTimes(2)
  })

  it('gives up on :complete after 8 transient failures', async () => {
    const calls = mockApi({ size: 10 })
    server.use(http.post(`${API}/v1/uploads/:id\\:complete`, () => (calls.complete.push({ key: null, parts: null }), problem(503, 'BUSY'))))
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    await expect(up.run()).rejects.toBeInstanceOf(ApiError)
    expect(calls.complete).toHaveLength(8)
  })

  it('treats a double resumeRun as harmless', async () => {
    mockApi({ size: 40 })
    fakeS3.delayMs = 20
    const up = await Upload.start('vid1', makeFile(40))
    Object.assign(up.options, opts())
    const done = up.run()
    await delay(5)
    up.pause()
    up.resumeRun()
    up.resumeRun()
    await expect(done).resolves.toBe('completed')
    expect(fakeS3.maxInFlight).toBeLessThanOrEqual(2)
  })

  it('throws NoSuchUpload (404) when a part PUT 404s and the upload is gone', async () => {
    mockApi({ size: 10 })
    fakeS3.handler = () => ({ status: 404 })
    server.use(http.get(`${API}/v1/uploads/:id`, () => HttpResponse.json({ id: 'up-1', status: 'aborted', size_bytes: 10, part_size_bytes: PART, total_parts: 1, uploaded_parts: [], next_parts: [] })))
    const up = await Upload.start('vid1', makeFile(10))
    Object.assign(up.options, opts())
    const err = await up.run().catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ status: 404, code: 'NoSuchUpload' })
  })

  it('sends an Idempotency-Key when starting', async () => {
    mockApi({ size: 10 })
    let key: string | null = null
    server.use(
      http.post(`${API}/v1/videos/:id/uploads`, ({ request }) => {
        key = request.headers.get('Idempotency-Key')
        return problem(500, 'X')
      }),
    )
    await Upload.start('vid1', makeFile(10)).catch(() => {})
    expect(key).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('with an empty store, UPLOAD_IN_PROGRESS resumes the named upload', async () => {
    const calls = mockApi({ size: 25, uploadedParts: [1, 2] })
    server.use(
      http.post(`${API}/v1/videos/:id/uploads`, () =>
        problem(409, 'UPLOAD_IN_PROGRESS', 'Resume upload up-1 with GET /v1/uploads/up-1, or cancel it.'),
      ),
    )
    expect(store.all()).toEqual([])
    const up = await Upload.start('vid1', makeFile(25))
    Object.assign(up.options, opts())
    await up.run()
    expect(calls.get).toBe(1)
    expect(fakeS3.puts.map((p) => p.partNumber)).toEqual([3])
  })
})

describe('store', () => {
  const u: SavedUpload = { uploadId: 'a', videoId: 'v', file: { name: 'n', size: 1, lastModified: 2 }, etags: {}, completeKey: 'k' }

  it('saves, reads, lists and removes under reel.uploads.v1', () => {
    store.save(u)
    expect(store.get('v')).toEqual(u)
    expect(store.all()).toEqual([u])
    expect(localStorage.getItem('reel.uploads.v1')).toContain('"uploadId":"a"')
    store.remove('v')
    expect(store.get('v')).toBeNull()
  })

  it('falls back to memory when localStorage throws', () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    store.save(u)
    expect(store.get('v')).toEqual(u)
    spy.mockRestore()
  })

  it('does not resurrect entries after storage is cleared externally', () => {
    store.save(u)
    localStorage.clear()
    expect(store.get('v')).toBeNull()
    expect(store.all()).toEqual([])
  })
})
