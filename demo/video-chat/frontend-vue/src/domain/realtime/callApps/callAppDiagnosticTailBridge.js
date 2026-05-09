import { onBeforeUnmount, watch } from 'vue';

export const CALL_DIAGNOSTICS_APP_KEY = 'call-diagnostics';
export const CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE = 'call_app.diagnostics.tail.event';
export const CLIENT_DIAGNOSTIC_WINDOW_EVENT = 'king:client-diagnostic';
export const CALL_APP_DIAGNOSTIC_WINDOW_EVENT = 'king:call-app-diagnostic';

const MAX_STRING_LENGTH = 600;
const MAX_EVENT_DETAIL_LENGTH = 1400;
const RECENT_EVENT_TTL_MS = 700;
const REDACTED = '[redacted]';

function unrefValue(value) {
  if (value && typeof value === 'object' && 'value' in value) return value.value;
  if (typeof value === 'function') return value();
  return value;
}

function safeString(value, fallback = '', maxLength = MAX_STRING_LENGTH) {
  const normalized = String(value ?? '').trim();
  const result = normalized || fallback;
  return result.length <= maxLength ? result : result.slice(0, maxLength);
}

function safeIdentifier(value, fallback = '') {
  return safeString(value, fallback, 120)
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^[_:.-]+|[_:.-]+$/g, '') || fallback;
}

function secretLikeKey(key) {
  return /token|authorization|password|secret|credential|cookie|session/i.test(String(key || ''));
}

function redactDiagnosticPayload(value, depth = 0) {
  if (depth >= 4) return '[depth_limited]';
  if (value === null || typeof value === 'boolean' || typeof value === 'number') return value;
  if (typeof value === 'string') return safeString(value, '', MAX_EVENT_DETAIL_LENGTH);
  if (Array.isArray(value)) return value.slice(0, 32).map((entry) => redactDiagnosticPayload(entry, depth + 1));
  if (value && typeof value === 'object') {
    const normalized = {};
    let count = 0;
    for (const [key, entry] of Object.entries(value)) {
      if (count >= 48) {
        normalized.__truncated__ = true;
        break;
      }
      const normalizedKey = safeIdentifier(key, 'field');
      normalized[normalizedKey] = secretLikeKey(normalizedKey) ? REDACTED : redactDiagnosticPayload(entry, depth + 1);
      count += 1;
    }
    return normalized;
  }
  return safeString(value, '', 240);
}

function sessionForTail(activeSession) {
  const session = unrefValue(activeSession) || null;
  if (!session || typeof session !== 'object') return null;
  if (safeIdentifier(session.app_key || '') !== CALL_DIAGNOSTICS_APP_KEY) return null;
  if (safeString(session.id || '') === '') return null;
  return session;
}

function normalizeLevel(value) {
  const level = safeIdentifier(value, 'info');
  if (level === 'warn') return 'warning';
  if (['debug', 'info', 'warning', 'error'].includes(level)) return level;
  return 'info';
}

function inferStage(entry) {
  const text = [
    entry.stage,
    entry.category,
    entry.event_type,
    entry.code,
    entry.message,
    JSON.stringify(entry.payload || {}),
  ].join(' ').toLowerCase();
  if (/(turn|relay|typ relay)/.test(text)) return 'turn';
  if (/(stun|srflx|server reflexive|typ srflx)/.test(text)) return 'stun';
  if (/(ice|candidate|host candidate|typ host|p2p)/.test(text)) return 'host';
  if (/(websocket|socket|signaling|room_snapshot|room_sync|foreground_reconnect)/.test(text)) return 'signaling';
  if (/(sfu|media|publisher|remote_video|decoder|keyframe|webrtc)/.test(text)) return 'sfu';
  if (/(call_app|iframe|crdt|launch|marketplace|availability)/.test(text)) return 'callapp';
  if (/(datachannel|data channel|queue)/.test(text)) return 'data';
  return 'runtime';
}

