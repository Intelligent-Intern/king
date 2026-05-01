import { reportClientDiagnostic } from '../../../support/clientDiagnostics';

const SFU_VIDEO_STABLE_MIN_FRAMES = 12;
const SFU_VIDEO_STABLE_MIN_AGE_MS = 1500;
const SFU_VIDEO_RECOVERY_CONSOLE_ATTEMPTS = 3;

function captureSfuVideoStatusDiagnostic(event = {}) {
  try {
    reportClientDiagnostic(event);
  } catch {
    // Video status reporting must not affect decode or render loops.
  }
}

function normalizeUserId(value) {
  const normalized = Number(value || 0);
  return Number.isInteger(normalized) && normalized > 0 ? normalized : 0;
}

export function shouldExposeSfuVideoRecoveryAttempt(attempt) {
  const normalizedAttempt = Math.max(0, Math.floor(Number(attempt || 0)));
  return normalizedAttempt >= SFU_VIDEO_RECOVERY_CONSOLE_ATTEMPTS
    && (
      normalizedAttempt === SFU_VIDEO_RECOVERY_CONSOLE_ATTEMPTS
      || normalizedAttempt % SFU_VIDEO_RECOVERY_CONSOLE_ATTEMPTS === 0
    );
}

export function logSfuVideoRecoveryStatus(label, {
  ageMs = 0,
  attempt = 0,
  localUserId = 0,
  peer,
  publisherId = '',
  receiveGapMs = 0,
  runtime = '',
  state = 'unstable',
}) {
  // Recovery status is reported through runtimeHealth backend diagnostics.
}

export function noteSfuRemoteVideoFrameStable(peer, frame, {
  currentUserId = 0,
  mediaRuntimePath = '',
}) {
  if (!peer || typeof peer !== 'object') return;
  if (Number(peer.sfuVideoStableLoggedAtMs || 0) > 0) return;

  const frameCount = Math.max(0, Math.floor(Number(peer.frameCount || 0)));
  if (frameCount < SFU_VIDEO_STABLE_MIN_FRAMES) return;

  const nowMs = Date.now();
  const createdAtMs = Number(peer.createdAtMs || 0);
  if (createdAtMs > 0 && (nowMs - createdAtMs) < SFU_VIDEO_STABLE_MIN_AGE_MS) return;

  peer.sfuVideoStableLoggedAtMs = nowMs;
  captureSfuVideoStatusDiagnostic({
    category: 'media',
    level: 'info',
    eventType: 'sfu_remote_video_stable',
    code: 'sfu_remote_video_stable',
    message: 'SFU remote video reached the stable frame threshold.',
    payload: {
      local_user_id: normalizeUserId(currentUserId),
      remote_user_id: normalizeUserId(peer.userId || frame?.publisherUserId),
      publisher_id: String(frame?.publisherId || '').trim(),
      track_id: String(frame?.trackId || '').trim(),
      frames: frameCount,
      received_frames: Math.max(0, Math.floor(Number(peer.receivedFrameCount || 0))),
      media_runtime_path: String(mediaRuntimePath || '').trim(),
    },
  });
}
