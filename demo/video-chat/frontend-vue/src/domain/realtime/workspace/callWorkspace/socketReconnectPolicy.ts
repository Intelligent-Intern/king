export const CONNECT_CYCLE_TIMEOUT_MS = 5 * 60 * 1000;
export const CONTROL_LANE_RECONNECT_INITIAL_DELAY_MS = 5 * 1000;
export const CONTROL_LANE_RECONNECT_MAX_DELAY_MS = 30 * 1000;

const EXPECTED_BROWSER_PAGE_EXIT_SOCKET_CLOSE_GRACE_MS = 15 * 1000;
let browserPageExitObservedAtMs = 0;
let browserPageExitCloseGuardBound = false;

function markBrowserPageExitObserved(event = null) {
  if (event?.persisted === true) return;
  browserPageExitObservedAtMs = Date.now();
}

export function clearBrowserPageExitObserved() {
  browserPageExitObservedAtMs = 0;
}

export function getBrowserPageExitObservedAtMs() {
  return browserPageExitObservedAtMs;
}

export function bindBrowserPageExitCloseGuard(forceReloadEvent) {
  if (browserPageExitCloseGuardBound || typeof window === 'undefined') return;
  browserPageExitCloseGuardBound = true;
  window.addEventListener(forceReloadEvent, markBrowserPageExitObserved, { capture: true });
  window.addEventListener('beforeunload', markBrowserPageExitObserved, { capture: true });
  window.addEventListener('pagehide', markBrowserPageExitObserved, { capture: true });
  window.addEventListener('pageshow', clearBrowserPageExitObserved, { capture: true });
}

export function isExpectedBrowserPageExitSocketClose(event, nowMs = Date.now()) {
  const closeCode = Number(event?.code || 0);
  const closeReason = String(event?.reason || '').trim();
  if (closeCode !== 1006 || closeReason !== '') return false;
  if (browserPageExitObservedAtMs <= 0) return false;
  return (nowMs - browserPageExitObservedAtMs) <= EXPECTED_BROWSER_PAGE_EXIT_SOCKET_CLOSE_GRACE_MS;
}

export function normalizeConnectFailureReason(reason, fallback = 'network_error') {
  const normalized = String(reason || '').trim().toLowerCase();
  return normalized !== '' ? normalized : fallback;
}

export function isAbnormalControlLaneClose(event, closeReason = '') {
  const closeCode = Number(event?.code || 0);
  const normalizedReason = String(closeReason || '').trim().toLowerCase();
  if (normalizedReason === 'client_leave' || normalizedReason === 'client_close') return false;
  if (normalizedReason === 'session_invalidated' || normalizedReason === 'stale_asset_version') return false;
  return closeCode === 1006 || closeCode === 1011 || normalizedReason === 'socket_error';
}

export function shouldScheduleControlLaneReconnect({
  opened = false,
  event = null,
  closeReason = '',
  transientReasons = [],
} = {}) {
  const normalizedReason = String(closeReason || '').trim().toLowerCase();
  if (opened !== true) return true;
  if (normalizedReason === 'auth_backend_error' || normalizedReason === 'websocket_reconnect_backfill_unavailable') return true;
  if (Array.isArray(transientReasons) && transientReasons.includes(normalizedReason)) return true;
  return isAbnormalControlLaneClose(event, normalizedReason);
}

export function controlLaneReconnectDelayMs(attempt, requestedDelayMs = null) {
  const requested = Number(requestedDelayMs);
  if (Number.isFinite(requested) && requested >= 0) {
    return Math.min(requested, CONTROL_LANE_RECONNECT_MAX_DELAY_MS);
  }
  const normalizedAttempt = Math.max(1, Number.parseInt(String(attempt || 1), 10) || 1);
  const backoff = CONTROL_LANE_RECONNECT_INITIAL_DELAY_MS * (2 ** Math.min(normalizedAttempt - 1, 3));
  return Math.min(backoff, CONTROL_LANE_RECONNECT_MAX_DELAY_MS);
}
