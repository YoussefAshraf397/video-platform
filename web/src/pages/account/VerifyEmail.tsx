import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, ApiError } from '../../api/client'
import { useSession } from '../../auth/SessionProvider'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { ResendVerification } from './ResendVerification'
import styles from './Account.module.css'

type State = { kind: 'working' } | { kind: 'done' } | { kind: 'failed'; problem: ApiError }

export function VerifyEmail() {
  const [params] = useSearchParams()
  const token = params.get('token')
  const { status } = useSession()
  const [state, setState] = useState<State>(
    token ? { kind: 'working' } : { kind: 'failed', problem: new ApiError({ status: 0, code: 'INVALID_VERIFICATION_TOKEN', title: '', detail: null, errors: [] }) },
  )
  // The token works once, so guard against running twice (Strict Mode runs effects twice in development).
  const started = useRef(false)

  useEffect(() => {
    if (!token || started.current) return
    started.current = true
    api('POST', '/v1/auth/email/verify', { body: { token }, auth: false }).then(
      () => setState({ kind: 'done' }),
      (e: unknown) => setState({ kind: 'failed', problem: toProblem(e) }),
    )
  }, [token])

  return (
    <div className={styles.auth}>
      <h1>Verify email</h1>
      {state.kind === 'working' && <p className={styles.note}>Checking your link…</p>}
      {state.kind === 'done' && (
        <>
          <p className={styles.success}>Email verified</p>
          {status === 'signed-in' ? <Link to="/studio">Go to your studio</Link> : <Link to="/sign-in">Sign in</Link>}
        </>
      )}
      {state.kind === 'failed' &&
        (state.problem.code === 'INVALID_VERIFICATION_TOKEN' ? (
          <>
            <p className={styles.success}>This link has expired. Send a new one</p>
            <ResendVerification />
          </>
        ) : (
          <ProblemMessage problem={state.problem} />
        ))}
    </div>
  )
}
