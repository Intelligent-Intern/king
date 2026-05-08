export const CALL_APP_PRESENCE_SIGNAL_TYPE = 'call-app/presence';
export const CALL_APP_PRESENCE_WINDOW_EVENT = 'king:call-app-presence';

const CALL_APP_PRESENCE_PAYLOAD_TYPES = new Set([
  'cursor.move',
  'selection.update',
  'tool.preview',
]);

function plainString(value, fallback = '') {
  return String(value || '').trim() || fallback;
}

export function normalizeCallAppPresencePayloadType(value) {
  const payloadType = plainString(value).toLowerCase();
  return CALL_APP_PRESENCE_PAYLOAD_TYPES.has(payloadType) ? payloadType : '';
}

export function normalizeCallAppPresenceDisplayName(value) {
  const displayName = plainString(value);
  if (displayName === '') return '';
  return displayName.slice(0, 80);
}

function normalizeCallAppPresenceGrantState(value) {
  const state = plainString(value).toLowerCase();
  return state === 'allowed' || state === 'denied' ? state : '';
}

function defaultGrantStateForSession(session = {}) {
  return plainString(session?.default_app_policy || session?.defaultAppPolicy).toLowerCase() === 'allowed_by_default'
    ? 'allowed'
    : 'denied';
}

export function callAppPresenceUserAuthorizedForSession(session = {}, userId = 0) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;

  const grants = Array.isArray(session?.grants) ? session.grants : [];
  const grant = grants.find((row) => (
    plainString(row?.subject_type).toLowerCase() === 'user'
    && Number(row?.user_id || row?.userId || 0) === normalizedUserId
  ));
  const explicitState = normalizeCallAppPresenceGrantState(grant?.grant_state || grant?.grantState);
  return (explicitState || defaultGrantStateForSession(session)) === 'allowed';
}

export function normalizeCallAppPresenceParticipantRows(rows, currentUserId = 0, session = null) {
  const localUserId = Number(currentUserId || 0);
  const seen = new Set();
  const participants = [];
  for (const row of Array.isArray(rows) ? rows : []) {
    const userId = Number(row?.userId || row?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0 || userId === localUserId || seen.has(userId)) continue;
    if (row?.isRoomMember === false || row?.is_room_member === false) continue;
    if (session && !callAppPresenceUserAuthorizedForSession(session, userId)) continue;
    seen.add(userId);
    participants.push({
      userId,
      displayName: normalizeCallAppPresenceDisplayName(row?.displayName || row?.display_name || `User ${userId}`),
    });
  }
  return participants;
}

function plainClone(value, depth = 0) {
  if (depth > 6) return null;
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value.slice(0, 4096);
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'boolean') return value;
  if (Array.isArray(value)) return value.slice(0, 128).map((entry) => plainClone(entry, depth + 1));
  if (typeof value !== 'object') return null;

  const result = {};
  for (const [key, entry] of Object.entries(value).slice(0, 64)) {
    const safeKey = String(key || '').trim().slice(0, 80);
    if (safeKey === '') continue;
    result[safeKey] = plainClone(entry, depth + 1);
  }
  return result;
}

export function normalizeCallAppPresencePayload(payloadType, payload = {}, options = {}) {
  const normalizedPayloadType = normalizeCallAppPresencePayloadType(payloadType);
  if (normalizedPayloadType === '') return null;
  const result = plainClone(payload) || {};
  const actorId = plainString(result.actor_id || options.actorId);
  const displayName = normalizeCallAppPresenceDisplayName(result.display_name || result.label || options.displayName);
  if (actorId !== '') result.actor_id = actorId;
  if (displayName !== '') {
    result.display_name = displayName;
    if (normalizedPayloadType === 'cursor.move') result.label = displayName;
  }
  if (normalizedPayloadType === 'cursor.move') {
    result.x = Math.max(0, Math.min(1600, Number(result.x || 0)));
    result.y = Math.max(0, Math.min(900, Number(result.y || 0)));
    result.color = plainString(result.color, '#1582bf').slice(0, 32);
  }
  if (normalizedPayloadType === 'selection.update') {
    result.selected_id = plainString(result.selected_id).slice(0, 160);
  }
  return result;
}

export function createCallAppPresenceSignalPayload(session, payloadType, payload) {
  const normalizedPayloadType = normalizeCallAppPresencePayloadType(payloadType);
  const normalizedPayload = normalizeCallAppPresencePayload(normalizedPayloadType, payload);
  if (!session || normalizedPayloadType === '' || !normalizedPayload) return null;
  return {
    kind: 'call_app_presence',
    app_session_id: plainString(session.id),
    app_key: plainString(session.app_key || session.appKey),
    document_id: plainString(session.document_id || session.documentId),
    payload_type: normalizedPayloadType,
    actor_id: plainString(normalizedPayload.actor_id),
    payload: normalizedPayload,
  };
}

export function normalizeRemoteCallAppPresenceSignal(payloadBody = {}) {
  const payload = payloadBody && typeof payloadBody === 'object' ? payloadBody : {};
  const payloadType = normalizeCallAppPresencePayloadType(payload.payload_type);
  const normalizedPayload = normalizeCallAppPresencePayload(payloadType, payload.payload || {}, {
    actorId: payload.actor_id,
  });
  if (payloadType === '' || !normalizedPayload) return null;
  return {
    kind: 'call_app_presence',
    app_session_id: plainString(payload.app_session_id),
    app_key: plainString(payload.app_key),
    document_id: plainString(payload.document_id),
    payload_type: payloadType,
    actor_id: plainString(payload.actor_id || normalizedPayload.actor_id),
    payload: normalizedPayload,
  };
}

export function dispatchCallAppPresenceSignal(payloadBody = {}, sender = {}) {
  if (typeof window === 'undefined') return false;
  const signal = normalizeRemoteCallAppPresenceSignal(payloadBody);
  if (!signal) return false;
  window.dispatchEvent(new CustomEvent(CALL_APP_PRESENCE_WINDOW_EVENT, {
    detail: {
      signal,
      sender: {
        user_id: Number(sender?.user_id || 0) || 0,
        display_name: normalizeCallAppPresenceDisplayName(sender?.display_name || sender?.displayName || ''),
      },
    },
  }));
  return true;
}
