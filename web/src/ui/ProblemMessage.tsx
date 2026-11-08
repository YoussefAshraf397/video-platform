import { ApiError } from '../api/client'
import styles from './ProblemMessage.module.css'

/** Turns anything thrown by a request into something we can show. */
export function toProblem(e: unknown): ApiError {
  if (e instanceof ApiError) return e
  return new ApiError({
    status: 0,
    code: null,
    title: 'Could not reach the server',
    detail: 'Check your connection and try again.',
    errors: [],
  })
}

export function ProblemMessage({ problem }: { problem: ApiError }) {
  return (
    <div role="alert" className={styles.problem}>
      <p className={styles.title}>{problem.title}</p>
      {problem.detail && <p className={styles.detail}>{problem.detail}</p>}
    </div>
  )
}
