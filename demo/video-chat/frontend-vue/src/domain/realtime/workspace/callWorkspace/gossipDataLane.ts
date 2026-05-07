import { arrayBufferToBase64Url, base64UrlToArrayBuffer } from '../../../../lib/sfu/framePayload';
import { GOSSIP_DATA_LANE_CONFIG, MEDIA_CARRIER_CONFIG } from '../../../../lib/gossipmesh/featureFlags';
import { GossipController } from '../../../../lib/gossipmesh/gossipController';
import { deriveGossipRolloutGateState } from '../../../../lib/gossipmesh/rolloutGate';
import { GossipRtcDataChannelTransport } from '../../../../lib/gossipmesh/rtcDataChannelTransport';

export function createCallWorkspaceGossipDataLane({
  callbacks,
  constants = {},
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
    dynamicIceServers,
  } = refs;
  const {
    defaultNativeIceServers = [],
  } = constants;

  let gossipDataChannelTransport = null;
  let liveGossipController = null;
  let liveGossipControllerKey = '';
  let unsubscribeLiveGossipDelivery = null;
  const assignedGossipNeighborIds = new Set();
  const dedicatedGossipPeerConnections = new Map();
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

  function normalizePeerId(value) {
    return String(value || '').trim();
  }

  function numericPeerId(value) {
    const numeric = Number(value);
    return Number.isInteger(numeric) && numeric > 0 ? numeric : 0;
  }

  function gossipPeerInitiatesConnection(peerId) {
    const local = normalizePeerId(localPeerId());
    const remote = normalizePeerId(peerId);
    if (local === '' || remote === '') return false;
    const localNumeric = numericPeerId(local);
    const remoteNumeric = numericPeerId(remote);
    if (localNumeric > 0 && remoteNumeric > 0) return localNumeric < remoteNumeric;
    return local < remote;
  }

  function currentGossipIceServers() {
    const dynamicServers = Array.isArray(dynamicIceServers?.value) ? dynamicIceServers.value : [];
    if (dynamicServers.length > 0) return dynamicServers;
    return Array.isArray(defaultNativeIceServers) ? defaultNativeIceServers : [];
  }

  function gossipRtcConfig() {
    return {
      iceCandidatePoolSize: 4,
      iceServers: currentGossipIceServers(),
    };
  }

  function normalizeGossipSdpForRemoteDescription(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    const normalized = raw.replace(/\r?\n/g, '\r\n');
    return normalized.endsWith('\r\n') ? normalized : `${normalized}\r\n`;
  }

  function gossipSignalPayload(kind, payload = {}) {
    return {
      ...payload,
      kind,
      runtime_path: 'gossipmesh',
      room_id: roomId(),
      call_id: callId(),
      data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
      diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      media_carrier_mode: MEDIA_CARRIER_CONFIG.mode,
    };
  }

  function sendDedicatedGossipSignal(type, targetPeerId, kind, payload = {}) {
    const targetUserId = numericPeerId(targetPeerId);
    if (targetUserId <= 0) return false;
    return sendSocketFrame({
      type,
      target_user_id: targetUserId,
      payload: gossipSignalPayload(kind, payload),
    });
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
        if (!gossipActiveDataLaneAllowed()) {
          captureClientDiagnostic({
            category: 'media',
            level: 'info',
            eventType: 'gossip_data_lane_shadow_message_dropped',
            code: 'gossip_data_lane_shadow_message_dropped',
            message: 'Gossip data lane received a frame while rollout gates are observational; dropping before media decode.',
            payload: {
              data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
              diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
              from_peer_id: String(fromPeerId || ''),
              message_type: String(msg?.type || ''),
              gate_decision: String(lastGossipRolloutGateState?.decision || 'no_rollout_gate_ack'),
              active_allowed: Boolean(lastGossipRolloutGateState?.active_allowed),
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
        if (controller && assignedGossipNeighborIds.has(normalizedPeerId)) {
          ensureLiveGossipPeer(normalizedPeerId);
          controller.updateCarrierStateFromDataChannel(normalizedPeerId, state, eventType);
        }
        if ((state === 'closed' || eventType === 'error') && assignedGossipNeighborIds.has(normalizedPeerId)) {
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
      closeAllDedicatedGossipPeerConnections('controller_key_changed');
      assignedGossipNeighborIds.clear();
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

  function ensureAssignedGossipNeighborConnections(reason = 'topology_hint') {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return 0;
    let ensuredCount = 0;
    for (const peerId of assignedGossipNeighborIds) {
      if (ensureDedicatedGossipNeighborConnection(peerId, reason)) {
        ensuredCount += 1;
      }
    }
    return ensuredCount;
  }

  function ensureDedicatedGossipNeighborConnection(peerId, reason = 'topology_hint') {
    const normalizedPeerId = normalizePeerId(peerId);
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return false;
    if (normalizedPeerId === '' || normalizedPeerId === '0' || normalizedPeerId === localPeerId()) return false;
    if (!assignedGossipNeighborIds.has(normalizedPeerId)) return false;
    if (typeof RTCPeerConnection !== 'function') {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_dedicated_peer_connection_unavailable',
        code: 'gossip_dedicated_peer_connection_unavailable',
        message: 'Dedicated gossip neighbor connection could not be created because RTCPeerConnection is unavailable.',
        payload: {
          peer_id: normalizedPeerId,
          reason: String(reason || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      return false;
    }

    const existing = dedicatedGossipPeerConnections.get(normalizedPeerId);
    if (existing?.pc && String(existing.pc.signalingState || '').trim().toLowerCase() !== 'closed') {
      if (!existing.bound) {
        const transport = ensureGossipDataChannelTransport();
        if (transport) {
          transport.bindPeerConnection(normalizedPeerId, existing.pc, Boolean(existing.initiator));
          existing.bound = true;
        }
      }
      if (existing.initiator && !existing.pc.localDescription) {
        void negotiateDedicatedGossipNeighbor(existing, reason);
      }
      return true;
    }

    let pc = null;
    try {
      pc = new RTCPeerConnection(gossipRtcConfig());
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'error',
        eventType: 'gossip_dedicated_peer_connection_create_failed',
        code: 'gossip_dedicated_peer_connection_create_failed',
        message: 'Dedicated gossip neighbor connection creation failed.',
        payload: {
          peer_id: normalizedPeerId,
          reason: String(reason || ''),
          error: String(error?.message || error || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
        immediate: true,
      });
      return false;
    }

    const entry = {
      peerId: normalizedPeerId,
      pc,
      initiator: gossipPeerInitiatesConnection(normalizedPeerId),
      pendingIce: [],
      negotiating: false,
      bound: false,
      createdAtMs: Date.now(),
    };
    dedicatedGossipPeerConnections.set(normalizedPeerId, entry);

    pc.addEventListener('icecandidate', (event) => {
      if (!event?.candidate) return;
      sendDedicatedGossipSignal('call/ice', normalizedPeerId, 'gossip_webrtc_ice', {
        candidate: typeof event.candidate.toJSON === 'function'
          ? event.candidate.toJSON()
          : event.candidate,
      });
    });
    pc.addEventListener('connectionstatechange', () => {
      handleDedicatedGossipPeerState(entry, 'connectionstatechange');
    });
    pc.addEventListener('iceconnectionstatechange', () => {
      handleDedicatedGossipPeerState(entry, 'iceconnectionstatechange');
    });
    if (entry.initiator) {
      pc.addEventListener('negotiationneeded', () => {
        void negotiateDedicatedGossipNeighbor(entry, 'negotiationneeded');
      });
    }

    const transport = ensureGossipDataChannelTransport();
    if (transport) {
      transport.bindPeerConnection(normalizedPeerId, pc, entry.initiator);
      entry.bound = true;
    }
    ensureLiveGossipPeer(normalizedPeerId);
    if (entry.initiator) {
      void negotiateDedicatedGossipNeighbor(entry, reason);
    }
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_dedicated_peer_connection_created',
      code: 'gossip_dedicated_peer_connection_created',
      message: 'Dedicated gossip-only RTCPeerConnection created for a server-assigned neighbor.',
      payload: {
        peer_id: normalizedPeerId,
        initiator: entry.initiator,
        reason: String(reason || ''),
        ice_server_count: currentGossipIceServers().length,
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      },
    });
    return true;
  }

  function handleDedicatedGossipPeerState(entry, eventType) {
    if (!entry?.pc) return;
    const peerId = normalizePeerId(entry.peerId);
    const connectionState = String(entry.pc.connectionState || '').trim().toLowerCase();
    const iceConnectionState = String(entry.pc.iceConnectionState || '').trim().toLowerCase();
    const states = [connectionState, iceConnectionState];
    const effectiveState = states.includes('failed')
      ? 'failed'
      : (states.includes('closed')
        ? 'closed'
        : (states.includes('disconnected')
          ? 'disconnected'
          : (states.includes('connected') || states.includes('completed') ? 'connected' : (connectionState || iceConnectionState))));
    const controller = ensureLiveGossipController();
    if (controller && assignedGossipNeighborIds.has(peerId)) {
      if (effectiveState === 'connected' || effectiveState === 'completed') {
        controller.setCarrierState?.(peerId, 'connected', `gossip_peer_${eventType}`);
      } else if (effectiveState === 'failed' || effectiveState === 'closed') {
        controller.setCarrierState?.(peerId, 'lost', `gossip_peer_${eventType}`);
      } else if (effectiveState === 'disconnected') {
        controller.setCarrierState?.(peerId, 'degraded', `gossip_peer_${eventType}`);
      }
    }
    captureClientDiagnostic({
      category: 'media',
      level: effectiveState === 'failed' || effectiveState === 'closed' ? 'warning' : 'info',
      eventType: 'gossip_dedicated_peer_connection_state',
      code: 'gossip_dedicated_peer_connection_state',
      message: 'Dedicated gossip neighbor RTCPeerConnection state changed.',
      payload: {
        peer_id: peerId,
        event_type: String(eventType || ''),
        connection_state: connectionState,
        ice_connection_state: iceConnectionState,
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      },
    });
    if (effectiveState === 'failed' || effectiveState === 'closed') {
      closeDedicatedGossipPeerConnection(peerId, `gossip_peer_${eventType}`, true);
    } else if (effectiveState === 'disconnected') {
      requestGossipTopologyRepair(peerId, `gossip_peer_${eventType}`);
    }
  }

  async function negotiateDedicatedGossipNeighbor(entry, reason = 'topology_hint') {
    if (!entry?.pc || !entry.initiator || entry.negotiating) return false;
    if (String(entry.pc.signalingState || '').trim().toLowerCase() === 'closed') return false;
    entry.negotiating = true;
    try {
      const offer = await entry.pc.createOffer();
      await entry.pc.setLocalDescription(offer);
      const local = entry.pc.localDescription;
      if (!local?.sdp) return false;
      return sendDedicatedGossipSignal('call/offer', entry.peerId, 'gossip_webrtc_offer', {
        reason: String(reason || ''),
        sdp: {
          type: local.type,
          sdp: local.sdp,
        },
      });
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_dedicated_offer_failed',
        code: 'gossip_dedicated_offer_failed',
        message: 'Dedicated gossip neighbor offer negotiation failed.',
        payload: {
          peer_id: normalizePeerId(entry.peerId),
          reason: String(reason || ''),
          error: String(error?.message || error || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      requestGossipTopologyRepair(entry.peerId, 'gossip_offer_failed');
      return false;
    } finally {
      entry.negotiating = false;
    }
  }

  async function flushDedicatedGossipPendingIce(entry) {
    if (!entry?.pc?.remoteDescription?.type) return false;
    let flushed = 0;
    while (entry.pendingIce.length > 0) {
      const candidatePayload = entry.pendingIce.shift();
      if (!candidatePayload) continue;
      try {
        await entry.pc.addIceCandidate(new RTCIceCandidate(candidatePayload));
        flushed += 1;
      } catch (error) {
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'gossip_dedicated_pending_ice_failed',
          code: 'gossip_dedicated_pending_ice_failed',
          message: 'Queued dedicated gossip ICE candidate could not be applied.',
          payload: {
            peer_id: normalizePeerId(entry.peerId),
            error: String(error?.message || error || ''),
            data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
            diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
          },
        });
      }
    }
    return flushed > 0;
  }

  function closeDedicatedGossipPeerConnection(peerId, reason = 'closed', requestRepair = false) {
    const normalizedPeerId = normalizePeerId(peerId);
    if (normalizedPeerId === '') return false;
    const entry = dedicatedGossipPeerConnections.get(normalizedPeerId);
    if (!entry) {
      gossipDataChannelTransport?.close(normalizedPeerId);
      return false;
    }
    dedicatedGossipPeerConnections.delete(normalizedPeerId);
    try {
      entry.pc?.close?.();
    } catch {}
    gossipDataChannelTransport?.close(normalizedPeerId);
    if (liveGossipController && assignedGossipNeighborIds.has(normalizedPeerId)) {
      liveGossipController.setCarrierState?.(normalizedPeerId, 'lost', String(reason || 'closed'));
    }
    if (requestRepair && assignedGossipNeighborIds.has(normalizedPeerId)) {
      requestGossipTopologyRepair(normalizedPeerId, reason);
    }
    return true;
  }

  function closeAllDedicatedGossipPeerConnections(reason = 'teardown') {
    let closed = 0;
    for (const peerId of Array.from(dedicatedGossipPeerConnections.keys())) {
      if (closeDedicatedGossipPeerConnection(peerId, reason, false)) {
        closed += 1;
      }
    }
    gossipDataChannelTransport?.close();
    return closed;
  }

  function handleGossipSignalingEvent(type, senderUserId, payloadBody = {}) {
    const kind = String(payloadBody?.kind || '').trim().toLowerCase();
    if (!['gossip_webrtc_offer', 'gossip_webrtc_answer', 'gossip_webrtc_ice'].includes(kind)) return false;
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return true;
    const peerId = normalizePeerId(senderUserId);
    if (peerId === '' || peerId === '0') return true;
    if (!assignedGossipNeighborIds.has(peerId)) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_signaling_unassigned_peer_ignored',
        code: 'gossip_signaling_unassigned_peer_ignored',
        message: 'Dedicated gossip WebRTC signaling was ignored because the sender is not in the server-assigned neighbor set.',
        payload: {
          peer_id: peerId,
          signal_type: String(type || ''),
          kind,
          assigned_neighbor_count: assignedGossipNeighborIds.size,
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      return true;
    }
    ensureDedicatedGossipNeighborConnection(peerId, 'signaling');
    if (kind === 'gossip_webrtc_offer') {
      void handleDedicatedGossipOffer(peerId, payloadBody || {});
    } else if (kind === 'gossip_webrtc_answer') {
      void handleDedicatedGossipAnswer(peerId, payloadBody || {});
    } else if (kind === 'gossip_webrtc_ice') {
      void handleDedicatedGossipIce(peerId, payloadBody || {});
    }
    return true;
  }

  async function handleDedicatedGossipOffer(peerId, payloadBody) {
    const entry = dedicatedGossipPeerConnections.get(normalizePeerId(peerId));
    if (!entry?.pc) return false;
    const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
    const type = String(sdpPayload?.type || '').trim().toLowerCase();
    const sdp = normalizeGossipSdpForRemoteDescription(sdpPayload?.sdp);
    if (type !== 'offer' || sdp === '') return false;
    try {
      const signalingState = String(entry.pc.signalingState || '').trim().toLowerCase();
      if (signalingState === 'have-local-offer') {
        const remoteOfferHasPriority = !gossipPeerInitiatesConnection(peerId);
        if (!remoteOfferHasPriority) return false;
        await entry.pc.setLocalDescription({ type: 'rollback' });
      } else if (signalingState !== 'stable' && signalingState !== '') {
        return false;
      }
      await entry.pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp }));
      await flushDedicatedGossipPendingIce(entry);
      const answer = await entry.pc.createAnswer();
      await entry.pc.setLocalDescription(answer);
      const local = entry.pc.localDescription;
      if (!local?.sdp) return false;
      return sendDedicatedGossipSignal('call/answer', peerId, 'gossip_webrtc_answer', {
        sdp: {
          type: local.type,
          sdp: local.sdp,
        },
      });
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_dedicated_offer_handle_failed',
        code: 'gossip_dedicated_offer_handle_failed',
        message: 'Dedicated gossip neighbor offer handling failed.',
        payload: {
          peer_id: normalizePeerId(peerId),
          error: String(error?.message || error || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      requestGossipTopologyRepair(peerId, 'gossip_offer_handle_failed');
      return false;
    }
  }

  async function handleDedicatedGossipAnswer(peerId, payloadBody) {
    const entry = dedicatedGossipPeerConnections.get(normalizePeerId(peerId));
    if (!entry?.pc) return false;
    const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
    const type = String(sdpPayload?.type || '').trim().toLowerCase();
    const sdp = normalizeGossipSdpForRemoteDescription(sdpPayload?.sdp);
    if (type !== 'answer' || sdp === '') return false;
    try {
      const signalingState = String(entry.pc.signalingState || '').trim().toLowerCase();
      if (signalingState !== 'have-local-offer') return false;
      await entry.pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp }));
      await flushDedicatedGossipPendingIce(entry);
      return true;
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_dedicated_answer_handle_failed',
        code: 'gossip_dedicated_answer_handle_failed',
        message: 'Dedicated gossip neighbor answer handling failed.',
        payload: {
          peer_id: normalizePeerId(peerId),
          error: String(error?.message || error || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      requestGossipTopologyRepair(peerId, 'gossip_answer_handle_failed');
      return false;
    }
  }

  async function handleDedicatedGossipIce(peerId, payloadBody) {
    const entry = dedicatedGossipPeerConnections.get(normalizePeerId(peerId));
    if (!entry?.pc) return false;
    const candidatePayload = payloadBody ? payloadBody.candidate : null;
    if (!candidatePayload || typeof candidatePayload !== 'object') return false;
    if (!entry.pc.remoteDescription?.type) {
      entry.pendingIce.push(candidatePayload);
      return true;
    }
    try {
      await entry.pc.addIceCandidate(new RTCIceCandidate(candidatePayload));
      return true;
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_dedicated_ice_failed',
        code: 'gossip_dedicated_ice_failed',
        message: 'Dedicated gossip ICE candidate could not be applied.',
        payload: {
          peer_id: normalizePeerId(peerId),
          error: String(error?.message || error || ''),
          data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
          diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        },
      });
      requestGossipTopologyRepair(peerId, 'gossip_ice_failed');
      return false;
    }
  }

  function requestGossipTopologyRepair(peerId, reason) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (!assignedGossipNeighborIds.has(String(peerId || ''))) return false;
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
      mediaCarrierMode: MEDIA_CARRIER_CONFIG.mode,
      rolloutStrategy: MEDIA_CARRIER_CONFIG.mode,
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
        closeDedicatedGossipPeerConnection(previousPeerId, 'topology_neighbor_removed');
      }
    }
    const dedicatedConnectionCount = ensureAssignedGossipNeighborConnections('topology_hint_applied');
    emitGossipTelemetrySnapshot('topology_hint_applied');
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_topology_hint_applied',
      code: 'gossip_topology_hint_applied',
      message: 'Gossip topology hint applied to dedicated data-channel neighbor connections.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        topology_epoch: Number(topologyHint.topology_epoch || 0),
        neighbor_count: assignedGossipNeighborIds.size,
        dedicated_neighbor_connection_count: dedicatedConnectionCount,
      },
    });
    return true;
  }

  function routeLiveGossipDeliveryToRemoteFrame(delivery) {
    if (!GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (!gossipActiveDataLaneAllowed()) return false;
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
    if (!GOSSIP_DATA_LANE_CONFIG.publish) {
      return recordGossipShadowWouldPublish(frame, 'publish_disabled');
    }
    if (!gossipActiveDataLaneAllowed()) {
      return recordGossipShadowWouldPublish(frame, 'rollout_gate_blocked');
    }
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

  function gossipActiveDataLaneAllowed() {
    if (GOSSIP_DATA_LANE_CONFIG.mode !== 'active') return false;
    if (MEDIA_CARRIER_CONFIG.gossipPrimary) {
      return Boolean(lastGossipRolloutGateState?.active_allowed)
        && Boolean(lastGossipRolloutGateState?.gossip_topology_healthy)
        && Boolean(lastGossipRolloutGateState?.media_security_recovery_ready);
    }
    return Boolean(lastGossipRolloutGateState?.active_allowed)
      && Boolean(lastGossipRolloutGateState?.sfu_baseline_healthy)
      && Boolean(lastGossipRolloutGateState?.media_security_recovery_ready);
  }

  function recordGossipShadowWouldPublish(frame, reason) {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled || !frame || typeof frame !== 'object') return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    controller?.recordTransportTelemetry?.(peerId, 'would_publish_frames', 1);
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_data_lane_shadow_would_publish',
      code: 'gossip_data_lane_shadow_would_publish',
      message: 'Gossip data lane recorded a frame that would have been published after SFU baseline send; media was not published.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        media_carrier_mode: MEDIA_CARRIER_CONFIG.mode,
        reason: String(reason || 'shadow_observe'),
        gate_decision: String(lastGossipRolloutGateState?.decision || 'no_rollout_gate_ack'),
        active_allowed: Boolean(lastGossipRolloutGateState?.active_allowed),
        sfu_baseline_healthy: Boolean(lastGossipRolloutGateState?.sfu_baseline_healthy),
        media_security_recovery_ready: Boolean(lastGossipRolloutGateState?.media_security_recovery_ready),
        blocking_buckets: Array.isArray(lastGossipRolloutGateState?.blocking_buckets)
          ? lastGossipRolloutGateState.blocking_buckets.slice(0, 8).map((bucket) => String(bucket || ''))
          : [],
        publisher_id: String(frame.publisherId || peerId),
        publisher_user_id: String(frame.publisherUserId || peerId),
        track_id: String(frame.trackId || ''),
        frame_type: String(frame.type || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
        codec_id: String(frame.codecId || ''),
        runtime_id: String(frame.runtimeId || ''),
        layout_mode: String(frame.layoutMode || 'full_frame'),
        layer_id: String(frame.layerId || 'full'),
      },
    });
    return false;
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
    if (peer && typeof peer === 'object') {
      peer.gossipDataLaneMode = GOSSIP_DATA_LANE_CONFIG.mode;
      peer.gossipDataChannelState = 'dedicated_peer_connection';
    }
    return false;
  }

  function closeGossipDataChannelForNativePeer(_peerId) {
    return false;
  }

  function pruneGossipNeighborForUserId(userId, reason = 'target_not_in_room') {
    const peerId = String(userId || '').trim();
    if (peerId === '' || peerId === '0') return false;
    if (!assignedGossipNeighborIds.has(peerId)) return false;

    assignedGossipNeighborIds.delete(peerId);
    gossipTopologyRepairRequestedAtByPeerId.delete(peerId);
    liveGossipController?.setCarrierState?.(peerId, 'lost', String(reason || 'target_not_in_room'));
    closeDedicatedGossipPeerConnection(peerId, reason);
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
    const gateState = deriveGossipRolloutGateState(payload, {
      mode: GOSSIP_DATA_LANE_CONFIG.mode,
      mediaCarrierMode: MEDIA_CARRIER_CONFIG.mode,
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
        media_carrier_mode: MEDIA_CARRIER_CONFIG.mode,
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
    closeAllDedicatedGossipPeerConnections('teardown');
    assignedGossipNeighborIds.clear();
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
    handleGossipSignalingEvent,
    pruneGossipNeighborForUserId,
    publishLocalEncodedFrameToGossip,
    teardownGossipDataLane,
  };
}
