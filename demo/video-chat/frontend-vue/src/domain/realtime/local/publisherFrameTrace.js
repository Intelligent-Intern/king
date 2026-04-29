let publisherFrameTraceSequence = 0;

const TRACE_STAGE_METRIC_KEYS = Object.freeze({
  get_user_media_frame_delivery: 'trace_get_user_media_frame_delivery_ms',
  video_frame_processor_read: 'trace_video_frame_processor_read_ms',
  video_frame_copy_to_rgba: 'trace_video_frame_copy_to_rgba_ms',
  offscreen_worker_draw_image: 'trace_offscreen_worker_draw_image_ms',
  offscreen_worker_get_image_data: 'trace_offscreen_worker_get_image_data_ms',
  offscreen_worker_round_trip: 'trace_offscreen_worker_round_trip_ms',
  dom_canvas_compatibility_draw_image: 'trace_dom_canvas_compatibility_draw_image_ms',
  dom_canvas_compatibility_get_image_data: 'trace_dom_canvas_compatibility_get_image_data_ms',
  dom_canvas_compatibility_throttle: 'trace_dom_canvas_compatibility_throttle_ms',
  video_frame_canvas_draw_image: 'trace_video_frame_canvas_draw_image_ms',
  video_frame_canvas_get_image_data: 'trace_video_frame_canvas_get_image_data_ms',
  dom_canvas_draw_image: 'trace_dom_canvas_draw_image_ms',
  dom_canvas_get_image_data: 'trace_dom_canvas_get_image_data_ms',
  wlvc_encode: 'trace_wlvc_encode_ms',
  protected_frame_wrap: 'trace_protected_frame_wrap_ms',
  protected_frame_skipped: 'trace_protected_frame_skipped_ms',
});

export function highResolutionNowMs() {
  return typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();
}

export function roundedStageMs(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) ? Number(Math.max(0, normalized).toFixed(3)) : 0;
}

export function currentSfuCodecId(encoder) {
  const constructorName = String(encoder?.constructor?.name || '').trim();
  if (constructorName === 'WasmWaveletVideoEncoder') return 'wlvc_wasm';
  if (constructorName === 'WaveletVideoEncoder') return 'wlvc_ts';
  return 'wlvc_unknown';
}

function nextPublisherFrameTraceId(timestamp) {
  publisherFrameTraceSequence = (publisherFrameTraceSequence + 1) % 1_000_000;
  return `pub_${Math.max(0, Number(timestamp || Date.now())).toString(36)}_${publisherFrameTraceSequence.toString(36)}`;
}

function normalizeTraceStageId(stageId) {
  return String(stageId || 'unknown_stage').trim().replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').toLowerCase() || 'unknown_stage';
}

function videoTrackSettings(videoTrack) {
  try {
    return typeof videoTrack?.getSettings === 'function' ? (videoTrack.getSettings() || {}) : {};
  } catch {
    return {};
  }
}

export function createPublisherFrameTrace({
  timestamp,
  startedAtMs,
  pipelineProfileId,
  video,
  videoTrack,
  frameSize,
}) {
  const settings = videoTrackSettings(videoTrack);
  return {
    id: nextPublisherFrameTraceId(timestamp),
    timestamp,
    startedAtMs,
    pipelineProfileId: String(pipelineProfileId || ''),
    sourceBackend: 'dom_canvas_compatibility_fallback',
    sourceVideoReadyState: Math.max(0, Number(video?.readyState || 0)),
    sourceTrackReadyState: String(videoTrack?.readyState || ''),
    sourceTrackWidth: Math.max(0, Number(settings.width || frameSize?.sourceWidth || 0)),
    sourceTrackHeight: Math.max(0, Number(settings.height || frameSize?.sourceHeight || 0)),
    sourceTrackFrameRate: Math.max(0, Number(settings.frameRate || 0)),
    stages: [],
    stageMetrics: {},
  };
}

export function markPublisherFrameTraceStage(trace, stageId, stageMs) {
  if (!trace || typeof trace !== 'object') return;
  const normalizedStageId = normalizeTraceStageId(stageId);
  const normalizedStageMs = roundedStageMs(stageMs);
  trace.stages.push(normalizedStageId);
  trace.currentStage = normalizedStageId;
  const metricKey = TRACE_STAGE_METRIC_KEYS[normalizedStageId] || `trace_${normalizedStageId}_ms`;
  trace.stageMetrics[metricKey] = normalizedStageMs;
}

