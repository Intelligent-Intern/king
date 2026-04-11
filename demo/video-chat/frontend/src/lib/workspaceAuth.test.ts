import { describe, expect, it } from 'vitest'
import { hasAuthenticatedSession } from './workspaceAuth'

describe('workspaceAuth', () => {
  it('accepts a complete session identity', () => {
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: 'Ada',
      color: '#0f62fe',
    })).toBe(true)
  })

  it('rejects missing or blank session fields', () => {
    expect(hasAuthenticatedSession(null)).toBe(false)
    expect(hasAuthenticatedSession({
      userId: '',
      name: 'Ada',
      color: '#0f62fe',
    })).toBe(false)
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: ' ',
      color: '#0f62fe',
    })).toBe(false)
    expect(hasAuthenticatedSession({
      userId: 'u-123',
      name: 'Ada',
      color: '  ',
    })).toBe(false)
  })
})
