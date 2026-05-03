/**
 * Ops-lane carrier state machine.
 *
 * Carrier loss is the default ops-lane reconnect signal. Measured publisher
 * transport stalls can still escalate through the backpressure controller, but
 * those callers must carry explicit diagnostics and bounded thresholds.
 *
 * States:
 * - connected: ops heartbeats and acks are healthy.
 * - degraded: missed heartbeats but not yet lost.
 * - lost: missed heartbeats or ack quorum failure for the carrier-loss window.
 *
 * Carrier-initiated reconnect is allowed only when state transitions to
 * `lost`.
 */

export type CarrierState = 'connected' | 'degraded' | 'lost'

export interface CarrierStateOptions {
  heartbeatIntervalMs?: number
  degradedAfterMissed?: number
  lostAfterMissed?: number
  ackQuorumWindowMs?: number
}

export interface CarrierStateChange {
  previous: CarrierState
  current: CarrierState
  reason: string
  timestampMs: number
}

const DEFAULT_HEARTBEAT_INTERVAL_MS = 1000
const DEFAULT_DEGRADED_AFTER_MISSED = 3
const DEFAULT_LOST_AFTER_MISSED = 6
const DEFAULT_ACK_QUORUM_WINDOW_MS = 8000

export class SfuCarrierState {
  private state: CarrierState = 'connected'
  private missedHeartbeats = 0
  private lastHeartbeatSentAtMs = 0
  private lastHeartbeatReceivedAtMs = 0
  private lastAckReceivedAtMs = 0
  private heartbeatIntervalMs: number
  private degradedAfterMissed: number
  private lostAfterMissed: number
  private ackQuorumWindowMs: number
  private stateChangeListeners: Array<(change: CarrierStateChange) => void> = []

  constructor(options: CarrierStateOptions = {}) {
    this.heartbeatIntervalMs = options.heartbeatIntervalMs ?? DEFAULT_HEARTBEAT_INTERVAL_MS
    this.degradedAfterMissed = options.degradedAfterMissed ?? DEFAULT_DEGRADED_AFTER_MISSED
    this.lostAfterMissed = options.lostAfterMissed ?? DEFAULT_LOST_AFTER_MISSED
    this.ackQuorumWindowMs = options.ackQuorumWindowMs ?? DEFAULT_ACK_QUORUM_WINDOW_MS
  }

  getState(): CarrierState {
    return this.state
  }

  isConnected(): boolean {
    return this.state === 'connected'
  }

  isDegraded(): boolean {
    return this.state === 'degraded'
  }

  isLost(): boolean {
    return this.state === 'lost'
  }

  canRequestReconnect(): boolean {
    return this.state === 'lost'
  }

  /**
   * Record that an ops heartbeat was sent.
   */
  heartbeatSent(nowMs = Date.now()): void {
    this.lastHeartbeatSentAtMs = nowMs
  }

  /**
   * Record that an ops heartbeat was received.
   */
  heartbeatReceived(nowMs = Date.now()): void {
    this.lastHeartbeatReceivedAtMs = nowMs
    this.missedHeartbeats = 0
    this.transitionIfNeeded('connected', 'heartbeat_received', nowMs)
  }

  /**
   * Record that an ops ack was received.
   */
  ackReceived(nowMs = Date.now()): void {
    this.lastAckReceivedAtMs = nowMs
  }

  /**
   * Check for missed heartbeats. Call this periodically.
   */
  checkHeartbeat(nowMs = Date.now()): void {
    if (this.state === 'lost') return

    const timeSinceLastReceived = this.lastHeartbeatReceivedAtMs > 0
      ? nowMs - this.lastHeartbeatReceivedAtMs
      : Infinity

    if (timeSinceLastReceived > this.heartbeatIntervalMs * this.lostAfterMissed) {
      this.missedHeartbeats = this.lostAfterMissed
      this.transitionIfNeeded('lost', 'heartbeat_timeout', nowMs)
      return
    }

    if (timeSinceLastReceived > this.heartbeatIntervalMs * this.degradedAfterMissed) {
      this.missedHeartbeats = Math.floor(timeSinceLastReceived / this.heartbeatIntervalMs)
      this.transitionIfNeeded('degraded', 'heartbeat_degraded', nowMs)
      return
    }

    this.missedHeartbeats = 0
    this.transitionIfNeeded('connected', 'heartbeat_healthy', nowMs)
  }

  /**
   * Check for ack quorum failure.
   */
  checkAckQuorum(nowMs = Date.now()): void {
    if (this.state === 'lost') return

    const timeSinceLastAck = this.lastAckReceivedAtMs > 0
      ? nowMs - this.lastAckReceivedAtMs
      : Infinity

    if (timeSinceLastAck > this.ackQuorumWindowMs) {
      this.transitionIfNeeded('lost', 'ack_quorum_failed', nowMs)
    }
  }

  /**
   * Handle ops WebSocket close.
   */
  socketClosed(nowMs = Date.now()): void {
    this.transitionIfNeeded('lost', 'socket_closed', nowMs)
  }

  /**
   * Handle server revocation or auth failure.
   */
  serverRevocation(reason = 'server_revocation', nowMs = Date.now()): void {
    this.transitionIfNeeded('lost', reason, nowMs)
  }

  /**
   * Reset to connected state (e.g., after reconnect).
   */
  reset(nowMs = Date.now()): void {
    this.missedHeartbeats = 0
    this.lastHeartbeatReceivedAtMs = nowMs
    this.lastAckReceivedAtMs = nowMs
    this.transitionIfNeeded('connected', 'reset', nowMs)
  }

  /**
   * Register a listener for state changes.
   */
  onChange(callback: (change: CarrierStateChange) => void): () => void {
    this.stateChangeListeners.push(callback)
    return () => {
      this.stateChangeListeners = this.stateChangeListeners.filter((cb) => cb !== callback)
    }
  }

  private transitionIfNeeded(targetState: CarrierState, reason: string, nowMs: number): void {
    const previous = this.state
    if (previous === targetState) return

    if (previous === 'lost' && targetState !== 'lost') {
      return
    }

    this.state = targetState
    const change: CarrierStateChange = {
      previous,
      current: targetState,
      reason,
      timestampMs: nowMs,
    }
    for (const listener of this.stateChangeListeners) {
      try {
        listener(change)
      } catch {
      }
    }
  }

  getDiagnostics(): Record<string, unknown> {
    return {
      lane: 'ops',
      carrier_state: this.state,
      missed_heartbeats: this.missedHeartbeats,
      last_heartbeat_sent_at_ms: this.lastHeartbeatSentAtMs,
      last_heartbeat_received_at_ms: this.lastHeartbeatReceivedAtMs,
      last_ack_received_at_ms: this.lastAckReceivedAtMs,
      heartbeat_interval_ms: this.heartbeatIntervalMs,
      degraded_after_missed: this.degradedAfterMissed,
      lost_after_missed: this.lostAfterMissed,
      ack_quorum_window_ms: this.ackQuorumWindowMs,
    }
  }
}
