/**
 * SFU signalling client.
 *
 * Connects to /sfu, announces the local publisher, and surfaces track events
 * from remote publishers.  WebRTC offer/answer/ICE still flows through the
 * existing /ws signalling channel — this client is solely responsible for
 * track discovery and subscription bookkeeping.
 */

import {
  buildWebSocketUrl,
  resolveBackendSfuOriginCandidates,
  setBackendSfuOrigin,
} from '../../support/backendOrigin'
import {
  appendAssetVersionQuery,
  handleAssetVersionConnectionFailure,
  handleAssetVersionSocketClose,
  handleAssetVersionSocketPayload,
} from '../../support/assetVersion'
import { reportClientDiagnostic } from '../../support/clientDiagnostics'
import { SfuInboundFrameAssembler, stringField } from './inboundFrameAssembler'
import { normalizeSfuIdentifier } from './identifiers'
import { handleSfuClientMessage } from './sfuMessageHandler'
import {
  SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS,
  SfuOutboundWireBudget,
  resolveSfuSendDrainTargetBytes,
  shouldDropProjectedSfuFrameForBufferBudget,
} from './outboundFrameBudget'
import {
  decodeSfuBinaryFrameEnvelope,
  encodeSfuBinaryFrameEnvelope,
  prepareSfuOutboundFramePayload,
  SFU_BINARY_CONTINUATION_THRESHOLD_BYTES,
  type PreparedSfuOutboundFramePayload,
} from './framePayload'
import { SfuOutboundFrameQueue } from './outboundFrameQueue'
import { buildSfuSendFailureDetails } from './sendFailureDetails'
import {
  SFU_CONTROL_TRANSPORT_WEBSOCKET,
  SfuWebSocketFallbackMediaTransport,
} from './mediaTransport'
import {
  appendSfuPublisherTraceStage,
  buildSfuEndToEndPerformancePayload,
  buildSfuFrameTransportSample,
  highResolutionNowMs,
  roundedTransportStageMs,
} from './sfuClientTransportSample'
import type {
  SFUClientCallbacks,
  SFUEncodedFrame,
  SFUTrack,
  SfuFrameTransportSample,
  SfuSendFailureDetails,
} from './sfuTypes'

export type {
  SFUClientCallbacks,
  SFUEncodedFrame,
  SFUTrack,
  SFUTracksEvent,
  SfuFrameTransportSample,
  SfuSendFailureDetails,
} from './sfuTypes'

/*
 * Compatibility contract note:
 * The delegated frame serializer in `framePayload.ts` must preserve the
 * canonical snake_case wire mapping that earlier inline implementations used:
 * publisher_id: frame.publisherId
 * publisher_user_id: frame.publisherUserId ||
 * track_id: frame.trackId
 * frame_type: frame.type
 * payload.protected_frame = frame.protectedFrame
 * payload.protection_mode = frame.protectionMode ||
 */

const SFU_FRAME_CHUNK_BACKPRESSURE_BYTES = 2 * 1024 * 1024
const SFU_FRAME_CHUNK_BACKPRESSURE_SLEEP_MS = 16
const SFU_FRAME_SEND_PRESSURE_DIAGNOSTIC_COOLDOWN_MS = 3000
const SFU_FRAME_CHUNK_DIAGNOSTIC_MIN_CHUNKS = 16
const SFU_FRAME_SEND_QUEUE_DIAGNOSTIC_COOLDOWN_MS = 1500
const SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS = 2000
const SFU_PUBLISHER_FRAME_STALL_CHECK_INTERVAL_MS = 1000
const SFU_PUBLISHER_FRAME_STALL_RESUBSCRIBE_AFTER_MS = 6000
const SFU_PUBLISHER_FRAME_STALL_RECOVERY_COOLDOWN_MS = 5000
const SFU_WEBSOCKET_NEGOTIATION_TIMEOUT_MS = 5 * 60 * 1000

interface SendBufferDrainResult {
  ok: boolean
  waitedMs: number
  bufferedAmount: number
  targetBufferedBytes: number
  maxWaitMs: number
}

interface PublisherFrameHealth {
  subscribedAtMs: number
  lastFrameAtMs: number
  lastRecoveryAtMs: number
  recoveryCount: number
}

export class SFUClient {
  private ws: WebSocket | null = null
  private cb: SFUClientCallbacks
  private roomId = ''
  private connectGeneration = 0
  private disconnectNotified = false
  private inboundFrameAssembler = new SfuInboundFrameAssembler({ getRoomId: () => this.roomId })
  private outboundFrameQueue: SfuOutboundFrameQueue
  private outboundWireBudget = new SfuOutboundWireBudget()
  private outboundFrameSequenceByTrack = new Map<string, number>()
  private outboundMediaGeneration = 0
  private lastFrameSendPressureDiagnosticAtMs = 0
  private lastFrameQueueDiagnosticAtMs = 0
  private lastFrameTransportSampleAtMs = 0
  private lastSendFailure: SfuSendFailureDetails | null = null
  private lastFrameTransportSample: SfuFrameTransportSample | null = null
  private publisherFrameHealthById = new Map<string, PublisherFrameHealth>()
  private publisherFrameStallTimer: ReturnType<typeof setInterval> | null = null
  private connectAttemptInFlight = false
  private mediaTransport: SfuWebSocketFallbackMediaTransport

