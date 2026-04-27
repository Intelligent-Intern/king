import {
  flattenTilePatchMetadata,
  normalizeTilePatchMetadata,
  parseTilePatchMetadataJson,
  serializeTilePatchMetadata,
  type SfuTilePatchMetadata,
} from './tilePatchMetadata'

export const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024
export const SFU_FRAME_PROTOCOL_VERSION = 2
export const SFU_BINARY_FRAME_MAGIC = 'KSFB'
export const SFU_BINARY_FRAME_ENVELOPE_VERSION = 1
export const SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION = 2

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
  tilePatch?: SfuTilePatchMetadata | null
  transportMetrics?: Record<string, unknown>
}

export interface PreparedSfuOutboundFramePayload {
  payload: Record<string, unknown>
  chunkField: SfuChunkField | null
  chunkValue: string
  metrics: Record<string, unknown>
  rawByteLength: number
  projectedBinaryEnvelopeBytes: number
  payloadChars: number
  chunkCount: number
  frameType: SfuFrameType
  protectionMode: SfuProtectionMode
  publisherId: string
  trackId: string
  timestamp: number
  frameSequence: number
  senderSentAtMs: number
  tilePatch: SfuTilePatchMetadata | null
}

export interface DecodedSfuBinaryFrameEnvelope {
  payload: Record<string, unknown>
  payloadBytes: ArrayBuffer
  payloadByteLength: number
  protectedFrame: string | null
  dataBase64: string | null
  tilePatch: SfuTilePatchMetadata | null
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
  const transportMetrics = normalizeTransportMetrics(frame.transportMetrics)
  Object.assign(payload, transportMetrics)
  const tilePatch = normalizeTilePatchMetadata(frame.tilePatch || frame)
  Object.assign(payload, flattenTilePatchMetadata(tilePatch))

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
  const payloadByteLength = protectedFrame !== '' || chunkValue !== ''
    ? base64UrlToArrayBuffer(chunkValue).byteLength
    : rawByteLength
  const roiAreaRatio = tilePatch
    ? Number((Math.max(0, Number(tilePatch.roiNormWidth || 0)) * Math.max(0, Number(tilePatch.roiNormHeight || 0))).toFixed(6))
    : 1
  const projectedBinaryEnvelopeBytes = (tilePatch ? 46 : 40)
    + encodeUtf8(String(frame.publisherId || '')).byteLength
    + encodeUtf8(String(frame.publisherUserId || '')).byteLength
    + encodeUtf8(String(frame.trackId || '')).byteLength
    + encodeUtf8('').byteLength
    + encodeUtf8(serializeTilePatchMetadata(tilePatch)).byteLength
    + payloadByteLength

  return {
    payload,
    chunkField,
    chunkValue,
    rawByteLength,
    projectedBinaryEnvelopeBytes,
    payloadChars,
    chunkCount,
    frameType: frame.type,
    protectionMode,
    publisherId: frame.publisherId,
    trackId: frame.trackId,
    timestamp: frame.timestamp,
    frameSequence,
    senderSentAtMs,
    tilePatch,
    metrics: {
      protocol_version: SFU_FRAME_PROTOCOL_VERSION,
      publisher_id: frame.publisherId,
      track_id: frame.trackId,
      frame_type: frame.type,
      frame_sequence: frameSequence,
      sender_sent_at_ms: senderSentAtMs,
      protection_mode: protectionMode,
      raw_byte_length: rawByteLength,
      payload_bytes: payloadByteLength,
      payload_chars: payloadChars,
      chunk_count: chunkCount,
      projected_binary_envelope_bytes: projectedBinaryEnvelopeBytes,
      projected_binary_envelope_overhead_bytes: Math.max(0, projectedBinaryEnvelopeBytes - payloadByteLength),
      legacy_base64_overhead_bytes: Math.max(0, payloadChars - payloadByteLength),
      projected_binary_delta_vs_legacy_bytes: projectedBinaryEnvelopeBytes - payloadChars,
      layout_mode: String(tilePatch?.layoutMode || 'full_frame'),
      layer_id: String(tilePatch?.layerId || 'full'),
      transport_frame_kind: `${String(tilePatch?.layoutMode || 'full_frame')}:${String(tilePatch?.layerId || 'full')}`,
      cache_epoch: Number(tilePatch?.cacheEpoch || 0),
      tile_count: Number(tilePatch?.tileIndices.length || 0),
      selection_tile_count: Number(transportMetrics.selection_tile_count || 0),
      selection_total_tile_count: Number(transportMetrics.selection_total_tile_count || 0),
      selection_tile_ratio: Number(transportMetrics.selection_tile_ratio || 0),
      selection_mask_guided: Boolean(transportMetrics.selection_mask_guided),
      roi_area_ratio: roiAreaRatio,
    },
  }
}

