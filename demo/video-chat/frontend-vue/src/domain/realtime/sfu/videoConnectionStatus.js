const SFU_VIDEO_STABLE_MIN_FRAMES = 12;
const SFU_VIDEO_STABLE_MIN_AGE_MS = 1500;
const SFU_VIDEO_RECOVERY_CONSOLE_ATTEMPTS = 3;

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
  console.warn(
    label,
    `state=${String(state || 'unstable')}`,
    `local_user=${normalizeUserId(localUserId)}`,
    `remote_user=${normalizeUserId(peer?.userId)}`,
    `publisher=${String(publisherId || '').trim()}`,
    `attempts=${Math.max(0, Math.floor(Number(attempt || 0)))}`,
    `age=${Math.max(0, Math.floor(Number(ageMs || 0)))}ms`,
    `receive_gap=${Math.max(0, Math.floor(Number(receiveGapMs || 0)))}ms`,
    `frames=${Math.max(0, Math.floor(Number(peer?.frameCount || 0)))}`,
    `received=${Math.max(0, Math.floor(Number(peer?.receivedFrameCount || 0)))}`,
    `runtime=${String(runtime || '').trim()}`,
  );
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
  console.info(
    '[KingRT] SFU video stable',
    `local_user=${normalizeUserId(currentUserId)}`,
    `remote_user=${normalizeUserId(peer.userId || frame?.publisherUserId)}`,
    `publisher=${String(frame?.publisherId || '').trim()}`,
    `track=${String(frame?.trackId || '').trim()}`,
    `frames=${frameCount}`,
    `received=${Math.max(0, Math.floor(Number(peer.receivedFrameCount || 0)))}`,
    `runtime=${String(mediaRuntimePath || '').trim()}`,
  );
}