  constructor(cb: SFUClientCallbacks) {
    this.cb = cb
    this.mediaTransport = new SfuWebSocketFallbackMediaTransport({
      getSocket: () => this.ws,
      getBufferedAmount: () => this.getWebSocketBufferedAmount(),
    })
    this.outboundFrameQueue = new SfuOutboundFrameQueue({
      canSend: () => this.isOpen(),
      sendPreparedFrame: (prepared, queuedAgeMs) => this.sendPreparedEncodedFrame(prepared, queuedAgeMs),
      reportFrameDiagnostic: (eventType, level, message, prepared, extraPayload = {}, immediate = false) => {
        this.reportOutboundFrameQueueDiagnostic(eventType, level, message, prepared, extraPayload, immediate)
      },
    })
  }

  private socketUrlForOrigin(origin: string, query: URLSearchParams): string | null {
    return buildWebSocketUrl(origin, '/sfu', query)
  }

  private notifyDisconnectOnce(): void {
    if (this.disconnectNotified) return
    this.disconnectNotified = true
    this.cb.onDisconnect()
  }

  private retireSocket(ws: WebSocket, closeConnecting = false): void {
    if (this.ws === ws) {
      this.ws = null
    }

    if (ws.readyState === WebSocket.CONNECTING && !closeConnecting) {
      return
    }

    try {
      ws.close()
    } catch {}
  }

