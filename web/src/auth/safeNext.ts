const UNSAFE = /[\\\s\u0000-\u001f\u007f]/

/** A same-site path to return to after sign-in, or /studio when the value could lead elsewhere. */
export function safeNext(value: string | null): string {
  const fallback = '/studio'
  if (!value || !value.startsWith('/') || value.startsWith('//')) return fallback
  try {
    // Check the decoded form too: "%09" and "%5c" are stripped or flipped by some parsers.
    if (UNSAFE.test(value) || UNSAFE.test(decodeURIComponent(value))) return fallback
    const url = new URL(value, window.location.origin)
    if (url.origin !== window.location.origin) return fallback
    return url.pathname + url.search + url.hash
  } catch {
    return fallback
  }
}
