export interface ReconnectStrategy {
  maxAttempts: number
  baseDelayMs: number
  maxDelayMs: number
}

export interface ReconnectPlan {
  attempt: number
  delayMs: number
}

export const DEFAULT_RECONNECT_STRATEGY: ReconnectStrategy = Object.freeze({
  maxAttempts: 8,
  baseDelayMs: 500,
  maxDelayMs: 4000,
})

export function planReconnectAttempt(
  previousAttempts: number,
  strategy: ReconnectStrategy = DEFAULT_RECONNECT_STRATEGY
): ReconnectPlan | null {
  const priorAttempts = Number.isFinite(previousAttempts) ? Math.max(0, Math.trunc(previousAttempts)) : 0
  const attempt = priorAttempts + 1

  if (attempt > strategy.maxAttempts) {
    return null
  }

  const exponentialDelay = strategy.baseDelayMs * (2 ** (attempt - 1))
  const delayMs = Math.min(strategy.maxDelayMs, exponentialDelay)

  return { attempt, delayMs }
}
