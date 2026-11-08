import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import type { paths } from '../api/schema'
import { session } from './session'

export type Me = paths['/me']['get']['responses']['200']['content']['application/json']
type LoginResponse = paths['/auth/login']['post']['responses']['200']['content']['application/json']

export type SessionStatus = 'loading' | 'signed-in' | 'signed-out'

export type SessionValue = {
  status: SessionStatus
  me: Me | null
  signIn(email: string, password: string): Promise<void>
  signOut(): Promise<void>
  signOutEverywhere(): Promise<void>
  /** Replaces the cached profile, for example with the response to a profile edit. */
  updateMe(me: Me): void
}

const SessionContext = createContext<SessionValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState<SessionStatus>('loading')
  const [me, setMe] = useState<Me | null>(null)
  const started = useRef(false)

  const loadMe = useCallback(async () => {
    const { data } = await api<Me>('GET', '/v1/me')
    setMe(data)
    setStatus('signed-in')
  }, [])

  useEffect(() => {
    // Refresh failures elsewhere (an expired session mid-use) sign the UI out.
    const off = session.subscribe((token) => {
      if (token === null) {
        setMe(null)
        setStatus('signed-out')
      }
    })
    if (!started.current) {
      started.current = true
      void (async () => {
        const token = await session.refresh()
        if (!token) return
        try {
          await loadMe()
        } catch {
          session.setToken(null)
        }
      })()
    }
    return off
  }, [loadMe])

  const signIn = useCallback(
    async (email: string, password: string) => {
      const { data } = await api<LoginResponse>('POST', '/v1/auth/login', { body: { email, password }, auth: false })
      session.setToken(data.access_token)
      try {
        await loadMe()
      } catch (e) {
        session.setToken(null)
        throw e
      }
    },
    [loadMe],
  )

  const end = useCallback(
    async (path: string) => {
      try {
        await api('POST', path)
      } catch {
        // Clear local state even if the request fails.
      }
      session.setToken(null)
      setMe(null)
      setStatus('signed-out')
      queryClient.clear()
    },
    [queryClient],
  )

  const signOut = useCallback(() => end('/v1/auth/logout'), [end])
  const signOutEverywhere = useCallback(() => end('/v1/auth/logout-all'), [end])

  const value = useMemo(
    () => ({ status, me, signIn, signOut, signOutEverywhere, updateMe: setMe }),
    [status, me, signIn, signOut, signOutEverywhere],
  )
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): SessionValue {
  const ctx = useContext(SessionContext)
  if (!ctx) throw new Error('useSession must be used inside SessionProvider')
  return ctx
}
