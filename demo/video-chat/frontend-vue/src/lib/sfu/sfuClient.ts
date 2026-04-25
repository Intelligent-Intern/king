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
}

export interface SFUClientCallbacks {
  onTracks:        (e: SFUTracksEvent) => void
  onUnpublished:   (publisherId: string, trackId: string) => void
  onPublisherLeft: (publisherId: string) => void
  onConnected?:    () => void
  onDisconnect:    () => void
  onEncodedFrame?: (frame: SFUEncodedFrame) => void
}

const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024
const SFU_FRAME_CHUNK_TTL_MS = 5000

interface PendingInboundFrameChunk {
  publisherId: string
  publisherUserId: string
  trackId: string
  timestamp: number
  frameType: 'keyframe' | 'delta'
  protectionMode: 'transport_only' | 'protected' | 'required'
  chunkField: 'data_base64_chunk' | 'protected_frame_chunk'
  chunkCount: number
  updatedAtMs: number
  chunks: Map<number, string>
}

export class SFUClient {
  private ws: WebSocket | null = null
  private cb: SFUClientCallbacks
  private connectGeneration = 0
  private disconnectNotified = false
  private pendingInboundFrameChunks = new Map<string, PendingInboundFrameChunk>()

  constructor(cb: SFUClientCallbacks) {
    this.cb = cb
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
    this.pendingInboundFrameChunks.clear()
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
    return Math.max(0, Number(this.ws?.bufferedAmount || 0))
  }

  subscribe(publisherId: string): void {
    this.send({ type: 'sfu/subscribe', publisher_id: publisherId })
  }

  unpublishTrack(trackId: string): void {
    this.send({ type: 'sfu/unpublish', track_id: trackId })
  }

  sendEncodedFrame(frame: SFUEncodedFrame): void {
    const payload: Record<string, unknown> = {
      type: 'sfu/frame',
      publisher_id: frame.publisherId,
      publisher_user_id: frame.publisherUserId || '',
      track_id: frame.trackId,
      timestamp: frame.timestamp,
      frame_type: frame.type,
    }
    if (frame.protectedFrame) {
      payload.protected_frame = frame.protectedFrame
      payload.protection_mode = frame.protectionMode || 'protected'
    } else {
      const normalizedBase64 = String(frame.dataBase64 || '').trim()
      if (normalizedBase64 !== '') {
        payload.data_base64 = normalizedBase64
      } else {
        payload.data_base64 = arrayBufferToBase64Url(frame.data || new ArrayBuffer(0))
      }
      payload.protection_mode = frame.protectionMode || 'transport_only'
    }

    const protectedFrame = String(payload.protected_frame || '').trim()
    if (protectedFrame !== '') {
      if (protectedFrame.length > SFU_FRAME_CHUNK_MAX_CHARS) {
        this.sendChunkedFramePayload(payload, 'protected_frame_chunk', protectedFrame)
        return
      }
      this.send(payload)
      return
    }

    const dataBase64 = String(payload.data_base64 || '').trim()
    if (dataBase64.length > SFU_FRAME_CHUNK_MAX_CHARS) {
      this.sendChunkedFramePayload(payload, 'data_base64_chunk', dataBase64)
      return
    }

    this.send(payload)
  }

  leave(): void {
    this.connectGeneration += 1
    this.disconnectNotified = false
    this.pendingInboundFrameChunks.clear()
    this.send({ type: 'sfu/leave' })
    if (this.ws) {
      // Force-close even when still CONNECTING to prevent orphaned sockets
      // that cause "WebSocket is closed before the connection is established".
      this.retireSocket(this.ws, true)
    }
    this.ws = null
  }