function normalizeTransportMetrics(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object') return {}
  const source = value as Record<string, unknown>
  const selectionTileCount = normalizeNonNegativeInteger(source.selection_tile_count ?? source.selectionTileCount)
  const selectionTotalTileCount = normalizeNonNegativeInteger(source.selection_total_tile_count ?? source.selectionTotalTileCount)
  const selectionTileRatio = normalizeUnitFloat(source.selection_tile_ratio ?? source.selectionTileRatio, 0)
  return {
    selection_tile_count: selectionTileCount,
    selection_total_tile_count: selectionTotalTileCount,
    selection_tile_ratio: selectionTileRatio,
    selection_mask_guided: Boolean(source.selection_mask_guided ?? source.selectionMaskGuided),
  }
}

export function createSfuFrameId(): string {
  const randomValue = Math.random().toString(36).slice(2, 10)
  return `frame_${Date.now().toString(36)}_${randomValue}`
}

export function frameChunkCount(payloadChars: number): number {
  return Math.max(1, Math.ceil(Math.max(0, payloadChars) / SFU_FRAME_CHUNK_MAX_CHARS))
}

export function encodeSfuBinaryFrameEnvelope(prepared: PreparedSfuOutboundFramePayload): ArrayBuffer | null {
  const payloadBytes = base64UrlToArrayBuffer(prepared.chunkValue)
  const payloadByteLength = Number(payloadBytes.byteLength || 0)
  if (payloadByteLength <= 0) return null

  const publisherIdBytes = encodeUtf8(prepared.publisherId)
  const publisherUserIdBytes = encodeUtf8(String(prepared.payload.publisher_user_id || ''))
  const trackIdBytes = encodeUtf8(prepared.trackId)
  const frameIdBytes = encodeUtf8(String(prepared.payload.frame_id || ''))
  const metadataJsonBytes = encodeUtf8(serializeTilePatchMetadata(prepared.tilePatch))
  const envelopeVersion = metadataJsonBytes.byteLength > 0
    ? SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    : SFU_BINARY_FRAME_ENVELOPE_VERSION

  const headerByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION ? 46 : 40
  const totalByteLength = headerByteLength
    + publisherIdBytes.byteLength
    + publisherUserIdBytes.byteLength
    + trackIdBytes.byteLength
    + frameIdBytes.byteLength
    + metadataJsonBytes.byteLength
    + payloadByteLength

  const out = new ArrayBuffer(totalByteLength)
  const view = new DataView(out)
  const bytes = new Uint8Array(out)

  bytes.set(encodeAscii(SFU_BINARY_FRAME_MAGIC), 0)
  view.setUint8(4, envelopeVersion)
  view.setUint8(5, 1)
  view.setUint8(6, prepared.frameType === 'keyframe' ? 1 : 0)
  view.setUint8(7, protectionModeCode(prepared.protectionMode))
  view.setUint16(8, Number(prepared.payload.protocol_version || SFU_FRAME_PROTOCOL_VERSION), true)
  view.setUint16(10, publisherIdBytes.byteLength, true)
  view.setUint16(12, publisherUserIdBytes.byteLength, true)
  view.setUint16(14, trackIdBytes.byteLength, true)
  view.setUint16(16, frameIdBytes.byteLength, true)
  if (envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION) {
    view.setUint32(18, metadataJsonBytes.byteLength, true)
    writeUint64(view, 22, Number(prepared.timestamp || 0))
    view.setUint32(30, Math.max(0, Number(prepared.frameSequence || 0)), true)
    writeUint64(view, 34, Math.max(0, Number(prepared.senderSentAtMs || 0)))
    view.setUint32(42, payloadByteLength, true)
  } else {
    writeUint64(view, 18, Number(prepared.timestamp || 0))
    view.setUint32(26, Math.max(0, Number(prepared.frameSequence || 0)), true)
    writeUint64(view, 30, Math.max(0, Number(prepared.senderSentAtMs || 0)))
    view.setUint32(38, payloadByteLength, true)
  }

  let offset = headerByteLength
  bytes.set(publisherIdBytes, offset)
  offset += publisherIdBytes.byteLength
  bytes.set(publisherUserIdBytes, offset)
  offset += publisherUserIdBytes.byteLength
  bytes.set(trackIdBytes, offset)
  offset += trackIdBytes.byteLength
  bytes.set(frameIdBytes, offset)
  offset += frameIdBytes.byteLength
  if (metadataJsonBytes.byteLength > 0) {
    bytes.set(metadataJsonBytes, offset)
    offset += metadataJsonBytes.byteLength
  }
  bytes.set(new Uint8Array(payloadBytes), offset)

  return out
}

