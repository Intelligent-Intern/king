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
    videoLayer: String(payload.video_layer || ''),
    outgoingVideoQualityProfile: String(payload.outgoing_video_quality_profile || ''),
    selectedVideoQualityProfile: String(payload.selected_video_quality_profile || payload.outgoing_video_quality_profile || ''),
    activeCaptureBackend: String(payload.active_capture_backend || payload.publisher_source_backend || ''),
    sourceFrameWidth: Math.max(0, Number(payload.source_frame_width || payload.source_track_width || 0)),
    sourceFrameHeight: Math.max(0, Number(payload.source_frame_height || payload.source_track_height || 0)),
    sourceFrameRate: Math.max(0, Number(payload.source_frame_rate || payload.source_track_frame_rate || 0)),
    sourceReadbackMs: Math.max(0, Number(payload.source_readback_ms || payload.readback_ms || 0)),
    droppedSourceFrameCount: Math.max(0, Number(payload.dropped_source_frame_count || 0)),
    automaticQualityTransitionCount: Math.max(0, Number(payload.automatic_quality_transition_count || 0)),
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

export function resolveSfuFirstOverBudgetStage(
  payload: Record<string, unknown>,
  sample: SfuFrameTransportSample,
): string {
  const sourceReadbackBudgetMs = Math.max(0, Number(payload.source_readback_budget_ms || payload.readback_budget_ms || 0))
  if (sourceReadbackBudgetMs > 0 && sample.sourceReadbackMs > sourceReadbackBudgetMs) return 'source_readback'
  if (sample.budgetMaxEncodedBytesPerFrame > 0 && sample.payloadBytes > sample.budgetMaxEncodedBytesPerFrame) return 'encoded_payload'
  if (sample.budgetMaxQueueAgeMs > 0 && sample.queuedAgeMs > sample.budgetMaxQueueAgeMs) return 'outbound_queue_age'
  if (sample.budgetMaxBufferedBytes > 0 && sample.websocketBufferedAmount > sample.budgetMaxBufferedBytes) return 'browser_send_buffer'
  const subscriberSendLatencyMs = Math.max(0, Number(payload.subscriber_send_latency_ms || 0))
  if (subscriberSendLatencyMs > 0 && sample.budgetMaxQueueAgeMs > 0 && subscriberSendLatencyMs > sample.budgetMaxQueueAgeMs) return 'subscriber_send'
  return 'within_budget'
}

export function buildSfuEndToEndPerformancePayload(
  payload: Record<string, unknown>,
  sample: SfuFrameTransportSample,
): Record<string, unknown> {
  return {
    sfu_performance_report_schema: 'sfu_end_to_end_v1',
    media_path_phase: 'publisher_send',
    first_over_budget_stage: resolveSfuFirstOverBudgetStage(payload, sample),
    active_capture_backend: sample.activeCaptureBackend,
    selected_video_quality_profile: sample.selectedVideoQualityProfile,
    outgoing_video_quality_profile: sample.outgoingVideoQualityProfile,
    video_layer: sample.videoLayer,
    frame_sequence: sample.frameSequence,
    source_frame_width: sample.sourceFrameWidth,
    source_frame_height: sample.sourceFrameHeight,
    source_frame_rate: sample.sourceFrameRate,
    source_readback_ms: sample.sourceReadbackMs,
    source_readback_budget_ms: Math.max(0, Number(payload.source_readback_budget_ms || payload.readback_budget_ms || 0)),
    encode_ms: sample.encodeMs,
    payload_bytes: sample.payloadBytes,
    wire_payload_bytes: sample.wirePayloadBytes,
    wire_overhead_bytes: sample.wireOverheadBytes,
    wire_vs_payload_ratio: sample.wireVsPayloadRatio,
    queued_age_ms: sample.queuedAgeMs,
    websocket_buffered_amount: sample.websocketBufferedAmount,
    king_receive_latency_ms: Math.max(0, Number(payload.king_receive_latency_ms || 0)),
    king_fanout_latency_ms: Math.max(0, Number(payload.king_fanout_latency_ms || 0)),
    subscriber_send_latency_ms: Math.max(0, Number(payload.subscriber_send_latency_ms || 0)),
    binary_envelope_encode_ms: sample.binaryEnvelopeEncodeMs,
    websocket_send_ms: sample.websocketSendMs,
    media_transport: String(payload.media_transport || ''),
    control_transport: String(payload.control_transport || ''),
    publisher_path_trace_stages: sample.publisherPathTraceStages,
    budget_max_encoded_bytes_per_frame: sample.budgetMaxEncodedBytesPerFrame,
    budget_max_queue_age_ms: sample.budgetMaxQueueAgeMs,
    budget_max_buffered_bytes: sample.budgetMaxBufferedBytes,
    dropped_source_frame_count: sample.droppedSourceFrameCount,
    automatic_quality_transition_count: sample.automaticQualityTransitionCount,
  }
}