function normalizeTailDiagnostic(detail, source, sequence) {
  const raw = detail && typeof detail === 'object' ? detail : {};
  const payload = redactDiagnosticPayload(raw.payload || raw.details || raw);
  const entry = {
    id: safeString(raw.id || raw.event_id || raw.operation_id || `${Date.now()}_${sequence}`, '', 180),
    source: safeIdentifier(source || raw.source || raw.category || 'client', 'client'),
    category: safeIdentifier(raw.category || source || 'runtime', 'runtime'),
    level: normalizeLevel(raw.level || raw.severity || 'info'),
    event_type: safeIdentifier(raw.event_type || raw.eventType || raw.type || 'diagnostic_event', 'diagnostic_event'),
    code: safeIdentifier(raw.code || raw.response_code || ''),
    message: safeString(raw.message || raw.event_type || raw.eventType || 'Diagnostic event', 'Diagnostic event', 500),
    stage: safeIdentifier(raw.stage || ''),
    call_id: safeString(raw.call_id || raw.callId || ''),
    room_id: safeString(raw.room_id || raw.roomId || ''),
    repeat_count: Math.max(1, Number(raw.repeat_count || 1) || 1),
    client_time: safeString(raw.client_time || raw.recorded_at || new Date().toISOString(), '', 80),
    timestamp_unix_ms: Number(raw.timestamp_unix_ms || Date.now()) || Date.now(),
    payload,
    persist: true,
  };
  if (entry.stage === '') entry.stage = inferStage(entry);
  const sourceAppKey = safeIdentifier(raw.app_key || raw.payload?.app_key || '');
  if (sourceAppKey === CALL_DIAGNOSTICS_APP_KEY && entry.event_type.startsWith('call_app_crdt_')) {
    entry.persist = false;
  }
  return entry;
}

export function createCallAppDiagnosticTailBridge({
  activeSession,
  iframeRef,
  postToIframe,
} = {}) {
  let sequence = 0;
  const recentEvents = new Map();

  function shouldSend(entry) {
    const now = Date.now();
    for (const [key, expiresAt] of recentEvents.entries()) {
      if (expiresAt <= now) recentEvents.delete(key);
    }
    const fingerprint = [
      entry.source,
      entry.level,
      entry.event_type,
      entry.code,
      entry.message,
      entry.call_id,
      entry.room_id,
    ].join('|');
    if (recentEvents.has(fingerprint)) return false;
    recentEvents.set(fingerprint, now + RECENT_EVENT_TTL_MS);
    return true;
  }

  function sendDiagnostic(detail, source) {
    const session = sessionForTail(activeSession);
    const frameWindow = iframeRef?.value?.contentWindow || null;
    if (!session || !frameWindow || typeof postToIframe !== 'function') return;
    sequence += 1;
    const diagnostic = normalizeTailDiagnostic(detail, source, sequence);
    if (!shouldSend(diagnostic)) return;
    postToIframe(frameWindow, session, CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE, {
      diagnostic,
    });
  }

  function handleClientDiagnostic(event) {
    sendDiagnostic(event?.detail || {}, 'client_diagnostics');
  }

  function handleCallAppDiagnostic(event) {
    sendDiagnostic(event?.detail || {}, 'call_app_diagnostics');
  }

  function sendAttachedEvent(session) {
    sendDiagnostic({
      source: 'call_diagnostics_tail',
      category: 'call_app',
      level: 'info',
      event_type: 'call_diagnostics_tail_attached',
      message: 'Diagnostic tail attached.',
      stage: 'callapp',
      app_key: CALL_DIAGNOSTICS_APP_KEY,
      payload: {
        app_session_id: session?.id,
        app_key: session?.app_key,
      },
    }, 'call_diagnostics_tail');
  }

  if (typeof window !== 'undefined') {
    window.addEventListener(CLIENT_DIAGNOSTIC_WINDOW_EVENT, handleClientDiagnostic);
    window.addEventListener(CALL_APP_DIAGNOSTIC_WINDOW_EVENT, handleCallAppDiagnostic);
  }

  watch(
    () => {
      const session = sessionForTail(activeSession);
      return session ? String(session.id || '') : '';
    },
    (sessionId) => {
      if (sessionId === '') return;
      if (typeof window === 'undefined') return;
      window.setTimeout(() => {
        const session = sessionForTail(activeSession);
        if (session && String(session.id || '') === sessionId) sendAttachedEvent(session);
      }, 0);
    },
    { immediate: true },
  );

  onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener(CLIENT_DIAGNOSTIC_WINDOW_EVENT, handleClientDiagnostic);
      window.removeEventListener(CALL_APP_DIAGNOSTIC_WINDOW_EVENT, handleCallAppDiagnostic);
    }
  });
}
