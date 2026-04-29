export const REMOTE_RENDER_SURFACE_ROLES = Object.freeze({
  FALLBACK: 'fallback',
  FULLSCREEN: 'fullscreen',
  GRID: 'grid',
  MAIN: 'main',
  MINI: 'mini',
});

const SURFACE_RENDER_INTERVAL_MS = Object.freeze({
  [REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN]: 0,
  [REMOTE_RENDER_SURFACE_ROLES.MAIN]: 0,
  [REMOTE_RENDER_SURFACE_ROLES.GRID]: 50,
  [REMOTE_RENDER_SURFACE_ROLES.MINI]: 100,
  [REMOTE_RENDER_SURFACE_ROLES.FALLBACK]: 66,
});

const SURFACE_DECODE_QUEUE_LIMIT = Object.freeze({
  [REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN]: 8,
  [REMOTE_RENDER_SURFACE_ROLES.MAIN]: 6,
  [REMOTE_RENDER_SURFACE_ROLES.GRID]: 3,
  [REMOTE_RENDER_SURFACE_ROLES.MINI]: 2,
  [REMOTE_RENDER_SURFACE_ROLES.FALLBACK]: 2,
});

const SURFACE_RENDER_PRIORITY = Object.freeze({
  [REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN]: 4,
  [REMOTE_RENDER_SURFACE_ROLES.MAIN]: 3,
  [REMOTE_RENDER_SURFACE_ROLES.GRID]: 2,
  [REMOTE_RENDER_SURFACE_ROLES.MINI]: 1,
  [REMOTE_RENDER_SURFACE_ROLES.FALLBACK]: 1,
});

export function normalizeRemoteRenderSurfaceRole(value) {
  const role = String(value || '').trim().toLowerCase();
  return Object.values(REMOTE_RENDER_SURFACE_ROLES).includes(role)
    ? role
    : REMOTE_RENDER_SURFACE_ROLES.FALLBACK;
}

export function remoteRenderPriorityForRole(role) {
  return SURFACE_RENDER_PRIORITY[normalizeRemoteRenderSurfaceRole(role)] || 1;
}

export function applyRemoteVideoSurfaceRole(node, {
  layoutMode = '',
  role = REMOTE_RENDER_SURFACE_ROLES.FALLBACK,
  userId = 0,
} = {}) {
  if (!node || typeof node !== 'object' || !node.dataset || typeof node.dataset !== 'object') return false;
  const normalizedRole = normalizeRemoteRenderSurfaceRole(role);
  node.dataset.callVideoSurfaceRole = normalizedRole;
  node.dataset.callVideoRenderPriority = String(remoteRenderPriorityForRole(normalizedRole));
  node.dataset.callVideoLayoutMode = String(layoutMode || '');
  const normalizedUserId = Number(userId || 0);
  if (Number.isInteger(normalizedUserId) && normalizedUserId > 0) {
    node.dataset.callVideoSurfaceUserId = String(normalizedUserId);
  } else {
    delete node.dataset.callVideoSurfaceUserId;
  }
  return true;
}

export function remoteRenderSurfaceRoleForPeer(peer) {
  return normalizeRemoteRenderSurfaceRole(peer?.decodedCanvas?.dataset?.callVideoSurfaceRole);
}

function normalizePositiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function remoteFrameTrackKey(frame) {
  return String(frame?.trackId || '').trim() || 'default';
}

function isRemoteKeyframe(frame) {
  return String(frame?.type || '').trim().toLowerCase() === 'keyframe'
    || String(frame?.type || '').trim().toLowerCase() === 'key';
}

function ensureRemoteRenderState(peer, frame) {
  if (!peer || typeof peer !== 'object') return null;
  if (!peer.remoteRenderStateByTrack || typeof peer.remoteRenderStateByTrack !== 'object') {
    peer.remoteRenderStateByTrack = {};
  }
  const trackKey = remoteFrameTrackKey(frame);
  if (!peer.remoteRenderStateByTrack[trackKey] || typeof peer.remoteRenderStateByTrack[trackKey] !== 'object') {
    peer.remoteRenderStateByTrack[trackKey] = {
      lastRenderedAtMs: 0,
      lastRenderedSequence: 0,
      lastRenderedTimestamp: 0,
      lastRenderedSurfaceRole: REMOTE_RENDER_SURFACE_ROLES.FALLBACK,
    };
  }
  return peer.remoteRenderStateByTrack[trackKey];
}

