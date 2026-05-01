import { reportClientDiagnostic } from '../../support/clientDiagnostics'
import { hasExplicitSfuTileMetadataFields, normalizeTilePatchMetadata } from './tilePatchMetadata'

const SFU_FRAME_CHUNK_TTL_MS = 5000
const SFU_FRAME_CHUNK_MAX_COUNT = 4096
const SFU_FRAME_RECEIVE_DIAGNOSTIC_COOLDOWN_MS = 1500

interface PendingInboundFrameChunk {
  publisherId: string
  publisherUserId: string
  trackId: string
  timestamp: number
  frameType: 'keyframe' | 'delta'
  protectionMode: 'transport_only' | 'protected' | 'required'
  chunkField: 'data_base64_chunk' | 'protected_frame_chunk'
  chunkCount: number
  payloadChars: number
  protocolVersion: number
  frameSequence: number
  senderSentAtMs: number
  codecId: string
  runtimeId: string
  layoutMode: 'full_frame' | 'tile_foreground' | 'background_snapshot'
  layerId: 'full' | 'foreground' | 'background'
  cacheEpoch: number
  tileColumns: number
  tileRows: number
  tileWidth: number
  tileHeight: number
  tileIndices: number[]
  roiNormX: number
  roiNormY: number
  roiNormWidth: number
  roiNormHeight: number
  updatedAtMs: number
  chunks: Map<number, string>
}

interface SfuInboundFrameAssemblerOptions {
  getRoomId: () => string
}

export class SfuInboundFrameAssembler {
  private pendingChunks = new Map<string, PendingInboundFrameChunk>()
  private lastDiagnosticAtMs = 0

  constructor(private readonly options: SfuInboundFrameAssemblerOptions) {}

  clear(): void {
    this.pendingChunks.clear()
  }

  rejectFramePayloadLengthMismatch(msg: any): boolean {
    const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)
    const dataBase64 = stringField(msg.dataBase64, msg.data_base64)
    const payloadChars = Math.max(0, integerField(0, msg.payloadChars, msg.payload_chars))
    const actualPayloadChars = protectedFrame !== '' ? protectedFrame.length : dataBase64.length
    if (payloadChars <= 0 || actualPayloadChars <= 0 || payloadChars === actualPayloadChars) {
      return false
    }

