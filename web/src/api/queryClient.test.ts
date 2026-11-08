import { ApiError } from './client'
import { shouldRetry } from './queryClient'

const err = (status: number) => new ApiError({ status, code: null, title: 't', detail: null, errors: [] })

describe('shouldRetry', () => {
  it('never retries 4xx', () => {
    expect(shouldRetry(0, err(404))).toBe(false)
    expect(shouldRetry(0, err(400))).toBe(false)
    expect(shouldRetry(0, err(499))).toBe(false)
  })
  it('retries 5xx and network errors up to 2 times', () => {
    expect(shouldRetry(0, err(500))).toBe(true)
    expect(shouldRetry(1, err(503))).toBe(true)
    expect(shouldRetry(2, err(500))).toBe(false)
    expect(shouldRetry(0, new TypeError('network'))).toBe(true)
    expect(shouldRetry(2, new TypeError('network'))).toBe(false)
  })
})
