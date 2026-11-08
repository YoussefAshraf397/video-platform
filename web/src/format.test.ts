import { formatAgo, formatDuration } from './format'

describe('formatDuration', () => {
  it('formats minutes and seconds', () => {
    expect(formatDuration(18 * 60_000 + 42_000)).toBe('18:42')
    expect(formatDuration(5_000)).toBe('0:05')
  })
  it('adds hours when needed', () => {
    expect(formatDuration(3_723_000)).toBe('1:02:03')
  })
})

describe('formatDuration guards', () => {
  it('returns 0:00 for bad input', () => {
    expect(formatDuration(NaN)).toBe('0:00')
    expect(formatDuration(-5)).toBe('0:00')
    expect(formatDuration(Infinity)).toBe('0:00')
  })
})

describe('formatAgo', () => {
  const now = new Date('2026-11-08T12:00:00Z')
  const ago = (ms: number) => new Date(now.getTime() - ms).toISOString()
  it('uses hours, yesterday, days', () => {
    expect(formatAgo(ago(2 * 3_600_000), now)).toBe('2 hours ago')
    expect(formatAgo(ago(30 * 3_600_000), now)).toBe('yesterday')
    expect(formatAgo(ago(3 * 86_400_000), now)).toBe('3 days ago')
  })
  it('uses a date beyond 30 days', () => {
    expect(formatAgo('2026-03-12T10:00:00Z', now)).toBe('12 Mar 2026')
  })
  it('singular and boundaries', () => {
    expect(formatAgo(ago(3_600_000), now)).toBe('1 hour ago')
    expect(formatAgo(ago(59 * 60_000), now)).toBe('59 minutes ago')
    expect(formatAgo(ago(60 * 60_000), now)).toBe('1 hour ago')
    expect(formatAgo(ago(30 * 86_400_000), now)).toBe('30 days ago')
    expect(formatAgo(ago(31 * 86_400_000), now)).toBe('8 Oct 2026')
  })
  it('writes September as Sep', () => {
    expect(formatAgo('2026-09-12T10:00:00Z', new Date('2027-01-01T00:00:00Z'))).toBe('12 Sep 2026')
  })
  it('returns empty for invalid input', () => {
    expect(formatAgo('nope', now)).toBe('')
  })
  it('handles very recent times', () => {
    expect(formatAgo(ago(10_000), now)).toBe('just now')
    expect(formatAgo(ago(5 * 60_000), now)).toBe('5 minutes ago')
  })
})
