import { sessionState } from '../domain/auth/session';
import { fetchBackend } from './backendFetch';
import { currentAssetVersion } from './assetVersion';

const DIAGNOSTICS_STORAGE_KEY = 'ii.videocall.client_diagnostics.pending.v1';
const DIAGNOSTICS_MAX_QUEUE = 60;
const DIAGNOSTICS_MAX_BATCH = 12;
const DIAGNOSTICS_FLUSH_INTERVAL_MS = 4000;
const DIAGNOSTICS_DEDUPE_WINDOW_MS = 15000;

let diagnosticsContextProvider = null;
let diagnosticsQueue = loadPersistedDiagnosticsQueue();
let diagnosticsFlushTimer = null;
let diagnosticsFlushPromise = null;
let diagnosticsLifecycleBound = false;
let diagnosticsRetryDelayMs = 2500;

function normalizeString(value, fallback = '', maxLength = 240) {
  const normalized = String(value ?? '').trim();
  if (normalized === '') return fallback;
  if (normalized.length <= maxLength) return normalized;
  return normalized.slice(0, maxLength);
}

function normalizeIdentifier(value, fallback = '') {
  const normalized = normalizeString(value, fallback, 96)
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^[_:.-]+|[_:.-]+$/g, '');
  return normalized || fallback;
}

function normalizeLevel(value) {
  const normalized = normalizeIdentifier(value, 'error');
  if (normalized === 'warn') return 'warning';
  if (['debug', 'info', 'warning', 'error'].includes(normalized)) return normalized;
  return 'error';
}

function utf8Length(value) {
  try {
    return new TextEncoder().encode(String(value ?? '')).length;
  } catch {
    return String(value ?? '').length;
  }
}

function sanitizePayload(value, depth = 0) {
  if (depth >= 4) return '[depth_limited]';
  if (value === null || typeof value === 'boolean' || typeof value === 'number') return value;
  if (typeof value === 'string') return normalizeString(value, '', 1200);
  if (value instanceof Error) {
    return {
      type: 'error',
      name: normalizeString(value.name, 'Error', 120),
      message: normalizeString(value.message, '', 500),
      stack: normalizeString(value.stack, '', 1200),
    };
  }
  if (Array.isArray(value)) {
    return value.slice(0, 24).map((entry) => sanitizePayload(entry, depth + 1));
  }
  if (value && typeof value === 'object') {
    const normalized = {};
    let count = 0;
    for (const [key, entry] of Object.entries(value)) {
      if (count >= 24) {
        normalized.__truncated__ = true;
        break;
      }
      normalized[normalizeString(key, 'key', 80)] = sanitizePayload(entry, depth + 1);
      count += 1;
    }
    return normalized;
  }
  return normalizeString(value, '', 400);
}

function normalizePayloadObject(value) {
  const sanitized = sanitizePayload(value);
  const wrapped = sanitized && typeof sanitized === 'object' && !Array.isArray(sanitized)
    ? sanitized
    : { value: sanitized };
  const encoded = JSON.stringify(wrapped);
  if (utf8Length(encoded) <= 6000) return wrapped;
  return {
    truncated: true,
    preview: normalizeString(encoded, '', 2000),
  };
}

function persistDiagnosticsQueue() {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(DIAGNOSTICS_STORAGE_KEY, JSON.stringify(diagnosticsQueue));
  } catch {
    // ignore storage errors
  }
}

function loadPersistedDiagnosticsQueue() {
  if (typeof localStorage === 'undefined') return [];
  try {
    const raw = localStorage.getItem(DIAGNOSTICS_STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((row) => row && typeof row === 'object').slice(-DIAGNOSTICS_MAX_QUEUE);
  } catch {
    return [];
  }
}

function resolveDiagnosticsContext() {
  const dynamic = typeof diagnosticsContextProvider === 'function'
    ? diagnosticsContextProvider() || {}
    : {};

  return {
    asset_version: currentAssetVersion(),
    page_url: typeof window !== 'undefined' ? String(window.location?.href || '') : '',
    page_path: typeof window !== 'undefined' ? String(window.location?.pathname || '') : '',
    browser_user_agent: typeof navigator !== 'undefined' ? String(navigator.userAgent || '') : '',
    backend_origin: typeof window !== 'undefined' ? String(window.location?.origin || '') : '',
    ...dynamic,
  };
}

function diagnosticsFingerprint(entry) {
  return [
    normalizeIdentifier(entry.category, 'media'),
    normalizeLevel(entry.level),
    normalizeIdentifier(entry.event_type, 'unknown'),
    normalizeIdentifier(entry.code, ''),
    normalizeString(entry.message, '', 160),
    normalizeString(entry.call_id, '', 120),
    normalizeString(entry.room_id, '', 120),
  ].join('|');
}

function scheduleDiagnosticsFlush(delayMs = DIAGNOSTICS_FLUSH_INTERVAL_MS) {
  if (diagnosticsFlushTimer !== null) {
    clearTimeout(diagnosticsFlushTimer);
  }

  diagnosticsFlushTimer = setTimeout(() => {
    diagnosticsFlushTimer = null;
    void flushClientDiagnostics({ reason: 'timer' });
  }, Math.max(50, Number(delayMs) || DIAGNOSTICS_FLUSH_INTERVAL_MS));
}

function bindDiagnosticsLifecycleHooks() {
  if (diagnosticsLifecycleBound || typeof window === 'undefined') return;
  diagnosticsLifecycleBound = true;

  window.addEventListener('pagehide', () => {
    void flushClientDiagnostics({ keepalive: true, reason: 'pagehide' });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      void flushClientDiagnostics({ keepalive: true, reason: 'hidden' });
    }
  });
}

