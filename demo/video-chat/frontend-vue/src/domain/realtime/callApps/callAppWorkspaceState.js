import { computed, ref } from 'vue';

export const CALL_APP_WORKSPACE_LAYOUT_MODE = 'call_app_workspace';
export const CALL_APP_WORKSPACE_MINI_LIMIT = 5;
const CALL_APP_IFRAME_ORIGIN = normalizeConfiguredCallAppOrigin(import.meta.env.VITE_VIDEOCHAT_CALL_APP_ORIGIN);

function normalizeConfiguredCallAppOrigin(value) {
  const trimmed = String(value || '').trim().replace(/\/+$/, '');
  if (trimmed === '') return '';
  const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
  try {
    const parsed = new URL(withScheme);
    parsed.pathname = '';
    parsed.search = '';
    parsed.hash = '';
    return parsed.toString().replace(/\/+$/, '');
  } catch {
    return '';
  }
}

function callAppOriginForAppKey(appKey) {
  if (CALL_APP_IFRAME_ORIGIN === '') return '';
  const hostAppKey = String(appKey || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9-]+/g, '-')
    .replace(/^-+|-+$/g, '');
  if (hostAppKey === '') return CALL_APP_IFRAME_ORIGIN;

  try {
    const parsed = new URL(CALL_APP_IFRAME_ORIGIN);
    const parts = parsed.hostname.split('.');
    if (parts.length >= 3 && ['app', 'apps', 'whiteboard'].includes(parts[0])) {
      parts[0] = hostAppKey;
      parsed.hostname = parts.join('.');
      parsed.pathname = '';
      parsed.search = '';
      parsed.hash = '';
      return parsed.toString().replace(/\/+$/, '');
    }
  } catch {
    return CALL_APP_IFRAME_ORIGIN;
  }

  return CALL_APP_IFRAME_ORIGIN;
}

function normalizeSession(raw = {}) {
  const session = raw && typeof raw === 'object' ? raw : {};
  const app = session.app && typeof session.app === 'object' ? session.app : {};
  return {
    ...session,
    id: String(session.id || '').trim(),
    call_id: String(session.call_id || session.callId || '').trim(),
    app_key: String(session.app_key || session.appKey || '').trim(),
    status: String(session.status || '').trim().toLowerCase(),
    document_id: String(session.document_id || session.documentId || '').trim(),
    app: {
      ...app,
      name: String(app.name || session.app_key || '').trim(),
      iframe_entrypoint: String(app.iframe_entrypoint || app.iframeEntrypoint || '').trim(),
      health_status: String(app.health_status || app.healthStatus || '').trim().toLowerCase(),
    },
  };
}

function normalizeRoomState(raw = {}) {
  const payload = raw && typeof raw === 'object' ? raw : {};
  const sessions = Array.isArray(payload.active_sessions)
    ? payload.active_sessions.map(normalizeSession).filter((session) => session.id !== '' && session.status === 'active')
    : [];
  return {
    active_sessions: sessions,
    active_session_count: Number(payload.active_session_count || sessions.length || 0),
    has_active_session: payload.has_active_session === true || sessions.length > 0,
  };
}

function normalizeParticipantRows(rows) {
  const seen = new Set();
  const participants = [];
  for (const row of Array.isArray(rows) ? rows : []) {
    const userId = Number(row?.userId || row?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0 || seen.has(userId)) continue;
    seen.add(userId);
    participants.push({
      ...row,
      userId,
      displayName: String(row?.displayName || row?.display_name || `User ${userId}`).trim() || `User ${userId}`,
      role: String(row?.role || 'user').trim() || 'user',
    });
    if (participants.length >= CALL_APP_WORKSPACE_MINI_LIMIT) break;
  }
  return participants;
}

export function callAppWorkspaceIframeUrl(session) {
  const normalizedSession = normalizeSession(session);
  const appKey = normalizedSession.app_key.replace(/[^A-Za-z0-9._-]/g, '');
  const entrypoint = normalizedSession.app.iframe_entrypoint
    .split('/')
    .map((part) => part.trim())
    .filter((part) => part !== '' && part !== '.' && part !== '..')
    .map(encodeURIComponent)
    .join('/');
  if (appKey === '' || entrypoint === '') return 'about:blank';
  const path = `/call-app/${encodeURIComponent(appKey)}/${entrypoint}`;
  const origin = callAppOriginForAppKey(appKey);
  return origin !== '' ? `${origin}${path}` : path;
}

export function createCallAppWorkspaceState({
  connectedParticipantUsers,
  miniVideoParticipants,
  nextTick,
  renderCallVideoLayout,
} = {}) {
  const callAppsRoomState = ref(normalizeRoomState());
  const activeCallAppSession = computed(() => callAppsRoomState.value.active_sessions[0] || null);
  const hasActiveCallAppSession = computed(() => activeCallAppSession.value !== null);
  const callAppWorkspaceMiniParticipants = computed(() => {
    const layoutRows = normalizeParticipantRows(miniVideoParticipants?.value || []);
    if (layoutRows.length > 0) return layoutRows;
    return normalizeParticipantRows(connectedParticipantUsers?.value || []);
  });

  function applyCallAppsRoomState(payload) {
    callAppsRoomState.value = normalizeRoomState(payload);
    if (typeof nextTick === 'function' && typeof renderCallVideoLayout === 'function') {
      nextTick(() => renderCallVideoLayout());
    }
  }

  return {
    activeCallAppSession,
    callAppsRoomState,
    callAppWorkspaceMiniParticipants,
    hasActiveCallAppSession,
    applyCallAppsRoomState,
  };
}
