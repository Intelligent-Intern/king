const MEDIA_RUNTIME_TELEMETRY_KEY = 'ii.videocall.media_runtime_transitions.v1';
const MEDIA_RUNTIME_TELEMETRY_MAX = 300;

function safeReadRaw() {
  if (typeof localStorage === 'undefined') return [];
  const raw = localStorage.getItem(MEDIA_RUNTIME_TELEMETRY_KEY);
  if (!raw) return [];
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((row) => row && typeof row === 'object');
  } catch {
    return [];
  }
}

function safePersist(rows) {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(MEDIA_RUNTIME_TELEMETRY_KEY, JSON.stringify(rows));
  } catch {
    // ignore persistence errors
  }
}

function normalizePath(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'wlvc_wasm') return 'wlvc_wasm';
  if (normalized === 'webrtc_native') return 'webrtc_native';
  if (normalized === 'unsupported') return 'unsupported';
  if (normalized === 'pending') return 'pending';
  return 'unknown';
}

function normalizeReason(value) {
  return String(value || '').trim().toLowerCase() || 'unspecified';
}

function normalizeNumber(value) {
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric > 0 ? numeric : 0;
}

function nextEventId(nowMs) {
  return `rtm_${nowMs}_${Math.random().toString(16).slice(2, 10)}`;
}

export function appendMediaRuntimeTransitionEvent(event = {}) {
  const nowMs = Date.now();
  const entry = {
    id: nextEventId(nowMs),
    type: 'media_runtime_transition',
    timestamp_unix_ms: nowMs,
    timestamp_iso: new Date(nowMs).toISOString(),
    from_path: normalizePath(event.from_path),
    to_path: normalizePath(event.to_path),
    reason: normalizeReason(event.reason),
    user_id: normalizeNumber(event.user_id),
    call_id: String(event.call_id || '').trim(),
    room_id: String(event.room_id || '').trim().toLowerCase(),
    capabilities: {
      stage_a: Boolean(event?.capabilities?.stage_a),
      stage_b: Boolean(event?.capabilities?.stage_b),
      preferred_path: normalizePath(event?.capabilities?.preferred_path),
      reasons: Array.isArray(event?.capabilities?.reasons)
        ? event.capabilities.reasons.map((row) => normalizeReason(row)).filter(Boolean).slice(0, 16)
        : [],
    },
  };

  const rows = safeReadRaw();
  rows.push(entry);
  if (rows.length > MEDIA_RUNTIME_TELEMETRY_MAX) {
    rows.splice(0, rows.length - MEDIA_RUNTIME_TELEMETRY_MAX);
  }
  safePersist(rows);
  return entry;
}

export function readMediaRuntimeTransitionEvents() {
  return safeReadRaw();
}

