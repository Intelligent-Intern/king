import {
  SCREEN_SHARE_MEDIA_SOURCE,
  isScreenShareMediaSource,
  isScreenShareUserId,
  screenShareDisplayName,
  screenShareOwnerUserIdFromSynthetic,
  screenShareUserIdForOwner,
} from '../../screenShareIdentity.js';

function positiveInteger(value) {
  const normalized = Number(value || 0);
  return Number.isInteger(normalized) && normalized > 0 ? normalized : 0;
}

function screenShareOwnerUserIdForPeer(peer, peerUserId) {
  return positiveInteger(peer?.screenShareOwnerUserId || peer?.screen_share_owner_user_id)
    || screenShareOwnerUserIdFromSynthetic(peerUserId)
    || positiveInteger(peer?.publisherUserId || peer?.publisher_user_id)
    || (isScreenShareMediaSource(peer?.mediaSource || peer?.media_source) ? peerUserId : 0);
}

export function screenShareParticipantRowFromMediaPeer(peer, options = {}) {
  if (!peer || typeof peer !== 'object') return null;
  const peerUserId = positiveInteger(peer.userId || peer.user_id);
  const screenSharePeer = isScreenShareUserId(peerUserId)
    || isScreenShareMediaSource(peer.mediaSource || peer.media_source);
  if (!screenSharePeer) return null;

  const ownerUserId = screenShareOwnerUserIdForPeer(peer, peerUserId);
  const screenShareUserId = isScreenShareUserId(peerUserId)
    ? peerUserId
    : screenShareUserIdForOwner(ownerUserId);
  if (screenShareUserId <= 0) return null;

  const displayName = String(peer.displayName || peer.display_name || '').trim()
    || screenShareDisplayName('', ownerUserId);
  return {
    userId: screenShareUserId,
    displayName,
    role: String(peer.role || 'user').trim().toLowerCase() || 'user',
    callRole: String(peer.callRole || peer.call_role || 'participant').trim().toLowerCase() || 'participant',
    connectedAt: String(peer.connectedAt || peer.connected_at || '').trim(),
    connections: 1,
    hasSnapshotConnection: false,
    mediaPeerSource: String(options.source || peer.mediaPeerSource || peer.media_peer_source || 'media').trim() || 'media',
    mediaSource: SCREEN_SHARE_MEDIA_SOURCE,
    screenShareOwnerUserId: ownerUserId,
    publisherUserId: positiveInteger(peer.publisherUserId || peer.publisher_user_id) || ownerUserId,
  };
}

export function mergeScreenShareParticipantRows(participants = [], mediaPeers = [], options = {}) {
  const rows = Array.isArray(participants) ? participants.slice() : [];
  const byUserId = new Map();
  rows.forEach((row, index) => {
    const userId = positiveInteger(row?.userId || row?.user_id);
    if (userId > 0) byUserId.set(userId, index);
  });

  for (const peer of mediaPeers || []) {
    const row = screenShareParticipantRowFromMediaPeer(peer, options);
    if (!row) continue;
    const existingIndex = byUserId.get(row.userId);
    if (existingIndex === undefined) {
      byUserId.set(row.userId, rows.length);
      rows.push(row);
      continue;
    }
    rows[existingIndex] = {
      ...rows[existingIndex],
      mediaSource: SCREEN_SHARE_MEDIA_SOURCE,
      screenShareOwnerUserId: row.screenShareOwnerUserId,
      publisherUserId: row.publisherUserId,
      connections: Math.max(1, Number(rows[existingIndex]?.connections || 0)),
    };
  }
  return rows;
}