    this.reportDiagnostic(
      'sfu_frame_rejected',
      'warning',
      'SFU frame payload length did not match the advertised length.',
      {
        publisher_id: stringField(msg.publisherId, msg.publisher_id),
        publisher_user_id: stringField(msg.publisherUserId, msg.publisher_user_id),
        track_id: stringField(msg.trackId, msg.track_id),
        frame_id: stringField(msg.frameId, msg.frame_id),
        frame_sequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
        payload_chars: payloadChars,
        actual_payload_chars: actualPayloadChars,
        reject_reason: 'payload_length_mismatch',
      },
      true,
    )
    return true
  }

  acceptChunk(msg: any): Record<string, unknown> | null {
    const frameId = stringField(msg.frameId, msg.frame_id)
    const chunkCount = integerField(0, msg.chunkCount, msg.chunk_count)
    const chunkIndex = integerField(-1, msg.chunkIndex, msg.chunk_index)
    const protectedChunk = stringField(msg.protectedFrameChunk, msg.protected_frame_chunk)
    const dataChunk = stringField(msg.dataBase64Chunk, msg.data_base64_chunk)
    const chunkField = protectedChunk !== '' ? 'protected_frame_chunk' : 'data_base64_chunk'
    const chunkValue = protectedChunk !== '' ? protectedChunk : dataChunk
    const protocolVersion = integerField(1, msg.protocolVersion, msg.protocol_version)
    const frameSequence = Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence))
    const senderSentAtMs = Math.max(0, integerField(0, msg.senderSentAtMs, msg.sender_sent_at_ms))
    const codecId = stringField(msg.codecId, msg.codec_id) || 'wlvc_unknown'
    const runtimeId = stringField(msg.runtimeId, msg.runtime_id) || 'unknown_runtime'
    const payloadChars = Math.max(0, integerField(0, msg.payloadChars, msg.payload_chars))
    const chunkPayloadChars = Math.max(0, integerField(chunkValue.length, msg.chunkPayloadChars, msg.chunk_payload_chars))
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
    const normalizedTileMetadata = normalizeTilePatchMetadata(tileMetadataInput)
    if (!normalizedTileMetadata && hasExplicitSfuTileMetadataFields(tileMetadataInput)) {
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk used invalid tile/layer/cache metadata and was rejected.',
        {
          frame_id: frameId,
          chunk_index: chunkIndex,
          chunk_count: chunkCount,
          reject_reason: 'invalid_tile_metadata',
        },
        true,
      )
      return null
    }
    const layoutMode = normalizedTileMetadata?.layoutMode || 'full_frame'
    const layerId = normalizedTileMetadata?.layerId || 'full'
    const cacheEpoch = Math.max(0, Number(normalizedTileMetadata?.cacheEpoch || 0))
    const tileColumns = Math.max(0, Number(normalizedTileMetadata?.tileColumns || 0))
    const tileRows = Math.max(0, Number(normalizedTileMetadata?.tileRows || 0))
    const tileWidth = Math.max(0, Number(normalizedTileMetadata?.tileWidth || 0))
    const tileHeight = Math.max(0, Number(normalizedTileMetadata?.tileHeight || 0))
    const tileIndices = Array.isArray(normalizedTileMetadata?.tileIndices) ? normalizedTileMetadata.tileIndices : []
    const roiNormX = Number(normalizedTileMetadata?.roiNormX ?? 0)
    const roiNormY = Number(normalizedTileMetadata?.roiNormY ?? 0)
    const roiNormWidth = Number(normalizedTileMetadata?.roiNormWidth ?? 1)
    const roiNormHeight = Number(normalizedTileMetadata?.roiNormHeight ?? 1)

    if (
      frameId === ''
      || chunkCount < 1
      || chunkCount > SFU_FRAME_CHUNK_MAX_COUNT
      || chunkIndex < 0
      || chunkIndex >= chunkCount
      || chunkValue === ''
      || chunkPayloadChars !== chunkValue.length
    ) {
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk was rejected before assembly.',
        {
          frame_id: frameId,
          chunk_index: chunkIndex,
          chunk_count: chunkCount,
          chunk_payload_chars: chunkPayloadChars,
          actual_chunk_payload_chars: chunkValue.length,
          reject_reason: 'invalid_chunk_header',
        },
      )
      return null
    }

    this.cleanupExpiredChunks()

    const publisherId = stringField(msg.publisherId, msg.publisher_id)
    const publisherUserId = stringField(msg.publisherUserId, msg.publisher_user_id)
    const trackId = stringField(msg.trackId, msg.track_id)
    const timestamp = Number(msg.timestamp || 0)
    const frameType = stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta'
    const protectionMode = stringField(msg.protectionMode, msg.protection_mode) === 'required'
      ? 'required'
      : (chunkField === 'protected_frame_chunk' ? 'protected' : 'transport_only')

    const existing = this.pendingChunks.get(frameId)
    if (!existing) {
      if (chunkIndex !== 0) {
        this.reportDiagnostic(
          'sfu_frame_chunk_rejected',
          'warning',
          'SFU frame chunk arrived before the first chunk; discarding the partial frame.',
          {
            frame_id: frameId,
            publisher_id: publisherId,
            publisher_user_id: publisherUserId,
            track_id: trackId,
            frame_type: frameType,
            frame_sequence: frameSequence,
            chunk_index: chunkIndex,
            expected_chunk_index: 0,
            reject_reason: 'out_of_order_chunk',
          },
          true,
        )
        return null
      }

      this.pendingChunks.set(frameId, {
        publisherId,
        publisherUserId,
        trackId,
        timestamp,
        frameType,
        protectionMode,
        chunkField,
        chunkCount,
        payloadChars,
        protocolVersion,
        frameSequence,
        senderSentAtMs,
        codecId,
        runtimeId,
        layoutMode,
        layerId,
        cacheEpoch,
        tileColumns,
        tileRows,
        tileWidth,
        tileHeight,
        tileIndices,
        roiNormX,
        roiNormY,
        roiNormWidth,
        roiNormHeight,
        updatedAtMs: Date.now(),
        chunks: new Map([[chunkIndex, chunkValue]]),
      })
      return chunkCount === 1
        ? buildReassembledFrame({
            frameId,
            publisherId,
            publisherUserId,
            trackId,
            timestamp,
            frameType,
            frameSequence,
            senderSentAtMs,
            protocolVersion,
            protectionMode,
            codecId,
            runtimeId,
            layoutMode,
            layerId,
            cacheEpoch,
            tileColumns,
            tileRows,
            tileWidth,
            tileHeight,
            tileIndices,
            roiNormX,
            roiNormY,
            roiNormWidth,
            roiNormHeight,
            payloadChars: payloadChars || chunkValue.length,
            chunkCount,
            chunkField,
            payload: chunkValue,
          })
        : null
    }

    if (!this.sameFrameMetadata(existing, {
      publisherId,
      publisherUserId,
      trackId,
      timestamp,
      frameType,
      protectionMode,
      chunkField,
      chunkCount,
      payloadChars,
      protocolVersion,
      frameSequence,
      senderSentAtMs,
      codecId,
      runtimeId,
      layoutMode,
      layerId,
      cacheEpoch,
      tileColumns,
      tileRows,
      tileWidth,
      tileHeight,
      tileIndices,
      roiNormX,
      roiNormY,
      roiNormWidth,
      roiNormHeight,
    })) {
      this.pendingChunks.delete(frameId)
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk metadata changed mid-frame; discarding the partial frame.',
        {
          frame_id: frameId,
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_id: trackId,
          frame_type: frameType,
          frame_sequence: frameSequence,
          reject_reason: 'chunk_metadata_mismatch',
        },
        true,
      )
      return null
    }

    const existingChunk = existing.chunks.get(chunkIndex)
    if (typeof existingChunk === 'string' && existingChunk !== chunkValue) {
      this.pendingChunks.delete(frameId)
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk was duplicated with different bytes; discarding the partial frame.',
        {
          frame_id: frameId,
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_id: trackId,
          frame_type: frameType,
          frame_sequence: frameSequence,
          chunk_index: chunkIndex,
          reject_reason: 'duplicate_chunk_mismatch',
        },
        true,
      )
      return null
    }
    if (typeof existingChunk !== 'string' && chunkIndex !== existing.chunks.size) {
      this.pendingChunks.delete(frameId)
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk arrived out of order; discarding the partial frame.',
        {
          frame_id: frameId,
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_id: trackId,
          frame_type: frameType,
          frame_sequence: frameSequence,
          chunk_index: chunkIndex,
          expected_chunk_index: existing.chunks.size,
          reject_reason: 'out_of_order_chunk',
        },
        true,
      )
      return null
    }

    existing.updatedAtMs = Date.now()
    existing.chunks.set(chunkIndex, chunkValue)
    if (existing.chunks.size < existing.chunkCount) return null

    let assembled = ''
    for (let index = 0; index < existing.chunkCount; index += 1) {
      const nextChunk = existing.chunks.get(index)
      if (typeof nextChunk !== 'string' || nextChunk === '') return null
      assembled += nextChunk
    }

    if (existing.payloadChars > 0 && assembled.length !== existing.payloadChars) {
      this.pendingChunks.delete(frameId)
      this.reportDiagnostic(
        'sfu_frame_chunk_rejected',
        'warning',
        'SFU frame chunk assembly produced a different payload length than advertised.',
        {
          frame_id: frameId,
          publisher_id: existing.publisherId,
          publisher_user_id: existing.publisherUserId,
          track_id: existing.trackId,
          frame_type: existing.frameType,
          frame_sequence: existing.frameSequence,
          payload_chars: existing.payloadChars,
          assembled_payload_chars: assembled.length,
          reject_reason: 'assembled_payload_length_mismatch',
        },
        true,
      )
      return null
    }

    this.pendingChunks.delete(frameId)
    return buildReassembledFrame({
      frameId,
      publisherId: existing.publisherId,
      publisherUserId: existing.publisherUserId,
      trackId: existing.trackId,
      timestamp: existing.timestamp,
      frameType: existing.frameType,
      frameSequence: existing.frameSequence,
      senderSentAtMs: existing.senderSentAtMs,
      protocolVersion: existing.protocolVersion,
      protectionMode: existing.protectionMode,
      codecId: existing.codecId,
      runtimeId: existing.runtimeId,
      layoutMode: existing.layoutMode,
      layerId: existing.layerId,
      cacheEpoch: existing.cacheEpoch,
      tileColumns: existing.tileColumns,
      tileRows: existing.tileRows,
      tileWidth: existing.tileWidth,
      tileHeight: existing.tileHeight,
      tileIndices: existing.tileIndices,
      roiNormX: existing.roiNormX,
      roiNormY: existing.roiNormY,
      roiNormWidth: existing.roiNormWidth,
      roiNormHeight: existing.roiNormHeight,
      payloadChars: existing.payloadChars || assembled.length,
      chunkCount: existing.chunkCount,
      chunkField: existing.chunkField,
      payload: assembled,
    })
  }

  private cleanupExpiredChunks(): void {
    const cutoffMs = Date.now() - SFU_FRAME_CHUNK_TTL_MS
    for (const [frameId, entry] of this.pendingChunks.entries()) {
      if (entry.updatedAtMs >= cutoffMs) continue
      this.pendingChunks.delete(frameId)
      this.reportDiagnostic(
        'sfu_frame_chunk_timeout',
        'warning',
        'SFU frame chunks timed out before a complete frame arrived.',
        {
          frame_id: frameId,
          publisher_id: entry.publisherId,
          publisher_user_id: entry.publisherUserId,
          track_id: entry.trackId,
          frame_type: entry.frameType,
          frame_sequence: entry.frameSequence,
          chunk_count: entry.chunkCount,
          received_chunk_count: entry.chunks.size,
          protection_mode: entry.protectionMode,
        },
      )
    }
  }

  private sameFrameMetadata(existing: PendingInboundFrameChunk, next: Omit<PendingInboundFrameChunk, 'updatedAtMs' | 'chunks'>): boolean {
    return existing.publisherId === next.publisherId
      && existing.publisherUserId === next.publisherUserId
      && existing.trackId === next.trackId
      && existing.timestamp === next.timestamp
      && existing.frameType === next.frameType
      && existing.protectionMode === next.protectionMode
      && existing.chunkField === next.chunkField
      && existing.chunkCount === next.chunkCount
      && existing.payloadChars === next.payloadChars
      && existing.protocolVersion === next.protocolVersion
      && existing.frameSequence === next.frameSequence
      && existing.senderSentAtMs === next.senderSentAtMs
      && existing.codecId === next.codecId
      && existing.runtimeId === next.runtimeId
      && existing.layoutMode === next.layoutMode
      && existing.layerId === next.layerId
      && existing.cacheEpoch === next.cacheEpoch
      && existing.tileColumns === next.tileColumns
      && existing.tileRows === next.tileRows
      && existing.tileWidth === next.tileWidth
      && existing.tileHeight === next.tileHeight
      && existing.roiNormX === next.roiNormX
      && existing.roiNormY === next.roiNormY
      && existing.roiNormWidth === next.roiNormWidth
      && existing.roiNormHeight === next.roiNormHeight
      && JSON.stringify(existing.tileIndices) === JSON.stringify(next.tileIndices)
  }

  private reportDiagnostic(
    eventType: string,
    level: 'info' | 'warning' | 'error',
    message: string,
    payload: Record<string, unknown>,
    immediate = false,
  ): void {
    const nowMs = Date.now()
    if (!immediate && (nowMs - this.lastDiagnosticAtMs) < SFU_FRAME_RECEIVE_DIAGNOSTIC_COOLDOWN_MS) {
      return
    }
    this.lastDiagnosticAtMs = nowMs
    const roomId = this.options.getRoomId()
    reportClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      roomId,
      payload: {
        room_id: roomId,
        ...payload,
      },
      immediate,
    })
  }
}

