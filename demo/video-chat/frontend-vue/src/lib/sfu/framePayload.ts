import {
  flattenTilePatchMetadata,
  normalizeTilePatchMetadata,
  parseTilePatchMetadataJson,
  type SfuTilePatchMetadata,
} from './tilePatchMetadata'

export const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024
export const SFU_FRAME_PROTOCOL_VERSION = 2
export const SFU_BINARY_FRAME_MAGIC = 'KSFB'
export const SFU_BINARY_FRAME_ENVELOPE_VERSION = 1
export const SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION = 2
export const SFU_BINARY_CONTINUATION_THRESHOLD_BYTES = 65535

export type SfuFrameType = 'keyframe' | 'delta'
export type SfuProtectionMode = 'transport_only' | 'protected' | 'required'
export type SfuChunkField = 'data_base64_chunk' | 'protected_frame_chunk'
export type SfuCodecId = 'wlvc_wasm' | 'wlvc_ts' | 'webcodecs_vp8' | 'wlvc_unknown'
export type SfuRuntimeId = 'wlvc_sfu' | 'webrtc_native' | 'unknown_runtime'
export type SfuVideoLayer = 'primary' | 'thumbnail'

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
  codecId?: SfuCodecId | string | null
  runtimeId?: SfuRuntimeId | string | null
  videoLayer?: SfuVideoLayer | string | null
  tilePatch?: SfuTilePatchMetadata | null
  transportMetrics?: Record<string, unknown>
}

export interface PreparedSfuOutboundFramePayload {
  payload: Record<string, unknown>
  chunkField: SfuChunkField | null
  chunkValue: string
  payloadBytes: ArrayBuffer
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
  codecId: string
  runtimeId: string
  videoLayer: SfuVideoLayer | ''
  tilePatch: SfuTilePatchMetadata | null
}

export interface DecodedSfuBinaryFrameEnvelope {
  payload: Record<string, unknown>
  payloadBytes: ArrayBuffer
  payloadByteLength: number
  protectedFrame: string | null
  dataBase64: string | null
  codecId: string
  runtimeId: string
  videoLayer: SfuVideoLayer | ''
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
  const codecId = normalizeCodecId(frame.codecId)
  const runtimeId = normalizeRuntimeId(frame.runtimeId)
  payload.codec_id = codecId
  payload.runtime_id = runtimeId
  const videoLayer = normalizeVideoLayer(
    frame.videoLayer
      ?? frame.transportMetrics?.video_layer
      ?? frame.transportMetrics?.videoLayer,
  )
  const transportMetrics = normalizeTransportMetrics(videoLayer !== ''
    ? { ...(frame.transportMetrics || {}), video_layer: videoLayer }
    : frame.transportMetrics)
  if (videoLayer !== '') payload.video_layer = videoLayer
  Object.assign(payload, transportMetrics)
  const tilePatch = normalizeTilePatchMetadata(frame.tilePatch || frame)
  Object.assign(payload, flattenTilePatchMetadata(tilePatch))

  let chunkField: SfuChunkField | null = null
  let chunkValue = ''
  let payloadBytes = frame.data instanceof ArrayBuffer ? frame.data : new ArrayBuffer(0)

  const protectedFrame = String(frame.protectedFrame || '').trim()
  if (protectedFrame !== '') {
    chunkField = 'protected_frame_chunk'
    chunkValue = protectedFrame
    payloadBytes = base64UrlToArrayBuffer(protectedFrame)
    payload.protection_mode = normalizeProtectionMode(frame.protectionMode, 'protected')
  } else {
    chunkField = 'data_base64_chunk'
    chunkValue = String(frame.dataBase64 || '').trim()
    if (payloadBytes.byteLength <= 0 && chunkValue !== '') {
      payloadBytes = base64UrlToArrayBuffer(chunkValue)
    }
    payload.protection_mode = normalizeProtectionMode(frame.protectionMode, 'transport_only')
  }

