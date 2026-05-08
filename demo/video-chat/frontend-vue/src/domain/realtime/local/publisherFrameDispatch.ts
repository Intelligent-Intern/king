import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';
import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';

export function publisherRequiresSfuBeforeEncode() {
  return VIDEOCHAT_MEDIA_CARRIER_CONFIG.sfuRequiredBeforeGossip;
}

function safeFunction(value, fallback = () => false) {
  return typeof value === 'function' ? value : fallback;
}

function diagnosticsPayload({ trackId, mediaRuntimePath, extra = {} }) {
  return {
    media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
    gossip_may_publish_without_sfu: VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipMayPublishWithoutSfu,
    sfu_send_optional: VIDEOCHAT_MEDIA_CARRIER_CONFIG.sfuSendIsOptional,
    diagnostics_label: VIDEOCHAT_MEDIA_CARRIER_CONFIG.diagnosticsLabel,
    media_runtime_path: String(mediaRuntimePath || ''),
    track_id: String(trackId || ''),
    ...extra,
  };
}

function publishGossipFrame({
  frame,
  trackId,
  mediaRuntimePath,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnosticError,
}) {
  try {
    return Boolean(publishLocalEncodedFrameToGossip(frame));
  } catch (gossipError) {
    safeFunction(captureClientDiagnosticError)('gossip_data_lane_publish_failed', gossipError, {
      media_runtime_path: String(mediaRuntimePath || ''),
      track_id: String(trackId || ''),
    }, {
      code: 'gossip_data_lane_publish_failed',
    });
    return false;
  }
}

function diagnoseOptionalSfuSkip({
  captureClientDiagnostic,
  eventType,
  message,
  trackId,
  mediaRuntimePath,
  failureDetails = null,
  immediate = false,
}) {
  safeFunction(captureClientDiagnostic, () => undefined)({
    category: 'media',
    level: 'warning',
    eventType,
    code: eventType,
    message,
    payload: diagnosticsPayload({
      trackId,
      mediaRuntimePath,
      extra: failureDetails && typeof failureDetails === 'object' ? { sfu_send_failure: failureDetails } : {},
    }),
    immediate,
  });
}

function shouldUseSfuFallbackAfterGossipPrimaryPublish(gossipPublished) {
  return VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary && !gossipPublished;
}

export async function dispatchPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  currentOpenSfuClient,
  getSfuClientBufferedAmount,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  onRequiredSfuUnavailable,
  onRequiredSfuFailure,
}) {
  const gossipFirst = VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary;
  const sfuOptional = VIDEOCHAT_MEDIA_CARRIER_CONFIG.sfuSendIsOptional;
  let gossipPublished = false;

  if (gossipFirst) {
    gossipPublished = publishGossipFrame({
      frame,
      trackId,
      mediaRuntimePath,
      publishLocalEncodedFrameToGossip,
      captureClientDiagnosticError,
    });
  }

  const sendClient = safeFunction(currentOpenSfuClient, () => null)();
  if (!sendClient) {
    if (gossipFirst && gossipPublished) {
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: true,
        sfuMirrorSkipped: true,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      };
    }
    if (!sfuOptional) {
      return {
        ok: Boolean(safeFunction(onRequiredSfuUnavailable)()),
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: false,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      };
    }
    const eventType = shouldUseSfuFallbackAfterGossipPrimaryPublish(gossipPublished)
      ? 'sfu_fallback_unavailable_after_gossip_publish_failure'
      : 'sfu_optional_send_unavailable_after_gossip_publish';
    diagnoseOptionalSfuSkip({
      captureClientDiagnostic,
      eventType,
      message: shouldUseSfuFallbackAfterGossipPrimaryPublish(gossipPublished)
        ? 'SFU fallback is unavailable after Gossip primary publication failed.'
        : 'SFU send path is unavailable; media carrier mode keeps Gossip publication independent.',
      trackId,
      mediaRuntimePath,
    });
    return {
      ok: gossipPublished,
      gossipPublished,
      sfuSent: false,
      sfuSendOptional: true,
      postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
    };
  }

  if (gossipFirst && !gossipPublished) {
    diagnoseOptionalSfuSkip({
      captureClientDiagnostic,
      eventType: 'sfu_fallback_after_gossip_primary_publish_failure',
      message: 'Gossip primary did not publish this frame; SFU fallback is being used to keep live media flowing.',
      trackId,
      mediaRuntimePath,
      failureDetails: {
        fallback_reason: 'gossip_publish_failed_or_gated',
        sfu_socket_open: true,
        gossip_primary_expected: true,
      },
      immediate: true,
    });
  }

  const sent = await sendClient.sendEncodedFrame(frame);
  if (sent === false) {
    const failureDetails = sendClient.getLastSendFailure?.() || null;
    if (!sfuOptional) {
      return {
        ok: Boolean(safeFunction(onRequiredSfuFailure)(failureDetails)),
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: false,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
        sfuSendFailureDetails: failureDetails,
      };
    }
    if (!gossipFirst) {
      gossipPublished = publishGossipFrame({
        frame,
        trackId,
        mediaRuntimePath,
        publishLocalEncodedFrameToGossip,
        captureClientDiagnosticError,
      });
    }
    diagnoseOptionalSfuSkip({
      captureClientDiagnostic,
      eventType: 'sfu_optional_send_failed_after_gossip_publish',
      message: 'SFU send failed, but the selected media carrier mode does not let SFU failure block Gossip publication.',
      trackId,
      mediaRuntimePath,
      failureDetails,
    });
    return {
      ok: gossipPublished,
      gossipPublished,
      sfuSent: false,
      sfuSendOptional: true,
      postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      sfuSendFailureDetails: failureDetails,
    };
  }

  if (!gossipFirst) {
    gossipPublished = publishGossipFrame({
      frame,
      trackId,
      mediaRuntimePath,
      publishLocalEncodedFrameToGossip,
      captureClientDiagnosticError,
    });
  }

  return {
    ok: true,
    gossipPublished,
    sfuSent: true,
    sfuSendOptional: sfuOptional,
    postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
  };
}

