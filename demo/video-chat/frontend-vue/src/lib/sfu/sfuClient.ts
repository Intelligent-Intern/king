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
  handleAssetVersionSocketClose,
  handleAssetVersionSocketPayload,
} from '../../support/assetVersion'
import { reportClientDiagnostic } from '../../support/clientDiagnostics'
import { SfuInboundFrameAssembler } from './inboundFrameAssembler'
import {
  decodeSfuBinaryFrameEnvelope,
  encodeSfuBinaryFrameEnvelope,
  base64UrlToArrayBuffer,
  prepareSfuOutboundFramePayload,
  SFU_BINARY_CONTINUATION_THRESHOLD_BYTES,
  type PreparedSfuOutboundFramePayload,
} from './framePayload'
import { SfuOutboundFrameQueue } from './outboundFrameQueue'
import { hasExplicitSfuTileMetadataFields, normalizeTilePatchMetadata } from './tilePatchMetadata'
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
const SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 500
const SFU_FRAME_SEND_PRESSURE_DIAGNOSTIC_COOLDOWN_MS = 3000
const SFU_FRAME_CHUNK_DIAGNOSTIC_MIN_CHUNKS = 16
const SFU_FRAME_SEND_QUEUE_DIAGNOSTIC_COOLDOWN_MS = 1500
const SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS = 2000

interface SendBufferDrainResult {
  ok: boolean
  waitedMs: number
  bufferedAmount: number
}

export class SFUClient {
  private ws: WebSocket | null = null
  private cb: SFUClientCallbacks
  private roomId = ''
  private connectGeneration = 0
  private disconnectNotified = false
  private inboundFrameAssembler = new SfuInboundFrameAssembler({ getRoomId: () => this.roomId })
  private outboundFrameQueue: SfuOutboundFrameQueue
  private outboundFrameSequenceByTrack = new Map<string, number>()
  private outboundMediaGeneration = 0
  private lastFrameSendPressureDiagnosticAtMs = 0
  private lastFrameQueueDiagnosticAtMs = 0
  private lastFrameTransportSampleAtMs = 0
  private lastSendFailure: SfuSendFailureDetails | null = null
  private lastFrameTransportSample: SfuFrameTransportSample | null = null

