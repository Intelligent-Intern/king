import {
  SFU_AUTO_QUALITY_RECOVERY_MAX_READBACK_BUDGET_RATIO,
  SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS,
  SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS,
  SFU_WLVC_MOTION_DELTA_CADENCE_WINDOW_MS,
  SFU_WLVC_MOTION_DELTA_MAX_CADENCE_LEVEL,
  SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD,
  SFU_WLVC_MOTION_DELTA_STABLE_SAMPLE_COUNT,
  SFU_WLVC_MOTION_DELTA_STABLE_WINDOW_MS,
} from './runtimeConfig.js';
import { publisherDroppedSourceFrameDiagnosticSurface } from './publisherDiagnosticsSurface.js';

export const PUBLISHER_BACKPRESSURE_ACTIONS = Object.freeze({
  CONTINUE: 'continue',
  PAUSE_ENCODE: 'pause_encode',
  DROP_FRAME: 'drop_frame',
  CADENCE_THROTTLE: 'cadence_throttle',
  PROFILE_DOWNSHIFT: 'profile_downshift',
  REQUEST_KEYFRAME: 'request_keyframe',
  SOCKET_RESTART: 'socket_restart',
});

function normalizedNumber(value, fallback = 0) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.max(0, numeric) : fallback;
}

function addAction(actions, action) {
  if (!actions.includes(action)) actions.push(action);
}

export function decidePublisherBackpressureAction(stageTelemetry = {}, config = {}) {
  const reason = String(stageTelemetry.reason || 'publisher_backpressure').trim().toLowerCase();
  const kind = String(stageTelemetry.kind || reason || 'publisher_backpressure').trim().toLowerCase();
  const bufferedAmount = normalizedNumber(stageTelemetry.bufferedAmount);
  const queueAgeMs = normalizedNumber(stageTelemetry.queueAgeMs);
  const encodeMs = normalizedNumber(stageTelemetry.encodeMs);
  const payloadBytes = normalizedNumber(stageTelemetry.payloadBytes);
  const receiverRenderLatencyMs = normalizedNumber(stageTelemetry.receiverRenderLatencyMs);
  const subscriberSendLatencyMs = normalizedNumber(stageTelemetry.subscriberSendLatencyMs);
  const skipCount = normalizedNumber(stageTelemetry.skipCount);
  const sendFailureCount = normalizedNumber(stageTelemetry.sendFailureCount);
  const sourceReadbackFailureCount = normalizedNumber(stageTelemetry.sourceReadbackFailureCount);
  const payloadPressureCount = normalizedNumber(stageTelemetry.payloadPressureCount);
  const encodeFailureCount = normalizedNumber(stageTelemetry.encodeFailureCount);
  const sustainedBackpressureMs = normalizedNumber(stageTelemetry.sustainedBackpressureMs);
  const highWaterBytes = Math.max(1, normalizedNumber(config.highWaterBytes, 1));
  const lowWaterBytes = Math.max(1, normalizedNumber(config.lowWaterBytes, highWaterBytes));
  const criticalBytes = Math.max(highWaterBytes, normalizedNumber(config.criticalBytes, highWaterBytes));
  const skipThreshold = Math.max(1, normalizedNumber(config.skipThreshold, 1));
  const sendFailureThreshold = Math.max(1, normalizedNumber(config.sendFailureThreshold, 1));
  const encodeFailureThreshold = Math.max(1, normalizedNumber(config.encodeFailureThreshold, 1));
  const motionDeltaProfileDownshiftThreshold = Math.max(1, normalizedNumber(
    config.motionDeltaProfileDownshiftThreshold,
    SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD,
  ));
  const backpressureWindowMs = Math.max(1, normalizedNumber(config.backpressureWindowMs, 1));
  const hardResetAfterMs = Math.max(backpressureWindowMs, normalizedNumber(config.hardResetAfterMs, backpressureWindowMs));
  const maxQueueAgeMs = Math.max(0, normalizedNumber(config.maxQueueAgeMs));
  const maxEncodeMs = Math.max(0, normalizedNumber(config.maxEncodeMs));
  const maxPayloadBytes = Math.max(0, normalizedNumber(config.maxPayloadBytes));
  const receiverLagPressureMs = Math.max(0, normalizedNumber(config.receiverLagPressureMs));
  const subscriberSendPressureMs = Math.max(0, normalizedNumber(config.subscriberSendPressureMs));
  const actions = [];

  const socketHigh = bufferedAmount >= highWaterBytes
    || (skipCount > 0 && bufferedAmount >= lowWaterBytes)
    || (maxQueueAgeMs > 0 && queueAgeMs >= maxQueueAgeMs);
  const socketCritical = bufferedAmount >= criticalBytes
    && sustainedBackpressureMs >= hardResetAfterMs;
  const sustainedPressure = sustainedBackpressureMs >= backpressureWindowMs;
  const encodeTooSlow = maxEncodeMs > 0 && encodeMs >= maxEncodeMs;
  const payloadTooLarge = maxPayloadBytes > 0 && payloadBytes >= maxPayloadBytes;
  const receiverLagging = receiverLagPressureMs > 0 && receiverRenderLatencyMs >= receiverLagPressureMs;
  const subscriberLagging = subscriberSendPressureMs > 0 && subscriberSendLatencyMs >= subscriberSendPressureMs;
  const budgetSendFailure = [
    'send_buffer_drain_timeout',
    'sfu_buffer_budget_exceeded',
    'sfu_ingress_latency_budget_exceeded',
    'sfu_projected_buffer_budget_exceeded',
    'sfu_wire_rate_budget_exceeded',
  ].includes(reason);

  if (kind === 'pre_encode_buffer') {
    if (socketHigh) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    }
  } else if (kind === 'encode_backpressure') {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    if (sustainedPressure || skipCount >= skipThreshold) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
    if (socketCritical) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.SOCKET_RESTART);
    }
  } else if (kind === 'send_failure') {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    if (budgetSendFailure || sendFailureCount >= sendFailureThreshold || socketHigh) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
    if (socketCritical) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.SOCKET_RESTART);
    }
  } else if (kind === 'source_readback_failure') {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    if (sourceReadbackFailureCount >= sendFailureThreshold) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
  } else if (kind === 'payload_pressure') {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.CADENCE_THROTTLE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    if (
      reason === 'sfu_protected_media_budget_pressure'
      || payloadPressureCount >= motionDeltaProfileDownshiftThreshold
    ) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
  } else if (kind === 'runtime_encode_error') {
    if (encodeFailureCount >= encodeFailureThreshold) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
  } else if (kind === 'receiver_feedback') {
    if (receiverLagging || subscriberLagging) {
      addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
    }
  } else if (socketHigh || encodeTooSlow || payloadTooLarge || receiverLagging || subscriberLagging) {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
  }

  return {
    actions: actions.length > 0 ? actions : [PUBLISHER_BACKPRESSURE_ACTIONS.CONTINUE],
    kind,
    reason,
    stage_telemetry: {
      buffered_amount: bufferedAmount,
      queue_age_ms: queueAgeMs,
      encode_ms: encodeMs,
      payload_bytes: payloadBytes,
      receiver_render_latency_ms: receiverRenderLatencyMs,
      subscriber_send_latency_ms: subscriberSendLatencyMs,
      skip_count: skipCount,
      send_failure_count: sendFailureCount,
      source_readback_failure_count: sourceReadbackFailureCount,
      payload_pressure_count: payloadPressureCount,
      encode_failure_count: encodeFailureCount,
      sustained_backpressure_ms: sustainedBackpressureMs,
    },
  };
}

