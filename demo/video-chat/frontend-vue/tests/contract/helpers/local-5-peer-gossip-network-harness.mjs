import assert from 'node:assert/strict';

const ROOM_ID = 'local-browser-gossip-room';
const CALL_ID = 'local-browser-gossip-call';
const PEER_IDS = Object.freeze(['peer-1', 'peer-2', 'peer-3', 'peer-4', 'peer-5']);

function edgeKey(leftPeerId, rightPeerId) {
  return [String(leftPeerId), String(rightPeerId)].sort().join('<->');
}

function frameId(message) {
  return `${message.publisher_id}:${message.track_id}:${message.media_generation}:${message.frame_sequence}`;
}

function ringTopology(epoch) {
  const hints = new Map();
  for (let index = 0; index < PEER_IDS.length; index += 1) {
    const peerId = PEER_IDS[index];
    const previousPeerId = PEER_IDS[(index + PEER_IDS.length - 1) % PEER_IDS.length];
    const nextPeerId = PEER_IDS[(index + 1) % PEER_IDS.length];
    hints.set(peerId, {
      lane: 'ops',
      type: 'topology_hint',
      room_id: ROOM_ID,
      call_id: CALL_ID,
      peer_id: peerId,
      topology_epoch: epoch,
      neighbors: [
        { peer_id: previousPeerId, transport: 'rtc_datachannel' },
        { peer_id: nextPeerId, transport: 'rtc_datachannel' },
      ],
    });
  }
  return hints;
}

function repairedTopologyAfterPeer2Peer3Loss(epoch) {
  const hints = ringTopology(epoch);
  hints.set('peer-2', {
    ...hints.get('peer-2'),
    neighbors: [
      { peer_id: 'peer-4', transport: 'rtc_datachannel' },
      { peer_id: 'peer-1', transport: 'rtc_datachannel' },
    ],
    reconnect_reason: 'repair_peer_2_peer_3_loss',
    repair: {
      authoritative: true,
      retired_edges: [{ peer_id: 'peer-2', neighbor_peer_id: 'peer-3' }],
      replacement_peer_ids: ['peer-4'],
    },
  });
  hints.set('peer-3', {
    ...hints.get('peer-3'),
    neighbors: [
      { peer_id: 'peer-4', transport: 'rtc_datachannel' },
      { peer_id: 'peer-5', transport: 'rtc_datachannel' },
    ],
    reconnect_reason: 'repair_peer_2_peer_3_loss',
    repair: {
      authoritative: true,
      retired_edges: [{ peer_id: 'peer-3', neighbor_peer_id: 'peer-2' }],
      replacement_peer_ids: ['peer-5'],
    },
  });
  return hints;
}

export function createLocalFivePeerGossipNetworkHarness({ GossipController }) {
  assert.equal(typeof GossipController, 'function', 'harness requires the production GossipController');

  const controllers = new Map();
  const deliveries = [];
  const transmissions = [];
  const droppedTransmissions = [];
  const inactiveEdges = new Set();
  const appliedTopologyByPeerId = new Map();

  for (const localPeerId of PEER_IDS) {
    const controller = new GossipController(ROOM_ID, CALL_ID);
    controller.setDataLaneConfig({
      enabled: true,
      mode: 'active',
      publish: true,
      receive: true,
      diagnosticsLabel: 'local_contract_5_peer_mesh',
    });
    controller.setDataTransport({
      kind: 'rtc_datachannel',
      sendData: (targetPeerId, message, fromPeerId) => {
        const linkKey = edgeKey(fromPeerId, targetPeerId);
        if (inactiveEdges.has(linkKey)) {
          droppedTransmissions.push({
            from_peer_id: fromPeerId,
            target_peer_id: targetPeerId,
            frame_id: frameId(message),
            reason: 'simulated_neighbor_loss',
          });
          return;
        }
        transmissions.push({
          from_peer_id: fromPeerId,
          target_peer_id: targetPeerId,
          frame_id: frameId(message),
          ttl: Number(message.ttl || 0),
        });
        controllers.get(String(targetPeerId))?.handleData(String(targetPeerId), message, String(fromPeerId));
      },
    });
    controller.onDataMessage((delivery) => {
      deliveries.push({
        local_controller_peer_id: localPeerId,
        receiving_peer_id: delivery.receiving_peer_id,
        from_peer_id: delivery.from_peer_id,
        frame_id: delivery.frame_id,
        frame_sequence: Number(delivery.message?.frame_sequence || 0),
        ttl: Number(delivery.message?.ttl || 0),
      });
    });
    for (const peerId of PEER_IDS) {
      controller.addPeer(peerId);
    }
    controllers.set(localPeerId, controller);
  }

  function applyTopology(hints) {
    const result = {};
    for (const [peerId, hint] of hints.entries()) {
      const applied = controllers.get(peerId).applyTopologyHint(peerId, hint);
      result[peerId] = applied;
      if (applied) {
        appliedTopologyByPeerId.set(peerId, hint);
      }
    }
    return result;
  }

  function publishFrame(publisherPeerId, frameSequence, overrides = {}) {
    const message = {
      type: 'sfu/frame',
      protocol_version: 2,
      publisher_id: publisherPeerId,
      publisher_user_id: publisherPeerId,
      track_id: 'camera-main',
      frame_sequence: frameSequence,
      media_generation: 1,
      frame_type: frameSequence === 1 ? 'keyframe' : 'delta',
      sender_sent_at_ms: Date.now(),
      data_base64: `frame-${frameSequence}`,
      ...overrides,
    };
    controllers.get(publisherPeerId).publishFrame(publisherPeerId, message);
    return frameId(message);
  }

  function receivedPeerIdsForFrame(id) {
    return Array.from(new Set(
      deliveries
        .filter((delivery) => delivery.frame_id === id)
        .map((delivery) => delivery.receiving_peer_id),
    )).sort();
  }

  function renderedPeerIdsForFrame(publisherPeerId, id) {
    return Array.from(new Set([publisherPeerId, ...receivedPeerIdsForFrame(id)])).sort();
  }

  function maxPeerFanoutForFrame(id) {
    const counts = new Map();
    for (const transmission of transmissions.filter((entry) => entry.frame_id === id)) {
      counts.set(transmission.from_peer_id, (counts.get(transmission.from_peer_id) || 0) + 1);
    }
    return Math.max(0, ...counts.values());
  }

  function loseNeighborEdge(leftPeerId, rightPeerId) {
    const linkKey = edgeKey(leftPeerId, rightPeerId);
    inactiveEdges.add(linkKey);
    controllers.get(leftPeerId)?.updateCarrierStateFromDataChannel(rightPeerId, 'closed', 'close');
    controllers.get(rightPeerId)?.updateCarrierStateFromDataChannel(leftPeerId, 'closed', 'close');
  }

  function dispose() {
    for (const controller of controllers.values()) {
      controller.dispose();
    }
  }

  return {
    peerIds: [...PEER_IDS],
    applyTopology,
    ringTopology,
    repairedTopologyAfterPeer2Peer3Loss,
    publishFrame,
    loseNeighborEdge,
    receivedPeerIdsForFrame,
    renderedPeerIdsForFrame,
    maxPeerFanoutForFrame,
    controllers,
    deliveries,
    transmissions,
    droppedTransmissions,
    appliedTopologyByPeerId,
    dispose,
  };
}
