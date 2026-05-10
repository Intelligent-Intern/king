export function normalizeOwnerAbsencePayload(payload) {
  if (!payload || typeof payload !== 'object') return null;

  const countdownRemainingMs = Number(payload.countdown_remaining_ms ?? payload.countdownRemainingMs ?? 0);
  const timerMs = Number(payload.timer_ms ?? payload.timerMs ?? 0);
  const countdownMs = Number(payload.countdown_ms ?? payload.countdownMs ?? 0);

  return {
    ...payload,
    enabled: Boolean(payload.enabled),
    status: String(payload.status || '').trim().toLowerCase(),
    callStatus: String(payload.call_status ?? payload.callStatus ?? '').trim().toLowerCase(),
    ownerPresent: Boolean(payload.owner_present ?? payload.ownerPresent ?? false),
    countdownStarted: Boolean(payload.countdown_started ?? payload.countdownStarted ?? false),
    countdownRemainingMs: Number.isFinite(countdownRemainingMs) ? Math.max(0, Math.round(countdownRemainingMs)) : 0,
    timerMs: Number.isFinite(timerMs) ? Math.max(0, Math.round(timerMs)) : 0,
    countdownMs: Number.isFinite(countdownMs) ? Math.max(0, Math.round(countdownMs)) : 0,
  };
}

export function shouldShowOwnerAbsenceMonitoring(payload) {
  const state = normalizeOwnerAbsencePayload(payload);
  return Boolean(state?.enabled && state.status === 'monitoring' && !state.ownerPresent);
}

export function shouldShowOwnerAbsenceCountdown(payload) {
  const state = normalizeOwnerAbsencePayload(payload);
  return Boolean(state?.enabled && state.status === 'countdown' && state.countdownStarted);
}

export function shouldShowOwnerAbsenceEnded(payload) {
  const state = normalizeOwnerAbsencePayload(payload);
  return Boolean(state?.enabled && state.status === 'ended');
}

export function formatOwnerAbsenceCountdown(ms) {
  const totalSeconds = Math.max(0, Math.ceil(Number(ms || 0) / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = String(totalSeconds % 60).padStart(2, '0');
  return `${minutes}:${seconds}`;
}