export function decodeSfuBinaryFrameEnvelope(input: ArrayBuffer): DecodedSfuBinaryFrameEnvelope | null {
  const buffer = input instanceof ArrayBuffer ? input : new ArrayBuffer(0)
  if (buffer.byteLength < 40) return null

  const view = new DataView(buffer)
  const bytes = new Uint8Array(buffer)
  if (decodeAscii(bytes.subarray(0, 4)) !== SFU_BINARY_FRAME_MAGIC) return null
  const envelopeVersion = view.getUint8(4)
  if (
    envelopeVersion !== SFU_BINARY_FRAME_ENVELOPE_VERSION
    && envelopeVersion !== SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
  ) return null
  if (view.getUint8(5) !== 1) return null

  const frameType = view.getUint8(6) === 1 ? 'keyframe' : 'delta'
  const protectionMode = codeProtectionMode(view.getUint8(7))
  const protocolVersion = Math.max(1, view.getUint16(8, true))
  const publisherIdLength = view.getUint16(10, true)
  const publisherUserIdLength = view.getUint16(12, true)
  const trackIdLength = view.getUint16(14, true)
  const frameIdLength = view.getUint16(16, true)
  const headerByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION ? 46 : 40
  const metadataJsonByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    ? view.getUint32(18, true)
    : 0
  const timestamp = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    ? readUint64(view, 22)
    : readUint64(view, 18)
  const frameSequence = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    ? view.getUint32(30, true)
    : view.getUint32(26, true)
  const senderSentAtMs = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    ? readUint64(view, 34)
    : readUint64(view, 30)
  const payloadByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    ? view.getUint32(42, true)
    : view.getUint32(38, true)

  const metadataByteLength = publisherIdLength + publisherUserIdLength + trackIdLength + frameIdLength + metadataJsonByteLength
  if ((headerByteLength + metadataByteLength + payloadByteLength) !== buffer.byteLength) {
    return null
  }

  let offset = headerByteLength
  const publisherId = decodeUtf8(bytes.subarray(offset, offset + publisherIdLength))
  offset += publisherIdLength
  const publisherUserId = decodeUtf8(bytes.subarray(offset, offset + publisherUserIdLength))
  offset += publisherUserIdLength
  const trackId = decodeUtf8(bytes.subarray(offset, offset + trackIdLength))
  offset += trackIdLength
  const frameId = decodeUtf8(bytes.subarray(offset, offset + frameIdLength))
  offset += frameIdLength
  const metadataJson = metadataJsonByteLength > 0
    ? decodeUtf8(bytes.subarray(offset, offset + metadataJsonByteLength))
    : ''
  offset += metadataJsonByteLength
  const payloadBytes = bytes.slice(offset, offset + payloadByteLength).buffer
  const tilePatch = parseTilePatchMetadataJson(metadataJson)

  const protectedFrame = protectionMode === 'transport_only' ? null : arrayBufferToBase64Url(payloadBytes)
  const dataBase64 = protectionMode === 'transport_only' ? arrayBufferToBase64Url(payloadBytes) : null

  return {
    payloadBytes,
    payloadByteLength,
    protectedFrame,
    dataBase64,
    tilePatch,
    payload: {
      type: 'sfu/frame',
      protocol_version: protocolVersion,
      publisher_id: publisherId,
      publisher_user_id: publisherUserId,
      track_id: trackId,
      timestamp,
      frame_type: frameType,
      protection_mode: protectionMode,
      frame_sequence: frameSequence,
      sender_sent_at_ms: senderSentAtMs,
      payload_bytes: payloadByteLength,
      payload_chars: payloadByteLength,
      chunk_count: 1,
      frame_id: frameId,
      ...flattenTilePatchMetadata(tilePatch),
      ...(protectedFrame ? { protected_frame: protectedFrame } : {}),
      ...(dataBase64 ? { data_base64: dataBase64 } : {}),
    },
  }
}

function normalizeNonNegativeInteger(value: unknown): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized < 0) return 0
  return Math.floor(normalized)
}

function normalizeUnitFloat(value: unknown, fallback = 0): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized)) return fallback
  return Math.max(0, Math.min(1, normalized))
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

export function arrayBufferToBase64Url(buffer: ArrayBuffer): string {
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

function protectionModeCode(value: SfuProtectionMode): number {
  if (value === 'required') return 2
  if (value === 'protected') return 1
  return 0
}

function codeProtectionMode(value: number): SfuProtectionMode {
  if (value === 2) return 'required'
  if (value === 1) return 'protected'
  return 'transport_only'
}

function encodeAscii(value: string): Uint8Array {
  return Uint8Array.from(String(value || ''), (character) => character.charCodeAt(0) & 0xff)
}

function decodeAscii(value: Uint8Array): string {
  let out = ''
  for (let index = 0; index < value.byteLength; index += 1) {
    out += String.fromCharCode(value[index] || 0)
  }
  return out
}

function encodeUtf8(value: string): Uint8Array {
  return new TextEncoder().encode(String(value || ''))
}

function decodeUtf8(value: Uint8Array): string {
  return new TextDecoder().decode(value)
}

function writeUint64(view: DataView, offset: number, value: number): void {
  const normalized = BigInt(Math.max(0, Math.floor(Number(value || 0))))
  view.setUint32(offset, Number(normalized & 0xffffffffn), true)
  view.setUint32(offset + 4, Number((normalized >> 32n) & 0xffffffffn), true)
}

function readUint64(view: DataView, offset: number): number {
  const low = BigInt(view.getUint32(offset, true))
  const high = BigInt(view.getUint32(offset + 4, true))
  return Number((high << 32n) | low)
}
