import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet } from 'react-router'
import { buttonClass } from '../ui/Button'
import styles from './AppShell.module.css'

export type ShellUser = { handle: string; display_name: string }

type Props = {
  /** null or undefined means signed out. */
  user?: ShellUser | null
  onSignOut?: () => void
}

/** A disclosure: a button that shows or hides a panel. Closes on Escape, outside click, or focus leaving. */
function MenuButton({
  label,
  className,
  menuClassName,
  children,
  panel,
}: {
  label: string
  className: string
  menuClassName: string
  children: ReactNode
  panel: (close: () => void) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const wrap = useRef<HTMLDivElement>(null)
  const trigger = useRef<HTMLButtonElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        trigger.current?.focus()
      }
    }
    const onClick = (e: MouseEvent) => {
      if (!wrap.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('mousedown', onClick)
    }
  }, [open])

  return (
    <div
      className={styles.menuWrap}
      ref={wrap}
      onBlur={(e) => {
        // Only keyboard focus moving elsewhere closes here. A null relatedTarget
        // means a click on non-focusable content (or Safari, which doesn't focus
        // clicked links); the document mousedown listener handles outside clicks.
        const next = e.relatedTarget as Node | null
        if (open && next && !wrap.current?.contains(next)) setOpen(false)
      }}
    >
      <button
        ref={trigger}
        type="button"
        className={className}
        aria-label={label}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        onClick={() => setOpen((o) => !o)}
      >
        {children}
      </button>
      {open && (
        <div id={panelId} className={menuClassName}>
          {panel(() => setOpen(false))}
        </div>
      )}
    </div>
  )
}

export function AppShell({ user, onSignOut }: Props) {
  return (
    <>
      <header className={styles.header}>
        <nav className={styles.nav} aria-label="Main">
          <Link to="/" className={styles.logo}>
            reel
          </Link>
          <NavLink to="/" end className={styles.link}>
            Home
          </NavLink>
          <MenuButton
            label="Categories"
            className={styles.link}
            menuClassName={`${styles.menu} ${styles.menuStart}`}
            panel={() => <p className={styles.empty}>Categories load here soon</p>}
          >
            Categories
          </MenuButton>
          <span className={styles.grow} />
          <div className={styles.actions}>
            {user ? (
              <>
                <Link to="/studio/upload" className={buttonClass('primary')}>
                  Upload
                </Link>
                <MenuButton
                  label="Account menu"
                  className={styles.avatar}
                  menuClassName={`${styles.menu} ${styles.menuEnd}`}
                  panel={(close) => (
                    <ul className={styles.list}>
                      <li>
                        <Link to="/studio" className={styles.menuItem} onClick={close}>
                          Studio
                        </Link>
                      </li>
                      <li>
                        <Link to="/settings" className={styles.menuItem} onClick={close}>
                          Settings
                        </Link>
                      </li>
                      <li>
                        <button
                          type="button"
                          className={styles.menuItem}
                          onClick={() => {
                            close()
                            onSignOut?.()
                          }}
                        >
                          Sign out
                        </button>
                      </li>
                    </ul>
                  )}
                >
                  <span aria-hidden="true">{user.display_name.trim().charAt(0).toUpperCase()}</span>
                </MenuButton>
              </>
            ) : (
              <>
                <Link to="/sign-in" className={buttonClass('secondary')}>
                  Sign in
                </Link>
                <Link to="/studio/upload" className={buttonClass('primary')}>
                  Upload
                </Link>
              </>
            )}
          </div>
        </nav>
      </header>
      <main className={styles.main}>
        <Outlet />
      </main>
    </>
  )
}
