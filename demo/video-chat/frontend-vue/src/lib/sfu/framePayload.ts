export const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024
export const SFU_FRAME_PROTOCOL_VERSION = 2

export type SfuFrameType = 'keyframe' | 'delta'
export type SfuProtectionMode = 'transport_only' | 'protected' | 'required'
export type SfuChunkField = 'data_base64_chunk' | 'protected_frame_chunk'

export interface SfuOutboundFrameInput {
  publisherId: string
  publisherUserId?: string
  trackId: string
  timestamp: number
  data?: ArrayBuffer
  dataBase64?: string | null
  type: SfuFrameType
  protectedFrame?: string | null
  protectionMode?: SfuProtectionMode
  frameSequence?: number
  senderSentAtMs?: number
}

export interface PreparedSfuOutboundFramePayload {
  payload: Record<string, unknown>
  chunkField: SfuChunkField | null
  chunkValue: string
  metrics: Record<string, unknown>
  rawByteLength: number
  payloadChars: number
  chunkCount: number
  frameType: SfuFrameType
  protectionMode: SfuProtectionMode
  publisherId: string
  trackId: string
  timestamp: number
  frameSequence: number
  senderSentAtMs: number
}

export function prepareSfuOutboundFramePayload(frame: SfuOutboundFrameInput): PreparedSfuOutboundFramePayload {
  const rawByteLength = Number(frame.data?.byteLength || 0)
  const frameSequence = normalizeNonNegativeInteger(frame.frameSequence)
  const senderSentAtMs = normalizePositiveInteger(frame.senderSentAtMs, Date.now())
  const payload: Record<string, unknown> = {
    type: 'sfu/frame',
    protocol_version: SFU_FRAME_PROTOCOL_VERSION,
    publisher_id: frame.publisherId,
    publisher_user_id: frame.publisherUserId || '',
    track_id: frame.trackId,
    timestamp: frame.timestamp,
    frame_type: frame.type,
    frame_sequence: frameSequence,
    sender_sent_at_ms: senderSentAtMs,
  }

  let chunkField: SfuChunkField | null = null
  let chunkValue = ''

  const protectedFrame = String(frame.protectedFrame || '').trim()
  if (protectedFrame !== '') {
    chunkField = 'protected_frame_chunk'
    chunkValue = protectedFrame
    payload.protected_frame = protectedFrame
    payload.protection_mode = normalizeProtectionMode(frame.protectionMode, 'protected')
  } else {
    chunkField = 'data_base64_chunk'
    chunkValue = String(frame.dataBase64 || '').trim()
    if (chunkValue === '') {
      chunkValue = arrayBufferToBase64Url(frame.data || new ArrayBuffer(0))
    }
    payload.data_base64 = chunkValue
    payload.protection_mode = normalizeProtectionMode(frame.protectionMode, 'transport_only')
  }

  const payloadChars = chunkValue.length
  const chunkCount = frameChunkCount(payloadChars)
  const protectionMode = normalizeProtectionMode(payload.protection_mode, 'transport_only')
  payload.payload_chars = payloadChars
  payload.chunk_count = chunkCount

  return {
    payload,
    chunkField,
    chunkValue,
    rawByteLength,
    payloadChars,
    chunkCount,
    frameType: frame.type,
    protectionMode,
    publisherId: frame.publisherId,
    trackId: frame.trackId,
    timestamp: frame.timestamp,
    frameSequence,
    senderSentAtMs,
    metrics: {
      protocol_version: SFU_FRAME_PROTOCOL_VERSION,
      publisher_id: frame.publisherId,
      track_id: frame.trackId,
      frame_type: frame.type,
      frame_sequence: frameSequence,
      sender_sent_at_ms: senderSentAtMs,
      protection_mode: protectionMode,
      raw_byte_length: rawByteLength,
      payload_chars: payloadChars,
      chunk_count: chunkCount,
    },
  }
}

export function createSfuFrameId(): string {
  const randomValue = Math.random().toString(36).slice(2, 10)
  return `frame_${Date.now().toString(36)}_${randomValue}`
}

export function frameChunkCount(payloadChars: number): number {
  return Math.max(1, Math.ceil(Math.max(0, payloadChars) / SFU_FRAME_CHUNK_MAX_CHARS))
}

function normalizeNonNegativeInteger(value: unknown): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized < 0) return 0
  return Math.floor(normalized)
}

function normalizePositiveInteger(value: unknown, fallback: number): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized <= 0) return fallback
  return Math.floor(normalized)
}

export function base64UrlToArrayBuffer(value: string): ArrayBuffer {
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

function normalizeProtectionMode(value: unknown, fallback: SfuProtectionMode): SfuProtectionMode {
  const normalized = String(value || '').trim()
  if (normalized === 'required' || normalized === 'protected' || normalized === 'transport_only') {
    return normalized
  }
  return fallback
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
