import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterAll, afterEach, beforeAll, vi } from 'vitest'
import { resetSession } from '../auth/session'
import { server } from './msw'

beforeAll(() => server.listen({ onUnhandledFrame: 'error' }))
afterEach(() => {
  cleanup()
  server.resetHandlers()
  resetSession()
})
afterAll(() => server.close())

// jsdom's media element methods are "not implemented" stubs that log errors.
HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve())
