export const PUBLISHER_BACKPRESSURE_ACTIONS = Object.freeze({
  CONTINUE: 'continue',
  PAUSE_ENCODE: 'pause_encode',
  DROP_FRAME: 'drop_frame',
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
  const payloadPressureCount = normalizedNumber(stageTelemetry.payloadPressureCount);
  const encodeFailureCount = normalizedNumber(stageTelemetry.encodeFailureCount);
  const sustainedBackpressureMs = normalizedNumber(stageTelemetry.sustainedBackpressureMs);
  const highWaterBytes = Math.max(1, normalizedNumber(config.highWaterBytes, 1));
  const lowWaterBytes = Math.max(1, normalizedNumber(config.lowWaterBytes, highWaterBytes));
  const criticalBytes = Math.max(highWaterBytes, normalizedNumber(config.criticalBytes, highWaterBytes));
  const skipThreshold = Math.max(1, normalizedNumber(config.skipThreshold, 1));
  const sendFailureThreshold = Math.max(1, normalizedNumber(config.sendFailureThreshold, 1));
  const encodeFailureThreshold = Math.max(1, normalizedNumber(config.encodeFailureThreshold, 1));
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
  } else if (kind === 'payload_pressure') {
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME);
    addAction(actions, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT);
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
  function resetWlvcBackpressureCounters() {
    state.wlvcBackpressureSkipCount = 0;
    state.wlvcBackpressureFirstAtMs = 0;
    state.wlvcBackpressurePauseUntilMs = 0;
    resetWlvcPayloadPressureCounters();
  }

  function resetWlvcFrameSendFailureCounters() {
    state.wlvcFrameSendFailureCount = 0;
    state.wlvcFrameSendFailureFirstAtMs = 0;
  }

  function resetWlvcPayloadPressureCounters() {
    state.wlvcPayloadPressureCount = 0;
    state.wlvcPayloadPressureFirstAtMs = 0;
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
      console.warn(
        '[KingRT] SFU video backpressure - skipping outgoing WLVC frame',
        `buffered=${bufferedAmount}`,
        `skipped=${state.wlvcBackpressureSkipCount}`,
        `track=${String(trackId || '')}`,
        `profile=${String(callMediaPrefs.outgoingVideoQualityProfile || '')}`,
      );
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
    console.warn(
      '[KingRT] SFU frame send failed at exact transport stage',
      `reason=${normalizedReason}`,
      `stage=${failureStage}`,
      `source=${failureSource}`,
      `transport=${failureTransportPath}`,
      `buffered=${normalizedBuffered}`,
      `track=${String(trackId || '')}`,
      `profile=${String(callMediaPrefs.outgoingVideoQualityProfile || '')}`,
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
        retry_after_ms: retryAfterMs,
        send_failure_pause_ms: sendFailurePauseMs,
        binary_continuation_state: String(details?.binaryContinuationState || 'unknown_binary_continuation_state'),
        sender_timestamp: Math.max(0, Number(details?.timestamp || 0)),
      },
    });
  }

  function handleWlvcFramePayloadPressure(payloadBytes, trackId, frameType = 'delta', details = {}) {
    const nowMs = Date.now();
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
    state.wlvcBackpressurePauseUntilMs = Math.max(
      state.wlvcBackpressurePauseUntilMs,
      nowMs + wlvcBackpressurePauseMs(Math.max(normalizedPayloadBytes, sfuWlvcSendBufferHighWaterBytes))
    );

    if ((nowMs - state.wlvcPayloadPressureLastLogAtMs) >= sfuBackpressureLogCooldownMs) {
      state.wlvcPayloadPressureLastLogAtMs = nowMs;
      console.warn(
        '[KingRT] SFU video payload pressure - dropping oversized WLVC frame',
        `payload=${normalizedPayloadBytes}`,
        `frame=${normalizedFrameType}`,
        `count=${state.wlvcPayloadPressureCount}`,
        `track=${String(trackId || '')}`,
        `profile=${String(callMediaPrefs.outgoingVideoQualityProfile || '')}`,
      );
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

  function restartSfuAfterVideoStall(reason, payload = {}) {
    const nowMs = Date.now();
    if ((nowMs - state.sfuVideoRecoveryLastAtMs) < sfuVideoRecoveryReconnectCooldownMs) {
      return false;
    }
    state.sfuVideoRecoveryLastAtMs = nowMs;

    console.warn(
      '[KingRT] restarting SFU socket after video stall',
      `reason=${String(reason || 'video_stall')}`,
      `runtime=${getMediaRuntimePath()}`,
    );
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
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    restartSfuAfterVideoStall,
    shouldDelayWlvcFrameForBackpressure,
    shouldThrottleWlvcEncodeLoop,
  };
}
