export function createSfuTransportState() {
  return {
    wlvcBackpressureSkipCount: 0,
    wlvcBackpressureFirstAtMs: 0,
    wlvcBackpressureLastLogAtMs: 0,
    wlvcBackpressurePauseUntilMs: 0,
    wlvcFrameSendFailureLastLogAtMs: 0,
    wlvcFrameSendFailureCount: 0,
    wlvcFrameSendFailureFirstAtMs: 0,
    sfuAutoQualityDowngradeLastAtMs: 0,
    sfuVideoRecoveryLastAtMs: 0,
  };
}

export function createSfuTransportController({
  callMediaPrefs,
  captureClientDiagnostic,
  downgradeSfuVideoQualityAfterEncodePressure,
  getMediaRuntimePath,
  getSfuSendFailureDetails,
  getRemotePeerCount,
  getShouldConnectSfu,
  onRestartSfu,
  resetWlvcEncoderAfterDroppedEncodedFrame,
  sfuAutoQualityDowngradeBackpressureWindowMs,
  sfuAutoQualityDowngradeSendFailureThreshold,
  sfuAutoQualityDowngradeSkipThreshold,
  sfuBackpressureLogCooldownMs,
  sfuClientRef,
  sfuConnectRetryDelayMs,
  sfuConnected,
  sfuVideoRecoveryReconnectCooldownMs,
  sfuWlvcBackpressureHardResetAfterMs,
  sfuWlvcBackpressureMaxPauseMs,
  sfuWlvcBackpressureMinPauseMs,
  sfuWlvcSendBufferCriticalBytes,
  sfuWlvcSendBufferHighWaterBytes,
  sfuWlvcSendBufferLowWaterBytes,
  state,
}) {
  function isSfuClientOpen() {
    const client = sfuClientRef.value;
    if (!client) return false;
    if (typeof client.isOpen === 'function') return client.isOpen();
    return client.ws?.readyState === WebSocket.OPEN;
  }

  function getSfuClientBufferedAmount() {
    const client = sfuClientRef.value;
    if (!client) return 0;
    const amount = typeof client.getBufferedAmount === 'function'
      ? client.getBufferedAmount()
      : Number(client.ws?.bufferedAmount || 0);
    return Number.isFinite(amount) ? Math.max(0, amount) : 0;
  }

  function resetWlvcBackpressureCounters() {
    state.wlvcBackpressureSkipCount = 0;
    state.wlvcBackpressureFirstAtMs = 0;
    state.wlvcBackpressurePauseUntilMs = 0;
  }

  function resetWlvcFrameSendFailureCounters() {
    state.wlvcFrameSendFailureCount = 0;
    state.wlvcFrameSendFailureFirstAtMs = 0;
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

  function shouldDelayWlvcFrameForBackpressure(bufferedAmount) {
    const normalizedBuffered = Math.max(0, Number(bufferedAmount || 0));
    if (normalizedBuffered >= sfuWlvcSendBufferHighWaterBytes) return true;
    return state.wlvcBackpressureSkipCount > 0
      && normalizedBuffered >= sfuWlvcSendBufferLowWaterBytes;
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
          hd_baseline_no_auto_downgrade: true,
          track_id: String(trackId || ''),
          outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
          media_runtime_path: getMediaRuntimePath(),
        },
      });
    }

    const sustainedBackpressureMs = state.wlvcBackpressureFirstAtMs > 0
      ? Math.max(0, nowMs - state.wlvcBackpressureFirstAtMs)
      : 0;
    if (
      sustainedBackpressureMs >= sfuAutoQualityDowngradeBackpressureWindowMs
      || state.wlvcBackpressureSkipCount >= sfuAutoQualityDowngradeSkipThreshold
    ) {
      if (downgradeSfuVideoQualityAfterEncodePressure('sfu_send_backpressure')) {
        resetWlvcBackpressureCounters();
        return;
      }
    }
    const socketLooksStuck = bufferedAmount >= sfuWlvcSendBufferCriticalBytes
      && sustainedBackpressureMs >= sfuWlvcBackpressureHardResetAfterMs;
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
    const details = failureDetails && typeof failureDetails === 'object'
      ? failureDetails
      : (typeof getSfuSendFailureDetails === 'function' ? getSfuSendFailureDetails() : null);
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
      nowMs + sfuWlvcBackpressureMinPauseMs
    );
    if (state.wlvcFrameSendFailureCount >= sfuAutoQualityDowngradeSendFailureThreshold) {
      if (downgradeSfuVideoQualityAfterEncodePressure(normalizedReason)) {
        resetWlvcFrameSendFailureCounters();
        return;
      }
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
        hd_baseline_no_auto_downgrade: true,
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
        binary_continuation_state: String(details?.binaryContinuationState || 'unknown_binary_continuation_state'),
        sender_timestamp: Math.max(0, Number(details?.timestamp || 0)),
      },
    });
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
    getSfuClientBufferedAmount,
    handleWlvcEncodeBackpressure,
    handleWlvcFrameSendFailure,
    isSfuClientOpen,
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    restartSfuAfterVideoStall,
    shouldDelayWlvcFrameForBackpressure,
    shouldThrottleWlvcEncodeLoop,
  };
}
