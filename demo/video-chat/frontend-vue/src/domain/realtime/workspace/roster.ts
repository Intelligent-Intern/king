import {
  normalizeCallRole,
  normalizeRole,
  normalizeRoomId,
} from './utils';

export function normalizeParticipantRow(raw) {
  const user = raw && typeof raw.user === 'object' ? raw.user : {};
  const userId = Number(user.id || raw?.user_id || raw?.userId || 0);
  const connectionId = String(raw?.connection_id || raw?.connectionId || '').trim();
  const connectedAt = String(raw?.connected_at || raw?.connectedAt || '').trim();
  const connectionCount = Number(raw?.connections || raw?.connection_count || raw?.connectionCount || 0);
  return {
    connectionId,
    hasConnection: connectionId !== '' || connectedAt !== '' || connectionCount > 0,
    roomId: normalizeRoomId(raw?.room_id || raw?.roomId || 'lobby'),
    userId: Number.isInteger(userId) && userId > 0 ? userId : 0,
    displayName: String(user.display_name || user.displayName || raw?.display_name || raw?.displayName || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(user.role || raw?.role),
    callRole: normalizeCallRole(user.call_role || user.callRole || raw?.call_role || raw?.callRole || 'participant'),
    connectedAt,
  };
}

export function participantSnapshotSignature(rows) {
  const normalizedRows = Array.isArray(rows) ? rows : [];
  return normalizedRows
    .map((row) => normalizeParticipantRow(row))
    .filter((row) => row.userId > 0 || row.connectionId !== '')
    .map((row) => [
      row.roomId,
      row.userId,
      row.displayName,
      row.role,
      row.callRole,
      row.hasConnection ? '1' : '0',
    ].join('\u001f'))
    .sort()
    .join('\u001e');
}

export function mergeLiveMediaPeerIntoRoster(aggregate, peer, options = {}) {
  if (!(aggregate instanceof Map) || !peer || typeof peer !== 'object') return;

  const currentUserId = Number(options.currentUserId || 0);
  const callParticipantRoles = options.callParticipantRoles || {};
  const allowMissingSnapshotSupplement = options.allowMissingSnapshotSupplement === true;
  const peerUserId = Number(peer?.userId || 0);
  if (!Number.isInteger(peerUserId) || peerUserId <= 0 || peerUserId === currentUserId) return;

  const source = String(options.source || 'media');
  const displayName = String(peer?.displayName || '').trim() || `User ${peerUserId}`;
  const callRole = normalizeCallRole(callParticipantRoles[peerUserId] || peer?.callRole || 'participant');
  const existing = aggregate.get(peerUserId);
  if (existing) {
    if (!allowMissingSnapshotSupplement && Number(existing.connections || 0) <= 0) return;
    existing.connections = Math.max(1, Number(existing.connections || 0));
    if (String(existing.displayName || '').trim() === '') {
      existing.displayName = displayName;
    }
    existing.callRole = normalizeCallRole(callParticipantRoles[peerUserId] || existing.callRole || callRole);
    existing.mediaPeerSource = source;
    return;
  }
  if (!allowMissingSnapshotSupplement) return;

  aggregate.set(peerUserId, {
    userId: peerUserId,
    displayName,
    role: 'user',
    callRole,
    connectedAt: '',
    connections: 1,
    mediaPeerSource: source,
  });
}

export function participantActivityWeight(source) {
  const normalized = String(source || '').trim().toLowerCase();
  if (normalized === 'speaking') return 1;
  if (normalized === 'motion') return 0.9;
  if (normalized === 'media_frame') return 1;
  if (normalized === 'media_track') return 0.85;
  if (normalized === 'reaction') return 0.72;
  if (normalized === 'chat') return 0.68;
  if (normalized === 'typing') return 0.6;
  if (normalized === 'control') return 0.45;
  return 0.5;
}

export function replaceNumericArray(target, values) {
  target.splice(0, target.length, ...values
    .map((value) => Number(value))
    .filter((value) => Number.isInteger(value) && value > 0));
}
