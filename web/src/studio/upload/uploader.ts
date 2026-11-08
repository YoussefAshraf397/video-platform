import { api, ApiError } from '../../api/client'
import { store, type SavedUpload } from './store'

/** Thrown by `Upload.resume` when the file is not the one the upload was started with. */
export class FileMismatchError extends Error {
  constructor() {
    super('This is not the file the upload was started with')
    this.name = 'FileMismatchError'
  }
}

/** The server says the upload is over (aborted, expired or failed): start a new one. */
export class UploadEndedError extends Error {
  status: string
  constructor(status: string) {
    super(`The upload is ${status}`)
    this.name = 'UploadEndedError'
    this.status = status
  }
}

/** A part could not be uploaded. `status` is the HTTP status of the last attempt, null for network errors. */
export class PartUploadError extends Error {
  partNumber: number
  status: number | null
  constructor(partNumber: number, status: number | null) {
    super(`Part ${partNumber} failed to upload${status ? ` (HTTP ${status})` : ''}`)
    this.name = 'PartUploadError'
    this.partNumber = partNumber
    this.status = status
  }
}

/** `abort()` was called while `run()` was pending. */
export class UploadAbortedError extends Error {
  constructor() {
    super('The upload was cancelled')
    this.name = 'UploadAbortedError'
  }
}

export type UploadOptions = {
  concurrency?: number
  sleep?: (ms: number) => Promise<void>
  random?: () => number
}

type SignedPart = { part_number: number; size_bytes: number; url: string; expires_at: string }
type UploadSession = {
  id: string
  status: string
  size_bytes: number
  part_size_bytes: number
  total_parts: number
  uploaded_parts: Array<{ part_number: number; size_bytes: number; etag: string }>
  next_parts: SignedPart[]
}

const MAX_ATTEMPTS = 8
const URL_MIN_LIFE_MS = 2 * 60_000
const SIGN_BATCH = 100
const SIGN_LOW_WATER = 5
const COMPLETING_WAIT_MS = 3000
const MAX_PARTS_MISSING_ROUNDS = 5
const MAX_COMPLETING_WAITS = 20
const PART_TIMEOUT_MS = 120_000
const PAUSED = Symbol('paused')

/** A network failure (fetch rejects with TypeError), 408, 429 or 5xx: worth retrying. */
const transient = (e: unknown) =>
  e instanceof TypeError || (e instanceof ApiError && (e.status === 408 || e.status === 429 || e.status >= 500))

/** `parts:sign` did not return a URL for a part that needs one. */
export class SignError extends Error {
  partNumber: number
  constructor(partNumber: number) {
    super(`The server did not return an upload URL for part ${partNumber}`)
    this.name = 'SignError'
    this.partNumber = partNumber
  }
}

const defaultSleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms))

function defaultConcurrency(): number {
  const type = (globalThis.navigator as { connection?: { effectiveType?: string } } | undefined)?.connection?.effectiveType
  return type === 'slow-2g' || type === '2g' || type === '3g' ? 2 : 4
}

const describeFile = (f: File) => ({ name: f.name, size: f.size, lastModified: f.lastModified })

export class Upload {
  options: UploadOptions = {}

  private etags: Map<number, string>
  private urls = new Map<number, { url: string; expiresAt: number; seq: number }>()
  private signSeq = 0
  private done = new Set<number>()
  private inflight = new Map<number, number>()
  private xhrs = new Set<XMLHttpRequest>()
  private progressCb: ((sent: number, total: number) => void) | null = null
  private paused = false
  private resumeWaiters: Array<() => void> = []
  private aborted = false
  private finished = false
  private running: Promise<'completed'> | null = null
  private queue: number[] = []

  private constructor(
    private saved: SavedUpload,
    private file: File,
    private session: UploadSession,
  ) {
    this.etags = new Map(Object.entries(saved.etags).map(([n, e]) => [Number(n), e]))
    this.absorb(session)
  }