  private send(msg: object): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(msg))
    }
  }

  private sendChunkedFramePayload(
    payload: Record<string, unknown>,
    chunkField: 'data_base64_chunk' | 'protected_frame_chunk',
    chunkValue: string,
  ): void {
    const totalChunks = Math.max(1, Math.ceil(chunkValue.length / SFU_FRAME_CHUNK_MAX_CHARS))
    const frameId = createSfuFrameId()

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
      const start = chunkIndex * SFU_FRAME_CHUNK_MAX_CHARS
      const end = start + SFU_FRAME_CHUNK_MAX_CHARS
      const chunkPayload: Record<string, unknown> = {
        type: 'sfu/frame-chunk',
        frame_id: frameId,
        publisher_id: payload.publisher_id,
        publisher_user_id: payload.publisher_user_id,
        track_id: payload.track_id,
        timestamp: payload.timestamp,
        frame_type: payload.frame_type,
        protection_mode: payload.protection_mode,
        chunk_index: chunkIndex,
        chunk_count: totalChunks,
      }
      chunkPayload[chunkField] = chunkValue.slice(start, end)
      this.send(chunkPayload)
    }
  }

  private cleanupPendingInboundFrameChunks(): void {
    const cutoffMs = Date.now() - SFU_FRAME_CHUNK_TTL_MS
    for (const [frameId, entry] of this.pendingInboundFrameChunks.entries()) {
      if (entry.updatedAtMs < cutoffMs) {
        this.pendingInboundFrameChunks.delete(frameId)
      }
    }
  }

  private acceptInboundFrameChunk(msg: any): any | null {
    const stringField = (...values: any[]): string => {
      for (const value of values) {
        const normalized = String(value ?? '').trim()
        if (normalized !== '') return normalized
      }
      return ''
    }

    const frameId = stringField(msg.frameId, msg.frame_id)
    const chunkCount = Number(msg.chunkCount ?? msg.chunk_count ?? 0)
    const chunkIndex = Number(msg.chunkIndex ?? msg.chunk_index ?? -1)
    const protectedChunk = stringField(msg.protectedFrameChunk, msg.protected_frame_chunk)
    const dataChunk = stringField(msg.dataBase64Chunk, msg.data_base64_chunk)
    const chunkField = protectedChunk !== '' ? 'protected_frame_chunk' : 'data_base64_chunk'
    const chunkValue = protectedChunk !== '' ? protectedChunk : dataChunk

    if (
      frameId === ''
      || !Number.isInteger(chunkCount)
      || !Number.isInteger(chunkIndex)
      || chunkCount < 1
      || chunkIndex < 0
      || chunkIndex >= chunkCount
      || chunkValue === ''
    ) {
      return null
    }

    this.cleanupPendingInboundFrameChunks()

    const publisherId = stringField(msg.publisherId, msg.publisher_id)
    const publisherUserId = stringField(msg.publisherUserId, msg.publisher_user_id)
    const trackId = stringField(msg.trackId, msg.track_id)
    const timestamp = Number(msg.timestamp || 0)
    const frameType = stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta'
    const protectionMode = stringField(msg.protectionMode, msg.protection_mode) === 'required'
      ? 'required'
      : (chunkField === 'protected_frame_chunk' ? 'protected' : 'transport_only')

    const existing = this.pendingInboundFrameChunks.get(frameId)
    if (!existing) {
      this.pendingInboundFrameChunks.set(frameId, {
        publisherId,
        publisherUserId,
        trackId,
        timestamp,
        frameType,
        protectionMode,
        chunkField,
        chunkCount,
        updatedAtMs: Date.now(),
        chunks: new Map([[chunkIndex, chunkValue]]),
      })
      return chunkCount === 1
        ? {
            type: 'sfu/frame',
            publisher_id: publisherId,
            publisher_user_id: publisherUserId,
            track_id: trackId,
            timestamp,
            frame_type: frameType,
            protection_mode: protectionMode,
            ...(chunkField === 'protected_frame_chunk'
              ? { protected_frame: chunkValue }
              : { data_base64: chunkValue }),
          }
        : null
    }

    if (
      existing.publisherId !== publisherId
      || existing.publisherUserId !== publisherUserId
      || existing.trackId !== trackId
      || existing.timestamp !== timestamp
      || existing.frameType !== frameType
      || existing.protectionMode !== protectionMode
      || existing.chunkField !== chunkField
      || existing.chunkCount !== chunkCount
    ) {
      this.pendingInboundFrameChunks.delete(frameId)
      return null
    }

    existing.updatedAtMs = Date.now()
    existing.chunks.set(chunkIndex, chunkValue)
    if (existing.chunks.size < existing.chunkCount) return null

    let assembled = ''
    for (let index = 0; index < existing.chunkCount; index += 1) {
      const nextChunk = existing.chunks.get(index)
      if (typeof nextChunk !== 'string' || nextChunk === '') {
        return null
      }
      assembled += nextChunk
    }

    this.pendingInboundFrameChunks.delete(frameId)
    return {
      type: 'sfu/frame',
      publisher_id: existing.publisherId,
      publisher_user_id: existing.publisherUserId,
      track_id: existing.trackId,
      timestamp: existing.timestamp,
      frame_type: existing.frameType,
      protection_mode: existing.protectionMode,
      ...(existing.chunkField === 'protected_frame_chunk'
        ? { protected_frame: assembled }
        : { data_base64: assembled }),
    }
  }

  private handleMessage(msg: any): void {
    const stringField = (...values: any[]): string => {
      for (const value of values) {
        const normalized = String(value ?? '').trim()
        if (normalized !== '') return normalized
      }
      return ''
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
          })
        }
        break

      case 'sfu/frame-chunk': {
        const reassembledFrame = this.acceptInboundFrameChunk(msg)
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

function createSfuFrameId(): string {
  const randomValue = Math.random().toString(36).slice(2, 10)
  return `frame_${Date.now().toString(36)}_${randomValue}`
}

function arrayBufferToBase64Url(buffer: ArrayBuffer): string {
  const view = new Uint8Array(buffer || new ArrayBuffer(0))
  let binary = ''
  for (let index = 0; index < view.byteLength; index += 1) {
    binary += String.fromCharCode(view[index])
  }
  const base64 = typeof btoa === 'function'
    ? btoa(binary)
    : Buffer.from(view).toString('base64')
  return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

function base64UrlToArrayBuffer(value: string): ArrayBuffer {
  const normalized = String(value || '').trim()
  if (normalized === '') return new ArrayBuffer(0)
  const base64 = normalized.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const binary = typeof atob === 'function'
    ? atob(padded)
    : Buffer.from(padded, 'base64').toString('binary')
  const out = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) {
    out[index] = binary.charCodeAt(index)
  }
  return out.buffer
}
