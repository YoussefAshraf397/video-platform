import { useState, type FormEvent } from 'react'
import { api, ApiError } from '../../api/client'
import { splitProblem } from '../../api/fieldErrors'
import { Button } from '../../ui/Button'
import { Field } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import styles from './Account.module.css'

export function ResendVerification() {
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
      await api('POST', '/v1/auth/email/resend', { body: { email }, auth: false })
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

  if (sent) return <p className={styles.success}>If that email has an unverified account, we've sent a new link.</p>

  return (
    <form className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
      <Field label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} />
      {problem && <ProblemMessage problem={problem} />}
      <Button variant="primary" type="submit" disabled={busy}>
        {busy ? 'Sending…' : 'Send a new link'}
      </Button>
    </form>
  )
}