  /**
   * Starts an upload for a draft video. On `409 UPLOAD_IN_PROGRESS` it resumes the upload the
   * error names (the saved state is reused when it matches this file).
   */
  static async start(videoId: string, file: File): Promise<Upload> {
    try {
      const { data } = await api<UploadSession>('POST', `/v1/videos/${videoId}/uploads`, {
        body: { size_bytes: file.size, content_type: file.type || 'video/mp4' },
        idempotencyKey: crypto.randomUUID(),
      })
      return Upload.adopt(videoId, file, data, {})
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 409 || e.code !== 'UPLOAD_IN_PROGRESS') throw e
      const id = /upload\s+(\S+?)\s+with\b/i.exec(e.detail ?? '')?.[1]
      if (!id) throw e
      const known = store.get(videoId)
      if (known && known.uploadId === id) {
        try {
          return await Upload.resume(known, file)
        } catch (err) {
          if (!(err instanceof FileMismatchError)) throw err
        }
      }
      const { data } = await api<UploadSession>('GET', `/v1/uploads/${id}`)
      if (data.status === 'aborted' || data.status === 'expired' || data.status === 'failed') throw new UploadEndedError(data.status)
      return Upload.adopt(videoId, file, data, {})
    }
  }

  /**
   * Continues a saved upload. The server's ETags win over the saved ones.
   * Rejects with an `ApiError` (404) when the server no longer knows the upload,
   * and with `UploadEndedError` when it is aborted, expired or failed.
   */
  static async resume(saved: SavedUpload, file: File): Promise<Upload> {
    const f = describeFile(file)
    if (f.name !== saved.file.name || f.size !== saved.file.size || f.lastModified !== saved.file.lastModified) {
      throw new FileMismatchError()
    }
    const { data } = await api<UploadSession>('GET', `/v1/uploads/${saved.uploadId}`)
    if (data.status === 'aborted' || data.status === 'expired' || data.status === 'failed') throw new UploadEndedError(data.status)
    const up = Upload.adopt(saved.videoId, file, data, saved)
    if (data.status === 'completed') up.finished = true
    return up
  }

  private static adopt(videoId: string, file: File, session: UploadSession, prior: Partial<SavedUpload>): Upload {
    const saved: SavedUpload = {
      uploadId: session.id,
      videoId,
      file: describeFile(file),
      etags: prior.etags ?? {},
      completeKey: prior.completeKey ?? crypto.randomUUID(),
    }
    const up = new Upload(saved, file, session)
    up.persist()
    return up
  }

  onProgress(cb: (sentBytes: number, totalBytes: number) => void) {
    this.progressCb = cb
  }

  run(): Promise<'completed'> {
    this.running ??= this.doRun().catch((e) => {
      this.running = null // a failed run can be retried on the same instance
      throw e
    })
    return this.running
  }

  /** Aborts in-flight parts and holds the queue. `run()` stays pending until `resumeRun()`. */
  pause() {
    if (this.paused) return
    this.paused = true
    for (const x of [...this.xhrs]) x.abort()
  }

  resumeRun() {
    if (!this.paused) return
    this.paused = false
    for (const w of this.resumeWaiters.splice(0)) w()
  }

  async abort(): Promise<void> {
    this.aborted = true
    for (const x of [...this.xhrs]) x.abort()
    this.resumeRun()
    await api('DELETE', `/v1/uploads/${this.saved.uploadId}`)
    store.remove(this.saved.videoId)
  }

  // --- internals ---

  private get id() {
    return this.saved.uploadId
  }
  private get total() {
    return this.session.total_parts
  }
  private partRange(n: number): [number, number] {
    const ps = this.session.part_size_bytes
    return [(n - 1) * ps, Math.min(n * ps, this.session.size_bytes)]
  }
  private partSize(n: number) {
    const [a, b] = this.partRange(n)
    return b - a
  }

  private absorb(s: UploadSession) {
    this.session = s
    for (const p of s.uploaded_parts) this.etags.set(p.part_number, p.etag)
    for (const p of s.next_parts) this.cacheUrl(p)
    this.done = new Set(this.etags.keys())
  }

  private cacheUrl(p: SignedPart) {
    this.urls.set(p.part_number, { url: p.url, expiresAt: Date.parse(p.expires_at), seq: ++this.signSeq })
  }

  private persist() {
    store.save({ ...this.saved, etags: Object.fromEntries(this.etags) })
  }

  private emitProgress() {
    let sent = 0
    for (const n of this.done) sent += this.partSize(n)
    for (const n of this.inflight.keys()) if (!this.done.has(n)) sent += this.inflight.get(n)!
    this.progressCb?.(Math.min(sent, this.session.size_bytes), this.session.size_bytes)
  }

  private async gate() {
    while (this.paused && !this.aborted) await new Promise<void>((r) => this.resumeWaiters.push(r))
    if (this.aborted) throw new UploadAbortedError()
  }

  private get sleep() {
    return this.options.sleep ?? defaultSleep
  }

  private backoff(attempt: number) {
    return (this.options.random ?? Math.random)() * Math.min(30_000, 1000 * 2 ** (attempt - 1))
  }

  private checkAborted() {
    if (this.aborted) throw new UploadAbortedError()
  }

  private async doRun(): Promise<'completed'> {
    if (this.finished) {
      this.emitProgress()
      store.remove(this.saved.videoId)
      return 'completed'
    }
    let rounds = 0
    let waits = 0
    let netAttempts = 0
    for (;;) {
      await this.uploadMissing()
      this.checkAborted()
      try {
        await api('POST', `/v1/uploads/${this.id}:complete`, {
          idempotencyKey: this.saved.completeKey,
          body: {
            parts: Array.from({ length: this.total }, (_, i) => ({ part_number: i + 1, etag: this.etags.get(i + 1)! })),
          },
        })
        this.checkAborted()
        this.finished = true
        store.remove(this.saved.videoId)
        return 'completed'
      } catch (e) {
        this.checkAborted()
        if (transient(e)) {
          if (++netAttempts >= MAX_ATTEMPTS) throw e
          await this.sleep(this.backoff(netAttempts))
          this.checkAborted()
          continue
        }
        if (!(e instanceof ApiError) || e.status !== 409) throw e
        netAttempts = 0
        if (e.code === 'UPLOAD_COMPLETING') {
          if (++waits > MAX_COMPLETING_WAITS) throw e
          await this.sleep(COMPLETING_WAIT_MS)
          this.checkAborted()
        } else if (e.code === 'PARTS_MISSING') {
          if (++rounds > MAX_PARTS_MISSING_ROUNDS) throw e
          const named = [...(e.detail ?? '').replace(/^.*?:/, '').matchAll(/\d+/g)].map((m) => Number(m[0]))
          const valid = named.filter((n) => n >= 1 && n <= this.total)
          if (valid.length === 0) {
            // Nothing parseable: ask the server what it has.
            const { data } = await api<UploadSession>('GET', `/v1/uploads/${this.id}`)
            this.etags.clear()
            this.absorb(data)
          } else {
            for (const n of valid) this.etags.delete(n)
            this.done = new Set(this.etags.keys())
          }
          this.persist()
        } else {
          throw e
        }
      }
    }
  }

  private async uploadMissing() {
    this.queue = []
    for (let n = 1; n <= this.total; n++) if (!this.etags.has(n)) this.queue.push(n)
    this.emitProgress()
    if (this.queue.length === 0) return
    const workers = Math.max(1, this.options.concurrency ?? defaultConcurrency())
    let failure: unknown = null
    const worker = async () => {
      for (;;) {
        if (failure) return
        await this.gate()
        if (failure) return // a failure may have been recorded while this worker was paused
        const n = this.queue.shift()
        if (n === undefined) return
        try {
          await this.uploadPart(n)
        } catch (e) {
          if (e === PAUSED) {
            this.queue.unshift(n)
            continue
          }
          failure ??= e
          return
        }
      }
    }
    await Promise.all(Array.from({ length: Math.min(workers, this.queue.length) }, worker))
    if (failure) throw failure
  }

  private fresh(n: number) {
    const u = this.urls.get(n)
    return !!u && u.expiresAt - Date.now() > URL_MIN_LIFE_MS
  }

  private pending() {
    return Array.from({ length: this.total }, (_, i) => i + 1).filter((n) => !this.etags.has(n))
  }

  private signing: Promise<unknown> = Promise.resolve()

  /** Calls parts:sign for `batch` and caches the URLs. `needed` must come back, else one retry, then SignError. */
  private async signBatch(batch: number[], needed: number | null) {
    const call = async (nums: number[]) => {
      const { data } = await api<{ parts: SignedPart[] }>('POST', `/v1/uploads/${this.id}/parts:sign`, {
        body: { part_numbers: nums },
      })
      for (const p of data.parts) this.cacheUrl(p)
      return data.parts
    }
    const got = await call(batch)
    if (needed !== null && !got.some((p) => p.part_number === needed)) {
      const again = await call([needed])
      if (!again.some((p) => p.part_number === needed)) throw new SignError(needed)
    }
  }

  private async urlFor(n: number, force: boolean, staleSeq = Infinity): Promise<string> {
    const needThis = force || !this.fresh(n)
    const freshPending = this.pending().filter((p) => this.fresh(p)).length
    if (needThis || freshPending < SIGN_LOW_WATER) {
      const run = this.signing.then(async () => {
        // A forced re-sign is pointless if a batch refreshed this part's URL after the failing attempt began.
        if (force && (this.urls.get(n)?.seq ?? 0) > staleSeq) return
        // Another worker may have refreshed things while this call waited its turn.
        if (!force && this.fresh(n) && (needThis || this.pending().filter((p) => this.fresh(p)).length >= SIGN_LOW_WATER)) return
        const batch: number[] = needThis ? [n] : []
        for (const p of this.pending()) {
          if (batch.length >= SIGN_BATCH) break
          if (p !== n && !this.fresh(p)) batch.push(p)
        }
        if (batch.length === 0) return
        if (!needThis) {
          // Prefetch for other parts: this part's URL is still fine, so a failure is not an error.
          try {
            await this.signBatch(batch, null)
          } catch {
            // ignored
          }
          return
        }
        for (let attempt = 1; ; attempt++) {
          try {
            await this.signBatch(batch, n)
            return
          } catch (e) {
            if (!transient(e) || attempt >= MAX_ATTEMPTS) throw e
            await this.sleep(this.backoff(attempt))
            this.checkAborted()
          }
        }
      })
      this.signing = run.catch(() => {})
      await run
    }
    return this.urls.get(n)!.url
  }

  private put(url: string, n: number): Promise<{ status: number; etag: string | null } | 'network'> {
    return new Promise((resolve, reject) => {
      const [a, b] = this.partRange(n)
      const xhr = new XMLHttpRequest()
      xhr.timeout = PART_TIMEOUT_MS
      this.xhrs.add(xhr)
      const end = () => this.xhrs.delete(xhr)
      xhr.open('PUT', url)
      xhr.upload.onprogress = (e) => {
        this.inflight.set(n, e.loaded)
        this.emitProgress()
      }
      xhr.onload = () => {
        end()
        resolve({ status: xhr.status, etag: xhr.getResponseHeader('ETag') })
      }
      xhr.onerror = xhr.ontimeout = () => {
        end()
        resolve('network')
      }
      xhr.onabort = () => {
        end()
        reject(this.aborted ? new UploadAbortedError() : PAUSED)
      }
      xhr.send(this.file.slice(a, b))
    })
  }

  private async uploadPart(n: number) {
    let force = false
    let staleSeq = Infinity
    let lastStatus: number | null = null
    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
      await this.gate()
      const url = await this.urlFor(n, force, staleSeq)
      const usedSeq = this.urls.get(n)!.seq
      force = false
      await this.gate() // paused while signing: do not start the XHR
      this.inflight.set(n, 0)
      let res: Awaited<ReturnType<Upload['put']>>
      try {
        res = await this.put(url, n)
      } catch (e) {
        this.inflight.delete(n)
        this.emitProgress()
        throw e
      }
      const ok = res !== 'network' && res.status >= 200 && res.status < 300
      if (res !== 'network' && ok && res.etag) {
        this.etags.set(n, res.etag)
        this.done.add(n)
        this.inflight.delete(n)
        this.persist()
        this.emitProgress()
        return
      }
      this.inflight.delete(n)
      this.emitProgress()
      // Without a readable ETag we cannot complete; the bucket must expose it through CORS.
      if (res !== 'network' && ok) throw new PartUploadError(n, res.status)
      lastStatus = res === 'network' ? null : res.status
      if (res !== 'network') {
        if (res.status === 403) {
          force = true
          staleSeq = usedSeq
          continue
        }
        if (res.status === 404) {
          // NoSuchUpload: find out what happened to the upload (this throws its own 404 if it is gone).
          const { data } = await api<UploadSession>('GET', `/v1/uploads/${this.id}`)
          throw new ApiError({
            status: 404,
            code: 'NoSuchUpload',
            title: 'Upload no longer exists',
            detail: `The upload is ${data.status}`,
            errors: [],
          })
        }
        const retryable = res.status === 408 || res.status === 429 || res.status >= 500
        if (!retryable) throw new PartUploadError(n, res.status)
      }
      if (attempt < MAX_ATTEMPTS) await this.sleep(this.backoff(attempt))
    }
    throw new PartUploadError(n, lastStatus)
  }
}
