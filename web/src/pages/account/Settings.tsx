import { useRef, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../api/client'
import { splitProblem } from '../../api/fieldErrors'
import { useSession, type Me } from '../../auth/SessionProvider'
import { Button } from '../../ui/Button'
import { Field, TextAreaField } from '../../ui/Field'
import { ProblemMessage, toProblem } from '../../ui/ProblemMessage'
import { useFocusFirstInvalid } from '../../ui/useFocusFirstInvalid'
import styles from './Account.module.css'

type SessionItem = {
  id: string
  user_agent: string | null
  ip_address: string | null
  created_at: string
  last_used_at: string
  current: boolean
}

function ProfileForm({ me }: { me: Me }) {
  const { updateMe } = useSession()
  const form = useRef<HTMLFormElement>(null)
  const [displayName, setDisplayName] = useState(me.display_name)
  const [handle, setHandle] = useState(me.handle)
  const [bio, setBio] = useState(me.bio ?? '')
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)
  const [problem, setProblem] = useState<ApiError | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  useFocusFirstInvalid(form, errors)

  async function submit(e: FormEvent) {
    e.preventDefault()
    const changes: Record<string, string | null> = {}
    if (displayName !== me.display_name) changes.display_name = displayName
    if (handle !== me.handle) changes.handle = handle
    if (bio !== (me.bio ?? '')) changes.bio = bio === '' ? null : bio
    setProblem(null)
    setErrors({})
    setSaved(false)
    if (Object.keys(changes).length === 0) return
    setBusy(true)
    try {
      const { data } = await api<Me>('PATCH', '/v1/me', { body: changes })
      updateMe(data)
      setSaved(true)
    } catch (err) {
      const p = toProblem(err)
      const split = splitProblem(p, ['display_name', 'handle', 'bio'])
      setErrors(split.fields)
      setProblem(split.problem)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form ref={form} className={styles.form} onSubmit={(e) => void submit(e)} noValidate>
      <Field
        label="Display name"
        value={displayName}
        onChange={(e) => setDisplayName(e.target.value)}
        error={errors.display_name}
      />
      <Field
        label="Handle"
        hint="Letters, numbers, underscores and dots. 3 to 30 characters."
        autoCapitalize="none"
        spellCheck={false}
        value={handle}
        onChange={(e) => setHandle(e.target.value)}
        error={errors.handle}
      />
      <TextAreaField label="Bio" value={bio} onChange={(e) => setBio(e.target.value)} error={errors.bio} />
      {problem && <ProblemMessage problem={problem} />}
      <div style={{ display: 'flex', gap: '1rem', alignItems: 'center' }}>
        <Button variant="primary" type="submit" disabled={busy}>
          {busy ? 'Saving…' : 'Save changes'}
        </Button>
        <span role="status" className={styles.success}>
          {saved ? 'Saved' : ''}
        </span>
      </div>
    </form>
  )
}

function Sessions() {
  const { signOut, signOutEverywhere } = useSession()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const list = useQuery({
    queryKey: ['sessions'],
    queryFn: async () => (await api<{ items: SessionItem[] }>('GET', '/v1/auth/sessions')).data.items,
  })
  const revoke = useMutation({
    mutationFn: (id: string) => api('DELETE', `/v1/auth/sessions/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sessions'] }),
  })

  const leaving = useRef(false)
  async function leave(everywhere: boolean) {
    if (leaving.current) return
    leaving.current = true
    // Go home first (replacing this page) so Back doesn't land on the sign-in redirect.
    void navigate('/', { replace: true })
    await (everywhere ? signOutEverywhere() : signOut())
  }

  const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })

  return (
    <>
      {list.isPending && <p className={styles.note}>Loading sessions</p>}
      {list.isError && <ProblemMessage problem={toProblem(list.error)} />}
      {list.data && (
        <>
          {revoke.isError && <ProblemMessage problem={toProblem(revoke.error)} />}
          <ul className={styles.sessions}>
            {list.data?.map((s) => {
              const name = s.user_agent ?? 'Unknown device'
              return (
                <li key={s.id} className={styles.session}>
                  <div>
                    <p>{name}</p>
                    <p className={styles.meta}>
                      {s.ip_address ? `${s.ip_address} · ` : ''}Last used {when(s.last_used_at)}
                    </p>
                    {s.current && <p className={styles.badge}>This device</p>}
                  </div>
                  <Button
                    variant="secondary"
                    aria-label={`Sign out ${name}`}
                    disabled={revoke.isPending && revoke.variables === s.id}
                    onClick={() => (s.current ? void leave(false) : revoke.mutate(s.id))}
                  >
                    Sign out
                  </Button>
                </li>
              )
            })}
          </ul>
          <Button variant="quiet" onClick={() => void leave(true)}>
            Sign out everywhere
          </Button>
        </>
      )}
    </>
  )
}

export function Settings() {
  const { me } = useSession()
  return (
    <div className={styles.settings}>
      <h1>Settings</h1>
      <section>
        <h2>Profile</h2>
        {me && <ProfileForm me={me} />}
      </section>
      <section>
        <h2>Sessions</h2>
        <Sessions />
      </section>
    </div>
  )
}
