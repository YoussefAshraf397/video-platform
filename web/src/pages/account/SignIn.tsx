import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import type { ApiError } from '../../api/client'
import { useSession } from '../../auth/SessionProvider'
import { safeNext } from '../../auth/safeNext'
import { Button } from '../../ui/Button'
import { Field } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import styles from './Account.module.css'

export function SignIn() {
  const { signIn } = useSession()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<ApiError | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setProblem(null)
    try {
      await signIn(email, password)
      void navigate(safeNext(params.get('next')), { replace: true })
    } catch (err) {
      setProblem(toProblem(err))
      setBusy(false)
    }
  }

  return (
    <div className={styles.auth}>
      <h1>Sign in</h1>
      <form className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
        <Field label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        <Field label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        {problem && <ProblemMessage problem={problem} />}
        <Button variant="primary" type="submit" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </Button>
      </form>
      <div className={styles.links}>
        <Link to="/forgot-password">Forgot your password?</Link>
        <Link to="/sign-up">Create an account</Link>
      </div>
    </div>
  )
}