  constructor(cb: SFUClientCallbacks) {
    this.cb = cb
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

    const failToNextCandidate = () => {
      if (generation !== this.connectGeneration) return
      if (opened) return
      if (failedOver) return
      failedOver = true
      if (this.ws === ws) {
        this.ws = null
      }
      this.connectWithCandidates(candidates, index + 1, query, roomId, generation)
    }

    ws.onopen = () => {
      if (generation !== this.connectGeneration) {
        try { ws.close() } catch {}
        return
      }
      opened = true
      this.disconnectNotified = false
      setBackendSfuOrigin(candidates[index] || '')
      this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })
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
      if (handleAssetVersionSocketClose(event)) return
      if (!opened) {
        failToNextCandidate()
        return
      }
      if (this.ws === ws) {
        this.ws = null
      }
      reportClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_socket_closed',
        code: normalizeIdentifier(String(event?.reason || '').trim(), 'sfu_socket_closed'),
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
        failToNextCandidate()
        return
      }
      try {
        ws.close()
      } catch {}
    }
  }

  connect(session: { userId: string; token: string; name: string }, roomId: string, callId = ''): void {
    this.connectGeneration += 1
    this.outboundMediaGeneration += 1
    this.disconnectNotified = false
    this.inboundFrameAssembler.clear()
    this.outboundFrameSequenceByTrack.clear()
    this.clearOutboundFrameQueue('socket_reconnect')
    this.roomId = roomId
    const generation = this.connectGeneration

    if (this.ws) {
      this.retireSocket(this.ws)
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
    this.send({ type: 'sfu/subscribe', publisher_id: publisherId })
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
    this.disconnectNotified = false
    this.inboundFrameAssembler.clear()
    this.outboundFrameSequenceByTrack.clear()
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

  private wait(ms: number): Promise<void> {
    return new Promise((resolve) => {
      setTimeout(resolve, ms)
    })
  }

  private async waitForSendBufferDrain(): Promise<SendBufferDrainResult> {
    const startMs = Date.now()
    while (this.ws?.readyState === WebSocket.OPEN && this.getWebSocketBufferedAmount() > SFU_FRAME_CHUNK_BACKPRESSURE_BYTES) {
      if ((Date.now() - startMs) >= SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS) {
        return {
          ok: false,
          waitedMs: Date.now() - startMs,
          bufferedAmount: this.getWebSocketBufferedAmount(),
        }
      }
      await this.wait(SFU_FRAME_CHUNK_BACKPRESSURE_SLEEP_MS)
    }
    return {
      ok: this.ws?.readyState === WebSocket.OPEN,
      waitedMs: Date.now() - startMs,
      bufferedAmount: this.getWebSocketBufferedAmount(),
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

    this.reportFrameSendPressureIfNeeded({
      ...metrics,
      buffered_amount: bufferedBeforeSend,
    })

    const drain = await this.waitForSendBufferDrain()
    metrics.send_drain_ms = drain.waitedMs
    if (!drain.ok) {
      this.reportFrameSendDiagnostic(
        'sfu_frame_send_aborted',
        'error',
        'SFU frame send aborted while waiting for websocket backpressure to drain.',
        {
          ...metrics,
          buffered_amount: drain.bufferedAmount,
          send_wait_ms: drain.waitedMs,
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
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      this.recordSendFailure(prepared, {
        reason: 'socket_not_open',
        stage: 'send_binary_envelope',
        source: 'websocket',
        message: 'Binary envelope send was attempted while the SFU websocket was not open.',
        transportPath: 'binary_envelope',
        bufferedAmount: this.getWebSocketBufferedAmount(),
      })
      return false
    }
    const encoded = encodeSfuBinaryFrameEnvelope(prepared)
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
      this.ws.send(encoded)
      const binaryContinuationRequired = encoded.byteLength > SFU_BINARY_CONTINUATION_THRESHOLD_BYTES
      const samplePayload = {
        ...metrics,
        transport_path: 'binary_envelope',
        wire_payload_bytes: encoded.byteLength,
        wire_overhead_bytes: Math.max(0, encoded.byteLength - Number(metrics.payload_bytes || 0)),
        binary_continuation_state: binaryContinuationRequired
          ? 'receiver_reassembles_rfc_continuation_frames'
          : 'single_binary_message_no_continuation_expected',
        binary_continuation_required: binaryContinuationRequired,
        binary_continuation_threshold_bytes: SFU_BINARY_CONTINUATION_THRESHOLD_BYTES,
        application_media_chunking: false,
        websocket_buffered_amount: this.getWebSocketBufferedAmount(),
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
    },
  ): void {
    this.lastSendFailure = {
      reason: String(details.reason || 'unknown_send_failure'),
      stage: String(details.stage || 'unknown_stage'),
      source: String(details.source || 'unknown_source'),
      message: String(details.message || 'Unknown SFU send failure.'),
      transportPath: String(details.transportPath || 'unknown_transport'),
      bufferedAmount: Math.max(0, Number(details.bufferedAmount || 0)),
      queueLength: this.outboundFrameQueue.length(),
      queuePayloadChars: this.outboundFrameQueue.queuedBytes(),
      activePayloadChars: this.outboundFrameQueue.activeBytes(),
      trackId: String(prepared.trackId || ''),
      chunkCount: Math.max(1, Number(prepared.chunkCount || 1)),
      payloadChars: Math.max(0, Number(prepared.payloadChars || 0)),
      payloadBytes: Math.max(0, Number(prepared.metrics?.payload_bytes || 0)),
      wirePayloadBytes: Math.max(0, Number(prepared.metrics?.projected_binary_envelope_bytes || prepared.projectedBinaryEnvelopeBytes || 0)),
      binaryContinuationState: String(prepared.metrics?.binary_continuation_state || 'unknown_binary_continuation_state'),
      timestamp: Math.max(0, Number(prepared.timestamp || 0)),
    }
  }

  private reportFrameSendPressureIfNeeded(payload: Record<string, unknown>): void {
    const chunkCount = Number(payload.chunk_count || 0)
    const bufferedAmount = Number(payload.buffered_amount || 0)
    if (
      chunkCount < SFU_FRAME_CHUNK_DIAGNOSTIC_MIN_CHUNKS
      && bufferedAmount < SFU_FRAME_CHUNK_BACKPRESSURE_BYTES
    ) {
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
      payload,
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
        wire_vs_payload_ratio: sample.wireVsPayloadRatio,
      },
    )
  }

  private recordFrameTransportSample(
    payload: Record<string, unknown>,
    nowMs = Date.now(),
  ): SfuFrameTransportSample {
    const payloadBytes = Math.max(0, Number(payload.payload_bytes || 0))
    const wirePayloadBytes = Math.max(0, Number(payload.wire_payload_bytes || 0))
    const wireVsPayloadRatio = payloadBytes > 0
      ? Number((wirePayloadBytes / payloadBytes).toFixed(4))
      : 0
    const sample = {
      transportPath: String(payload.transport_path || 'unknown_transport'),
      payloadBytes,
      wirePayloadBytes,
      wireOverheadBytes: Math.max(0, Number(payload.wire_overhead_bytes || 0)),
      wireVsPayloadRatio,
      websocketBufferedAmount: Math.max(0, Number(payload.websocket_buffered_amount || payload.buffered_amount || 0)),
      queueLength: Math.max(0, Number(payload.queue_length || 0)),
      queuePayloadChars: Math.max(0, Number(payload.queue_payload_chars || 0)),
      activePayloadChars: Math.max(0, Number(payload.active_payload_chars || 0)),
      trackId: String(payload.track_id || ''),
      frameType: String(payload.frame_type || ''),
      frameSequence: Math.max(0, Number(payload.frame_sequence || 0)),
      chunkCount: Math.max(1, Number(payload.chunk_count || 1)),
      outgoingVideoQualityProfile: String(payload.outgoing_video_quality_profile || ''),
      encodeMs: Math.max(0, Number(payload.encode_ms || 0)),
      queuedAgeMs: Math.max(0, Number(payload.queued_age_ms || 0)),
      sendDrainMs: Math.max(0, Number(payload.send_drain_ms || 0)),
      budgetMaxEncodedBytesPerFrame: Math.max(0, Number(payload.budget_max_encoded_bytes_per_frame || 0)),
      budgetMaxWireBytesPerSecond: Math.max(0, Number(payload.budget_max_wire_bytes_per_second || 0)),
      budgetMaxQueueAgeMs: Math.max(0, Number(payload.budget_max_queue_age_ms || 0)),
      budgetMaxBufferedBytes: Math.max(0, Number(payload.budget_max_buffered_bytes || 0)),
      binaryContinuationState: String(payload.binary_continuation_state || 'unknown_binary_continuation_state'),
      binaryContinuationRequired: Boolean(payload.binary_continuation_required),
      timestampUnixMs: nowMs,
    }
    this.lastFrameTransportSample = sample
    return sample
  }

  private handleMessage(msg: any): void {
    const stringField = (...values: any[]): string => {
      for (const value of values) {
        const normalized = String(value ?? '').trim()
        if (normalized !== '') return normalized
      }
      return ''
    }
    const integerField = (fallback: number, ...values: any[]): number => {
      for (const value of values) {
        const normalized = Number(value)
        if (Number.isFinite(normalized)) return Math.floor(normalized)
      }
      return fallback
    }
    const normalizeUnitFloat = (value: unknown, fallback: number): number => {
      const normalized = Number(value)
      if (!Number.isFinite(normalized)) return fallback
      return Math.max(0, Math.min(1, normalized))
    }

    switch (msg.type) {
      case 'sfu/joined':
        for (const publisherId of (msg.publishers ?? [])) {
          const normalizedPublisherId = stringField(publisherId)
          if (normalizedPublisherId !== '') {
            this.subscribe(normalizedPublisherId)
          }
        }
        break

      case 'sfu/tracks':
        this.cb.onTracks({
          roomId:          stringField(msg.roomId, msg.room_id),
          publisherId:     stringField(msg.publisherId, msg.publisher_id),
          publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
          publisherName:   stringField(msg.publisherName, msg.publisher_name),
          tracks:        msg.tracks ?? [],
        })
        break

      case 'sfu/unpublished':
        this.cb.onUnpublished(
          stringField(msg.publisherId, msg.publisher_id),
          stringField(msg.trackId, msg.track_id),
        )
        break

      case 'sfu/publisher_left':
        this.cb.onPublisherLeft(stringField(msg.publisherId, msg.publisher_id))
        break

      case 'sfu/frame':
        if (this.cb.onEncodedFrame) {
          const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)
          const dataBase64 = stringField(msg.dataBase64, msg.data_base64)
          const payloadChars = Math.max(0, integerField(0, msg.payloadChars, msg.payload_chars))
          const tileMetadataInput = {
            layoutMode: msg.layoutMode ?? msg.layout_mode,
            layerId: msg.layerId ?? msg.layer_id,
            cacheEpoch: msg.cacheEpoch ?? msg.cache_epoch,
            tileColumns: msg.tileColumns ?? msg.tile_columns,
            tileRows: msg.tileRows ?? msg.tile_rows,
            tileWidth: msg.tileWidth ?? msg.tile_width,
            tileHeight: msg.tileHeight ?? msg.tile_height,
            tileIndices: msg.tileIndices ?? msg.tile_indices,
            roiNormX: msg.roiNormX ?? msg.roi_norm_x,
            roiNormY: msg.roiNormY ?? msg.roi_norm_y,
            roiNormWidth: msg.roiNormWidth ?? msg.roi_norm_width,
            roiNormHeight: msg.roiNormHeight ?? msg.roi_norm_height,
          }
          const tileMetadata = normalizeTilePatchMetadata(tileMetadataInput)
          if (this.inboundFrameAssembler.rejectFramePayloadLengthMismatch(msg)) return
          if (!tileMetadata && hasExplicitSfuTileMetadataFields(tileMetadataInput)) {
            reportClientDiagnostic({
              category: 'media',
              level: 'warning',
              eventType: 'sfu_frame_rejected',
              code: 'sfu_frame_rejected',
              message: 'SFU frame used invalid tile/layer/cache metadata and was rejected.',
              roomId: this.roomId,
              payload: {
                room_id: this.roomId,
                publisher_id: stringField(msg.publisherId, msg.publisher_id),
                publisher_user_id: stringField(msg.publisherUserId, msg.publisher_user_id),
                track_id: stringField(msg.trackId, msg.track_id),
                frame_id: stringField(msg.frameId, msg.frame_id),
                frame_sequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
                reject_reason: 'invalid_tile_metadata',
              },
              immediate: true,
            })
            return
          }
          this.cb.onEncodedFrame({
            publisherId: stringField(msg.publisherId, msg.publisher_id),
            publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
            trackId: stringField(msg.trackId, msg.track_id),
            timestamp: msg.timestamp,
            data: dataBase64 !== ''
              ? base64UrlToArrayBuffer(dataBase64)
              : (Array.isArray(msg.data) ? new Uint8Array(msg.data).buffer : new ArrayBuffer(0)),
            dataBase64: dataBase64 || null,
            type: stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta',
            protected: msg.protected && typeof msg.protected === 'object' ? msg.protected : null,
            protectedFrame: protectedFrame || null,
            protectionMode: stringField(msg.protectionMode, msg.protection_mode) === 'required'
              ? 'required'
              : (protectedFrame !== '' ? 'protected' : 'transport_only'),
            protocolVersion: Math.max(1, integerField(1, msg.protocolVersion, msg.protocol_version)),
            frameSequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
            payloadChars,
            chunkCount: Math.max(1, integerField(1, msg.chunkCount, msg.chunk_count)),
            frameId: stringField(msg.frameId, msg.frame_id),
            senderSentAtMs: Math.max(0, integerField(0, msg.senderSentAtMs, msg.sender_sent_at_ms)),
            codecId: stringField(msg.codecId, msg.codec_id),
            runtimeId: stringField(msg.runtimeId, msg.runtime_id),
            outgoingVideoQualityProfile: stringField(
              msg.outgoingVideoQualityProfile,
              msg.outgoing_video_quality_profile,
            ),
            kingReceiveLatencyMs: Math.max(0, Number(msg.kingReceiveLatencyMs ?? msg.king_receive_latency_ms ?? 0)),
            kingFanoutLatencyMs: Math.max(0, Number(msg.kingFanoutLatencyMs ?? msg.king_fanout_latency_ms ?? 0)),
            subscriberSendLatencyMs: Math.max(0, Number(msg.subscriberSendLatencyMs ?? msg.subscriber_send_latency_ms ?? 0)),
            layoutMode: tileMetadata?.layoutMode || 'full_frame',
            layerId: tileMetadata?.layerId || 'full',
            cacheEpoch: Math.max(0, Number(tileMetadata?.cacheEpoch || 0)),
            tileColumns: Math.max(0, Number(tileMetadata?.tileColumns || 0)),
            tileRows: Math.max(0, Number(tileMetadata?.tileRows || 0)),
            tileWidth: Math.max(0, Number(tileMetadata?.tileWidth || 0)),
            tileHeight: Math.max(0, Number(tileMetadata?.tileHeight || 0)),
            tileIndices: Array.isArray(tileMetadata?.tileIndices) ? tileMetadata.tileIndices : null,
            roiNormX: Number(tileMetadata?.roiNormX ?? 0),
            roiNormY: Number(tileMetadata?.roiNormY ?? 0),
            roiNormWidth: Number(tileMetadata?.roiNormWidth ?? 1),
            roiNormHeight: Number(tileMetadata?.roiNormHeight ?? 1),
          })
        }
        break

      case 'sfu/frame-chunk': {
        reportClientDiagnostic({
          category: 'media',
          level: 'error',
          eventType: 'sfu_legacy_frame_chunk_rejected',
          code: 'binary_media_required',
          message: 'SFU media chunks must use binary media envelopes.',
          roomId: this.roomId,
          payload: {
            room_id: this.roomId,
            command_type: 'sfu/frame-chunk',
            publisher_id: stringField(msg.publisherId, msg.publisher_id),
            publisher_user_id: stringField(msg.publisherUserId, msg.publisher_user_id),
            track_id: stringField(msg.trackId, msg.track_id),
            frame_id: stringField(msg.frameId, msg.frame_id),
            frame_sequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
            reject_reason: 'binary_media_required',
            transport_path: 'legacy_json_media_chunk',
          },
          immediate: true,
        })
        break
      }

      case 'sfu/error':
        reportClientDiagnostic({
          category: 'media',
          level: 'error',
          eventType: 'sfu_command_error',
          code: normalizeIdentifier(stringField(msg.error), 'sfu_command_error'),
          message: 'SFU command failed.',
          roomId: stringField(msg.roomId, msg.room_id),
          payload: {
            room_id: stringField(msg.roomId, msg.room_id),
            command_type: stringField(msg.commandType, msg.command_type),
            error: stringField(msg.error),
          },
          immediate: true,
        })
        break
    }
  }
}

function normalizeIdentifier(value: string, fallback = ''): string {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^[_:.-]+|[_:.-]+$/g, '')

  return normalized || fallback
}
