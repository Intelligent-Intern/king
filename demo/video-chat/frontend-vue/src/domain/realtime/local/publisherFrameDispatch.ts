import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';

export function publisherRequiresSfuBeforeEncode() {
  return false;
}

function safeFunction(value, fallback = () => false) {
  return typeof value === 'function' ? value : fallback;
}

function diagnosticsPayload({ trackId, mediaRuntimePath, extra = {} }) {
  return {
    media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
    gossip_only_media_transport: true,
    gossip_only_media_transport_configured: VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipOnlyMediaTransport,
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

function diagnoseGossipPostPublishPressureEvent({
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
      extra: failureDetails && typeof failureDetails === 'object' ? { gossip_pressure: failureDetails } : {},
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

export async function dispatchPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  getSfuClientBufferedAmount,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  suppressGossipPrimary = false,
}) {
  const gossipPublished = suppressGossipPrimary === true
    ? false
    : publishGossipFrame({
      frame,
      trackId,
      mediaRuntimePath,
      publishLocalEncodedFrameToGossip,
      captureClientDiagnosticError,
    });
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

export async function dispatchWlvcPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  getSfuClientBufferedAmount,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  suppressGossipPrimary = false,
}) {
  return dispatchPublisherFrame({
    frame,
    trackId,
    mediaRuntimePath,
    getSfuClientBufferedAmount,
    publishLocalEncodedFrameToGossip,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    suppressGossipPrimary,
  });
}

export async function dispatchProtectedBrowserPublisherFrame({
  frame,
  trackId,
  mediaRuntimePath,
  getSfuClientBufferedAmount,
  publishLocalEncodedFrameToGossip,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  suppressGossipPrimary = false,
}) {
  return dispatchPublisherFrame({
    frame,
    trackId,
    mediaRuntimePath,
    getSfuClientBufferedAmount,
    publishLocalEncodedFrameToGossip,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    suppressGossipPrimary,
  });
}

export function diagnoseGossipPostPublishPressure({
  captureClientDiagnostic,
  mediaRuntimePath,
  trackId,
  bufferedAmount,
  pressureBudgetBytes,
}) {
  diagnoseGossipPostPublishPressureEvent({
    captureClientDiagnostic,
    eventType: 'gossip_post_publish_pressure',
    message: 'Post-publish media pressure crossed the budget after Gossip publication.',
    trackId,
    mediaRuntimePath,
    failureDetails: {
      bufferedAmount,
      pressureBudgetBytes,
    },
  });
}
