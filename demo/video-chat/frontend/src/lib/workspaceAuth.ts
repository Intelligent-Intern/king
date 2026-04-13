import type { SessionIdentity } from './authSession'

export function hasAuthenticatedSession(session: SessionIdentity | null): session is SessionIdentity {
  if (!session) {
    return false
  }

  return (
    session.userId.trim() !== ''
    && session.name.trim() !== ''
    && session.color.trim() !== ''
    && session.token.trim() !== ''
  )
}
