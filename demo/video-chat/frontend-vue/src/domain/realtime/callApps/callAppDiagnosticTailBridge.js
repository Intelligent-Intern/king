import { onBeforeUnmount, watch } from 'vue';

export const CALL_DIAGNOSTICS_APP_KEY = 'call-diagnostics';
export const CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE = 'call_app.diagnostics.tail.event';
export const CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE = 'call_app.diagnostics.telemetry.snapshot';
export const CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE = 'call_app.diagnostics.stage.update';
export const CLIENT_DIAGNOSTIC_WINDOW_EVENT = 'king:client-diagnostic';
export const CALL_APP_DIAGNOSTIC_WINDOW_EVENT = 'king:call-app-diagnostic';

const MAX_STRING_LENGTH = 600;
const MAX_EVENT_DETAIL_LENGTH = 1400;
const RECENT_EVENT_TTL_MS = 700;
const TELEMETRY_POLL_MS = 5000;
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

function mediaFrameLikeKey(key) {
  const normalized = String(key || '').trim().toLowerCase().replace(/[-.]+/g, '_');
  return /(^|_)((media|video|audio|image|canvas|pixel|encoded|raw)_)?frame_(data|payload|blob|buffer|bytes)($|_)/.test(normalized)
    || /(^|_)(media_frame|video_frame|encoded_frame|raw_frame|pixel_buffer|canvas_pixels)($|_)/.test(normalized);
}

function redactDiagnosticString(value, maxLength = MAX_EVENT_DETAIL_LENGTH) {
  return safeString(value, '', maxLength)
    .replace(/\bBearer\s+[A-Za-z0-9._~+/-]+=*/gi, `Bearer ${REDACTED}`)
    .replace(/\bBasic\s+[A-Za-z0-9+/=]+/gi, `Basic ${REDACTED}`)
    .replace(/\b(token|authorization|password|secret|credential|cookie)=([^&\s]+)/gi, `$1=${REDACTED}`);
}

function redactDiagnosticPayload(value, depth = 0) {
  if (depth >= 4) return '[depth_limited]';
  if (value === null || typeof value === 'boolean' || typeof value === 'number') return value;
  if (typeof value === 'string') return redactDiagnosticString(value, MAX_EVENT_DETAIL_LENGTH);
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
      normalized[normalizedKey] = (secretLikeKey(normalizedKey) || mediaFrameLikeKey(normalizedKey))
        ? REDACTED
        : redactDiagnosticPayload(entry, depth + 1);
      count += 1;
    }
    return normalized;
  }
  return safeString(value, '', 240);
}

function diagnosticPayload(raw) {
  if (raw?.type === CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE) {
    return raw.snapshot || raw.telemetry || raw.payload || raw.details || raw;
  }
  if (raw?.type === CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE) {
    return raw.payload || raw.details || raw;
  }
  return raw.payload || raw.details || raw;
}

function diagnosticMessageType(raw) {
  const type = safeIdentifier(raw?.type || raw?.message_type || raw?.event_type || raw?.eventType || '');
  if (type === CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE) return CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE;
  if (type === CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE) return CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE;
  return CALL_APP_DIAGNOSTIC_TAIL_MESSAGE_TYPE;
}

function sessionForTail(activeSession) {
  const session = unrefValue(activeSession) || null;
  if (!session || typeof session !== 'object') return null;
  if (safeIdentifier(session.app_key || '') !== CALL_DIAGNOSTICS_APP_KEY) return null;
  if (safeString(session.id || '') === '') return null;
  return session;
}

