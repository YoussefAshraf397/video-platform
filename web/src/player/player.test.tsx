import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { vi } from 'vitest'
import type { Playback } from '../api/types'
import { Player } from './Player'
import FakeHls, { fakeState, instances } from '../test/fakeHls'

vi.mock('hls.js', () => import('../test/fakeHls'))

const playback = (over: Partial<Playback> = {}): Playback => ({
  video: { id: 'v1', title: 'T', duration_ms: 20000, width: 1280, height: 720 },
  streams: { hls: 'http://media/master.m3u8' },
  auth: { type: 'cookie', expires_at: new Date(Date.now() + 3600_000).toISOString(), cookies: {} },
  thumbnails: { primary: [] },
  captions: [{ language: 'en', label: 'English', kind: 'captions', url: 'http://media/en.vtt' }],
  resume_position_ms: null,
  playback_session_id: 's',
  ...over,
})

beforeEach(() => {
  instances.length = 0
  fakeState.subtitleTracks = [{ lang: 'en', name: 'English' }]
  fakeState.levels = [
    { height: 360, bitrate: 1 },
    { height: 720, bitrate: 2 },
  ]
})

const flush = () => act(async () => { await Promise.resolve() })

describe('Player', () => {
  it('sends credentials with every media request', async () => {
    render(<Player playback={playback()} videoLanguage="en" onExpired={async () => {}} />)
    await flush()
    const xhr = { withCredentials: false }
    instances[0].config.xhrSetup!(xhr)
    expect(xhr.withCredentials).toBe(true)
    expect(instances[0].loaded).toEqual(['http://media/master.m3u8'])
  })

  it('selects the caption track matching the video language (primary subtag)', async () => {
    render(<Player playback={playback()} videoLanguage="en-US" onExpired={async () => {}} />)
    await flush()
    expect(screen.getByRole('combobox', { name: 'Captions' })).toHaveDisplayValue('English')
    expect(instances[0].subtitleTrack).toBe(0)
    expect(instances[0].subtitleDisplay).toBe(true)
  })

  it('leaves captions off when no track matches', async () => {
    render(<Player playback={playback()} videoLanguage="fr" onExpired={async () => {}} />)
    await flush()
    expect(screen.getByRole('combobox', { name: 'Captions' })).toHaveDisplayValue('Off')
  })

  it('hides the caption select with no tracks and the quality select with one level', async () => {
    fakeState.subtitleTracks = []
    fakeState.levels = [{ height: 720, bitrate: 2 }]
    render(<Player playback={playback({ captions: [] })} videoLanguage="en" onExpired={async () => {}} />)
    await flush()
    expect(screen.queryByRole('combobox', { name: 'Captions' })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Quality' })).not.toBeInTheDocument()
  })

  it('offers Auto plus each level and forces the chosen one', async () => {
    render(<Player playback={playback()} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    const select = screen.getByRole('combobox', { name: 'Quality' })
    expect([...select.querySelectorAll('option')].map((o) => o.textContent)).toEqual(['Auto', '360p', '720p'])
    await userEvent.selectOptions(select, '720p')
    expect(instances[0].currentLevel).toBe(1)
  })

  it('refreshes once per 403 burst, then resumes loading', async () => {
    const onExpired = vi.fn(async () => {})
    render(<Player playback={playback()} videoLanguage={null} onExpired={onExpired} />)
    await flush()
    const hls = instances[0]
    const forbidden = { fatal: false, type: 'networkError', details: 'fragLoadError', response: { code: 403 } }
    await act(async () => {
      hls.emit(FakeHls.Events.ERROR, forbidden)
      hls.emit(FakeHls.Events.ERROR, forbidden)
      hls.emit(FakeHls.Events.ERROR, forbidden)
    })
    expect(onExpired).toHaveBeenCalledTimes(1)
    expect(hls.loaded).toHaveLength(2)
    expect(hls.startLoadCalls).toEqual([0])
    // retry fails too: no new refresh, a problem message instead
    await act(async () => { hls.emit(FakeHls.Events.ERROR, forbidden) })
    expect(onExpired).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('alert')).toBeInTheDocument()
    expect(hls.stopped).toBe(true)
  })

  it('allows a new refresh after fragments load again', async () => {
    const onExpired = vi.fn(async () => {})
    render(<Player playback={playback()} videoLanguage={null} onExpired={onExpired} />)
    await flush()
    const hls = instances[0]
    const forbidden = { fatal: false, type: 'networkError', details: 'x', response: { code: 403 } }
    await act(async () => { hls.emit(FakeHls.Events.ERROR, forbidden) })
    await act(async () => { hls.emit(FakeHls.Events.FRAG_LOADED, {}) })
    await act(async () => { hls.emit(FakeHls.Events.ERROR, forbidden) })
    expect(onExpired).toHaveBeenCalledTimes(2)
  })

  it('refreshes ahead of expiry and clears the timer on unmount', async () => {
    vi.useFakeTimers()
    try {
      const onExpired = vi.fn(async () => {})
      const soon = new Date(Date.now() + 3600_000).toISOString()
      const { unmount } = render(<Player playback={playback({ auth: { type: 'cookie', expires_at: soon, cookies: {} } })} videoLanguage={null} onExpired={onExpired} />)
      await vi.advanceTimersByTimeAsync(54 * 60_000)
      expect(onExpired).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(2 * 60_000)
      expect(onExpired).toHaveBeenCalledTimes(1)
      unmount()
      await vi.advanceTimersByTimeAsync(3600_000)
      expect(onExpired).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
  })

  it('destroys hls on unmount and when the stream changes', async () => {
    const { rerender, unmount } = render(<Player playback={playback()} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    rerender(<Player playback={playback({ streams: { hls: 'http://media/other.m3u8' } })} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    expect(instances).toHaveLength(2)
    expect(instances[0].destroyed).toBe(true)
    unmount()
    expect(instances[1].destroyed).toBe(true)
  })

  it('does not rebuild hls when only the cookie expiry changes', async () => {
    const { rerender } = render(<Player playback={playback()} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    rerender(<Player playback={playback({ auth: { type: 'cookie', expires_at: new Date(Date.now() + 7200_000).toISOString(), cookies: {} } })} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    expect(instances).toHaveLength(1)
  })

  it('matches the caption language case-insensitively on the primary subtag, and tolerates a missing lang', async () => {
    fakeState.subtitleTracks = [{ name: 'Unknown' }, { lang: 'EN-gb', name: 'English (UK)' }]
    render(<Player playback={playback()} videoLanguage="en-US" onExpired={async () => {}} />)
    await flush()
    expect(screen.getByRole('combobox', { name: 'Captions' })).toHaveDisplayValue('English (UK)')
    expect(instances[0].subtitleTrack).toBe(1)
    expect(instances[0].subtitleDisplay).toBe(true)
  })

  it('lets the viewer pick Off, another track, then Off again; and Auto after a fixed level', async () => {
    render(<Player playback={playback()} videoLanguage="en" onExpired={async () => {}} />)
    await flush()
    const captions = screen.getByRole('combobox', { name: 'Captions' })
    await userEvent.selectOptions(captions, 'Off')
    expect(instances[0].subtitleTrack).toBe(-1)
    expect(instances[0].subtitleDisplay).toBe(false)
    await userEvent.selectOptions(captions, 'English')
    expect(instances[0].subtitleTrack).toBe(0)
    expect(instances[0].subtitleDisplay).toBe(true)
    const quality = screen.getByRole('combobox', { name: 'Quality' })
    await userEvent.selectOptions(quality, '360p')
    expect(instances[0].currentLevel).toBe(0)
    await userEvent.selectOptions(quality, 'Auto')
    expect(instances[0].currentLevel).toBe(-1)
  })

  it('keeps the viewer\'s choices, paused state and position through a 403 recovery', async () => {
    const onExpired = vi.fn(async () => {})
    render(<Player playback={playback()} videoLanguage="en" onExpired={onExpired} />)
    await flush()
    const hls = instances[0]
    const video = document.querySelector('video')!
    Object.defineProperty(video, 'currentTime', { value: 42, configurable: true })
    Object.defineProperty(video, 'paused', { value: true, configurable: true })
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Captions' }), 'Off')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Quality' }), '720p')
    const play = vi.mocked(HTMLMediaElement.prototype.play)
    play.mockClear()
    await act(async () => {
      hls.emit(FakeHls.Events.ERROR, { fatal: false, type: 'networkError', details: 'x', response: { code: 403 } })
    })
    await flush()
    expect(onExpired).toHaveBeenCalledTimes(1)
    expect(hls.loaded).toHaveLength(2)
    expect(hls.startLoadCalls).toEqual([42])
    expect(screen.getByRole('combobox', { name: 'Captions' })).toHaveDisplayValue('Off')
    expect(hls.subtitleTrack).toBe(-1)
    expect(hls.currentLevel).toBe(1)
    expect(screen.getByRole('combobox', { name: 'Quality' })).toHaveDisplayValue('720p')
    expect(play).not.toHaveBeenCalled()
  })

  it('resumes playing after a 403 recovery only if it was playing', async () => {
    render(<Player playback={playback()} videoLanguage="en" onExpired={async () => {}} />)
    await flush()
    const video = document.querySelector('video')!
    Object.defineProperty(video, 'paused', { value: false, configurable: true })
    const play = vi.mocked(HTMLMediaElement.prototype.play)
    play.mockClear()
    await act(async () => {
      instances[0].emit(FakeHls.Events.ERROR, { fatal: false, type: 'networkError', details: 'x', response: { code: 403 } })
    })
    await flush()
    expect(play).toHaveBeenCalledTimes(1)
  })

  it('reschedules the expiry timer when a refresh changes expires_at', async () => {
    vi.useFakeTimers()
    try {
      const onExpired = vi.fn(async () => {})
      const at = (ms: number) => new Date(Date.now() + ms).toISOString()
      const mk = (ms: number) => playback({ auth: { type: 'cookie', expires_at: at(ms), cookies: {} } })
      const { rerender } = render(<Player playback={mk(3600_000)} videoLanguage={null} onExpired={onExpired} />)
      await vi.advanceTimersByTimeAsync(50 * 60_000)
      rerender(<Player playback={mk(3600_000)} videoLanguage={null} onExpired={onExpired} />)
      // the old timer (due at 55 min) is gone; the new one is due 55 min from now
      await vi.advanceTimersByTimeAsync(10 * 60_000)
      expect(onExpired).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(46 * 60_000)
      expect(onExpired).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives up with a message after repeated fatal network errors', async () => {
    render(<Player playback={playback()} videoLanguage={null} onExpired={async () => {}} />)
    await flush()
    const hls = instances[0]
    const fatal = { fatal: true, type: 'networkError', details: 'manifestLoadError' }
    for (let i = 0; i < 3; i++) await act(async () => { hls.emit(FakeHls.Events.ERROR, fatal) })
    expect(hls.startLoadCalls).toHaveLength(3)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    await act(async () => { hls.emit(FakeHls.Events.ERROR, fatal) })
    expect(hls.startLoadCalls).toHaveLength(3)
    expect(screen.getByRole('alert')).toHaveTextContent('Playback stopped')
  })
})
