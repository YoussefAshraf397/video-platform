import { refreshDelayMs } from './refresh'

const now = Date.parse('2026-11-05T10:00:00Z')

describe('refreshDelayMs', () => {
  it('refreshes 5 minutes before expiry', () => {
    expect(refreshDelayMs('2026-11-05T11:00:00Z', now)).toBe(55 * 60_000)
  })
  it('is 0 when under 5 minutes are left or already expired', () => {
    expect(refreshDelayMs('2026-11-05T10:03:00Z', now)).toBe(0)
    expect(refreshDelayMs('2026-11-05T09:00:00Z', now)).toBe(0)
  })
  it('is 0 for an unparseable date', () => {
    expect(refreshDelayMs('nope', now)).toBe(0)
  })
})
