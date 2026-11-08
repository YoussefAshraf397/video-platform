import { useEffect, type RefObject } from 'react'

/** After a failed submit, moves focus to the first invalid field in form order. */
export function useFocusFirstInvalid(form: RefObject<HTMLFormElement | null>, errors: Record<string, string>) {
  useEffect(() => {
    if (Object.keys(errors).length === 0) return
    form.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [form, errors])
}
