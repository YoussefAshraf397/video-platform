import type { ButtonHTMLAttributes } from 'react'
import styles from './Button.module.css'

export type ButtonVariant = 'primary' | 'secondary' | 'quiet'

export function buttonClass(variant: ButtonVariant, extra?: string) {
  return [styles.button, styles[variant], extra].filter(Boolean).join(' ')
}

type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant: ButtonVariant }

export function Button({ variant, className, type = 'button', ...rest }: Props) {
  return <button type={type} className={buttonClass(variant, className)} {...rest} />
}
