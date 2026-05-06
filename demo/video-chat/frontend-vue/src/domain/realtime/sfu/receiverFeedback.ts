import {
  buildSfuLayerPreferencePayload,
  markSfuLayerPreferenceSent,
  sfuLayerPreferenceForRemoteSurfaceRole,
  shouldSendSfuLayerPreference,
  visibleParticipantCountForPeer,
} from './adaptiveQualityLayers';
import { coordinateSfuKeyframeRecoveryRequest } from './keyframeRecoveryCoordinator.ts';

const RECEIVER_FEEDBACK_MIN_INTERVAL_MS = 4000;
const RECEIVER_RENDER_LAG_PRESSURE_MS = 900;

function normalizePositiveNumber(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return Math.max(0, normalized);
}

function receiverFeedbackTargetUserId(peer, payload = {}) {
  return normalizePositiveNumber(
    peer?.userId
      || payload?.publisher_user_id
      || payload?.publisherUserId
      || 0
  );
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
    const targetUserId = receiverFeedbackTargetUserId(peer, payload);
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
      publisher_user_id: normalizePositiveNumber(frame?.publisherUserId || 0),
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
      publisher_user_id: normalizePositiveNumber(frame?.publisherUserId || 0),
      missing_frame_count: Math.round(missingFrames),
      frame_sequence: normalizePositiveNumber(frame?.frameSequence || 0),
      subscriber_send_latency_ms: normalizePositiveNumber(frame?.subscriberSendLatencyMs || 0),
      requested_action: 'force_full_keyframe',
      request_full_keyframe: true,
      ...extraPayload,
    });
  }

  function maybeSendReceiverKeyframeFeedback(peer, publisherId, frame, reason, extraPayload = {}) {
    const nowMs = Date.now();
    const coordinated = coordinateSfuKeyframeRecoveryRequest(peer, {
      publisherId,
      publisherUserId: frame?.publisherUserId || peer?.userId || 0,
      reason,
      trackId: frame?.trackId,
    }, nowMs);
    if (!coordinated.emit) {
      return maybeSendReceiverFeedback(peer, publisherId, 'sfu_receiver_keyframe_request_coalesced', nowMs, {
        publisher_user_id: normalizePositiveNumber(frame?.publisherUserId || 0),
        frame_sequence: normalizePositiveNumber(frame?.frameSequence || 0),
        original_reason: String(reason || 'sfu_receiver_keyframe_required'),
        requested_action: 'coalesce_full_keyframe',
        request_full_keyframe: false,
        keyframe_recovery_owner: 'sfu_per_publisher_keyframe_coordinator',
        keyframe_recovery_request_key: coordinated.requestKey,
        keyframe_recovery_request_until_ms: coordinated.requestUntilMs,
        keyframe_recovery_coalesce_window_ms: coordinated.coalesceWindowMs,
        ...extraPayload,
      });
    }
    return maybeSendReceiverFeedback(peer, publisherId, String(reason || 'sfu_receiver_keyframe_required'), nowMs, {
      publisher_user_id: normalizePositiveNumber(frame?.publisherUserId || 0),
      frame_sequence: normalizePositiveNumber(frame?.frameSequence || 0),
      subscriber_send_latency_ms: normalizePositiveNumber(frame?.subscriberSendLatencyMs || 0),
      requested_action: 'force_full_keyframe',
      request_full_keyframe: true,
      requested_video_layer: 'primary',
      keyframe_recovery_owner: 'sfu_per_publisher_keyframe_coordinator',
      keyframe_recovery_request_key: coordinated.requestKey,
      keyframe_recovery_request_until_ms: coordinated.requestUntilMs,
      keyframe_recovery_coalesce_window_ms: coordinated.coalesceWindowMs,
      ...extraPayload,
    });
  }

  function maybeSendReceiverLayerPreference(peer, publisherId, frame, renderSurfaceRole, extraPayload = {}) {
    if (!peer || typeof peer !== 'object') return false;
    if (typeof sendRemoteSfuVideoQualityPressure !== 'function') return false;

    const localUserId = normalizePositiveNumber(typeof currentUserId === 'function' ? currentUserId() : 0);
    const targetUserId = normalizePositiveNumber(peer.userId || 0);
    if (targetUserId <= 0 || targetUserId === localUserId) return false;

    const nowMs = Date.now();
    const visibleParticipantCount = visibleParticipantCountForPeer(peer);
    const layerPreference = sfuLayerPreferenceForRemoteSurfaceRole(renderSurfaceRole, {
      visibleParticipantCount,
    });
    if (!shouldSendSfuLayerPreference(peer, publisherId, frame, layerPreference, nowMs)) return false;

    const sent = sendRemoteSfuVideoQualityPressure(
      peer,
      publisherId,
      `sfu_receiver_${layerPreference}_layer_preference`,
      nowMs,
      {
        requester_user_id: localUserId,
        media_runtime_path: String(mediaRuntimePathRef?.value || ''),
        ...buildSfuLayerPreferencePayload({
          frame,
          layerPreference,
          renderSurfaceRole,
          visibleParticipantCount,
        }),
        ...extraPayload,
      },
    );
    if (sent) {
      markSfuLayerPreferenceSent(peer, publisherId, frame, layerPreference, nowMs);
    }
    return sent;
  }

  return {
    maybeSendReceiverKeyframeFeedback,
    maybeSendReceiverLayerPreference,
    maybeSendReceiverRenderLagFeedback,
    maybeSendReceiverSequenceGapFeedback,
  };
}