function decisionHasAction(decision, action) {
  return Array.isArray(decision?.actions) && decision.actions.includes(action);
}

export function createPublisherBackpressureController({
  callMediaPrefs,
  captureClientDiagnostic,
  downgradeSfuVideoQualityAfterEncodePressure,
  getMediaRuntimePath,
  getRemotePeerCount,
  getShouldConnectSfu,
  onRestartSfu,
  probeSfuVideoQualityAfterStableReadback,
  resetWlvcEncoderAfterDroppedEncodedFrame,
  sfuAutoQualityDowngradeBackpressureWindowMs,
  sfuAutoQualityDowngradeSendFailureThreshold,
  sfuAutoQualityDowngradeSkipThreshold,
  sfuBackpressureLogCooldownMs,
  sfuConnectRetryDelayMs,
  sfuConnected,
  sfuVideoRecoveryReconnectCooldownMs,
  sfuWlvcBackpressureHardResetAfterMs,
  sfuWlvcBackpressureMaxPauseMs,
  sfuWlvcBackpressureMinPauseMs,
  sfuWlvcEncodeFailureThreshold,
  sfuWlvcSendBufferCriticalBytes,
  sfuWlvcSendBufferHighWaterBytes,
  sfuWlvcSendBufferLowWaterBytes,
  state,
}) {
  const qualityRecoveryProbe = typeof probeSfuVideoQualityAfterStableReadback === 'function'
    ? probeSfuVideoQualityAfterStableReadback
    : (reason, details = {}) => (
      typeof downgradeSfuVideoQualityAfterEncodePressure === 'function'
        ? downgradeSfuVideoQualityAfterEncodePressure(reason, { ...details, direction: 'up' })
        : false
    );

  function resetWlvcBackpressureCounters() {
    state.wlvcBackpressureSkipCount = 0;
    state.wlvcBackpressureFirstAtMs = 0;
    state.wlvcBackpressurePauseUntilMs = 0;
    resetWlvcPayloadPressureCounters();
  }

  function resetWlvcFrameSendFailureCounters() {
    state.wlvcFrameSendFailureCount = 0;
    state.wlvcFrameSendFailureFirstAtMs = 0;
    resetWlvcSourceReadbackFailureCounters();
  }

  function resetWlvcPayloadPressureCounters() {
    state.wlvcPayloadPressureCount = 0;
    state.wlvcPayloadPressureFirstAtMs = 0;
  }

  function resetWlvcMotionDeltaStableWindow() {
    state.wlvcMotionDeltaStableStartedAtMs = 0;
    state.wlvcMotionDeltaStableSampleCount = 0;
  }

  function resetWlvcMotionDeltaCadence() {
    state.wlvcMotionDeltaCadenceLevel = 0;
    state.wlvcMotionDeltaCadenceUntilMs = 0;
    resetWlvcMotionDeltaStableWindow();
  }

  function resetWlvcSourceReadbackFailureCounters() {
    state.wlvcSourceReadbackFailureCount = 0;
    state.wlvcSourceReadbackFailureFirstAtMs = 0;
  }

  function resetWlvcSourceReadbackRecoveryWindow() {
    state.wlvcSourceReadbackStableStartedAtMs = 0;
    state.wlvcSourceReadbackStableSampleCount = 0;
    state.wlvcSourceReadbackLastSuccessAtMs = 0;
    state.wlvcSourceReadbackLastDrawMs = 0;
    state.wlvcSourceReadbackLastReadbackMs = 0;
  }

  function sourceReadbackTimingIsStable(details = {}) {
    const ratio = Math.max(
      0.1,
      Math.min(0.95, Number(SFU_AUTO_QUALITY_RECOVERY_MAX_READBACK_BUDGET_RATIO || 0.6)),
    );
    const drawImageMs = normalizedNumber(details?.drawImageMs ?? details?.draw_image_ms);
    const readbackMs = normalizedNumber(details?.readbackMs ?? details?.readback_ms);
    const drawBudgetMs = Math.max(
      1,
      normalizedNumber(details?.drawBudgetMs ?? details?.draw_budget_ms ?? details?.maxDrawImageMs ?? details?.max_draw_image_ms, 1),
    );
    const readbackBudgetMs = Math.max(
      1,
      normalizedNumber(details?.readbackBudgetMs ?? details?.readback_budget_ms ?? details?.maxReadbackMs ?? details?.max_readback_ms, 1),
    );
    if (drawImageMs <= 0 && readbackMs <= 0) return false;
    return drawImageMs <= drawBudgetMs * ratio && readbackMs <= readbackBudgetMs * ratio;
  }

  function motionDeltaPayloadIsStable(details = {}) {
    const encodedPayloadBytes = normalizedNumber(details?.encodedPayloadBytes ?? details?.encoded_payload_bytes);
    const payloadSoftLimitBytes = normalizedNumber(details?.payloadSoftLimitBytes ?? details?.payload_soft_limit_bytes);
    const maxPayloadBytes = normalizedNumber(details?.maxEncodedPayloadBytes ?? details?.max_encoded_payload_bytes);
    const referenceBytes = payloadSoftLimitBytes > 0 ? payloadSoftLimitBytes : maxPayloadBytes;
    if (referenceBytes <= 0 || encodedPayloadBytes <= 0) return false;
    return encodedPayloadBytes <= referenceBytes * 0.72;
  }

  function noteWlvcMotionDeltaSuccess(details = {}) {
    const nowMs = Math.max(0, Number(details?.nowMs || details?.timestamp || Date.now()));
    const cadenceLevel = normalizedMotionDeltaCadenceLevel(nowMs);
    if (cadenceLevel <= 0) {
      resetWlvcMotionDeltaStableWindow();
      return false;
    }
    if (!motionDeltaPayloadIsStable(details)) {
      resetWlvcMotionDeltaStableWindow();
      return false;
    }
    if (state.wlvcMotionDeltaStableStartedAtMs <= 0) {
      state.wlvcMotionDeltaStableStartedAtMs = nowMs;
      state.wlvcMotionDeltaStableSampleCount = 0;
    }
    state.wlvcMotionDeltaStableSampleCount = Number(state.wlvcMotionDeltaStableSampleCount || 0) + 1;
    const stableForMs = Math.max(0, nowMs - Number(state.wlvcMotionDeltaStableStartedAtMs || 0));
    if (
      stableForMs < SFU_WLVC_MOTION_DELTA_STABLE_WINDOW_MS
      || Number(state.wlvcMotionDeltaStableSampleCount || 0) < SFU_WLVC_MOTION_DELTA_STABLE_SAMPLE_COUNT
    ) {
      return false;
    }

    const previousLevel = cadenceLevel;
    const stableSampleCount = Number(state.wlvcMotionDeltaStableSampleCount || 0);
    state.wlvcMotionDeltaCadenceLevel = Math.max(0, previousLevel - 1);
    state.wlvcMotionDeltaCadenceUntilMs = state.wlvcMotionDeltaCadenceLevel > 0
      ? nowMs + SFU_WLVC_MOTION_DELTA_CADENCE_WINDOW_MS
      : 0;
    resetWlvcMotionDeltaStableWindow();

    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_wlvc_motion_delta_cadence_recovered',
      code: 'sfu_wlvc_motion_delta_cadence_recovered',
      message: 'WLVC motion delta payloads stayed below budget; encode cadence is recovering before a profile upshift probe.',
      payload: {
        previous_motion_delta_cadence_level: previousLevel,
        motion_delta_cadence_level: state.wlvcMotionDeltaCadenceLevel,
        motion_delta_cadence_multiplier: motionDeltaCadenceMultiplier(nowMs),
        stable_window_ms: SFU_WLVC_MOTION_DELTA_STABLE_WINDOW_MS,
        stable_for_ms: stableForMs,
        stable_sample_count: stableSampleCount,
        payload_bytes: normalizedNumber(details?.encodedPayloadBytes ?? details?.encoded_payload_bytes),
        payload_soft_limit_bytes: normalizedNumber(details?.payloadSoftLimitBytes ?? details?.payload_soft_limit_bytes),
        max_payload_bytes: normalizedNumber(details?.maxEncodedPayloadBytes ?? details?.max_encoded_payload_bytes),
        outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
        media_runtime_path: getMediaRuntimePath(),
      },
    });

    if (state.wlvcMotionDeltaCadenceLevel <= 0) {
      resetWlvcPayloadPressureCounters();
      return qualityRecoveryProbe('sfu_wlvc_motion_delta_recovered', {
        payload_bytes: normalizedNumber(details?.encodedPayloadBytes ?? details?.encoded_payload_bytes),
        payload_soft_limit_bytes: normalizedNumber(details?.payloadSoftLimitBytes ?? details?.payload_soft_limit_bytes),
        max_payload_bytes: normalizedNumber(details?.maxEncodedPayloadBytes ?? details?.max_encoded_payload_bytes),
        encode_ms: normalizedNumber(details?.encodeMs ?? details?.encode_ms),
        frame_type: String(details?.frameType || details?.frame_type || ''),
        layout_mode: String(details?.layoutMode || details?.layout_mode || ''),
        track_id: String(details?.trackId || details?.track_id || ''),
        outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
        media_runtime_path: getMediaRuntimePath(),
      });
    }
    return true;
  }

  function noteWlvcSourceReadbackSuccess(details = {}) {
    const nowMs = Math.max(0, Number(details?.nowMs || details?.timestamp || Date.now()));
    resetWlvcSourceReadbackFailureCounters();
    noteWlvcMotionDeltaSuccess(details);
    state.wlvcSourceReadbackLastSuccessAtMs = nowMs;
    state.wlvcSourceReadbackLastDrawMs = normalizedNumber(details?.drawImageMs ?? details?.draw_image_ms);
    state.wlvcSourceReadbackLastReadbackMs = normalizedNumber(details?.readbackMs ?? details?.readback_ms);

    if (!sourceReadbackTimingIsStable(details)) {
      resetWlvcSourceReadbackRecoveryWindow();
      return false;
    }
    if (state.wlvcSourceReadbackStableStartedAtMs <= 0) {
      state.wlvcSourceReadbackStableStartedAtMs = nowMs;
      state.wlvcSourceReadbackStableSampleCount = 0;
    }
    state.wlvcSourceReadbackStableSampleCount = Number(state.wlvcSourceReadbackStableSampleCount || 0) + 1;

    const stableForMs = Math.max(0, nowMs - Number(state.wlvcSourceReadbackStableStartedAtMs || 0));
    if (stableForMs < SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS) return false;
    const lastQualityChangeAtMs = Math.max(
      Number(state.sfuAutoQualityDowngradeLastAtMs || 0),
      Number(state.sfuAutoQualityRecoveryLastAtMs || 0),
    );
    if ((nowMs - lastQualityChangeAtMs) < SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS) return false;

    const probed = qualityRecoveryProbe('sfu_source_readback_recovered', {
      stable_window_ms: SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS,
      stable_for_ms: stableForMs,
      stable_sample_count: Number(state.wlvcSourceReadbackStableSampleCount || 0),
      draw_image_ms: state.wlvcSourceReadbackLastDrawMs,
      readback_ms: state.wlvcSourceReadbackLastReadbackMs,
      draw_budget_ms: Math.max(0, Number(details?.drawBudgetMs ?? details?.draw_budget_ms ?? 0)),
      readback_budget_ms: Math.max(0, Number(details?.readbackBudgetMs ?? details?.readback_budget_ms ?? 0)),
      readback_budget_ratio: SFU_AUTO_QUALITY_RECOVERY_MAX_READBACK_BUDGET_RATIO,
      readback_method: String(details?.readbackMethod || details?.readback_method || ''),
      source_backend: String(details?.sourceBackend || details?.source_backend || ''),
      readback_bytes: Math.max(0, Number(details?.readbackBytes || details?.readback_bytes || 0)),
      frame_width: Math.max(0, Number(details?.frameWidth || details?.frame_width || 0)),
      frame_height: Math.max(0, Number(details?.frameHeight || details?.frame_height || 0)),
      track_id: String(details?.trackId || details?.track_id || ''),
      outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
      media_runtime_path: getMediaRuntimePath(),
    });
    if (!probed) return false;
    state.sfuAutoQualityRecoveryLastAtMs = nowMs;
    resetWlvcSourceReadbackRecoveryWindow();
    return true;
  }

  function wlvcBackpressurePauseMs(bufferedAmount) {
    const normalizedBuffered = Math.max(0, Number(bufferedAmount || 0));
    const pressureRatio = Math.max(
      1,
      normalizedBuffered / Math.max(1, sfuWlvcSendBufferHighWaterBytes)
    );
    const pauseMs = Math.round(sfuWlvcBackpressureMinPauseMs * pressureRatio);
    return Math.min(
      sfuWlvcBackpressureMaxPauseMs,
      Math.max(sfuWlvcBackpressureMinPauseMs, pauseMs)
    );
  }

  function normalizedMotionDeltaCadenceLevel(nowMs = Date.now()) {
    const level = Math.max(0, Math.min(
      SFU_WLVC_MOTION_DELTA_MAX_CADENCE_LEVEL,
      Math.floor(Number(state.wlvcMotionDeltaCadenceLevel || 0)),
    ));
    if (level <= 0) return 0;
    if (Number(state.wlvcMotionDeltaCadenceUntilMs || 0) <= nowMs) {
      resetWlvcMotionDeltaCadence();
      return 0;
    }
    return level;
  }

  function motionDeltaCadenceMultiplier(nowMs = Date.now()) {
    const level = normalizedMotionDeltaCadenceLevel(nowMs);
    return Number((1 + (level * 0.45)).toFixed(2));
  }

  function resolveWlvcEncodeIntervalMs(baseIntervalMs = 0, details = {}) {
    const normalizedBaseIntervalMs = Math.max(1, Number(baseIntervalMs || 0));
    const multiplier = motionDeltaCadenceMultiplier(Date.now());
    return Math.max(1, Math.round(normalizedBaseIntervalMs * multiplier));
  }

  function shouldThrottleWlvcEncodeLoop(nowMs = Date.now()) {
    return state.wlvcBackpressurePauseUntilMs > nowMs;
  }

  function decide(stageTelemetry = {}) {
    return decidePublisherBackpressureAction(stageTelemetry, {
      backpressureWindowMs: sfuAutoQualityDowngradeBackpressureWindowMs,
      criticalBytes: sfuWlvcSendBufferCriticalBytes,
      hardResetAfterMs: sfuWlvcBackpressureHardResetAfterMs,
      highWaterBytes: sfuWlvcSendBufferHighWaterBytes,
      lowWaterBytes: sfuWlvcSendBufferLowWaterBytes,
      motionDeltaProfileDownshiftThreshold: SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD,
      sendFailureThreshold: sfuAutoQualityDowngradeSendFailureThreshold,
      skipThreshold: sfuAutoQualityDowngradeSkipThreshold,
    });
  }

  function shouldDelayWlvcFrameForBackpressure(bufferedAmount) {
    const decision = decide({
      kind: 'pre_encode_buffer',
      reason: 'bounded_queue_pre_encode_check',
      bufferedAmount,
      skipCount: state.wlvcBackpressureSkipCount,
    });
    return decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME)
      || decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
  }

  function handleWlvcEncodeBackpressure(bufferedAmount, trackId) {
    const nowMs = Date.now();
    resetWlvcSourceReadbackRecoveryWindow();
    if (state.wlvcBackpressureFirstAtMs <= 0) {
      state.wlvcBackpressureFirstAtMs = nowMs;
    }
    state.wlvcBackpressureSkipCount += 1;
    resetWlvcEncoderAfterDroppedEncodedFrame('sfu_send_backpressure_skip');
    state.wlvcBackpressurePauseUntilMs = Math.max(
      state.wlvcBackpressurePauseUntilMs,
      nowMs + wlvcBackpressurePauseMs(bufferedAmount)
    );

    if ((nowMs - state.wlvcBackpressureLastLogAtMs) >= sfuBackpressureLogCooldownMs) {
      state.wlvcBackpressureLastLogAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_video_backpressure',
        code: 'sfu_video_backpressure',
        message: 'Outgoing SFU video frames are being skipped because the websocket send buffer is full.',
        payload: {
          buffered_amount: bufferedAmount,
          skipped_frame_count: state.wlvcBackpressureSkipCount,
          forced_next_keyframe: true,
          adaptive_quality_downgrade_enabled: true,
          auto_quality_downgrade_skip_threshold: sfuAutoQualityDowngradeSkipThreshold,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
      });
    }

    const sustainedBackpressureMs = state.wlvcBackpressureFirstAtMs > 0
      ? Math.max(0, nowMs - state.wlvcBackpressureFirstAtMs)
      : 0;
    const decision = decide({
      kind: 'encode_backpressure',
      reason: 'sfu_send_backpressure',
      bufferedAmount,
      skipCount: state.wlvcBackpressureSkipCount,
      sustainedBackpressureMs,
    });
    if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)) {
      const downgradeReason = bufferedAmount >= sfuWlvcSendBufferHighWaterBytes
        ? 'sfu_send_backpressure_critical'
        : 'sfu_send_backpressure';
      if (downgradeSfuVideoQualityAfterEncodePressure(downgradeReason)) {
        resetWlvcBackpressureCounters();
        return;
      }
    }
    const socketLooksStuck = decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.SOCKET_RESTART);
    if (socketLooksStuck && restartSfuAfterVideoStall('sfu_send_buffer_stuck', {
      buffered_amount: bufferedAmount,
      skipped_frame_count: state.wlvcBackpressureSkipCount,
      sustained_backpressure_ms: sustainedBackpressureMs,
      outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
    })) {
      resetWlvcBackpressureCounters();
    }
  }

  function isSourceReadbackBudgetFailure(reason, details) {
    const normalizedReason = String(details?.reason || reason || '').trim().toLowerCase();
    if (normalizedReason === 'sfu_source_readback_budget_exceeded') return true;
    return String(details?.transportPath || details?.transport_path || '').trim().toLowerCase() === 'publisher_source_readback';
  }

  function handleSourceReadbackBudgetFailure(normalizedBuffered, trackId, normalizedReason, details, nowMs, retryAfterMs, sendFailurePauseMs) {
    resetWlvcSourceReadbackRecoveryWindow();
    if (
      state.wlvcSourceReadbackFailureFirstAtMs <= 0
      || (nowMs - state.wlvcSourceReadbackFailureFirstAtMs) > sfuAutoQualityDowngradeBackpressureWindowMs
    ) {
      state.wlvcSourceReadbackFailureFirstAtMs = nowMs;
      state.wlvcSourceReadbackFailureCount = 1;
    } else {
      state.wlvcSourceReadbackFailureCount += 1;
    }

    resetWlvcEncoderAfterDroppedEncodedFrame(normalizedReason);
    state.wlvcDroppedSourceFrameCount = Math.max(0, Number(state.wlvcDroppedSourceFrameCount || 0)) + 1;
    state.wlvcBackpressurePauseUntilMs = Math.max(
      state.wlvcBackpressurePauseUntilMs,
      nowMs + sendFailurePauseMs
    );

    const sustainedBackpressureMs = state.wlvcSourceReadbackFailureFirstAtMs > 0
      ? Math.max(0, nowMs - state.wlvcSourceReadbackFailureFirstAtMs)
      : 0;
    const decision = decide({
      kind: 'source_readback_failure',
      reason: normalizedReason,
      bufferedAmount: normalizedBuffered,
      sourceReadbackFailureCount: state.wlvcSourceReadbackFailureCount,
      sustainedBackpressureMs,
      queueAgeMs: details?.queueAgeMs,
      payloadBytes: details?.payloadBytes,
    });
    const failureStage = String(details?.stage || 'unknown_stage');
    const failureSource = String(details?.source || 'unknown_source');
    const publisherFrameTraceId = String(details?.publisherFrameTraceId || details?.publisher_frame_trace_id || '');

    if ((nowMs - state.wlvcSourceReadbackFailureLastLogAtMs) >= sfuBackpressureLogCooldownMs) {
      state.wlvcSourceReadbackFailureLastLogAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_source_readback_budget_pressure',
        code: 'sfu_source_readback_budget_pressure',
        message: 'Publisher source readback exceeded the active profile budget before WLVC encode.',
        payload: {
          reason: normalizedReason,
          stage: failureStage,
          source: failureSource,
          transport_path: 'publisher_source_readback',
          source_readback_failure_count: state.wlvcSourceReadbackFailureCount,
          ...publisherDroppedSourceFrameDiagnosticSurface({
            details,
            droppedSourceFrameCount: state.wlvcDroppedSourceFrameCount,
            selectedProfile: callMediaPrefs.outgoingVideoQualityProfile,
          }),
          source_readback_failure_threshold: sfuAutoQualityDowngradeSendFailureThreshold,
          requested_action: decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)
            ? PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT
            : PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE,
          buffered_amount: normalizedBuffered,
          retry_after_ms: retryAfterMs,
          send_failure_pause_ms: sendFailurePauseMs,
          publisher_frame_trace_id: publisherFrameTraceId,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
        immediate: decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
      });
    }

    if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_source_readback_profile_downshift',
        code: 'sfu_source_readback_profile_downshift',
        message: 'Outgoing SFU video quality is being lowered after consecutive source-readback budget failures.',
        payload: {
          reason: normalizedReason,
          source_readback_failure_count: state.wlvcSourceReadbackFailureCount,
          ...publisherDroppedSourceFrameDiagnosticSurface({
            details,
            droppedSourceFrameCount: state.wlvcDroppedSourceFrameCount,
            selectedProfile: callMediaPrefs.outgoingVideoQualityProfile,
          }),
          source_readback_failure_threshold: sfuAutoQualityDowngradeSendFailureThreshold,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
        immediate: true,
      });
      if (downgradeSfuVideoQualityAfterEncodePressure(normalizedReason)) {
        resetWlvcSourceReadbackFailureCounters();
      }
    }
  }

  function handleWlvcFrameSendFailure(bufferedAmount, trackId, reason = 'sfu_frame_send_failed', failureDetails = null) {
    const details = failureDetails && typeof failureDetails === 'object' ? failureDetails : null;
    const normalizedBuffered = Math.max(
      0,
      Number(details?.bufferedAmount ?? bufferedAmount ?? 0),
    );
    const normalizedReason = String(details?.reason || reason || 'sfu_frame_send_failed');
    if (shouldDelayWlvcFrameForBackpressure(normalizedBuffered)) {
      handleWlvcEncodeBackpressure(normalizedBuffered, trackId);
      return;
    }

    const nowMs = Date.now();
    const retryAfterMs = Math.max(
      0,
      Number(details?.retryAfterMs ?? details?.retry_after_ms ?? 0),
    );
    const sendFailurePauseMs = retryAfterMs > 0
      ? Math.min(
        sfuWlvcBackpressureMaxPauseMs,
        Math.max(sfuWlvcBackpressureMinPauseMs, retryAfterMs),
      )
      : sfuWlvcBackpressureMinPauseMs;
    if (isSourceReadbackBudgetFailure(normalizedReason, details)) {
      handleSourceReadbackBudgetFailure(normalizedBuffered, trackId, normalizedReason, details || {}, nowMs, retryAfterMs, sendFailurePauseMs);
      return;
    }
    resetWlvcSourceReadbackFailureCounters();
    resetWlvcSourceReadbackRecoveryWindow();
    if (
      state.wlvcFrameSendFailureFirstAtMs <= 0
      || (nowMs - state.wlvcFrameSendFailureFirstAtMs) > sfuAutoQualityDowngradeBackpressureWindowMs
    ) {
      state.wlvcFrameSendFailureFirstAtMs = nowMs;
      state.wlvcFrameSendFailureCount = 1;
    } else {
      state.wlvcFrameSendFailureCount += 1;
    }
    resetWlvcEncoderAfterDroppedEncodedFrame(normalizedReason);
    state.wlvcBackpressurePauseUntilMs = Math.max(
      state.wlvcBackpressurePauseUntilMs,
      nowMs + sendFailurePauseMs
    );
    const sustainedBackpressureMs = state.wlvcFrameSendFailureFirstAtMs > 0
      ? Math.max(0, nowMs - state.wlvcFrameSendFailureFirstAtMs)
      : 0;
    const decision = decide({
      kind: 'send_failure',
      reason: normalizedReason,
      bufferedAmount: normalizedBuffered,
      sendFailureCount: state.wlvcFrameSendFailureCount,
      sustainedBackpressureMs,
      queueAgeMs: details?.queueAgeMs,
      payloadBytes: details?.payloadBytes,
    });
    if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)) {
      if (downgradeSfuVideoQualityAfterEncodePressure(normalizedReason)) {
        resetWlvcFrameSendFailureCounters();
        return;
      }
    }
    if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.SOCKET_RESTART)) {
      restartSfuAfterVideoStall('sfu_send_buffer_stuck', {
        buffered_amount: normalizedBuffered,
        send_failure_count: state.wlvcFrameSendFailureCount,
        sustained_backpressure_ms: sustainedBackpressureMs,
        outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
      });
    }

    if ((nowMs - state.wlvcFrameSendFailureLastLogAtMs) < sfuBackpressureLogCooldownMs) {
      return;
    }
    state.wlvcFrameSendFailureLastLogAtMs = nowMs;
    const failureStage = String(details?.stage || 'unknown_stage');
    const failureSource = String(details?.source || 'unknown_source');
    const failureTransportPath = String(details?.transportPath || 'unknown_transport');
    const failureMessage = String(details?.message || 'Outgoing SFU video frame send failed.');
    const publisherFrameTraceId = String(details?.publisherFrameTraceId || details?.publisher_frame_trace_id || '');
    const publisherPathTraceStages = String(details?.publisherPathTraceStages || details?.publisher_path_trace_stages || '');
    const sourceDeliveryMs = Math.max(
      0,
      Number(details?.sourceDeliveryMs || details?.trace_get_user_media_frame_delivery_ms || 0),
    );
    const drawImageMs = Math.max(
      0,
      Number(details?.drawImageMs || details?.draw_image_ms || details?.trace_dom_canvas_draw_image_ms || 0),
    );
    const readbackMs = Math.max(
      0,
      Number(details?.readbackMs || details?.readback_ms || details?.trace_dom_canvas_get_image_data_ms || 0),
    );
    const encodeMs = Math.max(
      0,
      Number(details?.encodeMs || details?.encode_ms || details?.trace_wlvc_encode_ms || 0),
    );
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_frame_send_failed',
      code: 'sfu_frame_send_failed',
      message: failureMessage,
      payload: {
        reason: normalizedReason,
        stage: failureStage,
        source: failureSource,
        transport_path: failureTransportPath,
        buffered_amount: normalizedBuffered,
        forced_next_keyframe: true,
        adaptive_quality_downgrade_enabled: true,
        auto_quality_downgrade_send_failure_threshold: sfuAutoQualityDowngradeSendFailureThreshold,
        track_id: String(trackId || ''),
        outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
        media_runtime_path: getMediaRuntimePath(),
        queue_length: Math.max(0, Number(details?.queueLength || 0)),
        queue_payload_chars: Math.max(0, Number(details?.queuePayloadChars || 0)),
        active_payload_chars: Math.max(0, Number(details?.activePayloadChars || 0)),
        chunk_count: Math.max(0, Number(details?.chunkCount || 0)),
        payload_chars: Math.max(0, Number(details?.payloadChars || 0)),
        payload_bytes: Math.max(0, Number(details?.payloadBytes || 0)),
        wire_payload_bytes: Math.max(0, Number(details?.wirePayloadBytes || 0)),
        publisher_frame_trace_id: publisherFrameTraceId,
        publisher_path_trace_stages: publisherPathTraceStages,
        source_delivery_ms: sourceDeliveryMs,
        draw_image_ms: drawImageMs,
        readback_ms: readbackMs,
        encode_ms: encodeMs,
        retry_after_ms: retryAfterMs,
        send_failure_pause_ms: sendFailurePauseMs,
        binary_continuation_state: String(details?.binaryContinuationState || 'unknown_binary_continuation_state'),
        sender_timestamp: Math.max(0, Number(details?.timestamp || 0)),
      },
    });
  }

  function handleWlvcFramePayloadPressure(payloadBytes, trackId, frameType = 'delta', details = {}) {
    const nowMs = Date.now();
    resetWlvcSourceReadbackRecoveryWindow();
    const normalizedPayloadBytes = Math.max(0, Number(payloadBytes || 0));
    const normalizedFrameType = String(frameType || 'delta').trim().toLowerCase() === 'keyframe'
      ? 'keyframe'
      : 'delta';
    const pressureReason = String(details?.reason || 'sfu_high_motion_payload_pressure');
    if (
      state.wlvcPayloadPressureFirstAtMs <= 0
      || (nowMs - state.wlvcPayloadPressureFirstAtMs) > sfuAutoQualityDowngradeBackpressureWindowMs
    ) {
      state.wlvcPayloadPressureFirstAtMs = nowMs;
      state.wlvcPayloadPressureCount = 1;
    } else {
      state.wlvcPayloadPressureCount += 1;
    }

    resetWlvcEncoderAfterDroppedEncodedFrame(pressureReason);
    resetWlvcMotionDeltaStableWindow();
    const nextCadenceLevel = Math.min(
      SFU_WLVC_MOTION_DELTA_MAX_CADENCE_LEVEL,
      Math.max(
        Number(state.wlvcMotionDeltaCadenceLevel || 0),
        Math.max(1, Math.ceil(state.wlvcPayloadPressureCount / Math.max(1, SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD - 1))),
      ),
    );
    state.wlvcMotionDeltaCadenceLevel = nextCadenceLevel;
    state.wlvcMotionDeltaCadenceUntilMs = nowMs + SFU_WLVC_MOTION_DELTA_CADENCE_WINDOW_MS;
    state.wlvcBackpressurePauseUntilMs = Math.max(
      state.wlvcBackpressurePauseUntilMs,
      nowMs + wlvcBackpressurePauseMs(Math.max(normalizedPayloadBytes, sfuWlvcSendBufferHighWaterBytes))
    );

    if ((nowMs - state.wlvcPayloadPressureLastLogAtMs) >= sfuBackpressureLogCooldownMs) {
      state.wlvcPayloadPressureLastLogAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_video_payload_pressure',
        code: 'sfu_video_payload_pressure',
        message: 'Outgoing SFU video frame was dropped before send because WLVC exceeded the active profile budget.',
        payload: {
          payload_bytes: normalizedPayloadBytes,
          pressure_reason: pressureReason,
          max_payload_bytes: Math.max(0, Number(details?.max_payload_bytes || details?.maxPayloadBytes || 0)),
          payload_soft_limit_bytes: Math.max(0, Number(details?.payload_soft_limit_bytes || details?.payloadSoftLimitBytes || 0)),
          payload_soft_limit_ratio: Math.max(0, Number(details?.payload_soft_limit_ratio || details?.payloadSoftLimitRatio || 0)),
          keyframe_retry_after_ms: Math.max(0, Number(details?.keyframe_retry_after_ms || details?.keyframeRetryAfterMs || 0)),
          encode_ms: Math.max(0, Number(details?.encode_ms || details?.encodeMs || 0)),
          budget_max_encode_ms: Math.max(0, Number(details?.budget_max_encode_ms || details?.budgetMaxEncodeMs || 0)),
          frame_type: normalizedFrameType,
          layout_mode: String(details?.layout_mode || details?.layoutMode || 'full_frame'),
          payload_pressure_count: state.wlvcPayloadPressureCount,
          motion_delta_cadence_level: state.wlvcMotionDeltaCadenceLevel,
          motion_delta_cadence_until_ms: state.wlvcMotionDeltaCadenceUntilMs,
          motion_delta_cadence_multiplier: motionDeltaCadenceMultiplier(nowMs),
          motion_delta_profile_downshift_threshold: SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD,
          forced_next_keyframe: true,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
        immediate: true,
      });
    }

    const decision = decide({
      kind: 'payload_pressure',
      reason: pressureReason,
      payloadBytes: normalizedPayloadBytes,
      payloadPressureCount: state.wlvcPayloadPressureCount,
      encodeMs: details?.encode_ms || details?.encodeMs,
    });
    if (
      decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.CADENCE_THROTTLE)
      && (
        decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)
        || (nowMs - Number(state.wlvcMotionDeltaLastLogAtMs || 0)) >= sfuBackpressureLogCooldownMs
      )
    ) {
      state.wlvcMotionDeltaLastLogAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_wlvc_motion_delta_cadence_throttled',
        code: 'sfu_wlvc_motion_delta_cadence_throttled',
        message: 'WLVC high-motion payload pressure is throttling encode cadence before a destructive profile downshift.',
        payload: {
          pressure_reason: pressureReason,
          payload_bytes: normalizedPayloadBytes,
          payload_pressure_count: state.wlvcPayloadPressureCount,
          motion_delta_cadence_level: state.wlvcMotionDeltaCadenceLevel,
          motion_delta_cadence_until_ms: state.wlvcMotionDeltaCadenceUntilMs,
          motion_delta_cadence_multiplier: motionDeltaCadenceMultiplier(nowMs),
          motion_delta_profile_downshift_threshold: SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD,
          requested_action: PUBLISHER_BACKPRESSURE_ACTIONS.CADENCE_THROTTLE,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
        immediate: decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
      });
    }
    if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)) {
      downgradeSfuVideoQualityAfterEncodePressure(pressureReason);
    }
  }

  function handleWlvcRuntimeEncodeError({
    encodeFailureCount,
    reason = 'wlvc_encode_runtime_error',
    trackId = '',
    mediaRuntimePath = '',
  } = {}) {
    const decision = decidePublisherBackpressureAction({
      kind: 'runtime_encode_error',
      reason,
      encodeFailureCount,
    }, {
      encodeFailureThreshold: Math.max(1, normalizedNumber(sfuWlvcEncodeFailureThreshold, 1)),
    });
    if (!decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT)) {
      return false;
    }
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_publisher_backpressure_decision',
      code: 'sfu_publisher_backpressure_decision',
      message: 'Publisher backpressure controller selected a profile downshift after runtime encode failures.',
      payload: {
        reason: String(reason || 'wlvc_encode_runtime_error'),
        track_id: String(trackId || ''),
        media_runtime_path: String(mediaRuntimePath || getMediaRuntimePath()),
        requested_action: PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT,
        stage_telemetry: decision.stage_telemetry,
      },
      immediate: true,
    });
    return downgradeSfuVideoQualityAfterEncodePressure(reason);
  }

  function requestWlvcFullFrameKeyframe(reason = 'sfu_remote_keyframe_request', details = {}) {
    const nowMs = Date.now();
    const normalizedReason = String(reason || 'sfu_remote_keyframe_request').trim().toLowerCase();
    state.wlvcRemoteKeyframeRequestCount = Number(state.wlvcRemoteKeyframeRequestCount || 0) + 1;
    state.wlvcRemoteKeyframeRequestUntilMs = Math.max(
      Number(state.wlvcRemoteKeyframeRequestUntilMs || 0),
      nowMs + Math.max(3000, sfuWlvcBackpressureMinPauseMs * 6),
    );
    resetWlvcEncoderAfterDroppedEncodedFrame(normalizedReason);
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_remote_full_keyframe_requested',
      code: 'sfu_remote_full_keyframe_requested',
      message: 'A remote receiver requested a full-frame SFU keyframe; selective patch frames are disabled until it is sent.',
      payload: {
        reason: normalizedReason,
        sender_user_id: Math.max(0, Number(details?.senderUserId || details?.sender_user_id || 0)),
        publisher_id: String(details?.publisher_id || details?.publisherId || ''),
        request_count: state.wlvcRemoteKeyframeRequestCount,
        request_until_ms: state.wlvcRemoteKeyframeRequestUntilMs,
        outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
        media_runtime_path: getMediaRuntimePath(),
      },
      immediate: true,
    });
    return true;
  }

  function restartSfuAfterVideoStall(reason, payload = {}) {
    const nowMs = Date.now();
    if ((nowMs - state.sfuVideoRecoveryLastAtMs) < sfuVideoRecoveryReconnectCooldownMs) {
      return false;
    }
    state.sfuVideoRecoveryLastAtMs = nowMs;

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_video_reconnect_after_stall',
      code: 'sfu_video_reconnect_after_stall',
      message: 'The SFU socket is being reconnected because remote video stopped producing fresh frames.',
      payload: {
        ...payload,
        reason: String(reason || 'video_stall'),
        media_runtime_path: getMediaRuntimePath(),
        remote_peer_count: getRemotePeerCount(),
      },
      immediate: true,
    });

    sfuConnected.value = false;
    onRestartSfu(getShouldConnectSfu, sfuConnectRetryDelayMs);
    return true;
  }

  return {
    decidePublisherBackpressureAction: decide,
    handleWlvcEncodeBackpressure,
    handleWlvcFramePayloadPressure,
    handleWlvcFrameSendFailure,
    handleWlvcRuntimeEncodeError,
    noteWlvcSourceReadbackSuccess,
    requestWlvcFullFrameKeyframe,
    resolveWlvcEncodeIntervalMs,
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    restartSfuAfterVideoStall,
    shouldDelayWlvcFrameForBackpressure,
    shouldThrottleWlvcEncodeLoop,
  };
}
