import { ApiError } from './client'

/** The first message for each field, keyed by the API's field name. */
export function fieldErrors(e: ApiError): Record<string, string> {
  const out: Record<string, string> = {}
  for (const item of e.errors) {
    if (!(item.field in out)) out[item.field] = item.message
  }
  return out
}

/**
 * Splits a problem into messages for the fields a form renders and messages for everything else.
 * `problem` is what to show as a form-level alert, or null when every error sits under its field.
 */
export function splitProblem(e: ApiError, rendered: string[]): { fields: Record<string, string>; problem: ApiError | null } {
  const all = fieldErrors(e)
  const fields: Record<string, string> = {}
  const leftover: string[] = []
  for (const [field, message] of Object.entries(all)) {
    if (rendered.includes(field)) fields[field] = message
    else leftover.push(message)
  }
  if (Object.keys(all).length === 0) return { fields, problem: e }
  if (leftover.length === 0) return { fields, problem: null }
  return {
    fields,
    problem: new ApiError({ status: e.status, code: e.code, title: e.title, detail: [e.detail, ...leftover].filter(Boolean).join(' '), errors: [] }),
  }
}
