import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../../api/client'
import { splitProblem } from '../../api/fieldErrors'
import { Button } from '../../ui/Button'
import { Field } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import styles from './Account.module.css'

export function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)
  const [problem, setProblem] = useState<ApiError | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setProblem(null)
    setErrors({})
    try {
      await api('POST', '/v1/auth/password/forgot', { body: { email }, auth: false })
      setSent(true)
    } catch (err) {
      const p = toProblem(err)
      const split = splitProblem(p, ['email'])
      setErrors(split.fields)
      setProblem(split.problem)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={styles.auth}>
      <h1>Forgot password</h1>
      {sent ? (
        <p className={styles.success}>If that email has an account, we've sent a reset link. Check your inbox.</p>
      ) : (
        <form className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
          <Field label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} />
          {problem && <ProblemMessage problem={problem} />}
          <Button variant="primary" type="submit" disabled={busy}>
            {busy ? 'Sending…' : 'Send reset link'}
          </Button>
        </form>
      )}
      <div className={styles.links}>
        <Link to="/sign-in">Back to sign in</Link>
      </div>
    </div>
  )
}
