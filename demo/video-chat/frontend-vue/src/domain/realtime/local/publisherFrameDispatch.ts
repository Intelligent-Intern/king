import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';
import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';
import { normalizeAuthoritativePublisherTransport } from './authoritativePublisherMediaProfile';

export function publisherRequiresSfuBeforeEncode() {
  return false;
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

function diagnoseGossipPrimaryPublishFailure({
  captureClientDiagnostic,
  trackId,
  mediaRuntimePath,
}) {
  safeFunction(captureClientDiagnostic, () => undefined)({
    category: 'media',
    level: 'warning',
    eventType: 'gossip_primary_publish_failed',
    code: 'gossip_primary_publish_failed',
    message: 'Gossip primary publication failed; no alternate media path was attempted.',
    payload: diagnosticsPayload({
      trackId,
      mediaRuntimePath,
      extra: {
        fallback_reason: 'gossip_primary_no_alternate_path',
        gossip_primary_expected: true,
      },
    }),
    immediate: true,
  });
}

function isQuietSfuSendFailure(reason, details = {}) {
  const normalizedReason = String(reason || details?.reason || '').trim().toLowerCase();
  const abortReason = String(details?.abort_reason || details?.abortReason || '').trim().toLowerCase();
  const stage = String(details?.stage || '').trim().toLowerCase();
  return /socket_not_open|media_transport|unavailable_after|wire_rate_budget|buffer_budget|queue_age_budget|send_buffer_drain_timeout|binary_envelope|frame_send_failed/.test(
    `${normalizedReason} ${abortReason} ${stage}`,
  );
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
  onOptionalSfuFailure,
  suppressGossipPrimary = false,
  suppressSfuSendFailures = false,
  plannedTransport = '',
}) {
  const normalizedPlannedTransport = normalizeAuthoritativePublisherTransport(plannedTransport);
  const planRequiresGossipTransport = normalizedPlannedTransport === 'gossip' && suppressGossipPrimary !== true;
  const planRequiresSfuTransport = normalizedPlannedTransport === 'sfu';
  const gossipFirst = !planRequiresSfuTransport
    && (planRequiresGossipTransport || VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary)
    && suppressGossipPrimary !== true;
  const sfuOptional = !planRequiresSfuTransport && VIDEOCHAT_MEDIA_CARRIER_CONFIG.sfuSendIsOptional && suppressGossipPrimary !== true;
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

  if (gossipFirst) {
    if (!gossipPublished) {
      diagnoseGossipPrimaryPublishFailure({
        captureClientDiagnostic,
        trackId,
        mediaRuntimePath,
      });
    }
    return {
      ok: gossipPublished,
      gossipPublished,
      sfuSent: false,
      sfuSendOptional: false,
      alternatePathSuppressed: true,
      postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
    };
  }

  const sendClient = safeFunction(currentOpenSfuClient, () => null)();
  if (!sendClient) {
    if (suppressSfuSendFailures) {
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: false,
        sfuSendQuietDrop: true,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      };
    }
    if (gossipPublished) {
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: true,
        sfuMirrorSkipped: true,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      };
    }
    if (!gossipPublished && !planRequiresSfuTransport) {
      gossipPublished = publishGossipFrame({
        frame,
        trackId,
        mediaRuntimePath,
        publishLocalEncodedFrameToGossip,
        captureClientDiagnosticError,
      });
    }
    if (gossipPublished) {
      diagnoseOptionalSfuSkip({
        captureClientDiagnostic,
        eventType: 'sfu_send_unavailable_gossip_continues',
        message: 'SFU send path is unavailable; Gossip publication continues without waiting for SFU.',
        trackId,
        mediaRuntimePath,
      });
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: true,
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
    diagnoseOptionalSfuSkip({
      captureClientDiagnostic,
      eventType: 'sfu_optional_send_unavailable_after_gossip_publish',
      message: 'SFU send path is unavailable; media carrier mode keeps Gossip publication independent.',
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

  const sent = await sendClient.sendEncodedFrame(frame);
  if (sent === false) {
    const failureDetails = sendClient.getLastSendFailure?.() || null;
    if (suppressSfuSendFailures && (!failureDetails || isQuietSfuSendFailure('', failureDetails || {}))) {
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: false,
        sfuSendQuietDrop: true,
        sfuSendFailureDetails: failureDetails,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      };
    }
    if (!gossipFirst && !planRequiresSfuTransport) {
      gossipPublished = publishGossipFrame({
        frame,
        trackId,
        mediaRuntimePath,
        publishLocalEncodedFrameToGossip,
        captureClientDiagnosticError,
      });
    }
    if (gossipPublished) {
      diagnoseOptionalSfuSkip({
        captureClientDiagnostic,
        eventType: 'sfu_send_failed_gossip_continues',
        message: 'SFU send failed; Gossip publication continues without waiting for SFU recovery.',
        trackId,
        mediaRuntimePath,
        failureDetails,
      });
      safeFunction(onOptionalSfuFailure, () => undefined)(failureDetails);
      return {
        ok: true,
        gossipPublished,
        sfuSent: false,
        sfuSendOptional: true,
        postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
        sfuSendFailureDetails: failureDetails,
      };
    }
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
    diagnoseOptionalSfuSkip({
      captureClientDiagnostic,
      eventType: 'sfu_optional_send_failed_after_gossip_publish',
      message: 'SFU send failed, but the selected media carrier mode does not let SFU failure block Gossip publication.',
      trackId,
      mediaRuntimePath,
      failureDetails,
    });
    safeFunction(onOptionalSfuFailure, () => undefined)(failureDetails);
    return {
      ok: gossipPublished,
      gossipPublished,
      sfuSent: false,
      sfuSendOptional: true,
      postSendBufferedAmount: safeFunction(getSfuClientBufferedAmount, () => 0)(),
      sfuSendFailureDetails: failureDetails,
    };
  }

  if (!gossipFirst && !planRequiresSfuTransport) {
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
  suppressGossipPrimary = false,
  suppressSfuSendFailures = false,
  plannedTransport = '',
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
    suppressGossipPrimary,
    suppressSfuSendFailures,
    plannedTransport,
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
    onOptionalSfuFailure: (sfuSendFailureDetails) => {
      safeFunction(paceForcedKeyframeRecovery, () => undefined)();
      handleWlvcFrameSendFailure(
        getSfuClientBufferedAmount(),
        trackId,
        String(sfuSendFailureDetails?.reason || 'sfu_frame_send_failed'),
        sfuSendFailureDetails,
      );
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
  suppressGossipPrimary = false,
  suppressSfuSendFailures = false,
  plannedTransport = '',
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
    suppressGossipPrimary,
    suppressSfuSendFailures,
    plannedTransport,
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
    onOptionalSfuFailure: (sfuSendFailureDetails) => {
      if (!critical) {
        reportNonCriticalDrop(String(sfuSendFailureDetails?.reason || 'sfu_browser_thumbnail_frame_send_failed'), {
          ...(sfuSendFailureDetails || {}),
        });
        return;
      }
      handleWlvcFrameSendFailure(
        getSfuClientBufferedAmount(),
        trackId,
        String(sfuSendFailureDetails?.reason || 'sfu_browser_encoded_frame_send_failed'),
        sfuSendFailureDetails,
      );
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
