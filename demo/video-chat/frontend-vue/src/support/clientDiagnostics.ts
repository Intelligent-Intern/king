import { sessionState } from '../domain/auth/session';
import { fetchBackend } from './backendFetch';
import { currentAssetVersion, handleAssetLoadFailure } from './assetVersion';

const DIAGNOSTICS_STORAGE_KEY = 'ii.videocall.client_diagnostics.pending.v1';
const DIAGNOSTICS_MAX_QUEUE = 60;
const DIAGNOSTICS_MAX_BATCH = 12;
const DIAGNOSTICS_FLUSH_INTERVAL_MS = 30000;
const DIAGNOSTICS_MAX_REPEAT_COUNT = 9999;

let diagnosticsContextProvider = null;
let diagnosticsQueue = loadPersistedDiagnosticsQueue();
let diagnosticsFlushTimer = null;
let diagnosticsFlushPromise = null;
let diagnosticsLifecycleBound = false;
let diagnosticsRetryDelayMs = DIAGNOSTICS_FLUSH_INTERVAL_MS;
let diagnosticsGlobalErrorBound = false;
let diagnosticsSentFingerprints = new Set();
let consoleWarningDiagnosticsBound = false;
let originalConsoleWarn = null;

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

function envFlagEnabled(value) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return false;
  return ['1', 'true', 'yes', 'on'].includes(normalized);
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

function formatConsoleDiagnosticArg(value) {
  if (value instanceof Error) {
    return normalizeString(`${value.name || 'Error'}: ${value.message || ''}`, 'Error', 240);
  }
  if (typeof value === 'string') return normalizeString(value, '', 240);
  if (typeof value === 'number' || typeof value === 'boolean') return normalizeString(value, '', 120);
  if (value === null || typeof value === 'undefined') return String(value);

  try {
    return normalizeString(JSON.stringify(sanitizePayload(value)), '', 240);
  } catch {
    return normalizeString(value, '', 240);
  }
}

function formatConsoleDiagnosticMessage(args) {
  const message = args.map((entry) => formatConsoleDiagnosticArg(entry)).join(' ').trim();
  return normalizeString(message, 'Client console warning captured.', 500);
}

function clientConsoleWarningPassthroughEnabled() {
  return envFlagEnabled(import.meta.env.VITE_VIDEOCHAT_DEBUG_LOGS)
    || Boolean(globalThis?.__KING_VIDEOCHAT_DEBUG_LOGS__);
}

function consoleWarningStack() {
  try {
    const stack = new Error('client_console_warning').stack || '';
    return normalizeString(
      stack
        .split('\n')
        .slice(2, 8)
        .join('\n'),
      '',
      1200,
    );
  } catch {
    return '';
  }
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

function diagnosticErrorName(value) {
  if (value instanceof Error) return normalizeString(value.name, 'Error', 120);
  if (value && typeof value === 'object' && typeof value.name === 'string') {
    return normalizeString(value.name, 'Error', 120);
  }
  return '';
}

function diagnosticErrorMessage(value, fallback = 'Client runtime error captured.') {
  if (value instanceof Error) return normalizeString(value.message, fallback, 500);
  if (typeof value === 'string') return normalizeString(value, fallback, 500);
  if (value && typeof value === 'object' && typeof value.message === 'string') {
    return normalizeString(value.message, fallback, 500);
  }
  return fallback;
}

function reportGlobalClientRuntimeError(eventType, error, payload = {}) {
  try {
    reportClientDiagnostic({
      category: 'runtime',
      level: 'error',
      eventType,
      code: diagnosticErrorName(error) || eventType,
      message: diagnosticErrorMessage(error),
      payload: {
        ...payload,
        error,
      },
      immediate: true,
    });
  } catch {
    // Never let diagnostics create a secondary global error.
  }
}

function bindGlobalClientErrorDiagnostics() {
  if (diagnosticsGlobalErrorBound || typeof window === 'undefined') return;
  diagnosticsGlobalErrorBound = true;

  window.addEventListener('error', (event) => {
    const error = event?.error || event?.message || 'Client runtime error captured.';
    const payload = {
      source_file: normalizeString(event?.filename, '', 500),
      source_line: Math.max(0, Number(event?.lineno || 0)),
      source_column: Math.max(0, Number(event?.colno || 0)),
      message: normalizeString(event?.message, '', 500),
      global_event_type: 'error',
    };
    reportGlobalClientRuntimeError('call_workspace_runtime_error', error, payload);
    handleAssetLoadFailure(error, payload);
  });

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event?.reason || 'Client promise rejection captured.';
    const payload = {
      global_event_type: 'unhandledrejection',
    };
    reportGlobalClientRuntimeError('call_workspace_unhandled_rejection', reason, payload);
    if (handleAssetLoadFailure(reason, payload)) {
      event?.preventDefault?.();
    }
  });

  window.addEventListener('vite:preloadError', (event) => {
    const error = event?.payload || event?.error || event || 'Client preload error captured.';
    const payload = {
      global_event_type: 'vite:preloadError',
    };
    reportGlobalClientRuntimeError('call_workspace_unhandled_rejection', error, payload);
    if (handleAssetLoadFailure(error, payload)) {
      event?.preventDefault?.();
    }
  });
}

