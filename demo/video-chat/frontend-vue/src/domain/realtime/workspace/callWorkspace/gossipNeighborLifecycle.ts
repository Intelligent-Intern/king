import { DEFAULT_NATIVE_ICE_SERVERS } from '../config';

const GOSSIP_NEIGHBOR_RUNTIME_PATH = 'gossip_primary_neighbor';
const GOSSIP_NEIGHBOR_SIGNAL_KINDS = Object.freeze({
  offer: 'gossip_neighbor_offer',
  answer: 'gossip_neighbor_answer',
  ice: 'gossip_neighbor_ice',
});
const LEGACY_GOSSIP_WEBRTC_SIGNAL_KINDS = Object.freeze([
  'gossip_webrtc_offer',
  'gossip_webrtc_answer',
  'gossip_webrtc_ice',
]);

function safePeerId(value) {
  return String(value || '').trim();
}

function normalizeSdp(payload) {
  const sdpPayload = payload && typeof payload.sdp === 'object' ? payload.sdp : null;
  const type = String(sdpPayload?.type || '').trim().toLowerCase();
  const sdp = String(sdpPayload?.sdp || '').replace(/\r?\n/g, '\r\n');
  if ((type !== 'offer' && type !== 'answer') || sdp.trim() === '') return null;
  return { type, sdp: sdp.endsWith('\r\n') ? sdp : `${sdp}\r\n` };
}

function isGossipNeighborPayload(payload) {
  if (!payload || typeof payload !== 'object') return false;
  const kind = String(payload.kind || '').trim().toLowerCase();
  const runtimePath = String(payload.runtime_path || '').trim().toLowerCase();
  return Object.values(GOSSIP_NEIGHBOR_SIGNAL_KINDS).includes(kind)
    || LEGACY_GOSSIP_WEBRTC_SIGNAL_KINDS.includes(kind)
    || runtimePath === GOSSIP_NEIGHBOR_RUNTIME_PATH;
}

