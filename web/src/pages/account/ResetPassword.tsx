import { useRef, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, ApiError } from '../../api/client'
import { splitProblem } from '../../api/fieldErrors'
import { Button } from '../../ui/Button'
import { Field } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useFocusFirstInvalid } from '../../ui/useFocusFirstInvalid'
import styles from './Account.module.css'

export function ResetPassword() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const form = useRef<HTMLFormElement>(null)
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
      await api('POST', '/v1/auth/password/reset', { body: { token, password }, auth: false })
      setDone(true)
    } catch (err) {
      const p = toProblem(err)
      const split = splitProblem(p, ['password'])
      setErrors(split.fields)
      setProblem(split.problem)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={styles.auth}>
      <h1>Reset password</h1>
      {!token ? (
        <>
          <p className={styles.success}>This link is missing its token. Request a new one</p>
          <Link to="/forgot-password">Request a new link</Link>
        </>
      ) : done ? (
        <>
          <p className={styles.success}>Password updated</p>
          <Link to="/sign-in">Sign in</Link>
        </>
      ) : (
        <form ref={form} className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
          <Field
            label="New password"
            type="password"
            autoComplete="new-password"
            hint="Use at least 10 characters."
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={errors.password}
          />
          {problem && (
            <>
              <ProblemMessage problem={problem} />
              {problem.code === 'INVALID_RESET_TOKEN' && <Link to="/forgot-password">Request a new link</Link>}
            </>
          )}
          <Button variant="primary" type="submit" disabled={busy}>
            {busy ? 'Saving…' : 'Set new password'}
          </Button>
        </form>
      )}
    </div>
  )
}