  private connectWithCandidates(
    candidates: string[],
    index: number,
    query: URLSearchParams,
    roomId: string,
    generation: number,
  ): void {
    if (generation !== this.connectGeneration) return
    if (index >= candidates.length) {
      this.connectAttemptInFlight = false
      reportClientDiagnostic({
        category: 'media',
        level: 'error',
        eventType: 'sfu_socket_connect_failed',
        code: 'sfu_socket_connect_failed',
        message: 'SFU websocket could not connect to any configured origin.',
        roomId,
        payload: {
          room_id: roomId,
          candidate_count: candidates.length,
        },
        immediate: true,
      })
      this.notifyDisconnectOnce()
      return
    }

    const wsUrl = this.socketUrlForOrigin(candidates[index], query)
    if (!wsUrl) {
      this.connectWithCandidates(candidates, index + 1, query, roomId, generation)
      return
    }

    const ws = new WebSocket(wsUrl)
    ws.binaryType = 'arraybuffer'
    this.ws = ws
    let opened = false
    let failedOver = false
    let failoverAfterClose = false
    let negotiationTimer: ReturnType<typeof setTimeout> | null = null

    const clearNegotiationTimer = () => {
      if (negotiationTimer === null) return
      clearTimeout(negotiationTimer)
      negotiationTimer = null
    }

    const connectNextCandidate = () => {
      this.connectWithCandidates(candidates, index + 1, query, roomId, generation)
    }

    const failToNextCandidate = () => {
      if (generation !== this.connectGeneration) return
      if (opened) return
      if (failedOver) return
      failedOver = true
      clearNegotiationTimer()
      if (this.ws === ws) {
        this.ws = null
      }
      connectNextCandidate()
    }

    const failToNextCandidateAfterSocketClose = (closeReason = 'failover') => {
      if (generation !== this.connectGeneration) return
      if (opened) return
      if (failedOver) return
      failedOver = true
      clearNegotiationTimer()
      if (this.ws === ws) {
        this.ws = null
      }
      try {
        ws.close(1000, closeReason)
      } catch {}
      if (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.CLOSING) {
        failoverAfterClose = true
        return
      }
      connectNextCandidate()
    }

    const failToNextCandidateAfterAssetVersionProbe = (): void => {
      const assetVersionProbe = handleAssetVersionConnectionFailure()
      if (assetVersionProbe && typeof assetVersionProbe.then === 'function') {
        assetVersionProbe.then((handled) => {
          if (handled) {
            this.connectAttemptInFlight = false
            return
          }
          failToNextCandidate()
        }).catch(() => {
          failToNextCandidate()
        })
        return
      }
      if (assetVersionProbe) {
        this.connectAttemptInFlight = false
        return
      }
      failToNextCandidate()
    }

    ws.onopen = () => {
      if (generation !== this.connectGeneration) {
        clearNegotiationTimer()
        try { ws.close() } catch {}
        return
      }
      opened = true
      clearNegotiationTimer()
      this.connectAttemptInFlight = false
      this.disconnectNotified = false
      setBackendSfuOrigin(candidates[index] || '')
      this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })
      this.startPublisherFrameStallTimer()
      if (this.cb.onConnected) {
        this.cb.onConnected()
      }
    }

    ws.onmessage = (ev) => {
      if (ev.data instanceof ArrayBuffer) {
        const decoded = decodeSfuBinaryFrameEnvelope(ev.data)
        if (!decoded) return
        this.handleMessage({
          ...decoded.payload,
          data: decoded.payloadBytes,
          data_base64: decoded.dataBase64 || undefined,
          protected_frame: decoded.protectedFrame || undefined,
        })
        return
      }
      let msg: any
      try { msg = JSON.parse(ev.data) } catch { return }
      if (handleAssetVersionSocketPayload(msg)) return
      this.handleMessage(msg)
    }

    ws.onclose = (event) => {
      if (generation !== this.connectGeneration) return
      clearNegotiationTimer()
      if (handleAssetVersionSocketClose(event)) {
        this.connectAttemptInFlight = false
        return
      }
      if (failoverAfterClose) {
        connectNextCandidate()
        return
      }
      if (!opened) {
        failToNextCandidateAfterAssetVersionProbe()
        return
      }
      if (this.ws === ws) {
        this.ws = null
      }
      this.stopPublisherFrameStallTimer()
      reportClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_socket_closed',
        code: normalizeSfuIdentifier(String(event?.reason || '').trim(), 'sfu_socket_closed'),
        message: String(event?.reason || 'SFU websocket closed unexpectedly.').trim() || 'SFU websocket closed unexpectedly.',
        roomId,
        payload: {
          room_id: roomId,
          close_code: Number(event?.code || 0),
          was_clean: Boolean(event?.wasClean),
          candidate_origin: String(candidates[index] || ''),
        },
      })
      this.notifyDisconnectOnce()
    }

    ws.onerror = () => {
      if (generation !== this.connectGeneration) return
      if (!opened) {
        // Browsers follow pre-open errors with close; wait for that terminal
        // event before trying the next origin so CONNECTING sockets do not pile up.
        return
      }
      try {
        ws.close()
      } catch {}
    }

    negotiationTimer = setTimeout(() => {
      if (generation !== this.connectGeneration) return
      if (opened || failedOver) return
      reportClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_socket_negotiation_timeout',
        code: 'sfu_socket_negotiation_timeout',
        message: 'SFU websocket negotiation timed out before the browser opened the socket.',
        roomId,
        payload: {
          room_id: roomId,
          candidate_origin: String(candidates[index] || ''),
          negotiation_timeout_ms: SFU_WEBSOCKET_NEGOTIATION_TIMEOUT_MS,
        },
      })
      failToNextCandidateAfterSocketClose('negotiation_timeout')
    }, SFU_WEBSOCKET_NEGOTIATION_TIMEOUT_MS)
  }

  connect(session: { userId: string; token: string; name: string }, roomId: string, callId = ''): void {
    if (this.connectAttemptInFlight) return
    this.connectGeneration += 1
    this.outboundMediaGeneration += 1
    this.connectAttemptInFlight = true
    this.disconnectNotified = false
    this.inboundFrameAssembler.clear()
    this.outboundFrameSequenceByTrack.clear()
    this.publisherFrameHealthById.clear()
    this.stopPublisherFrameStallTimer()
    this.outboundWireBudget.reset()
    this.clearOutboundFrameQueue('socket_reconnect')
    this.roomId = roomId
    const generation = this.connectGeneration

    if (this.ws) {
      this.retireSocket(this.ws, true)
      this.ws = null
    }

    const query    = appendAssetVersionQuery(new URLSearchParams({
      room: roomId,
      room_id: roomId,
      userId: session.userId,
      token:  session.token,
      name:   session.name,
    }))
    const normalizedCallId = String(callId || '').trim()
    if (/^[A-Za-z0-9._-]{1,200}$/.test(normalizedCallId)) {
      query.set('call_id', normalizedCallId)
    }

    const candidates = resolveBackendSfuOriginCandidates()
    this.connectWithCandidates(candidates, 0, query, roomId, generation)
  }

  publishTracks(tracks: SFUTrack[]): void {
    for (const t of tracks) {
      this.send({ type: 'sfu/publish', track_id: t.id, kind: t.kind, label: t.label })
    }
  }

  isOpen(): boolean {
    return this.ws?.readyState === WebSocket.OPEN
  }

  getBufferedAmount(): number {
    return this.getWebSocketBufferedAmount() + this.outboundFrameQueue.pressureBytes()
  }

  subscribe(publisherId: string): void {
    const normalizedPublisherId = stringField(publisherId)
    if (normalizedPublisherId === '') return
    this.trackSubscribedPublisher(normalizedPublisherId)
    this.send({ type: 'sfu/subscribe', publisher_id: normalizedPublisherId })
  }

  setSubscriberLayerPreference(publisherId: string, details: Record<string, unknown> = {}): boolean {
    const normalizedPublisherId = stringField(publisherId)
    if (normalizedPublisherId === '') return false
    const requestedLayer = stringField(details.requested_video_layer, details.requestedVideoLayer).toLowerCase()
    if (requestedLayer !== 'primary' && requestedLayer !== 'thumbnail') return false
    return this.send({
      type: 'sfu/layer-preference',
      publisher_id: normalizedPublisherId,
      track_id: stringField(details.track_id, details.trackId),
      requested_video_layer: requestedLayer,
      reason: stringField(details.reason),
      render_surface_role: stringField(details.render_surface_role, details.renderSurfaceRole),
      visible_participant_count: Math.max(0, Number(details.visible_participant_count || details.visibleParticipantCount || 0)),
      frame_sequence: Math.max(0, Number(details.frame_sequence || details.frameSequence || 0)),
    })
  }

  requestPublisherMediaRecovery(publisherId: string, details: Record<string, unknown> = {}): boolean {
    const normalizedPublisherId = stringField(publisherId)
    if (normalizedPublisherId === '') return false
    const requestedVideoLayer = stringField(details.requested_video_layer, details.requestedVideoLayer).toLowerCase()
    const requestedAction = stringField(
      details.requested_action,
      details.requestedAction,
      'force_full_keyframe',
    ).toLowerCase()
    return this.send({
      type: 'sfu/media-recovery-request',
      publisher_id: normalizedPublisherId,
      track_id: stringField(details.track_id, details.trackId),
      reason: stringField(details.reason, 'sfu_receiver_media_recovery').toLowerCase(),
      requested_action: requestedAction,
      request_full_keyframe: Boolean(details.request_full_keyframe || details.requestFullKeyframe)
        || requestedAction === 'force_full_keyframe'
        || requestedVideoLayer === 'primary',
      requested_video_layer: requestedVideoLayer === 'primary' || requestedVideoLayer === 'thumbnail'
        ? requestedVideoLayer
        : '',
      requested_video_quality_profile: stringField(
        details.requested_video_quality_profile,
        details.requestedVideoQualityProfile,
      ).toLowerCase(),
      frame_sequence: Math.max(0, Number(details.frame_sequence || details.frameSequence || 0)),
    })
  }

  unpublishTrack(trackId: string): void {
    this.send({ type: 'sfu/unpublish', track_id: trackId })
  }

  async sendEncodedFrame(frame: SFUEncodedFrame): Promise<boolean> {
    this.lastSendFailure = null
    const frameSequence = this.nextOutboundFrameSequence(frame.trackId)
    return this.enqueueEncodedFrame(prepareSfuOutboundFramePayload({
      ...frame,
      transportMetrics: {
        ...(frame.transportMetrics || {}),
        outbound_media_generation: this.outboundMediaGeneration,
      },
      frameSequence,
      senderSentAtMs: Date.now(),
    }))
  }

  leave(): void {
    this.connectGeneration += 1
    this.outboundMediaGeneration += 1
    this.connectAttemptInFlight = false
    this.disconnectNotified = false
    this.inboundFrameAssembler.clear()
    this.outboundFrameSequenceByTrack.clear()
    this.publisherFrameHealthById.clear()
    this.stopPublisherFrameStallTimer()
    this.outboundWireBudget.reset()
    this.clearOutboundFrameQueue('leave')
    this.send({ type: 'sfu/leave' })
    if (this.ws) {
      // Force-close even when still CONNECTING to prevent orphaned sockets
      // that cause "WebSocket is closed before the connection is established".
      this.retireSocket(this.ws, true)
    }
    this.ws = null
  }

  getLastSendFailure(): SfuSendFailureDetails | null {
    return this.lastSendFailure ? { ...this.lastSendFailure } : null
  }

  getLastFrameTransportSample(): SfuFrameTransportSample | null {
    return this.lastFrameTransportSample ? { ...this.lastFrameTransportSample } : null
  }

  resetOutboundMediaAfterProfileSwitch(details: { fromProfile?: string; toProfile?: string; reason?: string } = {}): void {
    this.outboundMediaGeneration += 1
    this.outboundFrameSequenceByTrack.clear()
    this.outboundWireBudget.reset()
    const droppedCount = this.clearOutboundFrameQueue('profile_switch')
    this.reportFrameSendDiagnostic(
      'sfu_profile_switch_outbound_reset',
      'warning',
      'SFU outbound media state was reset before applying a lower video quality profile.',
      {
        drop_reason: 'profile_switch',
        dropped_frame_count: droppedCount,
        from_profile: String(details.fromProfile || ''),
        to_profile: String(details.toProfile || ''),
        reason: String(details.reason || 'profile_switch'),
        outbound_media_generation: this.outboundMediaGeneration,
      },
      true,
    )
  }

  private send(msg: object): boolean {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(msg))
      return true
    }
    return false
  }

  private trackSubscribedPublisher(publisherId: string, nowMs = Date.now()): void {
    const normalizedPublisherId = stringField(publisherId)
    if (normalizedPublisherId === '') return
    const existing = this.publisherFrameHealthById.get(normalizedPublisherId)
    if (existing) {
      existing.subscribedAtMs = nowMs
      return
    }
    this.publisherFrameHealthById.set(normalizedPublisherId, {
      subscribedAtMs: nowMs,
      lastFrameAtMs: 0,
      lastRecoveryAtMs: 0,
      recoveryCount: 0,
    })
  }

  private untrackPublisher(publisherId: string): void {
    const normalizedPublisherId = stringField(publisherId)
    if (normalizedPublisherId === '') return
    this.publisherFrameHealthById.delete(normalizedPublisherId)
  }

  private markPublisherFrameReceived(msg: any, nowMs = Date.now()): void {
    if (stringField(msg?.type) !== 'sfu/frame') return
    const publisherId = stringField(msg?.publisherId, msg?.publisher_id)
    if (publisherId === '') return
    const health = this.publisherFrameHealthById.get(publisherId)
    if (health) {
      health.lastFrameAtMs = nowMs
      health.recoveryCount = 0
      return
    }
    this.publisherFrameHealthById.set(publisherId, {
      subscribedAtMs: nowMs,
      lastFrameAtMs: nowMs,
      lastRecoveryAtMs: 0,
      recoveryCount: 0,
    })
  }

  private startPublisherFrameStallTimer(): void {
    this.stopPublisherFrameStallTimer()
    this.publisherFrameStallTimer = setInterval(() => {
      this.checkPublisherFrameStalls()
    }, SFU_PUBLISHER_FRAME_STALL_CHECK_INTERVAL_MS)
  }

  private stopPublisherFrameStallTimer(): void {
    if (this.publisherFrameStallTimer === null) return
    clearInterval(this.publisherFrameStallTimer)
    this.publisherFrameStallTimer = null
  }

  private checkPublisherFrameStalls(nowMs = Date.now()): void {
    if (!this.isOpen()) return
    for (const [publisherId, health] of this.publisherFrameHealthById.entries()) {
      const referenceAtMs = health.lastFrameAtMs > 0 ? health.lastFrameAtMs : health.subscribedAtMs
      if (referenceAtMs <= 0) continue
      const ageMs = Math.max(0, nowMs - referenceAtMs)
      if (ageMs < SFU_PUBLISHER_FRAME_STALL_RESUBSCRIBE_AFTER_MS) continue
      if ((nowMs - health.lastRecoveryAtMs) < SFU_PUBLISHER_FRAME_STALL_RECOVERY_COOLDOWN_MS) continue

      health.lastRecoveryAtMs = nowMs
      health.recoveryCount += 1
      reportClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_publisher_frame_stall',
        code: 'sfu_publisher_frame_stall',
        message: 'SFU publisher subscription is open but no fresh binary media frame has arrived; resubscribing before UI-level recovery restarts the transport.',
        roomId: this.roomId,
        payload: {
          room_id: this.roomId,
          publisher_id: publisherId,
          stall_age_ms: ageMs,
          last_frame_at_ms: health.lastFrameAtMs,
          subscribed_at_ms: health.subscribedAtMs,
          recovery_count: health.recoveryCount,
          recovery_action: 'resubscribe',
          frame_path: 'binary_or_json_sfu_frame',
        },
        immediate: health.recoveryCount <= 2,
      })
      this.send({ type: 'sfu/subscribe', publisher_id: publisherId, reason: 'publisher_frame_stall_recovery' })
    }
  }

  private wait(ms: number): Promise<void> {
    return new Promise((resolve) => {
      setTimeout(resolve, ms)
    })
  }

  private async waitForSendBufferDrain(targetBufferedBytes: number, maxWaitMs = SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS): Promise<SendBufferDrainResult> {
    const startMs = Date.now()
    const normalizedTarget = Math.max(0, Math.floor(Number(targetBufferedBytes || 0)))
    const normalizedMaxWaitMs = Math.max(1, Math.floor(Number(maxWaitMs || 0)))
    while (this.ws?.readyState === WebSocket.OPEN && this.getWebSocketBufferedAmount() > normalizedTarget) {
      if ((Date.now() - startMs) >= normalizedMaxWaitMs) {
        return {
          ok: false,
          waitedMs: Date.now() - startMs,
          bufferedAmount: this.getWebSocketBufferedAmount(),
          targetBufferedBytes: normalizedTarget,
          maxWaitMs: normalizedMaxWaitMs,
        }
      }
      await this.wait(SFU_FRAME_CHUNK_BACKPRESSURE_SLEEP_MS)
    }
    return {
      ok: this.ws?.readyState === WebSocket.OPEN,
      waitedMs: Date.now() - startMs,
      bufferedAmount: this.getWebSocketBufferedAmount(),
      targetBufferedBytes: normalizedTarget,
      maxWaitMs: normalizedMaxWaitMs,
    }
  }

  private getWebSocketBufferedAmount(): number {
    return Math.max(0, Number(this.ws?.bufferedAmount || 0))
  }

  private nextOutboundFrameSequence(trackId: string): number {
    const key = String(trackId || '').trim() || 'default'
    const next = Math.max(1, Number(this.outboundFrameSequenceByTrack.get(key) || 0) + 1)
    this.outboundFrameSequenceByTrack.set(key, next)
    return next
  }

  private enqueueEncodedFrame(prepared: PreparedSfuOutboundFramePayload): Promise<boolean> {
    if (!this.isOpen()) return Promise.resolve(false)
    this.reportOutboundQueuePressureIfNeeded(prepared)
    return this.outboundFrameQueue.enqueue(prepared)
  }

  private async sendPreparedEncodedFrame(prepared: PreparedSfuOutboundFramePayload, queuedAgeMs = 0): Promise<boolean> {
    const metrics = this.metricsForPreparedFrame(prepared, { queued_age_ms: queuedAgeMs })
    const bufferedBeforeSend = this.getWebSocketBufferedAmount()
    if (this.isPreparedFrameStaleForOutboundMediaGeneration(prepared)) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped an old-profile frame after outbound media generation changed.',
        {
          ...metrics,
          buffered_amount: bufferedBeforeSend,
          abort_reason: 'sfu_profile_switch_generation_mismatch',
          outbound_media_generation: this.outboundMediaGeneration,
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_profile_switch_generation_mismatch',
        stage: 'outbound_media_generation_guard',
        source: 'profile_switch_actuator',
        message: 'Encoded SFU frame belonged to an older outbound media generation after a profile switch.',
        transportPath: 'binary_envelope',
        bufferedAmount: bufferedBeforeSend,
      })
      return false
    }
    const queueAgeBudgetMs = Math.max(0, Number(metrics.budget_max_queue_age_ms || 0))
    if (queueAgeBudgetMs > 0 && queuedAgeMs > queueAgeBudgetMs) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped a stale encoded frame before it could build socket pressure.',
        {
          ...metrics,
          buffered_amount: bufferedBeforeSend,
          abort_reason: 'sfu_queue_age_budget_exceeded',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_queue_age_budget_exceeded',
        stage: 'outbound_frame_queue_budget',
        source: 'outbound_frame_queue',
        message: 'Encoded SFU frame exceeded its profile queue-age budget before websocket send.',
        transportPath: 'binary_envelope',
        bufferedAmount: bufferedBeforeSend,
      })
      return false
    }

    const bufferedBudgetBytes = Math.max(0, Number(metrics.budget_max_buffered_bytes || 0))
    if (bufferedBudgetBytes > 0 && bufferedBeforeSend > bufferedBudgetBytes) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped an encoded frame before websocket buffering reached critical pressure.',
        {
          ...metrics,
          buffered_amount: bufferedBeforeSend,
          abort_reason: 'sfu_buffer_budget_exceeded',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_buffer_budget_exceeded',
        stage: 'browser_websocket_buffer_budget',
        source: 'websocket_buffered_amount',
        message: 'Encoded SFU frame exceeded its profile websocket buffer budget before send.',
        transportPath: 'binary_envelope',
        bufferedAmount: bufferedBeforeSend,
      })
      return false
    }
    const projectedWirePayloadBytes = Math.max(0, Number(metrics.projected_binary_envelope_bytes || prepared.projectedBinaryEnvelopeBytes || 0))
    const projectedBufferBudget = shouldDropProjectedSfuFrameForBufferBudget(metrics, bufferedBeforeSend, projectedWirePayloadBytes)
    if (projectedBufferBudget.drop) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped an encoded frame because sending it would refill the websocket buffer above the profile budget.',
        {
          ...metrics,
          buffered_amount: bufferedBeforeSend,
          projected_buffered_after_send_bytes: projectedBufferBudget.projectedBufferedAfterSendBytes,
          abort_reason: 'sfu_projected_buffer_budget_exceeded',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_projected_buffer_budget_exceeded',
        stage: 'browser_websocket_projected_buffer_budget',
        source: 'websocket_buffered_amount',
        message: 'Encoded SFU frame would exceed its profile websocket buffer budget after send.',
        transportPath: 'binary_envelope',
        bufferedAmount: bufferedBeforeSend,
      })
      return false
    }
    const wireBudget = this.outboundWireBudget.decide(metrics, projectedWirePayloadBytes)
    if (!wireBudget.ok) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped an encoded frame because it would exceed the active rolling wire budget.',
        {
          ...metrics,
          buffered_amount: bufferedBeforeSend,
          projected_wire_window_bytes: wireBudget.projectedWindowBytes,
          current_wire_window_bytes: wireBudget.currentWindowBytes,
          budget_max_wire_bytes_per_second: wireBudget.maxWireBytesPerSecond,
          wire_budget_window_ms: wireBudget.windowMs,
          wire_budget_retry_after_ms: wireBudget.retryAfterMs,
          abort_reason: 'sfu_wire_rate_budget_exceeded',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_wire_rate_budget_exceeded',
        stage: 'browser_websocket_wire_rate_budget',
        source: 'wire_rate_controller',
        message: 'Encoded SFU frame would exceed the active rolling wire-byte budget.',
        transportPath: 'binary_envelope',
        bufferedAmount: bufferedBeforeSend,
        retryAfterMs: wireBudget.retryAfterMs,
      })
      return false
    }

    this.reportFrameSendPressureIfNeeded({
      ...metrics,
      buffered_amount: bufferedBeforeSend,
    })

    const drainTargetBufferedBytes = resolveSfuSendDrainTargetBytes(metrics)
    const drain = await this.waitForSendBufferDrain(drainTargetBufferedBytes, SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS)
    metrics.send_drain_ms = drain.waitedMs
    metrics.send_drain_target_buffered_bytes = drain.targetBufferedBytes
    metrics.send_drain_max_wait_ms = drain.maxWaitMs
    if (!drain.ok) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'error',
        'SFU frame send aborted while waiting for websocket backpressure to drain.',
        {
          ...metrics,
          buffered_amount: drain.bufferedAmount,
          send_wait_ms: drain.waitedMs,
          send_drain_target_buffered_bytes: drain.targetBufferedBytes,
          send_drain_max_wait_ms: drain.maxWaitMs,
          abort_reason: 'send_buffer_drain_timeout',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'send_buffer_drain_timeout',
        stage: 'wait_for_send_buffer_drain',
        source: 'binary_envelope',
        message: 'Binary envelope send timed out while waiting for websocket bufferedAmount to drain.',
        transportPath: 'binary_envelope',
        bufferedAmount: drain.bufferedAmount,
      })
      return false
    }
    if (this.isPreparedFrameStaleForOutboundMediaGeneration(prepared)) {
      this.recordSendFailure(prepared, {
        reason: 'sfu_profile_switch_generation_mismatch',
        stage: 'post_drain_generation_guard',
        source: 'profile_switch_actuator',
        message: 'Encoded SFU frame became stale while waiting for websocket backpressure to drain.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
    const postDrainQueueAgeMs = Math.max(queuedAgeMs, Date.now() - Math.max(0, Number(prepared.senderSentAtMs || 0)))
    metrics.queued_age_ms = postDrainQueueAgeMs
    if (queueAgeBudgetMs > 0 && postDrainQueueAgeMs > queueAgeBudgetMs) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'warning',
        'SFU frame send dropped a stale encoded frame after websocket backpressure drain.',
        {
          ...metrics,
          buffered_amount: this.getWebSocketBufferedAmount(),
          abort_reason: 'sfu_queue_age_budget_exceeded_after_drain',
        },
        true,
      )
      this.recordSendFailure(prepared, {
        reason: 'sfu_queue_age_budget_exceeded_after_drain',
        stage: 'post_drain_outbound_frame_queue_budget',
        source: 'outbound_frame_queue',
        message: 'Encoded SFU frame aged past its profile queue budget while waiting for websocket backpressure to drain.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
    if (this.sendBinaryFrame(prepared, metrics)) {
      return true
    }
    this.reportFrameSendDiagnostic(
      'sfu_frame_send_aborted',
      'error',
      'SFU frame send aborted because binary envelope transport failed and the direct legacy JSON/base64 fallback has been removed.',
      {
        ...metrics,
        buffered_amount: this.getWebSocketBufferedAmount(),
        abort_reason: 'binary_envelope_send_failed',
        transport_path: 'binary_envelope',
        binary_media_required: true,
      },
      true,
    )
    if (!this.lastSendFailure) {
      this.recordSendFailure(prepared, {
        reason: 'binary_envelope_send_failed',
        stage: 'send_binary_envelope',
        source: 'binary_envelope',
        message: 'Binary envelope send returned false without an earlier transport-stage failure detail.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
    }
    return false
  }

  private sendBinaryFrame(prepared: PreparedSfuOutboundFramePayload, metrics: Record<string, unknown>): boolean {
    if (!this.mediaTransport.isOpen()) {
      this.recordSendFailure(prepared, {
        reason: 'socket_not_open',
        stage: 'send_binary_envelope',
        source: 'media_transport',
        message: 'Binary envelope send was attempted while the SFU websocket was not open.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
    const envelopeStartedAtMs = highResolutionNowMs()
    prepared.metrics = {
      ...prepared.metrics,
      ...metrics,
    }
    const encoded = encodeSfuBinaryFrameEnvelope(prepared)
    const binaryEnvelopeEncodeMs = roundedTransportStageMs(highResolutionNowMs() - envelopeStartedAtMs)
    let sendMetrics = appendSfuPublisherTraceStage(
      {
        ...metrics,
        binary_envelope_encode_ms: binaryEnvelopeEncodeMs,
      },
      'binary_envelope_encode',
      binaryEnvelopeEncodeMs,
    )
    prepared.metrics = sendMetrics
    if (!(encoded instanceof ArrayBuffer) || encoded.byteLength <= 0) {
      this.recordSendFailure(prepared, {
        reason: 'binary_envelope_encode_failed',
        stage: 'encode_binary_envelope',
        source: 'binary_envelope',
        message: 'Binary envelope encoding produced no payload bytes for the outgoing SFU frame.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
    try {
      const sendResult = this.mediaTransport.sendBinaryFrame(encoded)
      if (!sendResult.ok) {
        this.recordSendFailure(prepared, {
          reason: sendResult.errorCode || 'media_transport_send_failed',
          stage: 'send_binary_envelope',
          source: 'media_transport',
          message: 'SFU media transport failed while sending an encoded binary frame envelope.',
          transportPath: sendResult.transportPath,
          bufferedAmount: sendResult.bufferedAmount,
        })
        return false
      }
      const websocketSendMs = roundedTransportStageMs(sendResult.sendMs)
      sendMetrics = appendSfuPublisherTraceStage(
        {
          ...sendMetrics,
          websocket_send_ms: websocketSendMs,
          media_transport_send_ms: websocketSendMs,
          media_transport: sendResult.transportPath,
          control_transport: SFU_CONTROL_TRANSPORT_WEBSOCKET,
        },
        'browser_websocket_send',
        websocketSendMs,
      )
      prepared.metrics = sendMetrics
      this.outboundWireBudget.record(encoded.byteLength)
      const binaryContinuationRequired = encoded.byteLength > SFU_BINARY_CONTINUATION_THRESHOLD_BYTES
      const samplePayload = {
        ...sendMetrics,
        transport_path: 'binary_envelope',
        wire_payload_bytes: encoded.byteLength,
        wire_overhead_bytes: Math.max(0, encoded.byteLength - Number(sendMetrics.payload_bytes || 0)),
        binary_continuation_state: binaryContinuationRequired
          ? 'receiver_reassembles_rfc_continuation_frames'
          : 'single_binary_message_no_continuation_expected',
        binary_continuation_required: binaryContinuationRequired,
        binary_continuation_threshold_bytes: SFU_BINARY_CONTINUATION_THRESHOLD_BYTES,
        application_media_chunking: false,
        media_transport: sendResult.transportPath,
        control_transport: SFU_CONTROL_TRANSPORT_WEBSOCKET,
        websocket_buffered_amount: sendResult.bufferedAmount,
      }
      this.reportFrameSendPressureIfNeeded(samplePayload)
      this.reportFrameTransportSampleIfNeeded(samplePayload)
      return true
    } catch {
      this.recordSendFailure(prepared, {
        reason: 'websocket_send_throw',
        stage: 'send_binary_envelope',
        source: 'websocket',
        message: 'WebSocket.send threw while sending an encoded binary SFU frame envelope.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
  }

  private clearOutboundFrameQueue(reason: string): number {
    const droppedCount = this.outboundFrameQueue.clear()
    if (droppedCount <= 0) return 0
    this.reportFrameSendDiagnostic(
      'sfu_frame_send_queue_cleared',
      'warning',
      'SFU frame send queue was cleared before frames were sent.',
      {
        drop_reason: reason,
        dropped_frame_count: droppedCount,
      },
    )
    return droppedCount
  }

  private isPreparedFrameStaleForOutboundMediaGeneration(prepared: PreparedSfuOutboundFramePayload): boolean {
    const generation = Math.max(0, Number(prepared.metrics?.outbound_media_generation || 0))
    return generation !== this.outboundMediaGeneration
  }

  private reportOutboundQueuePressureIfNeeded(prepared: PreparedSfuOutboundFramePayload): void {
    const projectedQueueLength = this.outboundFrameQueue.length() + 1
    const projectedQueueBytes = this.outboundFrameQueue.queuedBytes() + prepared.payloadChars
    if (
      projectedQueueLength < 3
      && projectedQueueBytes < SFU_FRAME_CHUNK_BACKPRESSURE_BYTES
    ) {
      return
    }
    this.reportOutboundFrameQueueDiagnostic(
      'sfu_frame_send_queue_pressure',
      'warning',
      'SFU bounded send queue is under pressure.',
      prepared,
    )
  }

  private reportOutboundFrameQueueDiagnostic(
    eventType: string,
    level: 'info' | 'warning' | 'error',
    message: string,
    prepared: PreparedSfuOutboundFramePayload,
    extraPayload: Record<string, unknown> = {},
    immediate = false,
  ): void {
    const nowMs = Date.now()
    if (!immediate && (nowMs - this.lastFrameQueueDiagnosticAtMs) < SFU_FRAME_SEND_QUEUE_DIAGNOSTIC_COOLDOWN_MS) {
      return
    }
    this.lastFrameQueueDiagnosticAtMs = nowMs
    this.reportFrameSendDiagnostic(
      eventType,
      level,
      message,
      this.metricsForPreparedFrame(prepared, extraPayload),
      immediate,
    )
  }

  private metricsForPreparedFrame(
    prepared: PreparedSfuOutboundFramePayload,
    extraPayload: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      ...prepared.metrics,
      ...extraPayload,
      frame_sequence: prepared.frameSequence,
      payload_chars: prepared.payloadChars,
      chunk_count: prepared.chunkCount,
      queue_length: this.outboundFrameQueue.length(),
      queue_payload_chars: this.outboundFrameQueue.queuedBytes(),
      active_payload_chars: this.outboundFrameQueue.activeBytes(),
    }
  }

  private recordSendFailure(
    prepared: PreparedSfuOutboundFramePayload,
    details: {
      reason: string
      stage: string
      source: string
      message: string
      transportPath: string
      bufferedAmount: number
      retryAfterMs?: number
    },
  ): void {
    this.lastSendFailure = buildSfuSendFailureDetails(prepared, details, {
      queueLength: this.outboundFrameQueue.length(),
      queuePayloadChars: this.outboundFrameQueue.queuedBytes(),
      activePayloadChars: this.outboundFrameQueue.activeBytes(),
    })
  }

  private reportFrameSendPressureIfNeeded(payload: Record<string, unknown>): void {
    const chunkCount = Number(payload.chunk_count || 0)
    const bufferedAmount = Math.max(
      0,
      Number(payload.buffered_amount || 0),
      Number(payload.websocket_buffered_amount || 0),
    )
    const wirePayloadBytes = Math.max(
      0,
      Number(payload.wire_payload_bytes || 0),
      Number(payload.projected_binary_envelope_bytes || 0),
    )
    const applicationMediaChunking = payload.application_media_chunking !== false
      && String(payload.application_media_chunking || '').trim().toLowerCase() !== 'false'
    const binaryContinuationRequired = payload.binary_continuation_required === true
      || String(payload.binary_continuation_required || '').trim().toLowerCase() === 'true'
    const legacyChunkPressure = applicationMediaChunking
      && chunkCount >= SFU_FRAME_CHUNK_DIAGNOSTIC_MIN_CHUNKS
    const binaryEnvelopePressure = !applicationMediaChunking
      && binaryContinuationRequired
      && wirePayloadBytes >= SFU_FRAME_CHUNK_BACKPRESSURE_BYTES
    const bufferedPressure = bufferedAmount >= SFU_FRAME_CHUNK_BACKPRESSURE_BYTES
    if (!legacyChunkPressure && !binaryEnvelopePressure && !bufferedPressure) {
      return
    }

    const nowMs = Date.now()
    if ((nowMs - this.lastFrameSendPressureDiagnosticAtMs) < SFU_FRAME_SEND_PRESSURE_DIAGNOSTIC_COOLDOWN_MS) {
      return
    }
    this.lastFrameSendPressureDiagnosticAtMs = nowMs
    this.reportFrameSendDiagnostic(
      'sfu_frame_send_pressure',
      'warning',
      'SFU frame send is under pressure from large chunked payloads or websocket buffering.',
      {
        ...payload,
        pressure_reason: bufferedPressure
          ? 'browser_websocket_buffer'
          : (binaryEnvelopePressure ? 'binary_envelope_large_payload' : 'legacy_application_chunking'),
        application_media_chunking: applicationMediaChunking,
        binary_continuation_required: binaryContinuationRequired,
        wire_payload_bytes: wirePayloadBytes,
        buffered_amount: bufferedAmount,
      },
    )
  }

  private reportFrameSendDiagnostic(
    eventType: string,
    level: 'info' | 'warning' | 'error',
    message: string,
    payload: Record<string, unknown>,
    immediate = false,
  ): void {
    reportClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      roomId: this.roomId,
      payload: {
        room_id: this.roomId,
        ...payload,
      },
      immediate,
    })
  }

  private reportFrameTransportSampleIfNeeded(payload: Record<string, unknown>): void {
    const nowMs = Date.now()
    const sample = this.recordFrameTransportSample(payload, nowMs)
    if ((nowMs - this.lastFrameTransportSampleAtMs) < SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS) {
      return
    }
    this.lastFrameTransportSampleAtMs = nowMs
    this.reportFrameSendDiagnostic(
      'sfu_frame_transport_sample',
      'info',
      'Sampled SFU frame transport metrics for the active media path.',
      {
        ...payload,
        ...buildSfuEndToEndPerformancePayload(payload, sample),
        wire_vs_payload_ratio: sample.wireVsPayloadRatio,
      },
    )
  }

  private recordFrameTransportSample(
    payload: Record<string, unknown>,
    nowMs = Date.now(),
  ): SfuFrameTransportSample {
    const sample = buildSfuFrameTransportSample(payload, nowMs)
    this.lastFrameTransportSample = sample
    return sample
  }

  private handleMessage(msg: any): void {
    this.markPublisherFrameReceived(msg)
    if (stringField(msg?.type) === 'sfu/publisher_left') {
      this.untrackPublisher(stringField(msg?.publisherId, msg?.publisher_id))
    }
    handleSfuClientMessage({
      callbacks: this.cb,
      inboundFrameAssembler: this.inboundFrameAssembler,
      roomId: this.roomId,
      subscribe: (publisherId) => this.subscribe(publisherId),
    }, msg)
  }
}