export async function dispatchWlvcPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  currentOpenSfuClient,
  getSfuClientBufferedAmount,
  handleWlvcFrameSendFailure,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  trace,
  timestamp,
  paceForcedKeyframeRecovery,
}) {
  return dispatchPublisherFrame({
    frame,
    trackId,
    mediaRuntimePath,
    currentOpenSfuClient,
    getSfuClientBufferedAmount,
    publishLocalEncodedFrameToGossip,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    onRequiredSfuUnavailable: () => {
      reportSfuClientUnavailableAfterEncode({
        getSfuClientBufferedAmount,
        handleWlvcFrameSendFailure,
        trackId,
        trace,
        timestamp,
      });
      return false;
    },
    onRequiredSfuFailure: (sfuSendFailureDetails) => {
      safeFunction(paceForcedKeyframeRecovery, () => undefined)();
      handleWlvcFrameSendFailure(
        getSfuClientBufferedAmount(),
        trackId,
        String(sfuSendFailureDetails?.reason || 'sfu_frame_send_failed'),
        sfuSendFailureDetails,
      );
      return false;
    },
  });
}

export async function dispatchProtectedBrowserPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  currentOpenSfuClient,
  getSfuClientBufferedAmount,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  handleWlvcFrameSendFailure,
  reportNonCriticalDrop,
  critical,
  codecId,
}) {
  return dispatchPublisherFrame({
    frame,
    trackId,
    mediaRuntimePath,
    currentOpenSfuClient,
    getSfuClientBufferedAmount,
    publishLocalEncodedFrameToGossip,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    onRequiredSfuUnavailable: () => {
      if (!critical) {
        reportNonCriticalDrop('sfu_client_unavailable_after_browser_thumbnail_encode', {
          bufferedAmount: getSfuClientBufferedAmount(),
        });
        return true;
      }
      handleWlvcFrameSendFailure(getSfuClientBufferedAmount(), trackId, 'sfu_client_unavailable_after_browser_encode', {
        reason: 'sfu_client_unavailable_after_browser_encode',
        codec_id: codecId,
        bufferedAmount: getSfuClientBufferedAmount(),
      });
      return false;
    },
    onRequiredSfuFailure: (sfuSendFailureDetails) => {
      if (!critical) {
        reportNonCriticalDrop(String(sfuSendFailureDetails?.reason || 'sfu_browser_thumbnail_frame_send_failed'), {
          ...(sfuSendFailureDetails || {}),
        });
        return true;
      }
      handleWlvcFrameSendFailure(
        getSfuClientBufferedAmount(),
        trackId,
        String(sfuSendFailureDetails?.reason || 'sfu_browser_encoded_frame_send_failed'),
        sfuSendFailureDetails,
      );
      return false;
    },
  });
}

export function diagnoseOptionalSfuPressureAfterGossip({
  captureClientDiagnostic,
  mediaRuntimePath,
  trackId,
  bufferedAmount,
  pressureBudgetBytes,
}) {
  diagnoseOptionalSfuSkip({
    captureClientDiagnostic,
    eventType: 'sfu_optional_send_pressure_after_gossip_publish',
    message: 'Optional SFU send crossed the pressure budget after Gossip publication.',
    trackId,
    mediaRuntimePath,
    failureDetails: {
      bufferedAmount,
      pressureBudgetBytes,
    },
  });
}
