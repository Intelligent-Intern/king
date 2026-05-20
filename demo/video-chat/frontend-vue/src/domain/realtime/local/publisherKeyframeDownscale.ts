export const FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH = 320;
export const FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT = 180;
export const FIRST_KEYFRAME_DOWNSCALE_EVENT = 'wlvc_first_keyframe_downscale_retry';
export const FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT = 'wlvc_first_keyframe_downscale_retry_failed';

function positiveInteger(value, fallback = 0) {
  const normalized = Math.floor(Number(value || 0));
  return Number.isFinite(normalized) && normalized > 0 ? normalized : fallback;
}

function nowMs() {
  return typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();
}

function roundedMs(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) ? Number(Math.max(0, normalized).toFixed(3)) : 0;
}

export function shouldRetryInitialKeyframeDownscale({
  isInitialFullFrame,
  retryConsumed,
  frameType,
  payloadBytes,
  maxPayloadBytes,
  payloadSoftLimitBytes,
  encodeMs,
  encodeBudgetMs,
} = {}) {
  if (!isInitialFullFrame || retryConsumed) return { retry: false, reason: '' };
  if (String(frameType || '').trim().toLowerCase() !== 'keyframe') return { retry: false, reason: '' };
  const normalizedPayloadBytes = Math.max(0, Number(payloadBytes || 0));
  const normalizedMaxPayloadBytes = Math.max(1, Number(maxPayloadBytes || 0));
  const normalizedSoftLimitBytes = Math.max(1, Number(payloadSoftLimitBytes || normalizedMaxPayloadBytes));
  const normalizedEncodeMs = Math.max(0, Number(encodeMs || 0));
  const normalizedEncodeBudgetMs = Math.max(1, Number(encodeBudgetMs || 0));
  if (normalizedPayloadBytes > normalizedMaxPayloadBytes) {
    return { retry: true, reason: 'first_keyframe_hard_budget_exceeded' };
  }
  if (normalizedPayloadBytes >= normalizedSoftLimitBytes) {
    return { retry: true, reason: 'first_keyframe_soft_payload_pressure' };
  }
  if (normalizedEncodeMs > normalizedEncodeBudgetMs) {
    return { retry: true, reason: 'first_keyframe_encode_budget_pressure' };
  }
  return { retry: false, reason: '' };
}

export function resolveFirstKeyframeDownscaleSize(frameSize = {}) {
  const width = positiveInteger(frameSize.frameWidth || frameSize.width);
  const height = positiveInteger(frameSize.frameHeight || frameSize.height);
  const currentUnit = Math.min(Math.floor(width / 16), Math.floor(height / 9));
  const minimumUnit = Math.max(
    Math.ceil(FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH / 16),
    Math.ceil(FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT / 9),
  );
  if (currentUnit <= minimumUnit) return null;
  let targetUnit = Math.max(minimumUnit, Math.floor(currentUnit / 2));
  if (targetUnit % 2 !== 0 && targetUnit > minimumUnit) targetUnit -= 1;
  if (targetUnit >= currentUnit) return null;
  return {
    frameWidth: targetUnit * 16,
    frameHeight: targetUnit * 9,
  };
}

export function buildFirstKeyframeDownscaleFrameSize(frameSize = {}, targetSize = {}) {
  const frameWidth = positiveInteger(targetSize.frameWidth, FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH);
  const frameHeight = positiveInteger(targetSize.frameHeight, FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT);
  return {
    ...frameSize,
    frameWidth,
    frameHeight,
    profileFrameWidth: frameWidth,
    profileFrameHeight: frameHeight,
    aspectMode: 'first_keyframe_downscale_16x9',
    framingMode: 'cover',
    targetAspectRatio: 16 / 9,
  };
}

export function downscaleImageDataForInitialKeyframe(
  imageData,
  targetSize,
  { documentRef = typeof document !== 'undefined' ? document : null } = {},
) {
  const sourceWidth = positiveInteger(imageData?.width);
  const sourceHeight = positiveInteger(imageData?.height);
  const targetWidth = positiveInteger(targetSize?.frameWidth);
  const targetHeight = positiveInteger(targetSize?.frameHeight);
  if (!imageData || sourceWidth <= 0 || sourceHeight <= 0 || targetWidth <= 0 || targetHeight <= 0) {
    throw new Error('first_keyframe_downscale_source_missing');
  }
  if (!documentRef || typeof documentRef.createElement !== 'function') {
    throw new Error('first_keyframe_downscale_canvas_unavailable');
  }

  const sourceCanvas = documentRef.createElement('canvas');
  sourceCanvas.width = sourceWidth;
  sourceCanvas.height = sourceHeight;
  const sourceContext = sourceCanvas.getContext('2d', { willReadFrequently: true });
  const targetCanvas = documentRef.createElement('canvas');
  targetCanvas.width = targetWidth;
  targetCanvas.height = targetHeight;
  const targetContext = targetCanvas.getContext('2d', { willReadFrequently: true });
  if (
    !sourceContext
      || !targetContext
      || typeof sourceContext.putImageData !== 'function'
      || typeof targetContext.drawImage !== 'function'
      || typeof targetContext.getImageData !== 'function'
  ) {
    throw new Error('first_keyframe_downscale_context_unavailable');
  }

  sourceContext.putImageData(imageData, 0, 0);
  const drawStartedAtMs = nowMs();
  targetContext.drawImage(sourceCanvas, 0, 0, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight);
  const drawImageMs = roundedMs(nowMs() - drawStartedAtMs);
  const readbackStartedAtMs = nowMs();
  const downscaledImageData = targetContext.getImageData(0, 0, targetWidth, targetHeight);
  const readbackMs = roundedMs(nowMs() - readbackStartedAtMs);

  return {
    imageData: downscaledImageData,
    drawImageMs,
    readbackMs,
    readbackBytes: Math.max(0, Number(downscaledImageData?.data?.byteLength || 0)),
  };
}