export function createGossipNeighborLifecycle({
  callbacks,
}) {
  const {
    activeCallId,
    activeRoomId,
    captureClientDiagnostic = () => {},
    currentUserId,
    getDataTransport = () => null,
    getIceServers = () => DEFAULT_NATIVE_ICE_SERVERS,
    onPeerConnectionState = () => false,
    sendSocketFrame = () => false,
  } = callbacks;

  const peers = new Map();
  const admittedPeerIds = new Set();
  let topologyEpoch = 0;

  function localPeerId() {
    return safePeerId(currentUserId());
  }

  function roomId() {
    return safePeerId(activeRoomId()) || 'lobby';
  }

  function callId() {
    return safePeerId(activeCallId()) || 'call';
  }

  function peerConnectionConfig() {
    const iceServers = getIceServers();
    return {
      iceServers: Array.isArray(iceServers) && iceServers.length > 0 ? iceServers : DEFAULT_NATIVE_ICE_SERVERS,
      iceCandidatePoolSize: 2,
    };
  }

  function shouldInitiatePeer(peerId) {
    const local = localPeerId();
    const remote = safePeerId(peerId);
    if (local === '' || remote === '') return false;
    const localNumber = Number(local);
    const remoteNumber = Number(remote);
    if (Number.isFinite(localNumber) && Number.isFinite(remoteNumber)) {
      return localNumber < remoteNumber;
    }
    return local < remote;
  }

  function ensurePeer(peerId, initiator, reason) {
    const normalizedPeerId = safePeerId(peerId);
    if (normalizedPeerId === '' || normalizedPeerId === localPeerId()) return null;
    if (typeof RTCPeerConnection !== 'function') return null;

    const existing = peers.get(normalizedPeerId);
    if (existing?.pc && existing.pc.signalingState !== 'closed') {
      if (existing.initiator !== Boolean(initiator)) {
        closePeer(normalizedPeerId, 'initiator_role_changed');
      } else {
        return existing;
      }
    }

    const current = peers.get(normalizedPeerId);
    if (current?.pc && current.pc.signalingState !== 'closed') {
      return current;
    }

    const pc = new RTCPeerConnection(peerConnectionConfig());
    const peer = {
      peerId: normalizedPeerId,
      pc,
      initiator: Boolean(initiator),
      pendingIce: [],
      negotiating: false,
      needsRenegotiate: false,
      renegotiateTimer: null,
    };
    peers.set(normalizedPeerId, peer);

    pc.addEventListener('icecandidate', (event) => {
      if (!event?.candidate) return;
      sendSocketFrame({
        type: 'call/ice',
        target_user_id: Number(normalizedPeerId),
        payload: {
          kind: GOSSIP_NEIGHBOR_SIGNAL_KINDS.ice,
          runtime_path: GOSSIP_NEIGHBOR_RUNTIME_PATH,
          room_id: roomId(),
          call_id: callId(),
          topology_epoch: topologyEpoch,
          transport: 'rtc_datachannel',
          candidate: event.candidate.toJSON(),
        },
      });
    });

    pc.addEventListener('negotiationneeded', () => {
      if (!peer.initiator) return;
      void negotiatePeer(peer, 'negotiationneeded');
    });

    pc.addEventListener('connectionstatechange', () => {
      const state = String(pc.connectionState || '').trim().toLowerCase();
      onPeerConnectionState(normalizedPeerId, state, 'connectionstatechange');
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_peer_connection_state',
        code: 'gossip_neighbor_peer_connection_state',
        message: 'Dedicated Gossip neighbor peer connection state changed.',
        payload: {
          peer_id: normalizedPeerId,
          connection_state: state,
          topology_epoch: topologyEpoch,
        },
        immediate: true,
      });
      if (state === 'closed' || state === 'failed') {
        closePeer(normalizedPeerId, state === 'failed' ? 'peer_connection_failed' : 'peer_connection_closed');
      }
    });

    const transport = getDataTransport();
    transport?.bindPeerConnection?.(normalizedPeerId, pc, peer.initiator);
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'gossip_neighbor_link_assigned',
      code: 'gossip_neighbor_link_assigned',
      message: 'Dedicated Gossip neighbor link was assigned by server topology.',
      payload: {
        peer_id: normalizedPeerId,
        initiator: peer.initiator,
        reason: String(reason || 'assigned'),
        topology_epoch: topologyEpoch,
      },
      immediate: true,
    });

    if (peer.initiator) {
      void negotiatePeer(peer, 'assigned_neighbor');
    }
    return peer;
  }

  async function negotiatePeer(peer, reason) {
    if (!peer?.pc || peer.negotiating) {
      if (peer) peer.needsRenegotiate = true;
      return false;
    }
    peer.negotiating = true;
    try {
      const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
      if (signalingState !== 'stable' && signalingState !== '') {
        peer.needsRenegotiate = true;
        return false;
      }
      const offer = await peer.pc.createOffer();
      await peer.pc.setLocalDescription(offer);
      const local = peer.pc.localDescription;
      if (!local?.sdp) return false;
      const sent = sendSocketFrame({
        type: 'call/offer',
        target_user_id: Number(peer.peerId),
        payload: {
          kind: GOSSIP_NEIGHBOR_SIGNAL_KINDS.offer,
          runtime_path: GOSSIP_NEIGHBOR_RUNTIME_PATH,
          room_id: roomId(),
          call_id: callId(),
          topology_epoch: topologyEpoch,
          transport: 'rtc_datachannel',
          assigned_by_server: true,
          sdp: {
            type: local.type,
            sdp: local.sdp,
          },
        },
      });
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_offer_sent',
        code: 'gossip_neighbor_offer_sent',
        message: 'Dedicated Gossip neighbor offer was sent.',
        payload: {
          peer_id: safePeerId(peer?.peerId),
          reason: String(reason || 'offer'),
          topology_epoch: topologyEpoch,
          sent,
        },
        immediate: true,
      });
      return sent;
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_offer_failed',
        code: 'gossip_neighbor_offer_failed',
        message: 'Dedicated Gossip neighbor offer failed.',
        payload: {
          peer_id: safePeerId(peer?.peerId),
          reason: String(reason || 'offer'),
          error: String(error?.message || error || ''),
        },
      });
      return false;
    } finally {
      peer.negotiating = false;
      if (peer.needsRenegotiate) {
        peer.needsRenegotiate = false;
        scheduleRenegotiate(peer, 'queued_renegotiate');
      }
    }
  }

  function scheduleRenegotiate(peer, reason) {
    if (!peer?.pc || peer.pc.signalingState === 'closed') return;
    if (peer.renegotiateTimer) return;
    peer.renegotiateTimer = setTimeout(() => {
      peer.renegotiateTimer = null;
      if (!peer?.pc || peer.pc.signalingState === 'closed') return;
      const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
      if (signalingState !== 'stable' && signalingState !== '') {
        peer.needsRenegotiate = true;
        scheduleRenegotiate(peer, reason);
        return;
      }
      void negotiatePeer(peer, reason);
    }, 150);
  }

  async function flushPendingIce(peer) {
    if (!peer?.pc?.remoteDescription?.type) return;
    while (peer.pendingIce.length > 0) {
      const candidate = peer.pendingIce.shift();
      try {
        await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch {
        // Ignore stale candidates; the repair lane handles failed edges.
      }
    }
  }

  async function handleOffer(senderPeerId, payload) {
    const normalizedPeerId = safePeerId(senderPeerId);
    if (normalizedPeerId === '' || normalizedPeerId === localPeerId()) return;
    const remote = normalizeSdp(payload);
    if (!remote || remote.type !== 'offer') return;

    const peer = ensurePeer(normalizedPeerId, false, 'remote_assigned_offer');
    if (!peer?.pc) return;
    try {
      const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
      if (signalingState === 'have-local-offer') {
        const remoteWinsCollision = normalizedPeerId < localPeerId();
        if (!remoteWinsCollision) return;
        await peer.pc.setLocalDescription({ type: 'rollback' });
      } else if (signalingState !== 'stable' && signalingState !== '') {
        return;
      }

      await peer.pc.setRemoteDescription(new RTCSessionDescription(remote));
      await flushPendingIce(peer);
      const answer = await peer.pc.createAnswer();
      const readyForAnswer = String(peer.pc.signalingState || '').trim().toLowerCase();
      if (readyForAnswer !== 'have-remote-offer' && readyForAnswer !== 'have-local-pranswer') {
        return;
      }
      try {
        await peer.pc.setLocalDescription(answer);
      } catch (error) {
        if (String(error?.message || error || '').toLowerCase().includes('wrong state: stable')) return;
        throw error;
      }
      const local = peer.pc.localDescription;
      if (!local?.sdp) return;
      sendSocketFrame({
        type: 'call/answer',
        target_user_id: Number(normalizedPeerId),
        payload: {
          kind: GOSSIP_NEIGHBOR_SIGNAL_KINDS.answer,
          runtime_path: GOSSIP_NEIGHBOR_RUNTIME_PATH,
          room_id: roomId(),
          call_id: callId(),
          topology_epoch: topologyEpoch,
          transport: 'rtc_datachannel',
          assigned_by_server: true,
          sdp: {
            type: local.type,
            sdp: local.sdp,
          },
        },
      });
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_answer_sent',
        code: 'gossip_neighbor_answer_sent',
        message: 'Dedicated Gossip neighbor answer was sent.',
        payload: {
          peer_id: normalizedPeerId,
          topology_epoch: topologyEpoch,
        },
        immediate: true,
      });
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_offer_handle_failed',
        code: 'gossip_neighbor_offer_handle_failed',
        message: 'Dedicated Gossip neighbor offer could not be applied.',
        payload: {
          peer_id: normalizedPeerId,
          error: String(error?.message || error || ''),
        },
        immediate: true,
      });
    }
  }

  async function handleAnswer(senderPeerId, payload) {
    const normalizedPeerId = safePeerId(senderPeerId);
    const peer = peers.get(normalizedPeerId);
    const remote = normalizeSdp(payload);
    if (!peer?.pc || !remote || remote.type !== 'answer') return;
    const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
    if (signalingState === 'stable' || signalingState === '') {
      return;
    }
    if (signalingState !== 'have-local-offer') {
      peer.needsRenegotiate = true;
      scheduleRenegotiate(peer, 'answer_arrived_during_unexpected_state');
      return;
    }
    try {
      try {
        await peer.pc.setRemoteDescription(new RTCSessionDescription(remote));
      } catch (error) {
        if (String(error?.message || error || '').toLowerCase().includes('wrong state: stable')) return;
        throw error;
      }
      await flushPendingIce(peer);
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_answer_applied',
        code: 'gossip_neighbor_answer_applied',
        message: 'Dedicated Gossip neighbor answer was applied.',
        payload: {
          peer_id: normalizedPeerId,
          topology_epoch: topologyEpoch,
        },
        immediate: true,
      });
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_neighbor_answer_apply_failed',
        code: 'gossip_neighbor_answer_apply_failed',
        message: 'Dedicated Gossip neighbor answer could not be applied.',
        payload: {
          peer_id: normalizedPeerId,
          error: String(error?.message || error || ''),
        },
        immediate: true,
      });
    }
  }

  async function handleIce(senderPeerId, payload) {
    const normalizedPeerId = safePeerId(senderPeerId);
    if (normalizedPeerId === '' || normalizedPeerId === localPeerId()) return;
    const candidate = payload?.candidate && typeof payload.candidate === 'object' ? payload.candidate : null;
    if (!candidate) return;
    const peer = peers.get(normalizedPeerId) || ensurePeer(normalizedPeerId, false, 'remote_ice');
    if (!peer?.pc) return;
    if (!peer.pc.remoteDescription?.type) {
      peer.pendingIce.push(candidate);
      return;
    }
    try {
      await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
    } catch {}
  }

  function handleGossipNeighborSignal(type, senderPeerId, payload) {
    if (!isGossipNeighborPayload(payload)) return false;
    const normalizedType = String(type || '').trim().toLowerCase();
    if (normalizedType === 'call/offer') {
      void handleOffer(senderPeerId, payload);
      return true;
    }
    if (normalizedType === 'call/answer') {
      void handleAnswer(senderPeerId, payload);
      return true;
    }
    if (normalizedType === 'call/ice') {
      void handleIce(senderPeerId, payload);
      return true;
    }
    return false;
  }

  function applyAssignedNeighbors(topologyHint, assignedPeerIds) {
    topologyEpoch = Math.max(0, Number(topologyHint?.topology_epoch || topologyEpoch || 0));
    admittedPeerIds.clear();
    for (const row of Array.isArray(topologyHint?.admitted_peers) ? topologyHint.admitted_peers : []) {
      const peerId = safePeerId(row?.peer_id || row?.user_id || '');
      if (peerId !== '' && peerId !== localPeerId()) admittedPeerIds.add(peerId);
    }

    const assigned = new Set(
      Array.from(assignedPeerIds || [])
        .map((peerId) => safePeerId(peerId))
        .filter((peerId) => peerId !== '' && peerId !== localPeerId())
    );
    for (const peerId of assigned) {
      admittedPeerIds.add(peerId);
      ensurePeer(peerId, shouldInitiatePeer(peerId), 'server_assigned_neighbor');
    }
    for (const peerId of Array.from(peers.keys())) {
      if (!assigned.has(peerId)) closePeer(peerId, 'retired_by_topology');
    }
    return peers.size;
  }

  function closePeer(peerId, reason = 'retired') {
    const normalizedPeerId = safePeerId(peerId);
    const peer = peers.get(normalizedPeerId);
    if (!peer) return false;
    peers.delete(normalizedPeerId);
    if (peer.renegotiateTimer) {
      clearTimeout(peer.renegotiateTimer);
      peer.renegotiateTimer = null;
    }
    try {
      peer.pc?.close?.();
    } catch {}
    getDataTransport()?.close?.(normalizedPeerId);
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_neighbor_link_retired',
      code: 'gossip_neighbor_link_retired',
      message: 'Dedicated Gossip neighbor link was retired.',
      payload: {
        peer_id: normalizedPeerId,
        reason: String(reason || 'retired'),
        topology_epoch: topologyEpoch,
      },
    });
    return true;
  }

  function teardown() {
    for (const peerId of Array.from(peers.keys())) {
      closePeer(peerId, 'teardown');
    }
    admittedPeerIds.clear();
    topologyEpoch = 0;
  }

  return {
    applyAssignedNeighbors,
    closePeer,
    handleGossipNeighborSignal,
    teardown,
  };
}
