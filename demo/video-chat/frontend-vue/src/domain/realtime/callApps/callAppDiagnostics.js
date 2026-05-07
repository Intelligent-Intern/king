export const CALL_APP_DIAGNOSTIC_EVENTS = Object.freeze([
  'call_app_launch_token_failed',
  'call_app_grants_changed',
  'call_app_crdt_append_latency',
  'call_app_crdt_replay_latency',
  'call_app_crdt_duplicate_suppressed',
  'call_app_crdt_snapshot_compacted',
  'call_app_iframe_bridge_error',
]);

function sanitizeDiagnosticValue(value) {
  if (value === null || value === undefined) return value;
  if (Array.isArray(value)) return value.map((entry) => sanitizeDiagnosticValue(entry));
  if (typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .filter(([key]) => !/token|authorization|password|secret/i.test(key))
        .map(([key, entry]) => [key, sanitizeDiagnosticValue(entry)]),
    );
  }
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'boolean') return value;
  return String(value || '').slice(0, 500);
}

export function callAppDiagnosticNow() {
  if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
    return performance.now();
  }
  return Date.now();
}

export function callAppDiagnosticElapsedMs(startedAt) {
  const started = Number(startedAt) || 0;
  if (started <= 0) return 0;
  return Math.max(0, Math.round(callAppDiagnosticNow() - started));
}

export function emitCallAppDiagnostic(eventType, fields = {}) {
  const normalizedType = String(eventType || '').trim();
  if (!CALL_APP_DIAGNOSTIC_EVENTS.includes(normalizedType)) return null;

  const diagnostic = {
    event_type: normalizedType,
    recorded_at: new Date().toISOString(),
    ...sanitizeDiagnosticValue(fields),
  };

  if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
    window.dispatchEvent(new CustomEvent('king:call-app-diagnostic', { detail: diagnostic }));
  }
  if (typeof console !== 'undefined' && typeof console.debug === 'function') {
    console.debug('[CallAppDiagnostics]', diagnostic);
  }
  return diagnostic;
}

export function emitCallAppResponseDiagnostics(payload, extraFields = {}) {
  const diagnostics = Array.isArray(payload?.result?.diagnostics)
    ? payload.result.diagnostics
    : [];
  diagnostics.forEach((diagnostic) => {
    emitCallAppDiagnostic(diagnostic?.event_type, {
      source: 'backend_response',
      ...extraFields,
      ...diagnostic,
    });
  });
}
