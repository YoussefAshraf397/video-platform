import { useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../../api/client'
import { splitProblem } from '../../api/fieldErrors'
import { Button } from '../../ui/Button'
import { Field } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useFocusFirstInvalid } from '../../ui/useFocusFirstInvalid'
import styles from './Account.module.css'

export function SignUp() {
  const form = useRef<HTMLFormElement>(null)
  const [displayName, setDisplayName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
  const [problem, setProblem] = useState<ApiError | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  useFocusFirstInvalid(form, errors)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setProblem(null)
    setErrors({})
    try {
      await api('POST', '/v1/auth/register', { body: { email, password, display_name: displayName }, auth: false })
      setDone(true)
    } catch (err) {
      const p = toProblem(err)
      const split = splitProblem(p, ['display_name', 'email', 'password'])
      setErrors(split.fields)
      setProblem(split.problem)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={styles.auth}>
      <h1>Create an account</h1>
      {done ? (
        <>
          <p className={styles.success}>Check your inbox to verify your email</p>
          <p className={styles.note}>The link we sent works for a limited time. Once you've verified, you can sign in.</p>
        </>
      ) : (
        <form ref={form} className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
          <Field label="Display name" autoComplete="name" value={displayName} onChange={(e) => setDisplayName(e.target.value)} error={errors.display_name} />
          <Field label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} />
          <Field
            label="Password"
            type="password"
            autoComplete="new-password"
            hint="Use at least 10 characters."
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={errors.password}
          />
          {problem && <ProblemMessage problem={problem} />}
          <Button variant="primary" type="submit" disabled={busy}>
            {busy ? 'Creating account…' : 'Create account'}
          </Button>
        </form>
      )}
      <p className={styles.note}>
        Already have an account? <Link to="/sign-in">Sign in</Link>
      </p>
    </div>
  )
}
