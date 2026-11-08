// Stand-in for XMLHttpRequest in jsdom, for S3 part PUTs.
// Install with `installFakeXhr()` (stubs globalThis.XMLHttpRequest); undo with `vi.unstubAllGlobals()`.
import { vi } from 'vitest'

export type PutRequest = { partNumber: number; url: string; size: number; attempt: number }
export type PutResult = { status: number; etag?: string } | 'network' | 'timeout'

export const fakeS3 = {
  puts: [] as PutRequest[],
  inFlight: 0,
  maxInFlight: 0,
  delayMs: 5,
  handler: (req: PutRequest): PutResult => ({ status: 200, etag: `"etag-${req.partNumber}"` }),
  reset() {
    this.puts = []
    this.inFlight = 0
    this.maxInFlight = 0
    this.delayMs = 5
    this.handler = (req) => ({ status: 200, etag: `"etag-${req.partNumber}"` })
  },
}

class FakeXhr {
  upload: { onprogress: ((e: { lengthComputable: boolean; loaded: number; total: number }) => void) | null } = {
    onprogress: null,
  }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  ontimeout: (() => void) | null = null
  onabort: (() => void) | null = null
  status = 0
  timeout = 0
  private url = ''
  private etag: string | null = null
  private timer: ReturnType<typeof setTimeout> | null = null
  private active = false

  open(_method: string, url: string) {
    this.url = url
  }
  getResponseHeader(name: string) {
    return name.toLowerCase() === 'etag' ? this.etag : null
  }
  send(body: Blob) {
    const partNumber = Number(new URL(this.url).searchParams.get('partNumber'))
    const attempt = fakeS3.puts.filter((p) => p.partNumber === partNumber).length + 1
    const req: PutRequest = { partNumber, url: this.url, size: body.size, attempt }
    fakeS3.puts.push(req)
    this.active = true
    fakeS3.inFlight++
    fakeS3.maxInFlight = Math.max(fakeS3.maxInFlight, fakeS3.inFlight)
    this.upload.onprogress?.({ lengthComputable: true, loaded: Math.floor(body.size / 2), total: body.size })
    this.timer = setTimeout(() => {
      this.finish()
      const result = fakeS3.handler(req)
      if (result === 'network') return this.onerror?.()
      if (result === 'timeout') return this.ontimeout?.()
      this.status = result.status
      this.etag = result.etag ?? null
      if (result.status < 300) this.upload.onprogress?.({ lengthComputable: true, loaded: body.size, total: body.size })
      this.onload?.()
    }, fakeS3.delayMs)
  }
  abort() {
    if (!this.active) return
    if (this.timer) clearTimeout(this.timer)
    this.finish()
    this.onabort?.()
  }
  private finish() {
    if (this.active) fakeS3.inFlight--
    this.active = false
  }
}

export function installFakeXhr() {
  fakeS3.reset()
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
}