function buildReassembledFrame(input: {
  frameId: string
  publisherId: string
  publisherUserId: string
  trackId: string
  timestamp: number
  frameType: 'keyframe' | 'delta'
  frameSequence: number
  senderSentAtMs: number
  codecId: string
  runtimeId: string
  protocolVersion: number
  protectionMode: 'transport_only' | 'protected' | 'required'
  layoutMode: 'full_frame' | 'tile_foreground' | 'background_snapshot'
  layerId: 'full' | 'foreground' | 'background'
  cacheEpoch: number
  tileColumns: number
  tileRows: number
  tileWidth: number
  tileHeight: number
  tileIndices: number[]
  roiNormX: number
  roiNormY: number
  roiNormWidth: number
  roiNormHeight: number
  payloadChars: number
  chunkCount: number
  chunkField: 'data_base64_chunk' | 'protected_frame_chunk'
  payload: string
}): Record<string, unknown> {
  return {
    type: 'sfu/frame',
    protocol_version: input.protocolVersion,
    frame_id: input.frameId,
    publisher_id: input.publisherId,
    publisher_user_id: input.publisherUserId,
    track_id: input.trackId,
    timestamp: input.timestamp,
    frame_type: input.frameType,
    frame_sequence: input.frameSequence,
    sender_sent_at_ms: input.senderSentAtMs,
    codec_id: input.codecId,
    runtime_id: input.runtimeId,
    protection_mode: input.protectionMode,
    layout_mode: input.layoutMode,
    layer_id: input.layerId,
    cache_epoch: input.cacheEpoch,
    tile_columns: input.tileColumns,
    tile_rows: input.tileRows,
    tile_width: input.tileWidth,
    tile_height: input.tileHeight,
    tile_indices: input.tileIndices,
    roi_norm_x: input.roiNormX,
    roi_norm_y: input.roiNormY,
    roi_norm_width: input.roiNormWidth,
    roi_norm_height: input.roiNormHeight,
    payload_chars: input.payloadChars,
    chunk_count: input.chunkCount,
    ...(input.chunkField === 'protected_frame_chunk'
      ? { protected_frame: input.payload }
      : { data_base64: input.payload }),
  }
}

export function stringField(...values: any[]): string {
  for (const value of values) {
    const normalized = String(value ?? '').trim()
    if (normalized !== '') return normalized
  }
  return ''
}

function integerField(fallback: number, ...values: any[]): number {
  for (const value of values) {
    const normalized = Number(value)
    if (Number.isFinite(normalized)) return Math.floor(normalized)
  }
  return fallback
}