export function publisherFrameTraceMetrics(trace) {
  if (!trace || typeof trace !== 'object') return {};
  return {
    publisher_frame_trace_id: String(trace.id || ''),
    publisher_path_trace_stage: String(trace.currentStage || 'created'),
    publisher_path_trace_stages: Array.isArray(trace.stages) ? trace.stages.join('>') : '',
    publisher_source_backend: String(trace.sourceBackend || 'unknown_source_backend'),
    publisher_trace_profile: String(trace.pipelineProfileId || ''),
    source_video_ready_state: Math.max(0, Number(trace.sourceVideoReadyState || 0)),
    source_track_ready_state: String(trace.sourceTrackReadyState || ''),
    source_track_width: Math.max(0, Number(trace.sourceTrackWidth || 0)),
    source_track_height: Math.max(0, Number(trace.sourceTrackHeight || 0)),
    source_track_frame_rate: Math.max(0, Number(trace.sourceTrackFrameRate || 0)),
    ...trace.stageMetrics,
  };
}

export function publisherFrameFailureDetails(trace, details) {
  return {
    ...(details || {}),
    ...publisherFrameTraceMetrics(trace),
  };
}

export function buildPublisherTransportStageMetrics({
  trace,
  pipelineProfileId,
  videoProfile,
  frameSize,
  drawImageMs,
  readbackMs,
  encodeMs,
  encodedPayloadBytes,
  maxEncodedFrameBudgetBytes,
  maxEncodedKeyframeBudgetBytes,
  maxEncodedPayloadBytes,
  encodeBudgetMs,
  drawBudgetMs,
  readbackBudgetMs,
  payloadSoftLimitBytes,
  payloadSoftLimitRatio,
  keyframeRetryDelayMs,
}) {
  return {
    ...publisherFrameTraceMetrics(trace),
    outgoing_video_quality_profile: pipelineProfileId,
    capture_width: videoProfile.captureWidth,
    capture_height: videoProfile.captureHeight,
    capture_frame_rate: videoProfile.captureFrameRate,
    frame_width: frameSize.frameWidth,
    frame_height: frameSize.frameHeight,
    profile_frame_width: frameSize.profileFrameWidth,
    profile_frame_height: frameSize.profileFrameHeight,
    source_frame_width: frameSize.sourceWidth,
    source_frame_height: frameSize.sourceHeight,
    source_aspect_ratio: Number(frameSize.sourceAspectRatio.toFixed(6)),
    publisher_aspect_mode: frameSize.aspectMode,
    draw_image_ms: drawImageMs,
    readback_ms: readbackMs,
    encode_ms: encodeMs,
    local_stage_elapsed_ms: roundedStageMs(highResolutionNowMs() - trace.startedAtMs),
    encoded_payload_bytes: encodedPayloadBytes,
    max_payload_bytes: maxEncodedPayloadBytes,
    budget_max_encoded_bytes_per_frame: maxEncodedFrameBudgetBytes,
    budget_max_keyframe_bytes_per_frame: maxEncodedKeyframeBudgetBytes,
    budget_max_wire_bytes_per_second: Math.max(1, Number(videoProfile.maxWireBytesPerSecond || 0)),
    budget_max_encode_ms: encodeBudgetMs,
    budget_max_draw_image_ms: drawBudgetMs,
    budget_max_readback_ms: readbackBudgetMs,
    budget_payload_soft_limit_bytes: payloadSoftLimitBytes,
    budget_payload_soft_limit_ratio: payloadSoftLimitRatio,
    budget_min_keyframe_retry_ms: keyframeRetryDelayMs,
    budget_max_queue_age_ms: Math.max(1, Number(videoProfile.maxQueueAgeMs || 0)),
    budget_max_buffered_bytes: Math.max(1, Number(videoProfile.maxBufferedBytes || 0)),
    budget_expected_recovery: String(videoProfile.expectedRecovery || ''),
  };
}