export function shouldDecodeRemoteFrame(peer, frame, decodeQueueSize = 0) {
  if (!peer || typeof peer !== 'object') {
    return { decode: false, reason: 'missing_peer', role: REMOTE_RENDER_SURFACE_ROLES.FALLBACK };
  }
  const role = remoteRenderSurfaceRoleForPeer(peer);
  if (isRemoteKeyframe(frame)) {
    return { decode: true, reason: 'keyframe', role };
  }
  const queueSize = normalizePositiveInteger(decodeQueueSize, 0);
  const queueLimit = SURFACE_DECODE_QUEUE_LIMIT[role] || SURFACE_DECODE_QUEUE_LIMIT[REMOTE_RENDER_SURFACE_ROLES.FALLBACK];
  if (queueSize > queueLimit) {
    return {
      decode: false,
      reason: 'decode_queue_pressure',
      role,
      queueLimit,
      queueSize,
    };
  }
  return { decode: true, reason: 'within_queue_budget', role, queueLimit, queueSize };
}

export function shouldRenderRemoteFrame(peer, frame, nowMs = Date.now()) {
  if (!peer || typeof peer !== 'object') {
    return { render: false, reason: 'missing_peer', role: REMOTE_RENDER_SURFACE_ROLES.FALLBACK };
  }
  const role = remoteRenderSurfaceRoleForPeer(peer);
  const state = ensureRemoteRenderState(peer, frame);
  if (!state) return { render: false, reason: 'missing_render_state', role };

  const frameSequence = normalizePositiveInteger(frame?.frameSequence, 0);
  const frameTimestamp = normalizePositiveInteger(frame?.timestamp, 0);
  if (frameSequence > 0 && state.lastRenderedSequence > 0 && frameSequence <= state.lastRenderedSequence) {
    return {
      render: false,
      reason: 'stale_sequence',
      role,
      frameSequence,
      lastRenderedSequence: state.lastRenderedSequence,
    };
  }
  if (frameSequence <= 0 && frameTimestamp > 0 && state.lastRenderedTimestamp > 0 && frameTimestamp < state.lastRenderedTimestamp) {
    return {
      render: false,
      reason: 'stale_timestamp',
      role,
      frameTimestamp,
      lastRenderedTimestamp: state.lastRenderedTimestamp,
    };
  }
  if (isRemoteKeyframe(frame) || role === REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN || role === REMOTE_RENDER_SURFACE_ROLES.MAIN) {
    return { render: true, reason: 'priority_surface', role };
  }

  const minIntervalMs = SURFACE_RENDER_INTERVAL_MS[role] || SURFACE_RENDER_INTERVAL_MS[REMOTE_RENDER_SURFACE_ROLES.FALLBACK];
  const elapsedMs = Math.max(0, Number(nowMs || 0) - Number(state.lastRenderedAtMs || 0));
  if (state.lastRenderedAtMs > 0 && elapsedMs < minIntervalMs) {
    return {
      render: false,
      reason: 'surface_render_throttle',
      role,
      elapsedMs,
      minIntervalMs,
    };
  }
  return { render: true, reason: 'within_surface_budget', role, elapsedMs, minIntervalMs };
}

export function markRemoteFrameRendered(peer, frame, nowMs = Date.now()) {
  const state = ensureRemoteRenderState(peer, frame);
  if (!state) return false;
  const role = remoteRenderSurfaceRoleForPeer(peer);
  state.lastRenderedAtMs = Math.max(0, Number(nowMs || 0));
  state.lastRenderedSequence = Math.max(state.lastRenderedSequence || 0, normalizePositiveInteger(frame?.frameSequence, 0));
  state.lastRenderedTimestamp = Math.max(state.lastRenderedTimestamp || 0, normalizePositiveInteger(frame?.timestamp, 0));
  state.lastRenderedSurfaceRole = role;
  peer.lastRemoteRenderSurfaceRole = role;
  return true;
}