export async function attemptInitialKeyframeDownscaleRetry({
  activeImageData,
  frameSizeForMetrics,
  firstKeyframeOriginalFrameSize = frameSizeForMetrics,
  firstKeyframeOriginalPayloadBytes = 0,
  downscaleRetryDecision = {},
  videoProfile = {},
  constants = {},
  timestamp,
  trackId = '',
  mediaRuntimePath = '',
  pipelineProfileId = '',
  maxEncodedPayloadBytes = 1,
  payloadSoftLimitBytes = 1,
  encodeBudgetMs = 1,
  createHybridEncoder,
  sfuFrameTypeFromWlvcData,
  stopIfPipelineProfileChanged = () => false,
  captureClientDiagnostic = () => {},
  captureClientDiagnosticError = () => {},
  markPublisherFrameTraceStage = () => {},
  trace = null,
} = {}) {
  const downscaleTargetSize = resolveFirstKeyframeDownscaleSize(frameSizeForMetrics);
  if (!downscaleTargetSize) {
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT,
      code: FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT,
      message: 'Initial WLVC keyframe exceeded budget but no smaller 16:9 retry size is available.',
      payload: {
        track_id: String(trackId || ''),
        media_runtime_path: String(mediaRuntimePath || ''),
        outgoing_video_quality_profile: String(pipelineProfileId || ''),
        reason: String(downscaleRetryDecision.reason || ''),
        original_frame_width: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameWidth || 0)),
        original_frame_height: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameHeight || 0)),
        original_payload_bytes: Math.max(0, Number(firstKeyframeOriginalPayloadBytes || 0)),
        min_retry_width: FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH,
        min_retry_height: FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT,
      },
      immediate: true,
    });
    return { ok: false, stopped: false };
  }

  let retryEncoder = null;
  try {
    const frameQuality = Math.max(1, Math.floor(Number(videoProfile.frameQuality || constants.sfuWlvcFrameQuality || 1)));
    const keyFrameInterval = Math.max(1, Math.floor(Number(videoProfile.keyFrameInterval || 1)));
    const downscaled = downscaleImageDataForInitialKeyframe(activeImageData, downscaleTargetSize);
    const downscaleFrameSize = buildFirstKeyframeDownscaleFrameSize(frameSizeForMetrics, downscaleTargetSize);
    retryEncoder = await createHybridEncoder({
      width: downscaleTargetSize.frameWidth,
      height: downscaleTargetSize.frameHeight,
      quality: frameQuality,
      keyFrameInterval,
    });
    if (stopIfPipelineProfileChanged()) {
      retryEncoder?.destroy?.();
      return { ok: false, stopped: true };
    }
    if (!retryEncoder) {
      throw new Error('first_keyframe_downscale_encoder_unavailable');
    }

    const retryEncodeStartedAtMs = nowMs();
    const retryEncoded = retryEncoder.encodeFrame(downscaled.imageData, timestamp);
    const retryFrameType = sfuFrameTypeFromWlvcData(retryEncoded.data, retryEncoded.type);
    const retryPayloadBytes = retryEncoded?.data instanceof ArrayBuffer
      ? Number(retryEncoded.data.byteLength || 0)
      : 0;
    const retryEncodeMs = roundedMs(nowMs() - retryEncodeStartedAtMs);
    const retryFitsBudget = retryFrameType === 'keyframe'
      && retryPayloadBytes <= maxEncodedPayloadBytes
      && retryPayloadBytes < payloadSoftLimitBytes
      && retryEncodeMs <= encodeBudgetMs;
    if (!retryFitsBudget) {
      retryEncoder.destroy?.();
      retryEncoder = null;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT,
        code: FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT,
        message: 'Initial WLVC keyframe downscale retry still exceeded the active transport budget.',
        payload: {
          track_id: String(trackId || ''),
          media_runtime_path: String(mediaRuntimePath || ''),
          outgoing_video_quality_profile: String(pipelineProfileId || ''),
          reason: String(downscaleRetryDecision.reason || ''),
          retry_frame_type: retryFrameType,
          original_frame_width: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameWidth || 0)),
          original_frame_height: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameHeight || 0)),
          original_payload_bytes: Math.max(0, Number(firstKeyframeOriginalPayloadBytes || 0)),
          retry_frame_width: downscaleTargetSize.frameWidth,
          retry_frame_height: downscaleTargetSize.frameHeight,
          retry_payload_bytes: retryPayloadBytes,
          retry_encode_ms: retryEncodeMs,
          max_payload_bytes: Math.max(1, Number(maxEncodedPayloadBytes || 0)),
          payload_soft_limit_bytes: Math.max(1, Number(payloadSoftLimitBytes || 0)),
          budget_max_encode_ms: Math.max(1, Number(encodeBudgetMs || 0)),
        },
        immediate: true,
      });
      return { ok: false, stopped: false };
    }

    markPublisherFrameTraceStage(trace, 'wlvc_first_keyframe_downscale_draw_image', downscaled.drawImageMs);
    markPublisherFrameTraceStage(trace, 'wlvc_first_keyframe_downscale_get_image_data', downscaled.readbackMs);
    markPublisherFrameTraceStage(trace, 'wlvc_first_keyframe_downscale_encode', retryEncodeMs);
    const transportMetrics = {
      first_keyframe_downscale_retry: true,
      first_keyframe_downscale_reason: String(downscaleRetryDecision.reason || ''),
      first_keyframe_downscale_retry_count: 1,
      first_keyframe_downscale_min_width: FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH,
      first_keyframe_downscale_min_height: FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT,
      first_keyframe_downscale_original_width: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameWidth || 0)),
      first_keyframe_downscale_original_height: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameHeight || 0)),
      first_keyframe_downscale_original_payload_bytes: Math.max(0, Number(firstKeyframeOriginalPayloadBytes || 0)),
      first_keyframe_downscale_width: downscaleTargetSize.frameWidth,
      first_keyframe_downscale_height: downscaleTargetSize.frameHeight,
      first_keyframe_downscale_payload_bytes: retryPayloadBytes,
      first_keyframe_downscale_draw_image_ms: downscaled.drawImageMs,
      first_keyframe_downscale_readback_ms: downscaled.readbackMs,
      first_keyframe_downscale_readback_bytes: downscaled.readbackBytes,
      first_keyframe_downscale_encode_ms: retryEncodeMs,
      keyframe_interval_after_downscale: keyFrameInterval,
    };
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: FIRST_KEYFRAME_DOWNSCALE_EVENT,
      code: FIRST_KEYFRAME_DOWNSCALE_EVENT,
      message: 'Initial WLVC keyframe exceeded budget and was retried once at a smaller 16:9 frame size.',
      payload: {
        track_id: String(trackId || ''),
        media_runtime_path: String(mediaRuntimePath || ''),
        outgoing_video_quality_profile: String(pipelineProfileId || ''),
        ...transportMetrics,
        max_payload_bytes: Math.max(1, Number(maxEncodedPayloadBytes || 0)),
        payload_soft_limit_bytes: Math.max(1, Number(payloadSoftLimitBytes || 0)),
        budget_max_encode_ms: Math.max(1, Number(encodeBudgetMs || 0)),
      },
      immediate: true,
    });

    const encoder = retryEncoder;
    retryEncoder = null;
    return {
      ok: true,
      stopped: false,
      encoder,
      frameQuality,
      keyFrameInterval,
      frameSize: downscaleFrameSize,
      imageData: downscaled.imageData,
      encoded: retryEncoded,
      encodedFrameType: retryFrameType,
      encodedPayloadBytes: retryPayloadBytes,
      encodeMs: retryEncodeMs,
      transportMetrics,
    };
  } catch (downscaleError) {
    retryEncoder?.destroy?.();
    captureClientDiagnosticError(FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT, downscaleError, {
      track_id: String(trackId || ''),
      media_runtime_path: String(mediaRuntimePath || ''),
      outgoing_video_quality_profile: String(pipelineProfileId || ''),
      reason: String(downscaleRetryDecision.reason || ''),
      original_frame_width: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameWidth || 0)),
      original_frame_height: Math.max(0, Number(firstKeyframeOriginalFrameSize?.frameHeight || 0)),
      original_payload_bytes: Math.max(0, Number(firstKeyframeOriginalPayloadBytes || 0)),
      retry_frame_width: downscaleTargetSize.frameWidth,
      retry_frame_height: downscaleTargetSize.frameHeight,
    }, {
      code: FIRST_KEYFRAME_DOWNSCALE_FAILED_EVENT,
      immediate: true,
    });
    return { ok: false, stopped: false };
  }
}
