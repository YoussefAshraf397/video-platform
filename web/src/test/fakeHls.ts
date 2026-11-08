// Stand-in for hls.js in jsdom: `vi.mock('hls.js', () => import('../test/fakeHls'))`.
type Handler = (event: string, data: unknown) => void

export const instances: FakeHls[] = []
export const fakeState = {
  levels: [
    { height: 360, bitrate: 500_000 },
    { height: 720, bitrate: 1_500_000 },
  ] as Array<{ height: number; bitrate: number }>,
  subtitleTracks: [] as Array<{ lang?: string; name?: string }>,
}

export default class FakeHls {
  static isSupported = () => true
  static Events = {
    MANIFEST_PARSED: 'hlsManifestParsed',
    SUBTITLE_TRACKS_UPDATED: 'hlsSubtitleTracksUpdated',
    SUBTITLE_TRACK_SWITCH: 'hlsSubtitleTrackSwitch',
    FRAG_LOADED: 'hlsFragLoaded',
    ERROR: 'hlsError',
  }
  static ErrorTypes = { NETWORK_ERROR: 'networkError', MEDIA_ERROR: 'mediaError' }

  config: { xhrSetup?: (xhr: { withCredentials: boolean }) => void }
  handlers = new Map<string, Handler[]>()
  levels = fakeState.levels
  subtitleTracks = fakeState.subtitleTracks
  private _track = -1
  get subtitleTrack() {
    return this._track
  }
  set subtitleTrack(id: number) {
    if (id === this._track) return
    this._track = id
    this.emit(FakeHls.Events.SUBTITLE_TRACK_SWITCH, { id })
  }
  subtitleDisplay = false
  currentLevel = -1
  loaded: string[] = []
  startLoadCalls: number[] = []
  stopped = false
  destroyed = false
  media: unknown = null

  constructor(config: FakeHls['config'] = {}) {
    this.config = config
    instances.push(this)
  }
  on(event: string, fn: Handler) {
    this.handlers.set(event, [...(this.handlers.get(event) ?? []), fn])
  }
  off() {}
  emit(event: string, data: unknown = {}) {
    for (const fn of this.handlers.get(event) ?? []) fn(event, data)
  }
  loadSource(url: string) {
    this.loaded.push(url)
    this.currentLevel = -1 // a new source starts on Auto
    queueMicrotask(() => {
      if (this.destroyed) return
      this.emit(FakeHls.Events.MANIFEST_PARSED, { levels: this.levels })
      this.emit(FakeHls.Events.SUBTITLE_TRACKS_UPDATED, { subtitleTracks: this.subtitleTracks })
      // Like hls.js's subtitle controller: it selects its own default (none, as DEFAULT=NO) as soon as
      // the event handlers return, overriding anything they set synchronously.
      this.subtitleTrack = -1
    })
  }
  attachMedia(media: unknown) {
    this.media = media
  }
  startLoad(position?: number) {
    this.startLoadCalls.push(position ?? -1)
  }
  stopLoad() {
    this.stopped = true
  }
  destroy() {
    this.destroyed = true
  }
}

export const Events = FakeHls.Events
