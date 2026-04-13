import { describe, expect, it } from 'vitest'
import { hasAuthenticatedSession } from './workspaceAuth'

describe('workspaceAuth', () => {
  it('accepts a complete session identity', () => {
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: 'Ada',
      color: '#0f62fe',
      token: 'token-123',
    })).toBe(true)
  })

  it('rejects missing or blank session fields', () => {
    expect(hasAuthenticatedSession(null)).toBe(false)
    expect(hasAuthenticatedSession({
      userId: '',
      name: 'Ada',
      color: '#0f62fe',
      token: 'token-123',
    })).toBe(false)
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: ' ',
      color: '#0f62fe',
      token: 'token-123',
    })).toBe(false)
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: 'Ada',
      color: '  ',
      token: 'token-123',
    })).toBe(false)
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: 'Ada',
      color: '#0f62fe',
      token: '   ',
    })).toBe(false)
  })
})