export function bindClientConsoleWarningDiagnostics() {
  if (consoleWarningDiagnosticsBound || typeof console === 'undefined') return;
  if (typeof console.warn !== 'function') return;

  consoleWarningDiagnosticsBound = true;
  originalConsoleWarn = console.warn.bind(console);

  console.warn = (...args) => {
    try {
      reportClientDiagnostic({
        category: 'runtime',
        level: 'warning',
        eventType: 'client_console_warning',
        code: 'console_warn',
        message: formatConsoleDiagnosticMessage(args),
        payload: {
          console_method: 'warn',
          console_args: sanitizePayload(args),
          console_stack: consoleWarningStack(),
        },
        immediate: true,
      });
    } catch {
      // Diagnostics must never create a secondary console warning.
    }

    if (clientConsoleWarningPassthroughEnabled()) {
      originalConsoleWarn(...args);
    }
  };
}

export function configureClientDiagnostics(contextProvider) {
  diagnosticsContextProvider = typeof contextProvider === 'function' ? contextProvider : null;
  bindClientConsoleWarningDiagnostics();
  bindDiagnosticsLifecycleHooks();
  bindGlobalClientErrorDiagnostics();
  if (diagnosticsQueue.length > 0) {
    scheduleDiagnosticsFlush();
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
  const normalizedLevel = normalizeLevel(level);
  if (normalizedLevel !== 'warning' && normalizedLevel !== 'error') {
    return null;
  }

  const context = resolveDiagnosticsContext();
  const entry = {
    category: normalizeIdentifier(category, 'media'),
    level: normalizedLevel,
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
    queued.repeat_count = Math.min(DIAGNOSTICS_MAX_REPEAT_COUNT, Number(queued.repeat_count || 1) + 1);
    queued.client_time = entry.client_time;
    queued.timestamp_unix_ms = entry.timestamp_unix_ms;
    queued.payload = entry.payload;
    persistDiagnosticsQueue();
    if (immediate) {
      scheduleDiagnosticsFlush(100);
    } else {
      scheduleDiagnosticsFlush();
    }
    return queued;
  }

  if (diagnosticsSentFingerprints.has(fingerprint)) {
    return null;
  }

  diagnosticsQueue.push(entry);
  if (diagnosticsQueue.length > DIAGNOSTICS_MAX_QUEUE) {
    diagnosticsQueue.splice(0, diagnosticsQueue.length - DIAGNOSTICS_MAX_QUEUE);
  }
  persistDiagnosticsQueue();

  if (immediate) {
    scheduleDiagnosticsFlush(100);
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

      for (const entry of batch) {
        diagnosticsSentFingerprints.add(diagnosticsFingerprint(entry));
      }
      diagnosticsQueue.splice(0, batch.length);
      persistDiagnosticsQueue();
      diagnosticsRetryDelayMs = DIAGNOSTICS_FLUSH_INTERVAL_MS;

      if (diagnosticsQueue.length > 0) {
        scheduleDiagnosticsFlush();
      }

      return true;
    } catch {
      diagnosticsRetryDelayMs = Math.min(120000, diagnosticsRetryDelayMs * 2);
      scheduleDiagnosticsFlush(diagnosticsRetryDelayMs);
      return false;
    } finally {
      diagnosticsFlushPromise = null;
    }
  })();

  return diagnosticsFlushPromise;
}
