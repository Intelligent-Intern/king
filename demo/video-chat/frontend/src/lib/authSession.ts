export interface SessionIdentity {
  userId: string
  name: string
  color: string
  token: string
}

export interface LoginPayload {
  session?: Partial<SessionIdentity> | null
}

export interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export const SESSION_STORAGE_KEY = 'king.video.chat.session.v2'
const DEFAULT_ACCENT_COLOR = '#0f62fe'
const DISPLAY_NAME_MAX = 48

export function normalizeDisplayName(value: string): string {
  return value.trim().replace(/\s+/g, ' ').slice(0, DISPLAY_NAME_MAX)
}

export function parseStoredSession(raw: string | null): SessionIdentity | null {
  if (!raw) {
    return null
  }

  try {
    const parsed = JSON.parse(raw)
    if (
      typeof parsed?.userId === 'string'
      && typeof parsed?.name === 'string'
      && typeof parsed?.color === 'string'
      && typeof parsed?.token === 'string'
      && parsed.userId.trim() !== ''
      && parsed.name.trim() !== ''
      && parsed.color.trim() !== ''
      && parsed.token.trim() !== ''
    ) {
      return {
        userId: parsed.userId,
        name: normalizeDisplayName(parsed.name),
        color: parsed.color,
        token: parsed.token,
      }
    }
  } catch {
    return null
  }

  return null
}

export function restorePersistedSession(storage: Pick<StorageLike, 'getItem'>): SessionIdentity | null {
  return parseStoredSession(storage.getItem(SESSION_STORAGE_KEY))
}

export function persistSessionIdentity(
  storage: Pick<StorageLike, 'setItem' | 'removeItem'>,
  session: SessionIdentity | null
): void {
  if (!session) {
    storage.removeItem(SESSION_STORAGE_KEY)
    return
  }

  storage.setItem(SESSION_STORAGE_KEY, JSON.stringify(session))
}

export function buildSessionFromLogin(
  payload: LoginPayload | null,
  fallback: { userId?: string | null; name: string; color: string; token?: string | null }
): SessionIdentity {
  const fallbackUserId = normalizeUserId(fallback.userId)
  const payloadSession = payload?.session ?? null
  const userId = normalizeUserId(payloadSession?.userId) || fallbackUserId || generateUserId()
  const name = normalizeDisplayName(String(payloadSession?.name ?? fallback.name))
  const color = normalizeColor(String(payloadSession?.color ?? ''), fallback.color)
  const token = normalizeToken(String(payloadSession?.token ?? fallback.token ?? ''))

  return {
    userId,
    name: name || 'User',
    color,
    token,
  }
}

function normalizeUserId(value: unknown): string {
  if (typeof value !== 'string') {
    return ''
  }
  return value.trim()
}

function normalizeColor(value: string, fallbackColor = DEFAULT_ACCENT_COLOR): string {
  const color = value.trim()
  const fallback = fallbackColor.trim()
  return color || fallback || DEFAULT_ACCENT_COLOR
}

function normalizeToken(value: string): string {
  return value.trim()
}

function generateUserId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `u-${crypto.randomUUID().slice(0, 8)}`
  }

  const entropy = Math.random().toString(36).slice(2, 10)
  return `u-${entropy}`
}
