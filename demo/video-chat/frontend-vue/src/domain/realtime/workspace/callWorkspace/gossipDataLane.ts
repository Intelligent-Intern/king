import { GOSSIP_DATA_LANE_CONFIG, VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../../lib/gossipmesh/featureFlags';
import { GossipController } from '../../../../lib/gossipmesh/gossipController';
import { GossipRtcDataChannelTransport } from '../../../../lib/gossipmesh/rtcDataChannelTransport';
import { createGossipNeighborLifecycle } from './gossipNeighborLifecycle';
import {
  gossipBinaryEnvelopeFromEncodedFrame,
  gossipFrameBinaryMessageFromMetadata,
  gossipFrameMetadataFromEncodedFrame,
  isGossipMediaFrameMessage,
  sfuFrameFromGossipMessage,
} from './gossipMediaFrameEnvelope';
import { decodeSfuBinaryFrameEnvelope } from '../../../../lib/sfu/framePayload';
import { strictPolicyEnabled } from './strictStabilityPolicy.ts';

export function createCallWorkspaceGossipDataLane({
  callbacks,
  policy = null,
}) {
  const {
    captureClientDiagnostic,
    currentUserId,
    activeRoomId,
    activeSocketCallId,
    activeCallId,
    defaultNativeIceServers = [],
    dynamicIceServers = null,
    handleSFUEncodedFrame,
    sendMediaRelayBinaryFrame = null,
    sendSocketBinaryFrame = null,
    sendSocketFrame,
  } = callbacks;
  let gossipDataChannelTransport = null;
  let gossipNeighborLifecycle = null;
  let liveGossipController = null;
  let liveGossipControllerKey = '';
  let liveGossipDirectPublisherKey = '';
  let unsubscribeLiveGossipDelivery = null;
  const assignedGossipNeighborIds = new Set();
  const openGossipDataChannelPeerIds = new Set();
  const liveGossipFrameSequenceByTrack = new Map();
  let lastGossipTelemetrySnapshotSentAtMs = 0;
  let lastGossipOpsLaneState = null;

  function strictGossipMediaDisabled(flag = 'disableGossipMediaRepair') {
    return strictPolicyEnabled(policy, flag);
  }

  function localPeerId() {
    return String(currentUserId() || '').trim();
  }

  function mediaCarrierDiagnosticPayload() {
    return {
      media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      media_carrier_diagnostics_label: VIDEOCHAT_MEDIA_CARRIER_CONFIG.diagnosticsLabel,
      gossip_may_publish_without_sfu: VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipMayPublishWithoutSfu,
      sfu_send_optional: VIDEOCHAT_MEDIA_CARRIER_CONFIG.sfuSendIsOptional,
    };
  }

  function roomId() {
    return String(activeRoomId() || '').trim() || 'lobby';
  }

  function callId() {
    return String(activeSocketCallId() || activeCallId() || '').trim() || 'call';
  }

  function currentGossipIceServers() {
    const dynamicServers = Array.isArray(dynamicIceServers?.value) ? dynamicIceServers.value : [];
    if (dynamicServers.length > 0) return dynamicServers;
    return Array.isArray(defaultNativeIceServers) ? defaultNativeIceServers : [];
  }

  function ensureGossipDataChannelTransport() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    if (strictGossipMediaDisabled()) return null;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return null;
    if (gossipDataChannelTransport) return gossipDataChannelTransport;

    gossipDataChannelTransport = new GossipRtcDataChannelTransport({
      localPeerId: peerId,
      onDataMessage: (msg, fromPeerId) => {
        const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary;
        if (!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG.receive) {
          captureClientDiagnostic({
            category: 'media',
            level: 'info',
            eventType: 'gossip_data_lane_shadow_message_dropped',
            code: 'gossip_data_lane_shadow_message_dropped',
            message: 'Gossip data lane received a frame while not active; dropping before media decode.',
            payload: {
              data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
              diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
              from_peer_id: String(fromPeerId || ''),
              message_type: String(msg?.type || ''),
            },
          });
          return;
        }
        const controller = ensureLiveGossipController();
        if (!controller) return;
        ensureLiveGossipPeer(String(fromPeerId || ''));
        controller.handleData(localPeerId(), msg, String(fromPeerId || ''));
      },
      onStateChange: (peerId, state, eventType) => {
        const normalizedPeerId = String(peerId || '');
        if (state === 'open' && eventType === 'open') {
          openGossipDataChannelPeerIds.add(normalizedPeerId);
        } else if (state === 'closed' || eventType === 'close' || eventType === 'error') {
          openGossipDataChannelPeerIds.delete(normalizedPeerId);
        }
        const controller = ensureLiveGossipController();
        if (controller && assignedGossipNeighborIds.has(normalizedPeerId)) {
          ensureLiveGossipPeer(normalizedPeerId);
        }
        captureClientDiagnostic({
          category: 'media',
          level: 'info',
          eventType: 'gossip_data_channel_state',
          code: 'gossip_data_channel_state',
          message: 'Gossip data channel state changed.',
          payload: {
            data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
            diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
            peer_id: normalizedPeerId,
            state: String(state || ''),
            event_type: String(eventType || ''),
          },
        });
      },
      onTelemetry: (event) => {
        const controller = liveGossipController;
        const peerId = String(event?.peerId || localPeerId()).trim();
        const counter = String(event?.counter || '').trim();
        if (!controller || peerId === '' || counter === '') return;
        controller.recordTransportTelemetry?.(peerId, counter, Math.max(1, Number(event?.increment || 1)));
        emitGossipTelemetrySnapshot('transport_telemetry');
      },
    });
    return gossipDataChannelTransport;
  }

  function ensureGossipNeighborLifecycle() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    if (strictGossipMediaDisabled()) return null;
    if (gossipNeighborLifecycle) return gossipNeighborLifecycle;
    gossipNeighborLifecycle = createGossipNeighborLifecycle({
      callbacks: {
        activeCallId: callId,
        activeRoomId: roomId,
        captureClientDiagnostic,
        currentUserId: localPeerId,
        getDataTransport: ensureGossipDataChannelTransport,
        getIceServers: currentGossipIceServers,
        allowAutomaticRenegotiate: () => !VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary
          && !strictGossipMediaDisabled('disableGossipNeighborRenegotiate'),
        onPeerConnectionState: handleGossipNeighborPeerConnectionState,
        sendSocketFrame,
      },
    });
    return gossipNeighborLifecycle;
  }

  function handleGossipNeighborPeerConnectionState(peerId, state, eventType) {
    const normalizedPeerId = String(peerId || '').trim();
    if (!assignedGossipNeighborIds.has(normalizedPeerId)) return false;
    const controller = ensureLiveGossipController();
    ensureLiveGossipPeer(normalizedPeerId);
    controller?.recordTransportTelemetry?.(localPeerId(), 'rtc_datachannel_sends', 0);
    return true;
  }

  function ensureLiveGossipController() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    if (strictGossipMediaDisabled()) return null;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return null;
    const controllerKey = `${roomId()}:${callId()}:${peerId}`;
    if (liveGossipController && liveGossipControllerKey === controllerKey) return liveGossipController;

    if (typeof unsubscribeLiveGossipDelivery === 'function') {
      unsubscribeLiveGossipDelivery();
      unsubscribeLiveGossipDelivery = null;
    }
    if (liveGossipController && liveGossipControllerKey !== controllerKey) {
      liveGossipController.dispose?.();
      liveGossipController = null;
      liveGossipControllerKey = '';
      assignedGossipNeighborIds.clear();
      openGossipDataChannelPeerIds.clear();
      gossipNeighborLifecycle?.teardown?.();
      gossipNeighborLifecycle = null;
      lastGossipTelemetrySnapshotSentAtMs = 0;
      gossipDataChannelTransport?.close();
      gossipDataChannelTransport = null;
    }
    liveGossipFrameSequenceByTrack.clear();

    const controller = new GossipController(roomId(), callId());
    controller.setDataLaneConfig(GOSSIP_DATA_LANE_CONFIG);
    const transport = ensureGossipDataChannelTransport();
    if (transport) {
      controller.setDataTransport(transport);
    }
    controller.addPeer(peerId);
    if (GOSSIP_DATA_LANE_CONFIG.receive) {
      unsubscribeLiveGossipDelivery = controller.onDataMessage((delivery) => {
        routeLiveGossipDeliveryToRemoteFrame(delivery);
      });
    }
    liveGossipController = controller;
    liveGossipControllerKey = controllerKey;
    return controller;
  }

  function ensureLiveGossipPeer(peerId) {
    const normalizedPeerId = String(peerId || '').trim();
    if (normalizedPeerId === '' || normalizedPeerId === '0') return false;
    const controller = ensureLiveGossipController();
    if (!controller) return false;
    if (!controller.getPeer(normalizedPeerId)) {
      controller.addPeer(normalizedPeerId);
    }
    return true;
  }

  function normalizeGossipTopologyHintPayload(payload) {
    const wrapperType = String(payload?.type || '').trim().toLowerCase();
    const payloadBody = payload?.payload && typeof payload.payload === 'object'
      ? payload.payload
      : null;
    const candidate = wrapperType === 'topology_hint'
      ? payload
      : payloadBody;
    if (!candidate || typeof candidate !== 'object') return null;
    const kind = String(candidate.kind || candidate.type || '').trim().toLowerCase();
    if (
      kind !== 'topology_hint'
      && kind !== 'gossip_topology'
      && kind !== 'gossip-topology'
      && wrapperType !== 'call/gossip-topology'
    ) return null;
    return {
      lane: 'ops',
      ...candidate,
      type: 'topology_hint',
    };
  }

  function topologyRepairRetiredPeerIdsForLocalPeer(topologyHint, peerId) {
    const repair = topologyHint?.repair && typeof topologyHint.repair === 'object' ? topologyHint.repair : null;
    if (!repair || repair.authoritative !== true) return [];
    const localPeerIdValue = String(peerId || '').trim();
    const retiredPeerIds = new Set(
      (Array.isArray(repair.retired_peer_ids) ? repair.retired_peer_ids : [])
        .map((value) => String(value || '').trim())
        .filter((value) => value !== '' && value !== localPeerIdValue)
    );
    for (const edge of Array.isArray(repair.retired_edges) ? repair.retired_edges : []) {
      const leftPeerId = String(edge?.peer_id || '').trim();
      const rightPeerId = String(edge?.neighbor_peer_id || edge?.lost_peer_id || '').trim();
      if (leftPeerId === localPeerIdValue && rightPeerId !== '') retiredPeerIds.add(rightPeerId);
      if (rightPeerId === localPeerIdValue && leftPeerId !== '') retiredPeerIds.add(leftPeerId);
    }
    return Array.from(retiredPeerIds);
  }

  function bindAssignedGossipNeighbors(topologyHint) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return 0;
    if (strictGossipMediaDisabled()) return 0;
    for (const peerId of assignedGossipNeighborIds) {
      ensureLiveGossipPeer(peerId);
    }
    return ensureGossipNeighborLifecycle()?.applyAssignedNeighbors(topologyHint, assignedGossipNeighborIds) || 0;
  }

  function emitGossipTelemetrySnapshot(reason = 'periodic') {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (strictGossipMediaDisabled()) return false;
    const controller = ensureLiveGossipController();
    const peerId = localPeerId();
    if (!controller || peerId === '' || peerId === '0') return false;
    const nowMs = Date.now();
    if ((nowMs - lastGossipTelemetrySnapshotSentAtMs) < 5000) return false;
    const snapshot = controller.createTelemetrySnapshot?.(peerId, {
      dataLaneMode: GOSSIP_DATA_LANE_CONFIG.mode,
      diagnosticsLabel: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      mediaCarrierMode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      rolloutStrategy: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
    });
    if (!snapshot) return false;
    const sent = sendSocketFrame({
      type: 'gossip/telemetry/snapshot',
      lane: 'ops',
      payload: {
        ...snapshot,
        reason: String(reason || 'periodic'),
      },
    });
    if (sent) {
      lastGossipTelemetrySnapshotSentAtMs = nowMs;
    }
    return sent;
  }

  function gossipTopologyNeighborUsesRtcDataChannel(topologyHint, peerId) {
    const normalizedPeerId = String(peerId || '').trim();
    if (normalizedPeerId === '') return false;
    return (Array.isArray(topologyHint?.neighbors) ? topologyHint.neighbors : []).some((neighbor) => (
      String(neighbor?.peer_id || '').trim() === normalizedPeerId
      && String(neighbor?.transport || '').trim().toLowerCase() === 'rtc_datachannel'
    ));
  }

  function applyGossipTopologyHint(payload) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return false;
    if (strictGossipMediaDisabled()) return false;
    const topologyHint = normalizeGossipTopologyHintPayload(payload);
    if (!topologyHint) return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    if (!controller) return false;

    const repairRetiredPeerIds = topologyRepairRetiredPeerIdsForLocalPeer(topologyHint, peerId);
    for (const retiredPeerId of repairRetiredPeerIds) {
      assignedGossipNeighborIds.delete(retiredPeerId);
      openGossipDataChannelPeerIds.delete(retiredPeerId);
      gossipNeighborLifecycle?.closePeer?.(retiredPeerId, 'repair_retired_edge');
    }

    controller.addPeer(peerId);
    for (const neighbor of Array.isArray(topologyHint.neighbors) ? topologyHint.neighbors : []) {
      const neighborId = String(neighbor?.peer_id || '').trim();
      if (neighborId === '' || neighborId === peerId) continue;
      controller.addPeer(neighborId);
    }

    const applied = controller.applyTopologyHint(peerId, topologyHint);
    if (!applied) return false;
    const peer = controller.getPeer(peerId);
    const previousAssignedNeighborIds = new Set(assignedGossipNeighborIds);
    assignedGossipNeighborIds.clear();
    for (const neighborId of peer?.neighbor_set || []) {
      const normalizedNeighborId = String(neighborId || '').trim();
      if (gossipTopologyNeighborUsesRtcDataChannel(topologyHint, normalizedNeighborId)) {
        assignedGossipNeighborIds.add(normalizedNeighborId);
      }
    }
    for (const previousPeerId of previousAssignedNeighborIds) {
      if (!assignedGossipNeighborIds.has(previousPeerId)) {
        openGossipDataChannelPeerIds.delete(previousPeerId);
        gossipNeighborLifecycle?.closePeer?.(previousPeerId, 'retired_by_topology');
      }
    }
    const boundCount = bindAssignedGossipNeighbors(topologyHint);
    emitGossipTelemetrySnapshot('topology_hint_applied');
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_topology_hint_applied',
      code: 'gossip_topology_hint_applied',
      message: 'Gossip topology hint applied to dedicated data-channel neighbor bindings.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        topology_epoch: Number(topologyHint.topology_epoch || 0),
        neighbor_count: assignedGossipNeighborIds.size,
        bound_dedicated_neighbor_count: boundCount,
        repair_authoritative: topologyHint?.repair?.authoritative === true,
        repair_retired_peer_count: repairRetiredPeerIds.length,
      },
    });
    return true;
  }

  function routeLiveGossipDeliveryToRemoteFrame(delivery) {
    const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary;
    if (!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (strictGossipMediaDisabled('disableGossipReceiveRecovery')) return false;
    const msg = delivery?.message || null;
    if (!isGossipMediaFrameMessage(msg)) return false;
    const frame = sfuFrameFromGossipMessage(msg, delivery);
    if (!frame) return false;
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_data_lane_frame_routed',
      code: 'gossip_data_lane_frame_routed',
      message: 'Gossip data lane accepted a frame and routed it to the remote decoder path.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        from_peer_id: String(delivery?.from_peer_id || ''),
        publisher_id: String(frame.publisherId || ''),
        publisher_user_id: String(frame.publisherUserId || ''),
        track_id: String(frame.trackId || ''),
        frame_sequence: Number(frame.frameSequence || 0),
        media_generation: Number(frame.mediaGeneration || 0),
        contract_version: String(frame.gossipContractVersion || ''),
        codec_id: String(frame.gossipCodecId || frame.codecId || ''),
        profile: String(frame.gossipProfile || ''),
        runtime_path: directGossipPrimary ? 'gossip_primary_direct' : String(frame.transportPath || ''),
        renderer_path: 'remote_decoded_canvas',
        renderer_entry: 'handleSFUEncodedFrame',
        decoded_pixels_required: true,
        frame_count_min: 1,
      },
    });
    routeGossipMediaFrameToRenderer(frame, directGossipPrimary);
    return true;
  }

  function routeGossipMediaFrameToRenderer(frame, directGossipPrimary) {
    handleSFUEncodedFrame(directGossipPrimary
      ? {
          ...frame,
          transportPath: 'gossip_primary_direct',
          protected: null,
          protectedFrame: null,
          protectionMode: 'transport_only',
        }
      : frame);
    return true;
  }

  function sendGossipFrameOverCallSocket(msg, frame = null) {
    if (!msg || typeof msg !== 'object') return false;
    if (typeof sendSocketBinaryFrame === 'function' && frame && typeof frame === 'object') {
      const binaryEnvelope = gossipBinaryEnvelopeFromEncodedFrame(frame, msg);
      if (!binaryEnvelope) return false;
      if (typeof sendMediaRelayBinaryFrame === 'function' && sendMediaRelayBinaryFrame(binaryEnvelope) === true) {
        return true;
      }
      if (sendSocketBinaryFrame(binaryEnvelope) === true) {
        return true;
      }
    }
    return false;
  }

  function handleGossipBinaryServerFrame(payload) {
    const input = payload instanceof ArrayBuffer
      ? payload
      : (ArrayBuffer.isView(payload) ? payload.buffer.slice(payload.byteOffset, payload.byteOffset + payload.byteLength) : null);
    if (!(input instanceof ArrayBuffer)) return false;
    const decoded = decodeSfuBinaryFrameEnvelope(input);
    if (!decoded?.payload || typeof decoded.payload !== 'object') return false;
    const body = {
      ...decoded.payload,
      data_binary: decoded.payloadBytes,
    };
    const frame = sfuFrameFromGossipMessage(body, {
      from_peer_id: String(body.publisher_user_id || body.publisher_id || ''),
    });
    if (!frame) return false;
    handleSFUEncodedFrame({
      ...frame,
      data: decoded.payloadBytes,
      transportPath: 'gossip_server_fanout',
      protected: null,
      protectedFrame: null,
      protectionMode: 'transport_only',
    });
    return true;
  }

  function handleGossipServerFrame(payload) {
    const body = payload?.payload && typeof payload.payload === 'object' ? payload.payload : payload;
    const frame = sfuFrameFromGossipMessage(body, {
      from_peer_id: String(payload?.sender?.user_id || body?.publisher_user_id || body?.publisher_id || ''),
    });
    if (!frame) return false;
    handleSFUEncodedFrame({
      ...frame,
      transportPath: 'gossip_server_fanout',
      protected: null,
      protectedFrame: null,
      protectionMode: 'transport_only',
    });
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_server_frame_routed',
      code: 'gossip_server_frame_routed',
      message: 'Gossip server fanout frame was routed to the remote decoder path.',
      payload: {
        publisher_id: String(frame.publisherId || ''),
        publisher_user_id: String(frame.publisherUserId || ''),
        track_id: String(frame.trackId || ''),
        frame_sequence: Number(frame.frameSequence || 0),
        runtime_path: 'gossip_server_fanout',
      },
    });
    return true;
  }

  function directGossipEgressCanAcceptLocalFrame(controller, peerId) {
    if (!controller || !GOSSIP_DATA_LANE_CONFIG.publish || !gossipDataChannelTransport) return false;
    const peer = controller.getPeer?.(peerId);
    const neighbors = Array.isArray(peer?.neighbor_set) ? peer.neighbor_set : [];
    return neighbors.some((neighborId) => {
      const normalizedNeighborId = String(neighborId || '').trim();
      return assignedGossipNeighborIds.has(normalizedNeighborId)
        && openGossipDataChannelPeerIds.has(normalizedNeighborId);
    });
  }

  function publishLocalEncodedFrameToGossip(frame) {
    if (strictGossipMediaDisabled('disableGossipPublish')) return false;
    const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary;
    if (!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG.publish) {
      return recordGossipShadowWouldPublish(frame, 'publish_disabled');
    }
    if (!frame || typeof frame !== 'object') return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const publisherKey = `${roomId()}:${callId()}:${peerId}`;
    if (directGossipPrimary && liveGossipDirectPublisherKey !== publisherKey) {
      liveGossipFrameSequenceByTrack.clear();
      liveGossipDirectPublisherKey = publisherKey;
    }
    const msg = gossipFrameMetadataFromEncodedFrame(frame, liveGossipFrameSequenceByTrack, {
      peerId,
      callId: callId(),
      roomId: roomId(),
      plainRelay: directGossipPrimary,
    });
    if (!msg) return false;
    const serverFanoutSent = sendGossipFrameOverCallSocket(msg, frame);
    const controller = ensureLiveGossipController();
    const directGossipEgressAccepted = directGossipPrimary
      ? directGossipEgressCanAcceptLocalFrame(controller, peerId)
      : Boolean(controller);
    const gossipPrimaryEgressAvailable = serverFanoutSent || directGossipEgressAccepted;
    if (directGossipPrimary && !gossipPrimaryEgressAvailable) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        code: 'gossip_server_fanout_socket_unavailable',
        message: 'Gossip primary frame was encoded while no server fanout or direct gossip egress was available.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          room_id: roomId(),
          call_id: callId(),
          local_peer_id: peerId,
          track_id: String(msg.track_id || ''),
          frame_sequence: Number(msg.frame_sequence || 0),
          assigned_neighbor_count: assignedGossipNeighborIds.size,
          open_data_channel_neighbor_count: openGossipDataChannelPeerIds.size,
          direct_gossip_egress_accepted: false,
        },
        eventType: 'gossip_server_fanout_socket_unavailable',
        immediate: true,
      });
      return false;
    }
    if (controller && directGossipEgressAccepted) {
      const directMsg = gossipFrameBinaryMessageFromMetadata(frame, msg);
      if (directMsg) {
        controller.publishFrame(peerId, directMsg);
      }
      emitGossipTelemetrySnapshot('local_publish');
    }
    if (directGossipPrimary) {
      return gossipPrimaryEgressAvailable;
    }
    return serverFanoutSent || Boolean(controller);
  }
  function gossipDataPlaneAllowed() {
    return GOSSIP_DATA_LANE_CONFIG.mode === 'active'
      && GOSSIP_DATA_LANE_CONFIG.publish
      && GOSSIP_DATA_LANE_CONFIG.receive
      && !strictGossipMediaDisabled();
  }

  function recordGossipShadowWouldPublish(frame, reason) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !frame || typeof frame !== 'object') return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    controller?.recordTransportTelemetry?.(peerId, 'would_publish_frames', 1);
    const backendVisibleBacktrace = VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary || GOSSIP_DATA_LANE_CONFIG.mode === 'active';
    captureClientDiagnostic({
      category: 'media',
      level: backendVisibleBacktrace ? 'warning' : 'info',
      eventType: 'gossip_data_lane_shadow_would_publish',
      code: 'gossip_data_lane_shadow_would_publish',
      message: 'Gossip data lane recorded a frame that would have been published after SFU baseline send; media was not published.',
      payload: {
        ...mediaCarrierDiagnosticPayload(),
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
        media_carrier_diagnostics_label: VIDEOCHAT_MEDIA_CARRIER_CONFIG.diagnosticsLabel,
        reason: String(reason || 'shadow_observe'),
        local_peer_id: peerId,
        assigned_neighbor_count: assignedGossipNeighborIds.size,
        ops_lane_authority: 'server_head',
        client_health_gate: false,
        has_rollout_gate_ack: Boolean(lastGossipOpsLaneState),
        publisher_id: String(frame.publisherId || peerId),
        publisher_user_id: String(frame.publisherUserId || peerId),
        track_id: String(frame.trackId || ''),
        frame_type: String(frame.type || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
        codec_id: String(frame.codecId || ''),
        runtime_id: String(frame.runtimeId || ''),
        layout_mode: String(frame.layoutMode || 'full_frame'),
        layer_id: String(frame.layerId || 'full'),
      },
      immediate: backendVisibleBacktrace,
    });
    return false;
  }

  function pruneGossipNeighborForUserId(userId, reason = 'target_not_in_room') {
    const peerId = String(userId || '').trim();
    if (peerId === '' || peerId === '0') return false;
    if (!assignedGossipNeighborIds.has(peerId)) return false;

    assignedGossipNeighborIds.delete(peerId);
    gossipNeighborLifecycle?.closePeer?.(peerId, String(reason || 'target_not_in_room'));
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'gossip_assigned_neighbor_pruned',
      code: 'gossip_assigned_neighbor_pruned',
      message: 'Stale assigned gossip neighbor was pruned after the signaling layer reported the target was no longer in the room.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        peer_id: peerId,
        reason: String(reason || 'target_not_in_room'),
      },
    });
    return true;
  }

  function applyGossipTelemetryAck(payload) {
    const type = String(payload?.type || '').trim().toLowerCase();
    if (type !== 'gossip/telemetry/ack') return false;
    if (strictGossipMediaDisabled()) return true;
    lastGossipOpsLaneState = {
      kind: 'gossip_server_head_ops_state',
      received_at_ms: Date.now(),
      server_head_authoritative: true,
      data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
      media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      decision: String(payload?.decision || payload?.rollout_gate?.decision || 'server_head_ops_ack'),
      active_allowed: payload?.active_allowed === true || payload?.rollout_gate?.active_allowed === true,
    };
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_server_head_ops_state',
      code: 'gossip_server_head_ops_state',
      message: 'Server-head Gossip ops-lane state was received; the client does not run health gates.',
      payload: {
        ...lastGossipOpsLaneState,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        client_health_gate: false,
        client_topology_repair: false,
        client_recovery_request: false,
      },
    });
    return true;
  }

  function getGossipRolloutGateState() {
    return lastGossipOpsLaneState ? { ...lastGossipOpsLaneState } : null;
  }

  function getAssignedGossipNeighborCount() {
    return assignedGossipNeighborIds.size;
  }

  function teardownGossipDataLane() {
    if (typeof unsubscribeLiveGossipDelivery === 'function') {
      unsubscribeLiveGossipDelivery();
      unsubscribeLiveGossipDelivery = null;
    }
    liveGossipController?.dispose?.();
    liveGossipController = null;
    liveGossipControllerKey = '';
    liveGossipFrameSequenceByTrack.clear();
    assignedGossipNeighborIds.clear();
    openGossipDataChannelPeerIds.clear();
    lastGossipOpsLaneState = null;
    lastGossipTelemetrySnapshotSentAtMs = 0;
    gossipNeighborLifecycle?.teardown?.();
    gossipNeighborLifecycle = null;
    gossipDataChannelTransport?.close();
    gossipDataChannelTransport = null;
  }

  return {
    applyGossipTelemetryAck,
    applyGossipTopologyHint,
    getAssignedGossipNeighborCount,
    getGossipRolloutGateState,
    handleGossipBinaryServerFrame,
    handleGossipNeighborSignal: (...args) => ensureGossipNeighborLifecycle()?.handleGossipNeighborSignal?.(...args) || false,
    handleGossipServerFrame,
    pruneGossipNeighborForUserId,
    publishLocalEncodedFrameToGossip,
    teardownGossipDataLane,
  };
}
