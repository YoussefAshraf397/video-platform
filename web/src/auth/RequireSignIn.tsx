import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { useSession } from './SessionProvider'

/** Renders its children only when signed in; otherwise sends the person to sign in and back. */
export function RequireSignIn({ children }: { children: ReactNode }) {
  const { status } = useSession()
  const { pathname, search } = useLocation()
  if (status === 'loading') return <p style={{ padding: '2rem 1rem' }}>Loading</p>
  if (status === 'signed-out') {
    const next = encodeURIComponent(pathname + search).replace(/%2F/g, '/')
    return <Navigate to={`/sign-in?next=${next}`} replace />
  }
  return <>{children}</>
}
