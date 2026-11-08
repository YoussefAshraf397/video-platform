export type SavedUpload = {
  uploadId: string
  videoId: string
  file: { name: string; size: number; lastModified: number }
  etags: Record<number, string>
  completeKey: string
}

const KEY = 'reel.uploads.v1'

let memory: Record<string, SavedUpload> = {}
let storageBroken = false

// Memory is used only when localStorage is unavailable or throws. Otherwise storage is the single source of truth.
function read(): Record<string, SavedUpload> {
  if (storageBroken) return memory
  try {
    const raw = localStorage.getItem(KEY)
    if (raw === null) return {}
    const parsed = JSON.parse(raw)
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed
    return {}
  } catch {
    storageBroken = true
    return memory
  }
}

function write(all: Record<string, SavedUpload>) {
  if (!storageBroken) {
    try {
      localStorage.setItem(KEY, JSON.stringify(all))
      return
    } catch {
      storageBroken = true
    }
  }
  memory = all
}

/** Saved uploads by video id, persisted in localStorage (key `reel.uploads.v1`), memory when it is unavailable. */
export const store = {
  save(u: SavedUpload) {
    write({ ...read(), [u.videoId]: u })
  },
  get(videoId: string): SavedUpload | null {
    return read()[videoId] ?? null
  },
  all(): SavedUpload[] {
    return Object.values(read())
  },
  remove(videoId: string) {
    const { [videoId]: _gone, ...rest } = read()
    write(rest)
  },
  /** Forgets everything and re-probes localStorage (for tests). */
  clear() {
    memory = {}
    storageBroken = false
    try {
      localStorage.removeItem(KEY)
    } catch {
      // unavailable
    }
  },
}
