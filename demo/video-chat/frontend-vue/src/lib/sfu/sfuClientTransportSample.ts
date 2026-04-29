import type { SfuFrameTransportSample } from './sfuTypes'

export function highResolutionNowMs(): number {
  return typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now()
}

export function roundedTransportStageMs(value: number): number {
  const normalized = Number(value || 0)
  return Number.isFinite(normalized) ? Number(Math.max(0, normalized).toFixed(3)) : 0
}

export function appendSfuPublisherTraceStage(
  payload: Record<string, unknown>,
  stageId: string,
  stageMs: number,
): Record<string, unknown> {
  const normalizedStage = String(stageId || 'unknown_stage').trim().replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').toLowerCase() || 'unknown_stage'
  const existingStages = String(payload.publisher_path_trace_stages || '').trim()
  return {
    ...payload,
    publisher_path_trace_stage: normalizedStage,
    publisher_path_trace_stages: existingStages !== '' ? `${existingStages}>${normalizedStage}` : normalizedStage,
    [`trace_${normalizedStage}_ms`]: roundedTransportStageMs(stageMs),
  }
}

export function buildSfuFrameTransportSample(
  payload: Record<string, unknown>,
  nowMs = Date.now(),
): SfuFrameTransportSample {
  const payloadBytes = Math.max(0, Number(payload.payload_bytes || 0))
  const wirePayloadBytes = Math.max(0, Number(payload.wire_payload_bytes || 0))
  const wireVsPayloadRatio = payloadBytes > 0
    ? Number((wirePayloadBytes / payloadBytes).toFixed(4))
    : 0
  return {
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
    sendDrainTargetBytes: Math.max(0, Number(payload.send_drain_target_buffered_bytes || 0)),
    sendDrainMaxWaitMs: Math.max(0, Number(payload.send_drain_max_wait_ms || 0)),
    binaryEnvelopeEncodeMs: Math.max(0, Number(payload.binary_envelope_encode_ms || 0)),
    websocketSendMs: Math.max(0, Number(payload.websocket_send_ms || 0)),
    publisherFrameTraceId: String(payload.publisher_frame_trace_id || ''),
    publisherPathTraceStage: String(payload.publisher_path_trace_stage || ''),
    publisherPathTraceStages: String(payload.publisher_path_trace_stages || ''),
    budgetMaxEncodedBytesPerFrame: Math.max(0, Number(payload.budget_max_encoded_bytes_per_frame || 0)),
    budgetMaxWireBytesPerSecond: Math.max(0, Number(payload.budget_max_wire_bytes_per_second || 0)),
    budgetMaxQueueAgeMs: Math.max(0, Number(payload.budget_max_queue_age_ms || 0)),
    budgetMaxBufferedBytes: Math.max(0, Number(payload.budget_max_buffered_bytes || 0)),
    binaryContinuationState: String(payload.binary_continuation_state || 'unknown_binary_continuation_state'),
    binaryContinuationRequired: Boolean(payload.binary_continuation_required),
    timestampUnixMs: nowMs,
  }
}
