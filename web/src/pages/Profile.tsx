import { useParams } from 'react-router'
import { Placeholder } from './Placeholder'
import { Profile } from './viewer/Profile'

/** The handle from a `/@handle` URL, without the "@". Empty when the segment isn't a handle. */
export function useProfileHandle(): string {
  const { at = '' } = useParams()
  return at.startsWith('@') ? at.slice(1) : ''
}

/** Renders the profile for `/@handle`, and Not found for any other single segment. */
export function ProfileRoute() {
  const handle = useProfileHandle()
  return handle ? <Profile handle={handle} /> : <Placeholder name="Not found" />
}
