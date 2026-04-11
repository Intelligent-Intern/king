import { describe, expect, it } from 'vitest'
import { DEFAULT_RECONNECT_STRATEGY, planReconnectAttempt } from './socketReconnect'

describe('socketReconnect helpers', () => {
  it('returns bounded exponential delays for reconnect attempts', () => {
    expect(planReconnectAttempt(0)).toEqual({ attempt: 1, delayMs: 500 })
    expect(planReconnectAttempt(1)).toEqual({ attempt: 2, delayMs: 1000 })
    expect(planReconnectAttempt(2)).toEqual({ attempt: 3, delayMs: 2000 })
    expect(planReconnectAttempt(3)).toEqual({ attempt: 4, delayMs: 4000 })
    expect(planReconnectAttempt(4)).toEqual({ attempt: 5, delayMs: 4000 })
  })

  it('returns null after the configured max attempts', () => {
    expect(planReconnectAttempt(DEFAULT_RECONNECT_STRATEGY.maxAttempts)).toBeNull()
  })

  it('normalizes malformed attempt counters safely', () => {
    expect(planReconnectAttempt(-10)).toEqual({ attempt: 1, delayMs: 500 })
    expect(planReconnectAttempt(Number.NaN)).toEqual({ attempt: 1, delayMs: 500 })
  })

  it('supports custom reconnect strategies', () => {
    const strategy = {
      maxAttempts: 3,
      baseDelayMs: 200,
      maxDelayMs: 700,
    }

    expect(planReconnectAttempt(0, strategy)).toEqual({ attempt: 1, delayMs: 200 })
    expect(planReconnectAttempt(1, strategy)).toEqual({ attempt: 2, delayMs: 400 })
    expect(planReconnectAttempt(2, strategy)).toEqual({ attempt: 3, delayMs: 700 })
    expect(planReconnectAttempt(3, strategy)).toBeNull()
  })
})