export function configureClientDiagnostics(contextProvider) {
  diagnosticsContextProvider = typeof contextProvider === 'function' ? contextProvider : null;
  bindDiagnosticsLifecycleHooks();
  if (diagnosticsQueue.length > 0) {
    scheduleDiagnosticsFlush(250);
  }
}

export function reportClientDiagnostic({
  category = 'media',
  level = 'error',
  eventType = '',
  code = '',
  message = '',
  payload = {},
  callId = '',
  roomId = '',
  immediate = false,
} = {}) {
  bindDiagnosticsLifecycleHooks();

  const normalizedEventType = normalizeIdentifier(eventType, '');
  if (normalizedEventType === '') return null;

  const context = resolveDiagnosticsContext();
  const entry = {
    category: normalizeIdentifier(category, 'media'),
    level: normalizeLevel(level),
    event_type: normalizedEventType,
    code: normalizeIdentifier(code, ''),
    message: normalizeString(message, '', 500),
    call_id: normalizeString(callId || context.call_id || context.callId || '', '', 120),
    room_id: normalizeString(roomId || context.room_id || context.roomId || '', '', 120),
    payload: normalizePayloadObject({
      ...context,
      ...payload,
    }),
    repeat_count: 1,
    client_time: new Date().toISOString(),
    timestamp_unix_ms: Date.now(),
  };

  const fingerprint = diagnosticsFingerprint(entry);
  for (let index = diagnosticsQueue.length - 1; index >= 0; index -= 1) {
    const queued = diagnosticsQueue[index];
    if (!queued || diagnosticsFingerprint(queued) !== fingerprint) continue;
    const ageMs = Math.abs(entry.timestamp_unix_ms - Number(queued.timestamp_unix_ms || 0));
    if (ageMs > DIAGNOSTICS_DEDUPE_WINDOW_MS) break;
    queued.repeat_count = Math.min(99, Number(queued.repeat_count || 1) + 1);
    queued.client_time = entry.client_time;
    queued.timestamp_unix_ms = entry.timestamp_unix_ms;
    queued.payload = entry.payload;
    persistDiagnosticsQueue();
    scheduleDiagnosticsFlush(immediate ? 50 : DIAGNOSTICS_FLUSH_INTERVAL_MS);
    return queued;
  }

  diagnosticsQueue.push(entry);
  if (diagnosticsQueue.length > DIAGNOSTICS_MAX_QUEUE) {
    diagnosticsQueue.splice(0, diagnosticsQueue.length - DIAGNOSTICS_MAX_QUEUE);
  }
  persistDiagnosticsQueue();

  if (immediate || diagnosticsQueue.length >= DIAGNOSTICS_MAX_BATCH) {
    scheduleDiagnosticsFlush(50);
  } else {
    scheduleDiagnosticsFlush();
  }

  return entry;
}

export async function flushClientDiagnostics({ keepalive = false, reason = 'manual' } = {}) {
  if (diagnosticsFlushPromise) {
    return diagnosticsFlushPromise;
  }
  if (diagnosticsQueue.length === 0) {
    return true;
  }

  const token = normalizeString(sessionState.sessionToken || '', '', 256);
  if (token === '') {
    return false;
  }

  const batch = diagnosticsQueue.slice(0, DIAGNOSTICS_MAX_BATCH);
  diagnosticsFlushPromise = (async () => {
    try {
      const { response } = await fetchBackend('/api/user/client-diagnostics', {
        method: 'POST',
        serialize: false,
        keepalive,
        retryOnNetworkError: false,
        timeoutMs: keepalive ? 3000 : 5000,
        headers: {
          accept: 'application/json',
          'content-type': 'application/json',
          authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          entries: batch,
          flush_reason: normalizeIdentifier(reason, 'manual'),
        }),
      });

      if (!response.ok) {
        throw new Error(`client_diagnostics_http_${response.status}`);
      }

      diagnosticsQueue.splice(0, batch.length);
      persistDiagnosticsQueue();
      diagnosticsRetryDelayMs = 2500;

      if (diagnosticsQueue.length > 0) {
        scheduleDiagnosticsFlush(250);
      }

      return true;
    } catch {
      diagnosticsRetryDelayMs = Math.min(20000, diagnosticsRetryDelayMs * 2);
      scheduleDiagnosticsFlush(diagnosticsRetryDelayMs);
      return false;
    } finally {
      diagnosticsFlushPromise = null;
    }
  })();

  return diagnosticsFlushPromise;
}
