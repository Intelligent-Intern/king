import { describe, expect, it } from 'vitest'
import {
  buildSessionFromLogin,
  normalizeDisplayName,
  parseStoredSession,
  persistSessionIdentity,
  restorePersistedSession,
  type StorageLike,
} from './authSession'

class MemoryStorage implements StorageLike {
  private readonly values = new Map<string, string>()

  getItem(key: string): string | null {
    return this.values.get(key) ?? null
  }

  setItem(key: string, value: string): void {
    this.values.set(key, value)
  }

  removeItem(key: string): void {
    this.values.delete(key)
  }
}

describe('authSession', () => {
  it('normalizes display names to trimmed single-space values', () => {
    expect(normalizeDisplayName('  Ada   Lovelace  ')).toBe('Ada Lovelace')
    expect(normalizeDisplayName('   ')).toBe('')
  })

  it('parses only valid stored sessions', () => {
    const valid = JSON.stringify({
      userId: 'u-123',
      name: '  Jochen  Admin ',
      color: '#0f62fe',
      token: 'token-123',
    })
    expect(parseStoredSession(valid)).toEqual({
      userId: 'u-123',
      name: 'Jochen Admin',
      color: '#0f62fe',
      token: 'token-123',
    })
    expect(parseStoredSession('{"userId":1}')).toBeNull()
    expect(parseStoredSession(null)).toBeNull()
  })

  it('persists and restores session identity in storage', () => {
    const storage = new MemoryStorage()
    persistSessionIdentity(storage, {
      userId: 'u-abc',
      name: 'Ada',
      color: '#123456',
      token: 'token-abc',
    })

    expect(restorePersistedSession(storage)).toEqual({
      userId: 'u-abc',
      name: 'Ada',
      color: '#123456',
      token: 'token-abc',
    })

    persistSessionIdentity(storage, null)
    expect(restorePersistedSession(storage)).toBeNull()
  })

  it('builds session from login payload with sanitized fallback', () => {
    const session = buildSessionFromLogin(
      {
        session: {
          userId: ' ',
          name: '  Test   User ',
          color: '',
          token: ' token-fallback ',
        },
      },
      {
        userId: 'u-fallback',
        name: 'Fallback Name',
        color: '#abcdef',
        token: '',
      }
    )

    expect(session).toEqual({
      userId: 'u-fallback',
      name: 'Test User',
      color: '#abcdef',
      token: 'token-fallback',
    })
  })
})
