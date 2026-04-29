const RECEIVER_FEEDBACK_MIN_INTERVAL_MS = 4000;
const RECEIVER_RENDER_LAG_PRESSURE_MS = 900;

function normalizePositiveNumber(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return Math.max(0, normalized);
}

export function createSfuReceiverFeedback({
  currentUserId,
  mediaRuntimePathRef,
  sendRemoteSfuVideoQualityPressure,
}) {
  function maybeSendReceiverFeedback(peer, publisherId, reason, nowMs, payload = {}) {
    if (!peer || typeof peer !== 'object') return false;
    if (typeof sendRemoteSfuVideoQualityPressure !== 'function') return false;

    const localUserId = normalizePositiveNumber(typeof currentUserId === 'function' ? currentUserId() : 0);
    const targetUserId = normalizePositiveNumber(peer.userId || 0);
    if (targetUserId <= 0 || targetUserId === localUserId) return false;

    const lastSentAtMs = normalizePositiveNumber(peer.lastReceiverFeedbackPressureSentAtMs || 0);
    if (lastSentAtMs > 0 && (nowMs - lastSentAtMs) < RECEIVER_FEEDBACK_MIN_INTERVAL_MS) return false;

    const sent = sendRemoteSfuVideoQualityPressure(peer, publisherId, reason, nowMs, {
      requester_user_id: localUserId,
      media_runtime_path: String(mediaRuntimePathRef?.value || ''),
      ...payload,
    });
    if (sent) {
      peer.lastReceiverFeedbackPressureSentAtMs = nowMs;
      peer.lastReceiverFeedbackPressureReason = reason;
    }
    return sent;
  }

  function maybeSendReceiverRenderLagFeedback(peer, publisherId, frame, receiverRenderLatencyMs, extraPayload = {}) {
    const renderLatencyMs = normalizePositiveNumber(receiverRenderLatencyMs);
    if (renderLatencyMs < RECEIVER_RENDER_LAG_PRESSURE_MS) return false;
    const nowMs = Date.now();
    return maybeSendReceiverFeedback(peer, publisherId, 'sfu_receiver_render_lag', nowMs, {
      receiver_render_latency_ms: Math.round(renderLatencyMs),
      frame_sequence: normalizePositiveNumber(frame?.frameSequence || 0),
      subscriber_send_latency_ms: normalizePositiveNumber(frame?.subscriberSendLatencyMs || 0),
      king_receive_latency_ms: normalizePositiveNumber(frame?.kingReceiveLatencyMs || 0),
      king_fanout_latency_ms: normalizePositiveNumber(frame?.kingFanoutLatencyMs || 0),
      ...extraPayload,
    });
  }

  function maybeSendReceiverSequenceGapFeedback(peer, publisherId, frame, missingFrameCount, extraPayload = {}) {
    const missingFrames = normalizePositiveNumber(missingFrameCount);
    if (missingFrames <= 0) return false;
    const nowMs = Date.now();
    return maybeSendReceiverFeedback(peer, publisherId, 'sfu_receiver_sequence_gap', nowMs, {
      missing_frame_count: Math.round(missingFrames),
      frame_sequence: normalizePositiveNumber(frame?.frameSequence || 0),
      subscriber_send_latency_ms: normalizePositiveNumber(frame?.subscriberSendLatencyMs || 0),
      ...extraPayload,
    });
  }

  return {
    maybeSendReceiverRenderLagFeedback,
    maybeSendReceiverSequenceGapFeedback,
  };
}
