import { createPublisherBackpressureController } from './publisherBackpressureController';

export function createSfuTransportState() {
  return {
    wlvcBackpressureSkipCount: 0,
    wlvcBackpressureFirstAtMs: 0,
    wlvcBackpressureLastLogAtMs: 0,
    wlvcBackpressurePauseUntilMs: 0,
    wlvcPayloadPressureCount: 0,
    wlvcPayloadPressureFirstAtMs: 0,
    wlvcPayloadPressureLastLogAtMs: 0,
    wlvcMotionDeltaCadenceLevel: 0,
    wlvcMotionDeltaCadenceUntilMs: 0,
    wlvcMotionDeltaStableStartedAtMs: 0,
    wlvcMotionDeltaStableSampleCount: 0,
    wlvcMotionDeltaLastLogAtMs: 0,
    wlvcFrameSendFailureLastLogAtMs: 0,
    wlvcFrameSendFailureCount: 0,
    wlvcFrameSendFailureFirstAtMs: 0,
    wlvcSourceReadbackFailureCount: 0,
    wlvcSourceReadbackFailureFirstAtMs: 0,
    wlvcSourceReadbackFailureLastLogAtMs: 0,
    wlvcSourceReadbackStableStartedAtMs: 0,
    wlvcSourceReadbackStableSampleCount: 0,
    wlvcSourceReadbackLastSuccessAtMs: 0,
    wlvcSourceReadbackLastDrawMs: 0,
    wlvcSourceReadbackLastReadbackMs: 0,
    wlvcDroppedSourceFrameCount: 0,
    wlvcRemoteKeyframeRequestCount: 0,
    wlvcRemoteKeyframeRequestUntilMs: 0,
    sfuAutomaticQualityTransitionCount: 0,
    sfuAutomaticQualityTransitionLastAtMs: 0,
    sfuAutoQualityDowngradeLastAtMs: 0,
    sfuAutoQualityRecoveryLastAtMs: 0,
    sfuBrowserEncoderCompatibilityDisabledUntilMs: 0,
    sfuBrowserEncoderCompatibilityLastRequestedAtMs: 0,
    sfuBrowserEncoderCompatibilityReason: '',
    sfuBrowserEncoderCompatibilityRequestedByUserId: 0,
    sfuRemotePrimaryLayerRequestedUntilMs: 0,
    sfuRemoteLayerPreferenceLastAtMs: 0,
    sfuRemoteLayerPreferenceLastAction: '',
    sfuVideoRecoveryLastAtMs: 0,
  };
}

export function createSfuTransportController(options) {
  const {
    getSfuSendFailureDetails,
    sfuClientRef,
  } = options;

  const publisherBackpressureController = createPublisherBackpressureController(options);

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

  function handleWlvcFrameSendFailure(bufferedAmount, trackId, reason = 'sfu_frame_send_failed', failureDetails = null) {
    const details = failureDetails && typeof failureDetails === 'object'
      ? failureDetails
      : (typeof getSfuSendFailureDetails === 'function' ? getSfuSendFailureDetails() : null);
    return publisherBackpressureController.handleWlvcFrameSendFailure(bufferedAmount, trackId, reason, details);
  }

  return {
    getSfuClientBufferedAmount,
    handleWlvcEncodeBackpressure: publisherBackpressureController.handleWlvcEncodeBackpressure,
    handleWlvcFramePayloadPressure: publisherBackpressureController.handleWlvcFramePayloadPressure,
    handleWlvcFrameSendFailure,
    handleWlvcRuntimeEncodeError: publisherBackpressureController.handleWlvcRuntimeEncodeError,
    isSfuClientOpen,
    noteWlvcSourceReadbackSuccess: publisherBackpressureController.noteWlvcSourceReadbackSuccess,
    requestWlvcFullFrameKeyframe: publisherBackpressureController.requestWlvcFullFrameKeyframe,
    resolveWlvcEncodeIntervalMs: publisherBackpressureController.resolveWlvcEncodeIntervalMs,
    resetWlvcBackpressureCounters: publisherBackpressureController.resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters: publisherBackpressureController.resetWlvcFrameSendFailureCounters,
    restartSfuAfterVideoStall: publisherBackpressureController.restartSfuAfterVideoStall,
    shouldDelayWlvcFrameForBackpressure: publisherBackpressureController.shouldDelayWlvcFrameForBackpressure,
    shouldThrottleWlvcEncodeLoop: publisherBackpressureController.shouldThrottleWlvcEncodeLoop,
  };
}
