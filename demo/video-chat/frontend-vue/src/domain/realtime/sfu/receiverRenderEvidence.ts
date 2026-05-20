const RECEIVER_RENDER_EVIDENCE_MIN_INTERVAL_MS = 2000;

function numberField(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return normalized;
}

function stringField(value, fallback = '') {
  const normalized = String(value ?? '').trim();
  return normalized === '' ? fallback : normalized;
}

function frameVideoLayer(frame) {
  const normalized = stringField(frame?.videoLayer || frame?.video_layer).toLowerCase();
  if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail';
  if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary';
  return '';
}

export function reportReceiverMissingFrameEvidence({
  peer,
  publisherId,
  frame,
  missingFrameCount,
  lastSequence,
  dropReason,
  mediaRuntimePath,
  captureClientDiagnostic,
}) {
  const nowMs = Date.now();
  if (!peer || typeof peer !== 'object' || typeof captureClientDiagnostic !== 'function') return;
  const missingFrames = Math.max(0, Math.round(numberField(missingFrameCount)));
  if (missingFrames <= 0) return;

  peer.lastReceiverMissingFrameEvidenceAtMs = nowMs;
  peer.lastReceiverMissingFrameEvidenceReason = stringField(dropReason, 'sequence_gap');
  peer.lastReceiverMissingFrameCount = missingFrames;
  peer.lastReceiverMissingFrameSequence = Math.max(0, Math.round(numberField(frame?.frameSequence)));

  captureClientDiagnostic({
    category: 'media',
    level: 'warning',
    eventType: 'sfu_receiver_missing_frame_evidence',
    code: 'sfu_receiver_missing_frame_evidence',
    message: 'Receiver observed missing SFU frame sequence evidence without scheduling a local reconnect.',
    payload: {
      publisher_id: stringField(publisherId),
      publisher_user_id: numberField(frame?.publisherUserId || peer?.userId),
      track_id: stringField(frame?.trackId),
      frame_type: stringField(frame?.type),
      frame_sequence: Math.max(0, Math.round(numberField(frame?.frameSequence))),
      last_frame_sequence: Math.max(0, Math.round(numberField(lastSequence))),
      missing_frame_count: missingFrames,
      receiver_render_evidence: true,
      local_reconnect_trigger: false,
      drop_reason: stringField(dropReason, 'sequence_gap'),
      requested_video_layer: frameVideoLayer(frame),
      subscriber_send_latency_ms: Math.max(0, numberField(frame?.subscriberSendLatencyMs)),
      king_receive_latency_ms: Math.max(0, numberField(frame?.kingReceiveLatencyMs)),
      king_fanout_latency_ms: Math.max(0, numberField(frame?.kingFanoutLatencyMs)),
      media_runtime_path: stringField(mediaRuntimePath),
    },
    immediate: true,
  });
}

export function reportReceiverRenderEvidence({
  peer,
  frame,
  renderedAtMs,
  renderDecision,
  receiverRenderLatencyMs,
  mediaRuntimePath,
  captureClientDiagnostic,
}) {
  if (!peer || typeof peer !== 'object' || typeof captureClientDiagnostic !== 'function') return false;
  const nowMs = numberField(renderedAtMs, Date.now());
  if ((nowMs - numberField(peer.lastReceiverRenderEvidenceAtMs)) < RECEIVER_RENDER_EVIDENCE_MIN_INTERVAL_MS) {
    return false;
  }
  peer.lastReceiverRenderEvidenceAtMs = nowMs;
  peer.lastReceiverRenderEvidenceSequence = Math.max(0, Math.round(numberField(frame?.frameSequence)));

  captureClientDiagnostic({
    category: 'media',
    level: 'info',
    eventType: 'sfu_receiver_render_evidence',
    code: 'sfu_receiver_render_evidence',
    message: 'Receiver rendered a remote SFU video frame.',
    payload: {
      publisher_id: stringField(frame?.publisherId),
      publisher_user_id: numberField(frame?.publisherUserId || peer?.userId),
      track_id: stringField(frame?.trackId),
      frame_type: stringField(frame?.type),
      frame_sequence: Math.max(0, Math.round(numberField(frame?.frameSequence))),
      frame_timestamp: Math.max(0, Math.round(numberField(frame?.timestamp))),
      receiver_render_evidence: true,
      local_reconnect_trigger: false,
      render_surface_role: stringField(renderDecision?.role),
      requested_video_layer: frameVideoLayer(frame),
      receiver_render_latency_ms: Math.max(0, numberField(receiverRenderLatencyMs)),
      frame_width: Math.max(0, Math.round(numberField(peer?.frameWidth || frame?.frameWidth || frame?.frame_width))),
      frame_height: Math.max(0, Math.round(numberField(peer?.frameHeight || frame?.frameHeight || frame?.frame_height))),
      frame_count: Math.max(0, Math.round(numberField(peer?.frameCount))),
      received_frame_count: Math.max(0, Math.round(numberField(peer?.receivedFrameCount))),
      media_runtime_path: stringField(mediaRuntimePath),
    },
  });
  return true;
}

export function recentReceiverMissingFrameEvidence(peer, nowMs, windowMs) {
  if (!peer || typeof peer !== 'object') return false;
  const lastEvidenceAtMs = numberField(peer.lastReceiverMissingFrameEvidenceAtMs);
  return lastEvidenceAtMs > 0 && (numberField(nowMs, Date.now()) - lastEvidenceAtMs) < Math.max(0, numberField(windowMs));
}