  const payloadByteLength = Number(payloadBytes.byteLength || 0)
  const payloadChars = chunkValue !== ''
    ? chunkValue.length
    : base64UrlEncodedLength(payloadByteLength)
  const chunkCount = frameChunkCount(payloadChars)
  const protectionMode = normalizeProtectionMode(payload.protection_mode, 'transport_only')
  payload.payload_chars = payloadChars
  payload.chunk_count = chunkCount
  const roiAreaRatio = tilePatch
    ? Number((Math.max(0, Number(tilePatch.roiNormWidth || 0)) * Math.max(0, Number(tilePatch.roiNormHeight || 0))).toFixed(6))
    : 1
  const metadataJson = serializeSfuEnvelopeMetadata({ tilePatch, codecId, runtimeId, transportMetrics })
  const metadataJsonBytes = encodeUtf8(metadataJson).byteLength
  const projectedBinaryEnvelopeBytes = (metadataJsonBytes > 0 ? 46 : 42)
    + encodeUtf8(String(frame.publisherId || '')).byteLength
    + encodeUtf8(String(frame.publisherUserId || '')).byteLength
    + encodeUtf8(String(frame.trackId || '')).byteLength
    + encodeUtf8('').byteLength
    + metadataJsonBytes
    + payloadByteLength
  const binaryContinuationRequired = projectedBinaryEnvelopeBytes > SFU_BINARY_CONTINUATION_THRESHOLD_BYTES

