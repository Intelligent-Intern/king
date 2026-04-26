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
  SFU_FRAME_CHUNK_MAX_CHARS,
  base64UrlToArrayBuffer,
  createSfuFrameId,
  prepareSfuOutboundFramePayload,
  type PreparedSfuOutboundFramePayload,
  type SfuChunkField,
} from './framePayload'
import { SfuOutboundFrameQueue } from './outboundFrameQueue'

export interface SFUTrack {
  id: string
  kind: 'audio' | 'video'
  label: string
}

export interface SFUTracksEvent {
  roomId: string
  publisherId: string
  publisherUserId: string
  publisherName: string
  tracks: SFUTrack[]
}

export interface SFUEncodedFrame {
  publisherId: string
  publisherUserId?: string
  trackId: string
  timestamp: number
  data?: ArrayBuffer
  dataBase64?: string | null
  type: 'keyframe' | 'delta'
  protected?: Record<string, unknown> | null
  protectedFrame?: string | null
  protectionMode?: 'transport_only' | 'protected' | 'required'
  protocolVersion?: number
  frameSequence?: number
  payloadChars?: number
  chunkCount?: number
  frameId?: string
  senderSentAtMs?: number
}

export interface SFUClientCallbacks {
  onTracks:        (e: SFUTracksEvent) => void
  onUnpublished:   (publisherId: string, trackId: string) => void
  onPublisherLeft: (publisherId: string) => void
  onConnected?:    () => void
  onDisconnect:    () => void
  onEncodedFrame?: (frame: SFUEncodedFrame) => void
}

const SFU_FRAME_CHUNK_BACKPRESSURE_BYTES = 512 * 1024
const SFU_FRAME_CHUNK_BACKPRESSURE_SLEEP_MS = 16
const SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 2000
const SFU_FRAME_SEND_PRESSURE_DIAGNOSTIC_COOLDOWN_MS = 3000
const SFU_FRAME_CHUNK_DIAGNOSTIC_MIN_CHUNKS = 16
const SFU_FRAME_SEND_QUEUE_DIAGNOSTIC_COOLDOWN_MS = 1500

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
  private lastFrameSendPressureDiagnosticAtMs = 0
  private lastFrameQueueDiagnosticAtMs = 0

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
    const frameSequence = this.nextOutboundFrameSequence(frame.trackId)
    return this.enqueueEncodedFrame(prepareSfuOutboundFramePayload({
      ...frame,
      frameSequence,
      senderSentAtMs: Date.now(),
    }))
  }

  leave(): void {
    this.connectGeneration += 1
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
    this.reportFrameSendPressureIfNeeded({
      ...metrics,
      buffered_amount: this.getWebSocketBufferedAmount(),
    })

    if (prepared.chunkField && prepared.chunkValue.length > SFU_FRAME_CHUNK_MAX_CHARS) {
      return this.sendChunkedFramePayload(prepared.payload, prepared.chunkField, prepared.chunkValue, metrics)
    }

    const drain = await this.waitForSendBufferDrain()
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
      return false
    }
    return this.send(prepared.payload)
  }

  private clearOutboundFrameQueue(reason: string): void {
    const droppedCount = this.outboundFrameQueue.clear()
    if (droppedCount <= 0) return
    this.reportFrameSendDiagnostic(
      'sfu_frame_send_queue_cleared',
      'warning',
      'SFU frame send queue was cleared before frames were sent.',
      {
        drop_reason: reason,
        dropped_frame_count: droppedCount,
      },
    )
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

  private async sendChunkedFramePayload(
    payload: Record<string, unknown>,
    chunkField: SfuChunkField,
    chunkValue: string,
    metrics: Record<string, unknown> = {},
  ): Promise<boolean> {
    const totalChunks = Math.max(1, Math.ceil(chunkValue.length / SFU_FRAME_CHUNK_MAX_CHARS))
    const frameId = createSfuFrameId()
    let totalWaitMs = 0

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
      const drain = await this.waitForSendBufferDrain()
      totalWaitMs += drain.waitedMs
      if (!drain.ok) {
        this.reportFrameSendDiagnostic(
          'sfu_frame_send_aborted',
          'error',
          'SFU frame send aborted while waiting for websocket backpressure to drain.',
          {
            ...metrics,
            frame_id: frameId,
            chunk_index: chunkIndex,
            chunk_count: totalChunks,
            chunk_field: chunkField,
            buffered_amount: drain.bufferedAmount,
            send_wait_ms: totalWaitMs,
            abort_reason: 'send_buffer_drain_timeout',
          },
          true,
        )
        return false
      }
      const start = chunkIndex * SFU_FRAME_CHUNK_MAX_CHARS
      const end = start + SFU_FRAME_CHUNK_MAX_CHARS
      const chunkPayload: Record<string, unknown> = {
        type: 'sfu/frame-chunk',
        protocol_version: payload.protocol_version,
        frame_id: frameId,
        publisher_id: payload.publisher_id,
        publisher_user_id: payload.publisher_user_id,
        track_id: payload.track_id,
        timestamp: payload.timestamp,
        frame_type: payload.frame_type,
        frame_sequence: payload.frame_sequence,
        sender_sent_at_ms: payload.sender_sent_at_ms,
        protection_mode: payload.protection_mode,
        payload_chars: chunkValue.length,
        chunk_payload_chars: Math.max(0, chunkValue.slice(start, end).length),
        chunk_index: chunkIndex,
        chunk_count: totalChunks,
      }
      chunkPayload[chunkField] = chunkValue.slice(start, end)
      if (!this.send(chunkPayload)) {
        this.reportFrameSendDiagnostic(
          'sfu_frame_send_aborted',
          'error',
          'SFU frame send aborted because the websocket was not open for a chunk.',
          {
            ...metrics,
            frame_id: frameId,
            chunk_index: chunkIndex,
            chunk_count: totalChunks,
            chunk_field: chunkField,
            buffered_amount: this.getWebSocketBufferedAmount(),
            send_wait_ms: totalWaitMs,
            abort_reason: 'socket_not_open',
          },
          true,
        )
        return false
      }
    }
    this.reportFrameSendPressureIfNeeded({
      ...metrics,
      frame_id: frameId,
      chunk_field: chunkField,
      chunk_count: totalChunks,
      buffered_amount: this.getWebSocketBufferedAmount(),
      send_wait_ms: totalWaitMs,
    })
    return true
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
          if (this.inboundFrameAssembler.rejectFramePayloadLengthMismatch(msg)) return
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
          })
        }
        break

      case 'sfu/frame-chunk': {
        const reassembledFrame = this.inboundFrameAssembler.acceptChunk(msg)
        if (reassembledFrame) {
          this.handleMessage(reassembledFrame)
        }
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
