import { arrayBufferToBase64Url, base64UrlToArrayBuffer } from '../../../../lib/sfu/framePayload';
import { GOSSIP_DATA_LANE_CONFIG } from '../../../../lib/gossipmesh/featureFlags';
import { GossipController } from '../../../../lib/gossipmesh/gossipController';
import { deriveGossipRolloutGateState } from '../../../../lib/gossipmesh/rolloutGate';
import { GossipRtcDataChannelTransport } from '../../../../lib/gossipmesh/rtcDataChannelTransport';

export function createCallWorkspaceGossipDataLane({
  callbacks,
  refs,
}) {
  const {
    captureClientDiagnostic,
    currentUserId,
    activeRoomId,
    activeSocketCallId,
    activeCallId,
    handleSFUEncodedFrame,
    sendSocketFrame,
  } = callbacks;
  const {
    nativePeerConnectionsRef,
  } = refs;

  let gossipDataChannelTransport = null;
  let liveGossipController = null;
  let liveGossipControllerKey = '';
  let unsubscribeLiveGossipDelivery = null;
  const assignedGossipNativeNeighborIds = new Set();
  const liveGossipFrameSequenceByTrack = new Map();
  const gossipTopologyRepairRequestedAtByPeerId = new Map();
  let lastGossipTelemetrySnapshotSentAtMs = 0;
  let lastGossipRolloutGateState = null;

  function localPeerId() {
    return String(currentUserId() || '').trim();
  }

  function roomId() {
    return String(activeRoomId() || '').trim() || 'lobby';
  }

  function callId() {
    return String(activeSocketCallId() || activeCallId() || '').trim() || 'call';
  }

  function ensureGossipDataChannelTransport() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return null;
    if (gossipDataChannelTransport) return gossipDataChannelTransport;

    gossipDataChannelTransport = new GossipRtcDataChannelTransport({
      localPeerId: peerId,
      onDataMessage: (msg, fromPeerId) => {
        if (!GOSSIP_DATA_LANE_CONFIG.receive) {
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
        const controller = ensureLiveGossipController();
        if (controller && assignedGossipNativeNeighborIds.has(normalizedPeerId)) {
          ensureLiveGossipPeer(normalizedPeerId);
          controller.updateCarrierStateFromDataChannel(normalizedPeerId, state, eventType);
        }
        if ((state === 'closed' || eventType === 'error') && assignedGossipNativeNeighborIds.has(normalizedPeerId)) {
          requestGossipTopologyRepair(peerId, eventType);
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

  function ensureLiveGossipController() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
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
      assignedGossipNativeNeighborIds.clear();
      gossipTopologyRepairRequestedAtByPeerId.clear();
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

  function bindAssignedGossipNativeNeighbors() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return 0;
    let boundCount = 0;
    for (const peerId of assignedGossipNativeNeighborIds) {
      const peer = nativePeerConnectionsRef.value.get(Number(peerId));
      if (bindGossipDataChannelForNativePeer(peer)) {
        boundCount += 1;
      }
    }
    return boundCount;
  }

  function requestGossipTopologyRepair(peerId, reason) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (!assignedGossipNativeNeighborIds.has(String(peerId || ''))) return false;
    const normalizedPeerId = String(peerId || '').trim();
    if (normalizedPeerId === '') return false;
    const nowMs = Date.now();
    const lastRequestedAtMs = Number(gossipTopologyRepairRequestedAtByPeerId.get(normalizedPeerId) || 0);
    if ((nowMs - lastRequestedAtMs) < 3000) return false;
    gossipTopologyRepairRequestedAtByPeerId.set(normalizedPeerId, nowMs);
    const controller = ensureLiveGossipController();
    controller?.recordTransportTelemetry?.(localPeerId(), 'topology_repairs_requested', 1);
    const sent = sendSocketFrame({
      type: 'gossip/topology-repair/request',
      lane: 'ops',
      payload: {
        kind: 'gossip_topology_repair_request',
        room_id: String(activeRoomId() || '').trim(),
        call_id: String(activeSocketCallId() || activeCallId() || '').trim(),
        peer_id: localPeerId(),
        lost_peer_id: String(peerId || ''),
        lost_neighbor_peer_id: normalizedPeerId,
        reason: String(reason || ''),
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      },
    });
    captureClientDiagnostic({
      category: 'media',
      level: sent ? 'warning' : 'info',
      eventType: 'gossip_topology_repair_requested',
      code: 'gossip_topology_repair_requested',
      message: 'Gossip topology repair was requested after an assigned data-channel neighbor changed carrier state.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        peer_id: normalizedPeerId,
        reason: String(reason || 'gossip_data_channel_lost'),
        sent,
      },
    });
    return sent;
  }

  function emitGossipTelemetrySnapshot(reason = 'periodic') {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    const controller = ensureLiveGossipController();
    const peerId = localPeerId();
    if (!controller || peerId === '' || peerId === '0') return false;
    const nowMs = Date.now();
    if ((nowMs - lastGossipTelemetrySnapshotSentAtMs) < 5000) return false;
    const snapshot = controller.createTelemetrySnapshot?.(peerId, {
      dataLaneMode: GOSSIP_DATA_LANE_CONFIG.mode,
      diagnosticsLabel: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      rolloutStrategy: 'sfu_first_explicit',
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
    const topologyHint = normalizeGossipTopologyHintPayload(payload);
    if (!topologyHint) return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    if (!controller) return false;

    controller.addPeer(peerId);
    for (const neighbor of Array.isArray(topologyHint.neighbors) ? topologyHint.neighbors : []) {
      const neighborId = String(neighbor?.peer_id || '').trim();
      if (neighborId === '' || neighborId === peerId) continue;
      controller.addPeer(neighborId);
    }

    const applied = controller.applyTopologyHint(peerId, topologyHint);
    if (!applied) return false;
    const peer = controller.getPeer(peerId);
    const previousAssignedNeighborIds = new Set(assignedGossipNativeNeighborIds);
    assignedGossipNativeNeighborIds.clear();
    for (const neighborId of peer?.neighbor_set || []) {
      const normalizedNeighborId = String(neighborId || '').trim();
      if (gossipTopologyNeighborUsesRtcDataChannel(topologyHint, normalizedNeighborId)) {
        assignedGossipNativeNeighborIds.add(normalizedNeighborId);
      }
    }
    for (const previousPeerId of previousAssignedNeighborIds) {
      if (!assignedGossipNativeNeighborIds.has(previousPeerId)) {
        closeGossipDataChannelForNativePeer(previousPeerId);
      }
    }
    const boundCount = bindAssignedGossipNativeNeighbors();
    emitGossipTelemetrySnapshot('topology_hint_applied');
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_topology_hint_applied',
      code: 'gossip_topology_hint_applied',
      message: 'Gossip topology hint applied to native data-channel neighbor bindings.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        topology_epoch: Number(topologyHint.topology_epoch || 0),
        neighbor_count: assignedGossipNativeNeighborIds.size,
        bound_native_neighbor_count: boundCount,
      },
    });
    return true;
  }

  function routeLiveGossipDeliveryToRemoteFrame(delivery) {
    if (!GOSSIP_DATA_LANE_CONFIG.receive) return false;
    const msg = delivery?.message || null;
    if (!msg || msg.type !== 'sfu/frame') return false;
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
      },
    });
    handleSFUEncodedFrame(frame);
    return true;
  }

  function publishLocalEncodedFrameToGossip(frame) {
    if (!GOSSIP_DATA_LANE_CONFIG.publish) return false;
    const controller = ensureLiveGossipController();
    if (!controller || !frame || typeof frame !== 'object') return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const trackId = String(frame.trackId || '').trim();
    if (trackId === '') return false;

    const sequenceKey = `${peerId}:${trackId}`;
    const frameSequence = Math.max(1, Number(liveGossipFrameSequenceByTrack.get(sequenceKey) || 0) + 1);
    liveGossipFrameSequenceByTrack.set(sequenceKey, frameSequence);
    const dataBuffer = normalizeGossipFrameArrayBuffer(frame.data);
    const dataBase64 = dataBuffer.byteLength > 0 ? arrayBufferToBase64Url(dataBuffer) : '';
    const protectedFrame = String(frame.protectedFrame || '').trim();
    const protectionMode = protectedFrame !== ''
      ? String(frame.protectionMode || 'protected')
      : String(frame.protectionMode || 'transport_only');
    const msg = {
      type: 'sfu/frame',
      protocol_version: 2,
      publisher_id: String(frame.publisherId || peerId),
      publisher_user_id: String(frame.publisherUserId || peerId),
      track_id: trackId,
      timestamp: frame.timestamp,
      frame_type: String(frame.type || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
      frame_sequence: frameSequence,
      sender_sent_at_ms: Date.now(),
      codec_id: String(frame.codecId || ''),
      runtime_id: String(frame.runtimeId || ''),
      protection_mode: protectionMode,
      data_base64: dataBase64,
      protected_frame: protectedFrame,
      payload_chars: protectedFrame !== '' ? protectedFrame.length : dataBase64.length,
      chunk_count: 1,
      layout_mode: String(frame.layoutMode || 'full_frame'),
      layer_id: String(frame.layerId || 'full'),
      cache_epoch: Math.max(0, Number(frame.cacheEpoch || 0)),
      tile_columns: Math.max(0, Number(frame.tileColumns || 0)),
      tile_rows: Math.max(0, Number(frame.tileRows || 0)),
      tile_width: Math.max(0, Number(frame.tileWidth || 0)),
      tile_height: Math.max(0, Number(frame.tileHeight || 0)),
      tile_indices: Array.isArray(frame.tileIndices) ? frame.tileIndices : [],
      roi_norm_x: Math.max(0, Number(frame.roiNormX || 0)),
      roi_norm_y: Math.max(0, Number(frame.roiNormY || 0)),
      roi_norm_width: Math.max(0, Number(frame.roiNormWidth || 0)),
      roi_norm_height: Math.max(0, Number(frame.roiNormHeight || 0)),
    };
    controller.publishFrame(peerId, msg);
    emitGossipTelemetrySnapshot('local_publish');
    return true;
  }

  function normalizeGossipFrameArrayBuffer(data) {
    if (data instanceof ArrayBuffer) return data;
    if (ArrayBuffer.isView(data)) {
      return data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);
    }
    return new ArrayBuffer(0);
  }

  function sfuFrameFromGossipMessage(msg, delivery) {
    const publisherId = String(msg.publisherId || msg.publisher_id || msg.publisher_user_id || '').trim();
    const trackId = String(msg.trackId || msg.track_id || '').trim();
    if (publisherId === '' || trackId === '') return null;
    const dataBase64 = String(msg.dataBase64 || msg.data_base64 || msg.payload || '').trim();
    const frameSequence = Math.max(0, Number(msg.frameSequence ?? msg.frame_sequence ?? 0));
    const mediaGeneration = Math.max(0, Number(msg.mediaGeneration ?? msg.media_generation ?? 0));
    return {
      publisherId,
      publisherUserId: String(msg.publisherUserId || msg.publisher_user_id || msg.publisher_id || ''),
      trackId,
      timestamp: msg.timestamp,
      data: dataBase64 !== ''
        ? base64UrlToArrayBuffer(dataBase64)
        : (msg.data instanceof ArrayBuffer
          ? msg.data
          : (Array.isArray(msg.data) ? new Uint8Array(msg.data).buffer : new ArrayBuffer(0))),
      dataBase64: dataBase64 || null,
      type: String(msg.frameType || msg.frame_type || msg.frameType || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
      protected: msg.protected && typeof msg.protected === 'object' ? msg.protected : null,
      protectedFrame: String(msg.protectedFrame || msg.protected_frame || ''),
      protectionMode: String(msg.protectionMode || msg.protection_mode || '') === 'required'
        ? 'required'
        : (msg.protectedFrame || msg.protected_frame ? 'protected' : 'transport_only'),
      protocolVersion: Math.max(1, Number(msg.protocolVersion ?? msg.protocol_version ?? 1)),
      frameSequence,
      mediaGeneration,
      payloadChars: Math.max(0, Number(msg.payloadChars ?? msg.payload_chars ?? dataBase64.length)),
      chunkCount: Math.max(1, Number(msg.chunkCount ?? msg.chunk_count ?? 1)),
      frameId: String(msg.frameId || msg.frame_id || delivery?.frame_id || ''),
      senderSentAtMs: Math.max(0, Number(msg.senderSentAtMs ?? msg.sender_sent_at_ms ?? 0)),
      codecId: String(msg.codecId || msg.codec_id || ''),
      runtimeId: String(msg.runtimeId || msg.runtime_id || ''),
      videoLayer: String(msg.videoLayer || msg.video_layer || ''),
      outgoingVideoQualityProfile: String(msg.outgoingVideoQualityProfile || msg.outgoing_video_quality_profile || ''),
      layoutMode: String(msg.layoutMode || msg.layout_mode || 'full_frame'),
      layerId: String(msg.layerId || msg.layer_id || 'full'),
      cacheEpoch: Math.max(0, Number(msg.cacheEpoch ?? msg.cache_epoch ?? 0)),
      tileColumns: Math.max(0, Number(msg.tileColumns ?? msg.tile_columns ?? 0)),
      tileRows: Math.max(0, Number(msg.tileRows ?? msg.tile_rows ?? 0)),
      tileWidth: Math.max(0, Number(msg.tileWidth ?? msg.tile_width ?? 0)),
      tileHeight: Math.max(0, Number(msg.tileHeight ?? msg.tile_height ?? 0)),
      tileIndices: Array.isArray(msg.tileIndices) ? msg.tileIndices : (Array.isArray(msg.tile_indices) ? msg.tile_indices : []),
      roiNormX: Math.max(0, Number(msg.roiNormX ?? msg.roi_norm_x ?? 0)),
      roiNormY: Math.max(0, Number(msg.roiNormY ?? msg.roi_norm_y ?? 0)),
      roiNormWidth: Math.max(0, Number(msg.roiNormWidth ?? msg.roi_norm_width ?? 0)),
      roiNormHeight: Math.max(0, Number(msg.roiNormHeight ?? msg.roi_norm_height ?? 0)),
      transportPath: 'gossip_rtc_datachannel',
    };
  }

  function bindGossipDataChannelForNativePeer(peer) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return false;
    if (!peer?.pc) return false;
    const peerId = String(peer.userId || '').trim();
    if (peerId === '' || peerId === '0') return false;
    if (!assignedGossipNativeNeighborIds.has(peerId)) return false;
    const transport = ensureGossipDataChannelTransport();
    if (!transport) return false;
    ensureLiveGossipPeer(peerId);
    const channel = transport.bindPeerConnection(peerId, peer.pc, Boolean(peer.initiator));
    peer.gossipDataLaneMode = GOSSIP_DATA_LANE_CONFIG.mode;
    peer.gossipDataChannelState = String(channel?.readyState || 'pending');
    return true;
  }

  function closeGossipDataChannelForNativePeer(peerId) {
    gossipDataChannelTransport?.close(String(peerId || ''));
  }

  function applyGossipTelemetryAck(payload) {
    const type = String(payload?.type || '').trim().toLowerCase();
    if (type !== 'gossip/telemetry/ack') return false;
    const gateState = deriveGossipRolloutGateState(payload, {
      mode: GOSSIP_DATA_LANE_CONFIG.mode,
    });
    lastGossipRolloutGateState = gateState;
    captureClientDiagnostic({
      category: 'media',
      level: gateState.active_allowed ? 'info' : 'warning',
      eventType: 'gossip_rollout_gate_state',
      code: 'gossip_rollout_gate_state',
      message: 'Gossip rollout gate evaluated sanitized telemetry aggregates.',
      payload: {
        ...gateState,
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      },
    });
    return true;
  }

  function getGossipRolloutGateState() {
    return lastGossipRolloutGateState ? { ...lastGossipRolloutGateState } : null;
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
    assignedGossipNativeNeighborIds.clear();
    gossipTopologyRepairRequestedAtByPeerId.clear();
    lastGossipRolloutGateState = null;
    lastGossipTelemetrySnapshotSentAtMs = 0;
    gossipDataChannelTransport?.close();
    gossipDataChannelTransport = null;
  }

  return {
    applyGossipTelemetryAck,
    applyGossipTopologyHint,
    bindGossipDataChannelForNativePeer,
    closeGossipDataChannelForNativePeer,
    getGossipRolloutGateState,
    publishLocalEncodedFrameToGossip,
    teardownGossipDataLane,
  };
}