  return {
    payload,
    chunkField,
    chunkValue,
    payloadBytes,
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
    codecId,
    runtimeId,
    videoLayer,
    tilePatch,
    metrics: {
      protocol_version: SFU_FRAME_PROTOCOL_VERSION,
      publisher_id: frame.publisherId,
      track_id: frame.trackId,
      frame_type: frame.type,
      frame_sequence: frameSequence,
      sender_sent_at_ms: senderSentAtMs,
      codec_id: codecId,
      runtime_id: runtimeId,
      ...(videoLayer !== '' ? { video_layer: videoLayer } : {}),
      protection_mode: protectionMode,
      ...transportMetrics,
      raw_byte_length: rawByteLength,
      payload_bytes: payloadByteLength,
      payload_chars: payloadChars,
      chunk_count: chunkCount,
      projected_binary_envelope_bytes: projectedBinaryEnvelopeBytes,
      projected_binary_envelope_overhead_bytes: Math.max(0, projectedBinaryEnvelopeBytes - payloadByteLength),
      legacy_base64_overhead_bytes: Math.max(0, payloadChars - payloadByteLength),
      projected_binary_delta_vs_legacy_bytes: projectedBinaryEnvelopeBytes - payloadChars,
      binary_envelope_version: metadataJsonBytes > 0
        ? SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
        : SFU_BINARY_FRAME_ENVELOPE_VERSION,
      binary_continuation_state: binaryContinuationRequired
        ? 'receiver_reassembles_rfc_continuation_frames'
        : 'single_binary_message_no_continuation_expected',
      binary_continuation_required: binaryContinuationRequired,
      binary_continuation_threshold_bytes: SFU_BINARY_CONTINUATION_THRESHOLD_BYTES,
      application_media_chunking: false,
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
  const profile = String(source.outgoing_video_quality_profile ?? source.outgoingVideoQualityProfile ?? '').trim().toLowerCase()
  const expectedRecovery = String(source.budget_expected_recovery ?? source.budgetExpectedRecovery ?? '').trim()
  const publisherFrameTraceId = String(source.publisher_frame_trace_id ?? source.publisherFrameTraceId ?? '').trim()
  const publisherPathTraceStage = String(source.publisher_path_trace_stage ?? source.publisherPathTraceStage ?? '').trim()
  const publisherPathTraceStages = String(source.publisher_path_trace_stages ?? source.publisherPathTraceStages ?? '').trim()
  const publisherSourceBackend = String(source.publisher_source_backend ?? source.publisherSourceBackend ?? '').trim()
  const publisherTraceProfile = String(source.publisher_trace_profile ?? source.publisherTraceProfile ?? '').trim()
  const publisherAspectMode = String(source.publisher_aspect_mode ?? source.publisherAspectMode ?? '').trim()
  const publisherFramingMode = String(source.publisher_framing_mode ?? source.publisherFramingMode ?? '').trim()
  const sourceTrackReadyState = String(source.source_track_ready_state ?? source.sourceTrackReadyState ?? '').trim()
  const activeCaptureBackend = String(source.active_capture_backend ?? source.activeCaptureBackend ?? publisherSourceBackend).trim()
  const selectedVideoQualityProfile = String(source.selected_video_quality_profile ?? source.selectedVideoQualityProfile ?? profile).trim().toLowerCase()
  const publisherReadbackMethod = String(source.publisher_readback_method ?? source.publisherReadbackMethod ?? '').trim()
  const browserEncoderCodec = String(source.publisher_browser_encoder_codec ?? source.publisherBrowserEncoderCodec ?? '').trim()
  const browserEncoderConfigCodec = String(source.publisher_browser_encoder_config_codec ?? source.publisherBrowserEncoderConfigCodec ?? '').trim()
  const browserEncoderHardwareAcceleration = String(source.publisher_browser_encoder_hardware_acceleration ?? source.publisherBrowserEncoderHardwareAcceleration ?? '').trim()
  const browserEncoderLatencyMode = String(source.publisher_browser_encoder_latency_mode ?? source.publisherBrowserEncoderLatencyMode ?? '').trim()
  const videoLayer = normalizeVideoLayer(source.video_layer ?? source.videoLayer)
  const selectedVideoLayer = normalizeVideoLayer(source.selected_video_layer ?? source.selectedVideoLayer ?? videoLayer)
  const browserEncoderLayer = normalizeVideoLayer(source.publisher_browser_encoder_layer ?? source.publisherBrowserEncoderLayer ?? videoLayer)
  const metrics: Record<string, unknown> = {
    selection_tile_count: selectionTileCount,
    selection_total_tile_count: selectionTotalTileCount,
    selection_tile_ratio: selectionTileRatio,
    selection_mask_guided: Boolean(source.selection_mask_guided ?? source.selectionMaskGuided),
  }
  if (profile !== '') metrics.outgoing_video_quality_profile = profile
  if (expectedRecovery !== '') metrics.budget_expected_recovery = expectedRecovery
  if (publisherFrameTraceId !== '') metrics.publisher_frame_trace_id = publisherFrameTraceId
  if (publisherPathTraceStage !== '') metrics.publisher_path_trace_stage = publisherPathTraceStage
  if (publisherPathTraceStages !== '') metrics.publisher_path_trace_stages = publisherPathTraceStages
  if (publisherSourceBackend !== '') metrics.publisher_source_backend = publisherSourceBackend
  if (publisherTraceProfile !== '') metrics.publisher_trace_profile = publisherTraceProfile
  if (publisherAspectMode !== '') metrics.publisher_aspect_mode = publisherAspectMode
  if (publisherFramingMode !== '') metrics.publisher_framing_mode = publisherFramingMode
  if (sourceTrackReadyState !== '') metrics.source_track_ready_state = sourceTrackReadyState
  if (activeCaptureBackend !== '') metrics.active_capture_backend = activeCaptureBackend
  if (selectedVideoQualityProfile !== '') metrics.selected_video_quality_profile = selectedVideoQualityProfile
  if (publisherReadbackMethod !== '') metrics.publisher_readback_method = publisherReadbackMethod
  if (browserEncoderCodec !== '') metrics.publisher_browser_encoder_codec = browserEncoderCodec
  if (browserEncoderConfigCodec !== '') metrics.publisher_browser_encoder_config_codec = browserEncoderConfigCodec
  if (browserEncoderHardwareAcceleration !== '') metrics.publisher_browser_encoder_hardware_acceleration = browserEncoderHardwareAcceleration
  if (browserEncoderLatencyMode !== '') metrics.publisher_browser_encoder_latency_mode = browserEncoderLatencyMode
  if (videoLayer !== '') metrics.video_layer = videoLayer
  if (selectedVideoLayer !== '') metrics.selected_video_layer = selectedVideoLayer
  if (browserEncoderLayer !== '') metrics.publisher_browser_encoder_layer = browserEncoderLayer

  const integerFields: Array<[string, unknown]> = [
    ['capture_width', source.capture_width ?? source.captureWidth],
    ['capture_height', source.capture_height ?? source.captureHeight],
    ['frame_width', source.frame_width ?? source.frameWidth],
    ['frame_height', source.frame_height ?? source.frameHeight],
    ['profile_frame_width', source.profile_frame_width ?? source.profileFrameWidth],
    ['profile_frame_height', source.profile_frame_height ?? source.profileFrameHeight],
    ['encoded_payload_bytes', source.encoded_payload_bytes ?? source.encodedPayloadBytes],
    ['max_payload_bytes', source.max_payload_bytes ?? source.maxPayloadBytes],
    ['budget_max_encoded_bytes_per_frame', source.budget_max_encoded_bytes_per_frame ?? source.budgetMaxEncodedBytesPerFrame],
    ['budget_max_keyframe_bytes_per_frame', source.budget_max_keyframe_bytes_per_frame ?? source.budgetMaxKeyframeBytesPerFrame],
    ['budget_max_wire_bytes_per_second', source.budget_max_wire_bytes_per_second ?? source.budgetMaxWireBytesPerSecond],
    ['budget_max_queue_age_ms', source.budget_max_queue_age_ms ?? source.budgetMaxQueueAgeMs],
    ['queued_age_ms', source.queued_age_ms ?? source.queuedAgeMs],
    ['queue_age_ms', source.queue_age_ms ?? source.queueAgeMs],
    ['budget_max_buffered_bytes', source.budget_max_buffered_bytes ?? source.budgetMaxBufferedBytes],
    ['budget_payload_soft_limit_bytes', source.budget_payload_soft_limit_bytes ?? source.budgetPayloadSoftLimitBytes],
    ['budget_min_keyframe_retry_ms', source.budget_min_keyframe_retry_ms ?? source.budgetMinKeyframeRetryMs],
    ['outbound_media_generation', source.outbound_media_generation ?? source.outboundMediaGeneration],
    ['king_receive_at_ms', source.king_receive_at_ms ?? source.kingReceiveAtMs],
    ['source_video_ready_state', source.source_video_ready_state ?? source.sourceVideoReadyState],
    ['source_track_width', source.source_track_width ?? source.sourceTrackWidth],
    ['source_track_height', source.source_track_height ?? source.sourceTrackHeight],
    ['source_frame_width', source.source_frame_width ?? source.sourceFrameWidth],
    ['source_frame_height', source.source_frame_height ?? source.sourceFrameHeight],
    ['dropped_source_frame_count', source.dropped_source_frame_count ?? source.droppedSourceFrameCount],
    ['automatic_quality_transition_count', source.automatic_quality_transition_count ?? source.automaticQualityTransitionCount],
    ['publisher_browser_encoder_bitrate', source.publisher_browser_encoder_bitrate ?? source.publisherBrowserEncoderBitrate],
  ]
  for (const [key, fieldValue] of integerFields) {
    const normalized = normalizeNonNegativeInteger(fieldValue)
    if (normalized > 0) metrics[key] = normalized
  }

  const numberFields: Array<[string, unknown]> = [
    ['capture_frame_rate', source.capture_frame_rate ?? source.captureFrameRate],
    ['draw_image_ms', source.draw_image_ms ?? source.drawImageMs],
    ['readback_ms', source.readback_ms ?? source.readbackMs],
    ['encode_ms', source.encode_ms ?? source.encodeMs],
    ['local_stage_elapsed_ms', source.local_stage_elapsed_ms ?? source.localStageElapsedMs],
    ['budget_max_encode_ms', source.budget_max_encode_ms ?? source.budgetMaxEncodeMs],
    ['budget_max_draw_image_ms', source.budget_max_draw_image_ms ?? source.budgetMaxDrawImageMs],
    ['budget_max_readback_ms', source.budget_max_readback_ms ?? source.budgetMaxReadbackMs],
    ['budget_payload_soft_limit_ratio', source.budget_payload_soft_limit_ratio ?? source.budgetPayloadSoftLimitRatio],
    ['send_drain_ms', source.send_drain_ms ?? source.sendDrainMs],
    ['source_track_frame_rate', source.source_track_frame_rate ?? source.sourceTrackFrameRate],
    ['source_frame_rate', source.source_frame_rate ?? source.sourceFrameRate],
    ['source_aspect_ratio', source.source_aspect_ratio ?? source.sourceAspectRatio],
    ['source_crop_x', source.source_crop_x ?? source.sourceCropX],
    ['source_crop_y', source.source_crop_y ?? source.sourceCropY],
    ['source_crop_width', source.source_crop_width ?? source.sourceCropWidth],
    ['source_crop_height', source.source_crop_height ?? source.sourceCropHeight],
    ['publisher_target_aspect_ratio', source.publisher_target_aspect_ratio ?? source.publisherTargetAspectRatio],
    ['source_draw_image_ms', source.source_draw_image_ms ?? source.sourceDrawImageMs],
    ['source_draw_image_budget_ms', source.source_draw_image_budget_ms ?? source.sourceDrawImageBudgetMs],
    ['source_readback_ms', source.source_readback_ms ?? source.sourceReadbackMs],
    ['source_readback_budget_ms', source.source_readback_budget_ms ?? source.sourceReadbackBudgetMs],
    ['trace_get_user_media_frame_delivery_ms', source.trace_get_user_media_frame_delivery_ms ?? source.traceGetUserMediaFrameDeliveryMs],
    ['trace_dom_canvas_draw_image_ms', source.trace_dom_canvas_draw_image_ms ?? source.traceDomCanvasDrawImageMs],
    ['trace_dom_canvas_get_image_data_ms', source.trace_dom_canvas_get_image_data_ms ?? source.traceDomCanvasGetImageDataMs],
    ['trace_wlvc_encode_ms', source.trace_wlvc_encode_ms ?? source.traceWlvcEncodeMs],
    ['trace_protected_frame_wrap_ms', source.trace_protected_frame_wrap_ms ?? source.traceProtectedFrameWrapMs],
    ['trace_protected_frame_skipped_ms', source.trace_protected_frame_skipped_ms ?? source.traceProtectedFrameSkippedMs],
    ['trace_binary_envelope_encode_ms', source.trace_binary_envelope_encode_ms ?? source.traceBinaryEnvelopeEncodeMs],
    ['trace_browser_websocket_send_ms', source.trace_browser_websocket_send_ms ?? source.traceBrowserWebsocketSendMs],
    ['binary_envelope_encode_ms', source.binary_envelope_encode_ms ?? source.binaryEnvelopeEncodeMs],
    ['websocket_send_ms', source.websocket_send_ms ?? source.websocketSendMs],
    ['king_receive_latency_ms', source.king_receive_latency_ms ?? source.kingReceiveLatencyMs],
    ['king_fanout_latency_ms', source.king_fanout_latency_ms ?? source.kingFanoutLatencyMs],
    ['subscriber_send_latency_ms', source.subscriber_send_latency_ms ?? source.subscriberSendLatencyMs],
    ['receiver_render_latency_ms', source.receiver_render_latency_ms ?? source.receiverRenderLatencyMs],
  ]
  for (const [key, fieldValue] of numberFields) {
    const normalized = normalizeNonNegativeNumber(fieldValue)
    if (normalized > 0) metrics[key] = normalized
  }

  return metrics
}

export function createSfuFrameId(): string {
  const randomValue = Math.random().toString(36).slice(2, 10)
  return `frame_${Date.now().toString(36)}_${randomValue}`
}

export function frameChunkCount(payloadChars: number): number {
  return Math.max(1, Math.ceil(Math.max(0, payloadChars) / SFU_FRAME_CHUNK_MAX_CHARS))
}

export function encodeSfuBinaryFrameEnvelope(prepared: PreparedSfuOutboundFramePayload): ArrayBuffer | null {
  const payloadBytes = prepared.payloadBytes instanceof ArrayBuffer
    ? prepared.payloadBytes
    : new ArrayBuffer(0)
  const payloadByteLength = Number(payloadBytes.byteLength || 0)
  if (payloadByteLength <= 0) return null

  const publisherIdBytes = encodeUtf8(prepared.publisherId)
  const publisherUserIdBytes = encodeUtf8(String(prepared.payload.publisher_user_id || ''))
  const trackIdBytes = encodeUtf8(prepared.trackId)
  const frameIdBytes = encodeUtf8(String(prepared.payload.frame_id || ''))
  const metadataJsonBytes = encodeUtf8(serializeSfuEnvelopeMetadata(prepared))
  const envelopeVersion = metadataJsonBytes.byteLength > 0
    ? SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION
    : SFU_BINARY_FRAME_ENVELOPE_VERSION

  const headerByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION ? 46 : 42
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
  const headerByteLength = envelopeVersion === SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION ? 46 : 42
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
  const { codecId, runtimeId, transportMetrics } = parseSfuEnvelopeMetadata(metadataJson)
  const videoLayer = normalizeVideoLayer(transportMetrics.video_layer ?? transportMetrics.videoLayer)

  const protectedFrame = protectionMode === 'transport_only' ? null : arrayBufferToBase64Url(payloadBytes)
  const dataBase64 = null
  const payloadChars = protectedFrame ? protectedFrame.length : base64UrlEncodedLength(payloadByteLength)

  return {
    payloadBytes,
    payloadByteLength,
    protectedFrame,
    dataBase64,
    codecId,
    runtimeId,
    videoLayer,
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
      codec_id: codecId,
      runtime_id: runtimeId,
      ...(videoLayer !== '' ? { video_layer: videoLayer } : {}),
      payload_bytes: payloadByteLength,
      payload_chars: payloadChars,
      chunk_count: 1,
      frame_id: frameId,
      ...transportMetrics,
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

function normalizeNonNegativeNumber(value: unknown): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized < 0) return 0
  return Number(normalized.toFixed(3))
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

function normalizeCodecId(value: unknown): string {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === 'wlvc_wasm' || normalized === 'wlvc_ts' || normalized === 'webcodecs_vp8') return normalized
  return 'wlvc_unknown'
}

function normalizeRuntimeId(value: unknown): string {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === 'wlvc_sfu' || normalized === 'webrtc_native') return normalized
  return 'unknown_runtime'
}

export function normalizeVideoLayer(value: unknown): SfuVideoLayer | '' {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail'
  if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary'
  return ''
}

function serializeSfuEnvelopeMetadata(input: {
  tilePatch?: SfuTilePatchMetadata | null
  codecId?: string | null
  runtimeId?: string | null
  transportMetrics?: Record<string, unknown> | null
  metrics?: Record<string, unknown> | null
}): string {
  const tilePatch = normalizeTilePatchMetadata(input.tilePatch)
  const metadata: Record<string, unknown> = {
    codec_id: normalizeCodecId(input.codecId),
    runtime_id: normalizeRuntimeId(input.runtimeId),
  }
  Object.assign(metadata, normalizeTransportMetrics(input.transportMetrics ?? input.metrics))
  Object.assign(metadata, flattenTilePatchMetadata(tilePatch))
  return JSON.stringify(metadata)
}

function parseSfuEnvelopeMetadata(value: string): {
  codecId: string
  runtimeId: string
  transportMetrics: Record<string, unknown>
} {
  try {
    const parsed = JSON.parse(String(value || ''))
    if (!parsed || typeof parsed !== 'object') {
      return { codecId: 'wlvc_unknown', runtimeId: 'unknown_runtime', transportMetrics: {} }
    }
    const source = parsed as Record<string, unknown>
    return {
      codecId: normalizeCodecId(source.codecId ?? source.codec_id),
      runtimeId: normalizeRuntimeId(source.runtimeId ?? source.runtime_id),
      transportMetrics: normalizeTransportMetrics(source),
    }
  } catch {
    return { codecId: 'wlvc_unknown', runtimeId: 'unknown_runtime', transportMetrics: {} }
  }
}

function base64UrlEncodedLength(byteLength: number): number {
  const normalized = Math.max(0, Math.floor(Number(byteLength || 0)))
  if (normalized <= 0) return 0
  const paddedLength = Math.ceil(normalized / 3) * 4
  const paddingChars = normalized % 3 === 0 ? 0 : (normalized % 3 === 1 ? 2 : 1)
  return paddedLength - paddingChars
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