function callIdForSession(session) {
  return safeString(session?.call_id || session?.callId || session?.call?.id || '', '', 200);
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
  const payload = redactDiagnosticPayload(diagnosticPayload(raw));
  const messageType = diagnosticMessageType(raw);
  const entry = {
    id: safeString(raw.id || raw.event_id || raw.operation_id || `${Date.now()}_${sequence}`, '', 180),
    source: safeIdentifier(source || raw.source || raw.category || 'client', 'client'),
    category: safeIdentifier(raw.category || source || 'runtime', 'runtime'),
    level: normalizeLevel(raw.level || raw.severity || 'info'),
    event_type: safeIdentifier(raw.event_type || raw.eventType || raw.type || 'diagnostic_event', 'diagnostic_event'),
    code: safeIdentifier(raw.code || raw.response_code || ''),
    message: redactDiagnosticString(raw.message || raw.event_type || raw.eventType || 'Diagnostic event', 500),
    stage: safeIdentifier(raw.stage || ''),
    status: safeIdentifier(raw.status || ''),
    call_id: safeString(raw.call_id || raw.callId || ''),
    room_id: safeString(raw.room_id || raw.roomId || ''),
    instance_id: safeString(raw.instance_id || raw.instanceId || raw.app_session_id || ''),
    repeat_count: Math.max(1, Number(raw.repeat_count || 1) || 1),
    client_time: safeString(raw.client_time || raw.recorded_at || new Date().toISOString(), '', 80),
    timestamp_unix_ms: Number(raw.timestamp_unix_ms || Date.now()) || Date.now(),
    payload,
    persist: raw.persist !== false && messageType !== CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE,
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
  apiRequest,
} = {}) {
  let sequence = 0;
  let telemetryTimer = 0;
  let telemetryInFlight = false;
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
    const raw = detail && typeof detail === 'object' ? detail : {};
    sequence += 1;
    const diagnostic = normalizeTailDiagnostic(raw, source, sequence);
    if (!shouldSend(diagnostic)) return;
    const messageType = diagnosticMessageType(raw);
    const payload = {
      diagnostic,
    };
    if (messageType === CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE) {
      payload.snapshot = diagnostic.payload;
    } else if (messageType === CALL_APP_DIAGNOSTIC_STAGE_UPDATE_TYPE) {
      payload.stage = diagnostic.stage;
      payload.status = diagnostic.status;
      payload.payload = diagnostic.payload;
    }
    postToIframe(frameWindow, session, messageType, payload);
  }

  function handleClientDiagnostic(event) {
    sendDiagnostic(event?.detail || {}, 'client_diagnostics');
  }

  function handleCallAppDiagnostic(event) {
    sendDiagnostic(event?.detail || {}, 'call_app_diagnostics');
  }

  function telemetryEndpoint(session) {
    const callId = callIdForSession(session);
    return callId === '' ? '' : `/api/calls/${encodeURIComponent(callId)}/call-apps/call-diagnostics/telemetry-snapshot`;
  }

  async function sendTelemetrySnapshot(session) {
    if (typeof apiRequest !== 'function' || telemetryInFlight) return;
    const endpoint = telemetryEndpoint(session);
    const callId = callIdForSession(session);
    if (endpoint === '') return;
    telemetryInFlight = true;
    try {
      const payload = await apiRequest(endpoint);
      const telemetry = payload?.result?.telemetry || payload?.telemetry || payload?.result || payload;
      sendDiagnostic({
        type: CALL_APP_DIAGNOSTIC_TELEMETRY_SNAPSHOT_TYPE,
        source: 'call_diagnostics_telemetry',
        category: 'telemetry',
        level: 'info',
        event_type: 'call_diagnostics_telemetry_snapshot',
        message: 'Telemetry snapshot captured.',
        stage: 'telemetry',
        call_id: callId,
        instance_id: telemetry?.instance?.id || telemetry?.instances?.[0]?.id || '',
        persist: false,
        snapshot: telemetry,
      }, 'call_diagnostics_telemetry');
    } catch (error) {
      sendDiagnostic({
        source: 'call_diagnostics_telemetry',
        category: 'telemetry',
        level: 'warning',
        event_type: 'call_diagnostics_telemetry_unavailable',
        message: error instanceof Error ? error.message : 'Telemetry snapshot unavailable.',
        stage: 'telemetry',
        call_id: callId,
        persist: false,
        payload: {
          endpoint,
          retry_ms: TELEMETRY_POLL_MS,
        },
      }, 'call_diagnostics_telemetry');
    } finally {
      telemetryInFlight = false;
    }
  }

  function stopTelemetryTimer() {
    if (typeof window !== 'undefined' && telemetryTimer > 0) {
      window.clearInterval(telemetryTimer);
    }
    telemetryTimer = 0;
    telemetryInFlight = false;
  }

  function startTelemetryTimer(session) {
    if (typeof window === 'undefined' || typeof apiRequest !== 'function') return;
    const sessionId = safeString(session?.id || '', '', 180);
    if (sessionId === '' || telemetryEndpoint(session) === '') return;
    stopTelemetryTimer();
    sendTelemetrySnapshot(session);
    telemetryTimer = window.setInterval(() => {
      const currentSession = sessionForTail(activeSession);
      if (!currentSession || safeString(currentSession.id || '', '', 180) !== sessionId) {
        stopTelemetryTimer();
        return;
      }
      sendTelemetrySnapshot(currentSession);
    }, TELEMETRY_POLL_MS);
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
      stopTelemetryTimer();
      if (sessionId === '') return;
      if (typeof window === 'undefined') return;
      window.setTimeout(() => {
        const session = sessionForTail(activeSession);
        if (session && String(session.id || '') === sessionId) {
          sendAttachedEvent(session);
          startTelemetryTimer(session);
        }
      }, 0);
    },
    { immediate: true },
  );

  onBeforeUnmount(() => {
    stopTelemetryTimer();
    if (typeof window !== 'undefined') {
      window.removeEventListener(CLIENT_DIAGNOSTIC_WINDOW_EVENT, handleClientDiagnostic);
      window.removeEventListener(CALL_APP_DIAGNOSTIC_WINDOW_EVENT, handleCallAppDiagnostic);
    }
  });
}
