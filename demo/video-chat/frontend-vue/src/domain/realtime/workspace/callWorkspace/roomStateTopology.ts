export function applyGossipTopologyFromRoomStatePayload(payload, localPeerId, applyGossipTopologyHint) {
  if (!payload || typeof payload !== 'object' || typeof applyGossipTopologyHint !== 'function') {
    return false;
  }
  if (applyGossipTopologyHint(payload?.gossip_topology)) {
    return true;
  }

  const safeLocalPeerId = String(localPeerId || '').trim();
  const byPeerId = payload?.gossip_topology_by_peer_id;
  if (safeLocalPeerId === '' || !byPeerId || typeof byPeerId !== 'object') {
    return false;
  }

  return applyGossipTopologyHint(byPeerId[safeLocalPeerId]);
}
