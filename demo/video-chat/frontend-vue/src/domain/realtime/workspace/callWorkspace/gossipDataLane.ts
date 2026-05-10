import { arrayBufferToBase64Url, base64UrlToArrayBuffer } from '../../../../lib/sfu/framePayload';
import { GOSSIP_DATA_LANE_CONFIG, VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../../lib/gossipmesh/featureFlags';
import { GossipController } from '../../../../lib/gossipmesh/gossipController';
import { deriveGossipRolloutGateState } from '../../../../lib/gossipmesh/rolloutGate';
import { GossipDirectTransport } from '../../../../lib/gossipmesh/directGossipTransport';
import { createGossipRecoveryState } from './gossipRecoveryState';

export function createCallWorkspaceGossipDataLane({
  callbacks,
}) {
  const {
    captureClientDiagnostic,
    currentUserId,
    activeRoomId,
    activeSocketCallId,
    activeCallId,
    defaultNativeIceServers = [],
    dynamicIceServers = null,
    getConnectedParticipantCount = () => 0,
    getLocalAudioLevel = () => null,
    getLocalAudioStream = () => null,
    handleSFUEncodedFrame,
    sendSocketFrame,
  } = callbacks;
  let gossipDirectTransport = null;
  let gossipAudioDirectTransport = null;
  let liveGossipController = null;
  let liveGossipControllerKey = '';
  let unsubscribeLiveGossipDelivery = null;
  const assignedGossipNeighborIds = new Set();
  const liveGossipFrameSequenceByTrack = new Map();
  const liveGossipAudioSequenceByTrack = new Map();
  const liveGossipAudioSinks = new Map();
  const gossipTopologyRepairRequestedAtByPeerId = new Map();
  const gossipRecoveryState = createGossipRecoveryState();
  let gossipAudioUnlockBound = false;
  let gossipAudioPingContext = null;
  let gossipAudioRecorder = null;
  let gossipAudioRecorderTrackId = '';
  let gossipAudioRecorderMimeType = '';
  let gossipAudioMediaGeneration = 1;
  let lastGossipTelemetrySnapshotSentAtMs = 0;
  let lastGossipRolloutGateState = null;
  let lastGossipPrimaryTopologyAdmissionDiagnosticAtMs = 0;

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

  function supportedGossipAudioMimeType() {
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') return '';
    const candidates = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
    ];
    return candidates.find((entry) => MediaRecorder.isTypeSupported(entry)) || '';
  }

  function playGossipAudioPing(msg, fromPeerId) {
    const publisherId = String(msg?.publisherId || msg?.publisher_id || msg?.publisher_user_id || '').trim();
    if (publisherId === '' || publisherId === localPeerId()) return false;
    if (typeof window === 'undefined') return false;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return false;
    if (!gossipAudioPingContext) gossipAudioPingContext = new AudioContextCtor();
    const context = gossipAudioPingContext;
    const schedule = () => {
      const now = context.currentTime;
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      const durationSeconds = Math.max(0.05, Math.min(1, Number(msg?.duration_ms || 250) / 1000));
      const peakGain = Math.max(0.01, Math.min(0.6, Number(msg?.gain || 0.16)));
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(Math.max(80, Math.min(2000, Number(msg?.frequency_hz || 440))), now);
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(peakGain, now + 0.025);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + durationSeconds);
      oscillator.connect(gain).connect(context.destination);
      oscillator.start(now);
      oscillator.stop(now + durationSeconds + 0.03);
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'gossip_audio_ping_played',
        code: 'gossip_audio_ping_played',
        message: 'Gossip audio ping played with Web Audio.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          from_peer_id: String(fromPeerId || ''),
          publisher_id: publisherId,
          frequency_hz: Math.max(80, Math.min(2000, Number(msg?.frequency_hz || 440))),
          duration_ms: Math.round(durationSeconds * 1000),
          reason: String(msg?.reason || ''),
        },
      });
    };
    if (context.state === 'suspended' && typeof context.resume === 'function') {
      context.resume().then(schedule).catch(() => undefined);
      return true;
    }
    schedule();
    return true;
  }

  function currentLocalAudioTrack() {
    const stream = getLocalAudioStream?.();
    if (!(stream instanceof MediaStream)) return null;
    return stream.getAudioTracks().find((track) => track?.readyState === 'live') || null;
  }

  function stopGossipAudioPublisher(reason = 'stopped') {
    const recorder = gossipAudioRecorder;
    gossipAudioRecorder = null;
    gossipAudioRecorderTrackId = '';
    gossipAudioRecorderMimeType = '';
    if (recorder && String(recorder.state || '') !== 'inactive') {
      try { recorder.stop(); } catch {}
    }
    if (recorder) {
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'gossip_audio_publisher_stopped',
        code: 'gossip_audio_publisher_stopped',
        message: 'Gossip audio publisher stopped.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          reason: String(reason || 'stopped'),
        },
      });
    }
  }

  function syncGossipAudioPublisher(reason = 'sync') {
    if (!VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary || !GOSSIP_DATA_LANE_CONFIG.publish) {
      stopGossipAudioPublisher('gossip_audio_disabled');
      return false;
    }
    if (!gossipPrimaryTopologyReady()) return false;
    const track = currentLocalAudioTrack();
    if (!track) {
      stopGossipAudioPublisher('no_live_audio_track');
      return false;
    }
    if (gossipAudioRecorder && gossipAudioRecorderTrackId === track.id && String(gossipAudioRecorder.state || '') === 'recording') {
      return true;
    }
    stopGossipAudioPublisher('audio_track_changed');
    if (typeof MediaRecorder === 'undefined') {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_recorder_unavailable',
        code: 'gossip_audio_recorder_unavailable',
        message: 'Gossip audio could not start because MediaRecorder is unavailable.',
        payload: mediaCarrierDiagnosticPayload(),
      });
      return false;
    }
    const mimeType = supportedGossipAudioMimeType();
    if (mimeType === '') {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_codec_unavailable',
        code: 'gossip_audio_codec_unavailable',
        message: 'Gossip audio could not start because Opus recording is unavailable.',
        payload: mediaCarrierDiagnosticPayload(),
      });
      return false;
    }
    try {
      const stream = new MediaStream([track]);
      const recorder = new MediaRecorder(stream, {
        mimeType,
        audioBitsPerSecond: 32000,
      });
      gossipAudioRecorder = recorder;
      gossipAudioRecorderTrackId = track.id;
      gossipAudioRecorderMimeType = mimeType;
      gossipAudioMediaGeneration += 1;
      recorder.addEventListener('dataavailable', (event) => {
        if (!event?.data || event.data.size <= 0) return;
        void publishLocalGossipAudioChunk(event.data, track.id, mimeType, getLocalAudioLevel?.());
      });
      recorder.addEventListener('error', () => {
        stopGossipAudioPublisher('recorder_error');
      });
      recorder.start(250);
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'gossip_audio_publisher_started',
        code: 'gossip_audio_publisher_started',
        message: 'Gossip audio publisher started using browser Opus encoding over the data lane.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          reason: String(reason || 'sync'),
          track_id: track.id,
          codec_id: mimeType,
        },
      });
      return true;
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_publisher_failed',
        code: 'gossip_audio_publisher_failed',
        message: 'Gossip audio publisher failed to start.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          reason: String(reason || 'sync'),
          error: error instanceof Error ? error.message : String(error),
        },
      });
      return false;
    }
  }

  function roomId() {
    return String(activeRoomId() || '').trim() || 'lobby';
  }

  function callId() {
    return String(activeSocketCallId() || activeCallId() || '').trim() || 'call';
  }

  function ensureGossipDirectTransport() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return null;
    if (gossipDirectTransport) return gossipDirectTransport;

    gossipDirectTransport = new GossipDirectTransport({
      roomId: roomId(),
      callId: callId(),
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
        if (!gossipDataPlaneAllowed()) {
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
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'gossip_direct_link_state',
          code: 'gossip_direct_link_state',
          message: 'Gossip direct link state changed.',
          payload: {
            data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
            diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
            peer_id: normalizedPeerId,
            state: String(state || ''),
            event_type: String(eventType || ''),
          },
          immediate: true,
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
    return gossipDirectTransport;
  }

  function ensureGossipAudioDirectTransport() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return null;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return null;
    if (gossipAudioDirectTransport) return gossipAudioDirectTransport;

    gossipAudioDirectTransport = new GossipDirectTransport({
      roomId: roomId(),
      callId: `${callId()}:audio`,
      localPeerId: peerId,
      onDataMessage: (msg, fromPeerId) => {
        if (!GOSSIP_DATA_LANE_CONFIG.receive || !gossipDataPlaneAllowed()) return;
        routeLiveGossipDeliveryToAudio({
          receiving_peer_id: localPeerId(),
          from_peer_id: String(fromPeerId || '').trim(),
          frame_id: String(msg?.frame_id || msg?.frameId || ''),
          message: msg,
        });
      },
      onTelemetry: (event) => {
        const controller = liveGossipController;
        const peerId = String(event?.peerId || localPeerId()).trim();
        const counter = String(event?.counter || '').trim();
        if (!controller || peerId === '' || counter === '') return;
        controller.recordTransportTelemetry?.(peerId, counter, Math.max(1, Number(event?.increment || 1)));
      },
    });
    return gossipAudioDirectTransport;
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
      assignedGossipNeighborIds.clear();
      gossipTopologyRepairRequestedAtByPeerId.clear();
      gossipRecoveryState.clear();
      stopGossipAudioPublisher('controller_changed');
      lastGossipTelemetrySnapshotSentAtMs = 0;
      gossipDirectTransport?.close();
      gossipDirectTransport = null;
      gossipAudioDirectTransport?.close();
      gossipAudioDirectTransport = null;
    }
    liveGossipFrameSequenceByTrack.clear();
    liveGossipAudioSequenceByTrack.clear();

    const controller = new GossipController(roomId(), callId());
    controller.setDataLaneConfig(GOSSIP_DATA_LANE_CONFIG);
    const transport = ensureGossipDirectTransport();
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

  function bindAssignedGossipNeighbors() {
    if (!GOSSIP_DATA_LANE_CONFIG.enabled) return 0;
    const controller = ensureLiveGossipController();
    const transport = ensureGossipDirectTransport();
    const audioTransport = ensureGossipAudioDirectTransport();
    for (const peerId of assignedGossipNeighborIds) {
      ensureLiveGossipPeer(peerId);
      controller?.setCarrierState?.(peerId, 'connected', 'gossip_direct_neighbor_bound');
      transport?.connectPeer?.(peerId);
      audioTransport?.connectPeer?.(peerId);
    }
    return assignedGossipNeighborIds.size;
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
    const topologyHint = normalizeGossipTopologyHintPayload(payload);
    if (!topologyHint) return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    if (!controller) return false;

    const repairRetiredPeerIds = topologyRepairRetiredPeerIdsForLocalPeer(topologyHint, peerId);
    for (const retiredPeerId of repairRetiredPeerIds) {
      assignedGossipNeighborIds.delete(retiredPeerId);
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
    const transport = ensureGossipDirectTransport();
    const audioTransport = ensureGossipAudioDirectTransport();
    for (const neighborId of peer?.neighbor_set || []) {
      const normalizedNeighborId = String(neighborId || '').trim();
      if (gossipTopologyNeighborUsesRtcDataChannel(topologyHint, normalizedNeighborId)) {
        assignedGossipNeighborIds.add(normalizedNeighborId);
        transport?.connectPeer?.(normalizedNeighborId);
        audioTransport?.connectPeer?.(normalizedNeighborId);
        controller.setCarrierState?.(normalizedNeighborId, 'connected', 'gossip_direct_topology_neighbor');
      }
    }
    for (const previousPeerId of previousAssignedNeighborIds) {
      if (!assignedGossipNeighborIds.has(previousPeerId)) {
        continue;
      }
    }
    const boundCount = bindAssignedGossipNeighbors();
    syncGossipAudioPublisher('topology_hint_applied');
    emitGossipTelemetrySnapshot('topology_hint_applied');
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'gossip_topology_hint_applied',
      code: 'gossip_topology_hint_applied',
      message: 'Gossip topology hint applied to direct peer neighbor bindings.',
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
    if (!GOSSIP_DATA_LANE_CONFIG.receive) return false;
    if (!gossipDataPlaneAllowed()) return false;
    const msg = delivery?.message || null;
    if (!msg) return false;
    if (msg.type !== 'sfu/frame') return false;
    const frame = sfuFrameFromGossipMessage(msg, delivery);
    if (!frame) return false;
    requestGossipRecoveryForReceivedFrame(frame, delivery);
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
        frame_type: String(frame.type || '').trim() === 'keyframe' ? 'keyframe' : 'delta',
        frame_sequence: Number(frame.frameSequence || 0),
        media_generation: Number(frame.mediaGeneration || 0),
        protection_mode: String(frame.protectionMode || ''),
        layout_mode: String(frame.layoutMode || ''),
      },
    });
    handleSFUEncodedFrame(frame);
    return true;
  }

  function routeLiveGossipDeliveryToAudio(delivery) {
    const msg = delivery?.message || null;
    if (!msg) return false;
    if (msg.type === 'gossip/audio-ping') {
      return playGossipAudioPing(msg, delivery?.from_peer_id);
    }
    if (msg.type !== 'gossip/audio-chunk') return false;
    const publisherId = String(msg.publisherId || msg.publisher_id || msg.publisher_user_id || '').trim();
    if (publisherId === '' || publisherId === localPeerId()) return false;
    const dataBase64 = String(msg.dataBase64 || msg.data_base64 || '').trim();
    if (dataBase64 === '') return false;
    const codecId = String(msg.codecId || msg.codec_id || 'audio/webm;codecs=opus').trim() || 'audio/webm;codecs=opus';
    const dataBuffer = base64UrlToArrayBuffer(dataBase64);
    if (!(dataBuffer instanceof ArrayBuffer) || dataBuffer.byteLength <= 0) return false;
    const sink = ensureGossipAudioSink(publisherId, codecId);
    if (!sink) return false;
    sink.queue.push(dataBuffer);
    drainGossipAudioSink(sink);
    maybePlayGossipAudioSink(sink, 'audio_chunk');
    const nowMs = Date.now();
    if ((nowMs - Number(sink.lastDiagnosticAtMs || 0)) > 5000) {
      sink.lastDiagnosticAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'gossip_audio_chunk_routed',
        code: 'gossip_audio_chunk_routed',
        message: 'Gossip audio chunk routed to the browser audio decoder.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          from_peer_id: String(delivery?.from_peer_id || ''),
          publisher_id: publisherId,
          track_id: String(msg.track_id || ''),
          codec_id: codecId,
          frame_sequence: Math.max(0, Number(msg.frame_sequence || 0)),
          payload_bytes: dataBuffer.byteLength,
        },
      });
    }
    return true;
  }

  function ensureGossipAudioSink(publisherId, codecId) {
    if (typeof window === 'undefined' || typeof MediaSource === 'undefined' || typeof document === 'undefined') return null;
    bindGossipAudioUnlockHandlers();
    const normalizedPublisherId = String(publisherId || '').trim();
    if (normalizedPublisherId === '') return null;
    const normalizedCodecId = String(codecId || 'audio/webm;codecs=opus').trim() || 'audio/webm;codecs=opus';
    if (typeof MediaSource.isTypeSupported === 'function' && !MediaSource.isTypeSupported(normalizedCodecId)) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_sink_codec_unsupported',
        code: 'gossip_audio_sink_codec_unsupported',
        message: 'Gossip audio sink could not start because the browser rejected the codec.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          publisher_id: normalizedPublisherId,
          codec_id: normalizedCodecId,
        },
      });
      return null;
    }
    const existing = liveGossipAudioSinks.get(normalizedPublisherId);
    if (existing && existing.codecId === normalizedCodecId) return existing;
    if (existing) closeGossipAudioSink(existing);

    const mediaSource = new MediaSource();
    const objectUrl = URL.createObjectURL(mediaSource);
    const audio = new Audio();
    audio.autoplay = true;
    audio.muted = false;
    audio.volume = 1;
    audio.playsInline = true;
    audio.preload = 'auto';
    audio.dataset.gossipAudioPublisherId = normalizedPublisherId;
    audio.style.display = 'none';
    audio.src = objectUrl;
    document.body.appendChild(audio);

    const sink = {
      publisherId: normalizedPublisherId,
      codecId: normalizedCodecId,
      mediaSource,
      sourceBuffer: null as SourceBuffer | null,
      audio,
      objectUrl,
      queue: [] as ArrayBuffer[],
      lastPlayAttemptAtMs: 0,
      lastDiagnosticAtMs: 0,
    };
    mediaSource.addEventListener('sourceopen', () => {
      try {
        sink.sourceBuffer = mediaSource.addSourceBuffer(normalizedCodecId);
        sink.sourceBuffer.mode = 'sequence';
        sink.sourceBuffer.addEventListener('updateend', () => {
          trimGossipAudioSinkBuffer(sink);
          drainGossipAudioSink(sink);
        });
        drainGossipAudioSink(sink);
        maybePlayGossipAudioSink(sink, 'sourceopen');
      } catch (error) {
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'gossip_audio_sink_failed',
          code: 'gossip_audio_sink_failed',
          message: 'Gossip audio sink failed to attach the browser decoder.',
          payload: {
            ...mediaCarrierDiagnosticPayload(),
            publisher_id: normalizedPublisherId,
            codec_id: normalizedCodecId,
            error: error instanceof Error ? error.message : String(error),
          },
        });
      }
    }, { once: true });
    liveGossipAudioSinks.set(normalizedPublisherId, sink);
    return sink;
  }

  function bindGossipAudioUnlockHandlers() {
    if (gossipAudioUnlockBound || typeof document === 'undefined') return;
    gossipAudioUnlockBound = true;
    const unlock = () => {
      for (const sink of liveGossipAudioSinks.values()) {
        maybePlayGossipAudioSink(sink, 'user_gesture');
      }
    };
    document.addEventListener('pointerdown', unlock, { passive: true });
    document.addEventListener('keydown', unlock);
    document.addEventListener('touchstart', unlock, { passive: true });
  }

  function drainGossipAudioSink(sink) {
    if (!sink?.sourceBuffer || sink.sourceBuffer.updating || sink.queue.length <= 0) return false;
    if (sink.mediaSource?.readyState !== 'open') return false;
    const next = sink.queue.shift();
    try {
      sink.sourceBuffer.appendBuffer(next);
      return true;
    } catch (error) {
      sink.queue.length = 0;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_append_failed',
        code: 'gossip_audio_append_failed',
        message: 'Gossip audio chunk could not be appended to the browser decoder.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          publisher_id: String(sink.publisherId || ''),
          codec_id: String(sink.codecId || ''),
          error: error instanceof Error ? error.message : String(error),
        },
      });
      return false;
    }
  }

  function trimGossipAudioSinkBuffer(sink) {
    const sourceBuffer = sink?.sourceBuffer;
    const audio = sink?.audio;
    if (!sourceBuffer || sourceBuffer.updating || !audio) return;
    try {
      if (sourceBuffer.buffered.length <= 0) return;
      const currentTime = Number(audio.currentTime || 0);
      const start = sourceBuffer.buffered.start(0);
      const removeEnd = Math.max(0, currentTime - 8);
      if (removeEnd > start) sourceBuffer.remove(start, removeEnd);
    } catch {}
  }

  function maybePlayGossipAudioSink(sink, reason) {
    if (!sink?.audio || typeof sink.audio.play !== 'function') return false;
    const nowMs = Date.now();
    if ((nowMs - Number(sink.lastPlayAttemptAtMs || 0)) < 1000) return false;
    sink.lastPlayAttemptAtMs = nowMs;
    sink.audio.play().catch((error) => {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'gossip_audio_play_blocked',
        code: 'gossip_audio_play_blocked',
        message: 'Browser blocked Gossip audio playback.',
        payload: {
          ...mediaCarrierDiagnosticPayload(),
          publisher_id: String(sink.publisherId || ''),
          reason: String(reason || 'play'),
          error: error instanceof Error ? error.message : String(error),
        },
      });
    });
    return true;
  }

  function closeGossipAudioSink(sink) {
    try { sink?.audio?.pause?.(); } catch {}
    try { if (sink?.audio) sink.audio.src = ''; } catch {}
    try { sink?.audio?.remove?.(); } catch {}
    try { if (sink?.objectUrl) URL.revokeObjectURL(sink.objectUrl); } catch {}
    sink.queue = [];
  }

  function publishLocalEncodedFrameToGossip(frame) {
    if (!GOSSIP_DATA_LANE_CONFIG.publish) {
      return recordGossipShadowWouldPublish(frame, 'publish_disabled');
    }
    if (!gossipDataPlaneAllowed()) {
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
    gossipRecoveryState.rememberPublishedFrame(msg);
    controller.publishFrame(peerId, msg);
    syncGossipAudioPublisher('video_frame_publish');
    emitGossipTelemetrySnapshot('local_publish');
    return true;
  }

  async function publishLocalGossipAudioChunk(blob, trackId, codecId, audioLevel = null) {
    if (!gossipDataPlaneAllowed()) return false;
    const dataBuffer = await blob.arrayBuffer();
    if (!(dataBuffer instanceof ArrayBuffer) || dataBuffer.byteLength <= 0) return false;
    return publishGossipAudioChunk({
      trackId,
      codecId,
      dataBuffer,
      timestamp: Date.now(),
      durationMs: 250,
      audioLevel,
    });
  }

  function publishGossipAudioChunk({ trackId, codecId, dataBuffer, timestamp, durationMs, audioLevel = null }) {
    if (!GOSSIP_DATA_LANE_CONFIG.publish || !gossipDataPlaneAllowed()) return false;
    const transport = ensureGossipAudioDirectTransport();
    const peerId = localPeerId();
    const normalizedTrackId = String(trackId || '').trim();
    if (!transport || peerId === '' || peerId === '0' || normalizedTrackId === '') return false;
    const sequenceKey = `${peerId}:${normalizedTrackId}:audio`;
    const frameSequence = Math.max(1, Number(liveGossipAudioSequenceByTrack.get(sequenceKey) || 0) + 1);
    liveGossipAudioSequenceByTrack.set(sequenceKey, frameSequence);
    const dataBase64 = arrayBufferToBase64Url(dataBuffer);
    const msg = {
      type: 'gossip/audio-chunk',
      protocol_version: 1,
      publisher_id: peerId,
      publisher_user_id: peerId,
      track_id: normalizedTrackId,
      timestamp: Number(timestamp || Date.now()),
      frame_sequence: frameSequence,
      media_generation: gossipAudioMediaGeneration,
      sender_sent_at_ms: Date.now(),
      codec_id: String(codecId || 'audio/webm;codecs=opus'),
      protection_mode: 'transport_only',
      data_base64: dataBase64,
      payload_chars: dataBase64.length,
      duration_ms: Math.max(1, Number(durationMs || 250)),
      ttl: 1,
      route_id: `${callId()}:audio:${peerId}:${Date.now()}:${frameSequence}`,
    };
    const normalizedAudioLevel = Number(audioLevel);
    if (Number.isFinite(normalizedAudioLevel)) {
      msg.audio_level = Math.max(0, Math.min(1, normalizedAudioLevel));
    }
    transport.broadcastData?.(msg, peerId);
    emitGossipTelemetrySnapshot('local_audio_publish');
    return true;
  }
  function requestGossipRecoveryForReceivedFrame(frame, delivery) {
    const recoveryRequest = gossipRecoveryState.recoveryRequestForReceivedFrame(frame);
    if (!recoveryRequest) return false;
    return requestGossipRecoveryOverOpsLane({
      ...recoveryRequest,
      from_peer_id: String(delivery?.from_peer_id || ''),
    });
  }
  function requestGossipRecoveryOverOpsLane(request) {
    if (!GOSSIP_DATA_LANE_CONFIG.receive || !gossipDataPlaneAllowed()) return false;
    if (!gossipRecoveryState.shouldSendRecoveryRequest(request)) return false;
    const peerId = localPeerId();
    const publisherId = String(request?.publisher_id || '').trim();
    const trackId = String(request?.track_id || '').trim();
    if (peerId === '' || peerId === '0' || publisherId === '' || trackId === '') return false;
    const requestType = String(request?.request_type || '').trim() === 'missing_frame' ? 'missing_frame' : 'keyframe';
    const controller = ensureLiveGossipController();
    controller?.recordTransportTelemetry?.(peerId, requestType === 'missing_frame' ? 'missing_frame_requests' : 'keyframe_requests', 1);
    if (requestType === 'keyframe' || request?.prefer_keyframe === true) {
      controller?.requestKeyframe?.(peerId, publisherId, trackId);
    }
    const requestId = `ggr_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    const sent = sendSocketFrame({
      type: 'gossip/recovery/request',
      lane: 'ops',
      payload: {
        kind: 'gossip_recovery_request',
        request_id: requestId,
        request_type: requestType,
        room_id: String(activeRoomId() || '').trim(),
        call_id: String(activeSocketCallId() || activeCallId() || '').trim(),
        requester_peer_id: peerId,
        publisher_id: publisherId,
        publisher_user_id: String(request?.publisher_user_id || publisherId).trim(),
        track_id: trackId,
        media_generation: Math.max(0, Number(request?.media_generation || 0)),
        missing_from_sequence: Math.max(0, Number(request?.missing_from_sequence || 0)),
        missing_to_sequence: Math.max(0, Number(request?.missing_to_sequence || 0)),
        last_received_sequence: Math.max(0, Number(request?.last_received_sequence || 0)),
        observed_frame_sequence: Math.max(0, Number(request?.frame_sequence || 0)),
        prefer_keyframe: request?.prefer_keyframe === true || requestType === 'keyframe',
        reason: String(request?.reason || 'gossip_native_recovery'),
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
      },
    });
    captureClientDiagnostic({
      category: 'media',
      level: sent ? 'warning' : 'info',
      eventType: 'gossip_native_recovery_requested',
      code: 'gossip_native_recovery_requested',
      message: 'Gossip-native media recovery requested a missing frame or keyframe over the server ops lane.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        request_id: requestId,
        request_type: requestType,
        publisher_id: publisherId,
        track_id: trackId,
        missing_from_sequence: Math.max(0, Number(request?.missing_from_sequence || 0)),
        missing_to_sequence: Math.max(0, Number(request?.missing_to_sequence || 0)),
        sent,
      },
    });
    return sent;
  }
  function handleGossipRecoveryOpsMessage(type, senderUserId, payload) {
    const normalizedType = String(type || '').trim().toLowerCase();
    if (normalizedType !== 'call/gossip-recovery' && normalizedType !== 'gossip/recovery/request') return false;
    const body = payload?.payload && typeof payload.payload === 'object' ? payload.payload : payload;
    if (!body || typeof body !== 'object') return false;
    if (String(body.kind || '').trim().toLowerCase() !== 'gossip_recovery_request') return false;
    const peerId = localPeerId();
    const publisherId = String(body.publisher_id || '').trim();
    const publisherUserId = String(body.publisher_user_id || publisherId).trim();
    if (peerId === '' || peerId === '0') return true;
    if (publisherId !== peerId && publisherUserId !== peerId) return true;

    const controller = ensureLiveGossipController();
    if (!controller) return true;
    const retransmitFrames = gossipRecoveryState.cachedFramesForRequest(body);
    let servedCount = 0;
    for (const frame of retransmitFrames) {
      if (publishGossipRecoveryFrame(controller, peerId, frame, body, 'retransmit')) servedCount += 1;
    }
    if (servedCount <= 0) {
      const keyframe = gossipRecoveryState.cachedKeyframeForRequest(body);
      if (keyframe && publishGossipRecoveryFrame(controller, peerId, keyframe, body, 'keyframe')) servedCount = 1;
    }
    if (servedCount > 0) {
      controller.recordTransportTelemetry?.(peerId, 'retransmits_served', servedCount);
      emitGossipTelemetrySnapshot('gossip_recovery_served');
    }
    captureClientDiagnostic({
      category: 'media',
      level: servedCount > 0 ? 'info' : 'warning',
      eventType: servedCount > 0 ? 'gossip_native_recovery_served' : 'gossip_native_recovery_cache_miss',
      code: servedCount > 0 ? 'gossip_native_recovery_served' : 'gossip_native_recovery_cache_miss',
      message: servedCount > 0 ? 'Gossip-native recovery served cached media over bounded peer links.' : 'Gossip-native recovery request reached the publisher, but no cached frame was available.',
      payload: {
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        request_id: String(body.request_id || ''),
        request_type: String(body.request_type || ''),
        sender_user_id: Number(senderUserId || 0),
        requester_peer_id: String(body.requester_peer_id || ''),
        publisher_id: publisherId,
        track_id: String(body.track_id || ''),
        served_count: servedCount,
      },
    });
    return true;
  }
  function publishGossipRecoveryFrame(controller, peerId, frame, request, recoveryKind) {
    if (!frame || typeof frame !== 'object') return false;
    controller.publishFrame(peerId, {
      ...frame,
      ttl: Math.max(1, Math.min(2, Number(frame.ttl || 2))),
      route_id: `${callId()}:${peerId}:recovery:${Date.now()}:${String(recoveryKind || 'frame')}:${Number(frame.frame_sequence || 0)}`,
      recovery_kind: String(recoveryKind || 'frame'),
      recovery_request_id: String(request?.request_id || ''),
      recovery_for_peer_id: String(request?.requester_peer_id || ''),
      recovery_reason: String(request?.reason || 'gossip_native_recovery'),
    });
    return true;
  }
  function gossipActiveDataLaneAllowed() {
    if (GOSSIP_DATA_LANE_CONFIG.mode !== 'active' || !lastGossipRolloutGateState?.active_allowed) return false;
    if (VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary) {
      return Boolean(lastGossipRolloutGateState?.gossip_topology_healthy)
        && Boolean(lastGossipRolloutGateState?.media_security_recovery_ready);
    }
    return Boolean(lastGossipRolloutGateState?.sfu_baseline_healthy)
      && Boolean(lastGossipRolloutGateState?.media_security_recovery_ready);
  }

  function gossipPrimaryTopologyReady() {
    if (!VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary) return false;
    if (GOSSIP_DATA_LANE_CONFIG.mode !== 'active' || !GOSSIP_DATA_LANE_CONFIG.publish || !GOSSIP_DATA_LANE_CONFIG.receive) return false;
    const peerId = localPeerId();
    if (peerId === '' || peerId === '0') return false;
    const controller = ensureLiveGossipController();
    if (!controller) return false;
    const peer = controller.getPeer(peerId);
    const assignedNeighborCount = assignedGossipNeighborIds.size;
    const controllerNeighborCount = Array.isArray(peer?.neighbor_set) ? peer.neighbor_set.length : 0;
    const participantCount = Math.max(0, Number(getConnectedParticipantCount?.() || 0));
    if (assignedNeighborCount > 0 || controllerNeighborCount > 0 || participantCount > 1) return true;
    return typeof gossipDirectTransport?.hasAnyOpenChannel === 'function'
      && gossipDirectTransport.hasAnyOpenChannel();
  }

  function diagnoseGossipPrimaryTopologyAdmission() {
    const nowMs = Date.now();
    if ((nowMs - lastGossipPrimaryTopologyAdmissionDiagnosticAtMs) < 10000) return;
    lastGossipPrimaryTopologyAdmissionDiagnosticAtMs = nowMs;
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'gossip_primary_topology_admission_without_rollout_gate',
      code: 'gossip_primary_topology_admission_without_rollout_gate',
      message: 'Gossip primary accepted media on assigned topology before telemetry rollout gates caught up.',
      payload: {
        ...mediaCarrierDiagnosticPayload(),
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        local_peer_id: localPeerId(),
        assigned_neighbor_count: assignedGossipNeighborIds.size,
        has_rollout_gate_ack: Boolean(lastGossipRolloutGateState),
        gate_decision: String(lastGossipRolloutGateState?.decision || 'no_rollout_gate_ack'),
        active_allowed: Boolean(lastGossipRolloutGateState?.active_allowed),
        gossip_topology_healthy: Boolean(lastGossipRolloutGateState?.gossip_topology_healthy),
        media_security_recovery_ready: Boolean(lastGossipRolloutGateState?.media_security_recovery_ready),
      },
      immediate: true,
    });
  }

  function gossipDataPlaneAllowed() {
    if (gossipActiveDataLaneAllowed()) return true;
    if (
      VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary
      && GOSSIP_DATA_LANE_CONFIG.mode === 'active'
      && GOSSIP_DATA_LANE_CONFIG.publish
      && GOSSIP_DATA_LANE_CONFIG.receive
      && localPeerId() !== ''
      && localPeerId() !== '0'
    ) {
      diagnoseGossipPrimaryTopologyAdmission();
      return true;
    }
    if (!gossipPrimaryTopologyReady()) return false;
    diagnoseGossipPrimaryTopologyAdmission();
    return true;
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
      message: VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary
        ? 'Gossip data lane deferred a frame until assigned neighbors are ready.'
        : 'Gossip data lane recorded a frame that would have been published after SFU baseline send; media was not published.',
      payload: {
        ...mediaCarrierDiagnosticPayload(),
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
        media_carrier_diagnostics_label: VIDEOCHAT_MEDIA_CARRIER_CONFIG.diagnosticsLabel,
        reason: String(reason || 'shadow_observe'),
        local_peer_id: peerId,
        assigned_neighbor_count: assignedGossipNeighborIds.size,
        has_rollout_gate_ack: Boolean(lastGossipRolloutGateState),
        gate_decision: String(lastGossipRolloutGateState?.decision || 'no_rollout_gate_ack'),
        active_allowed: Boolean(lastGossipRolloutGateState?.active_allowed),
        gossip_topology_healthy: Boolean(lastGossipRolloutGateState?.gossip_topology_healthy),
        sfu_baseline_required_for_active: Boolean(lastGossipRolloutGateState?.sfu_baseline_required_for_active),
        sfu_baseline_healthy: Boolean(lastGossipRolloutGateState?.sfu_baseline_healthy),
        sfu_fallback_healthy: Boolean(lastGossipRolloutGateState?.sfu_fallback_healthy),
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
      immediate: backendVisibleBacktrace,
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
      receivedAtMs: Math.max(0, Number(msg.receivedAtMs ?? msg.received_at_ms ?? 0)),
      forwardedAtMs: Math.max(0, Number(msg.forwardedAtMs ?? msg.forwarded_at_ms ?? 0)),
      relayPeerId: String(msg.relayPeerId || msg.relay_peer_id || delivery?.from_peer_id || ''),
      hopCount: Math.max(0, Number(msg.hopCount ?? msg.hop_count ?? 0)),
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

  function pruneGossipNeighborForUserId(userId, reason = 'target_not_in_room') {
    const peerId = String(userId || '').trim();
    if (peerId === '' || peerId === '0') return false;
    if (!assignedGossipNeighborIds.has(peerId)) return false;

    assignedGossipNeighborIds.delete(peerId);
    gossipTopologyRepairRequestedAtByPeerId.delete(peerId);
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
      mediaCarrierMode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
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
        media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
      },
    });
    return true;
  }

  function getGossipRolloutGateState() {
    return lastGossipRolloutGateState ? { ...lastGossipRolloutGateState } : null;
  }

  function getAssignedGossipNeighborCount() {
    return assignedGossipNeighborIds.size;
  }

  function teardownGossipDataLane() {
    stopGossipAudioPublisher('teardown');
    if (typeof unsubscribeLiveGossipDelivery === 'function') {
      unsubscribeLiveGossipDelivery();
      unsubscribeLiveGossipDelivery = null;
    }
    liveGossipController?.dispose?.();
    liveGossipController = null;
    liveGossipControllerKey = '';
    liveGossipFrameSequenceByTrack.clear();
    liveGossipAudioSequenceByTrack.clear();
    gossipRecoveryState.clear();
    assignedGossipNeighborIds.clear();
    gossipTopologyRepairRequestedAtByPeerId.clear();
    lastGossipRolloutGateState = null;
    lastGossipTelemetrySnapshotSentAtMs = 0;
    gossipDirectTransport?.close();
    gossipDirectTransport = null;
    gossipAudioDirectTransport?.close();
    gossipAudioDirectTransport = null;
    for (const sink of liveGossipAudioSinks.values()) {
      closeGossipAudioSink(sink);
    }
    liveGossipAudioSinks.clear();
    gossipAudioPingContext?.close?.().catch?.(() => undefined);
    gossipAudioPingContext = null;
  }

  return {
    applyGossipTelemetryAck,
    applyGossipTopologyHint,
    getAssignedGossipNeighborCount,
    getGossipRolloutGateState,
    handleGossipNeighborSignal: (...args) => handleGossipRecoveryOpsMessage(...args),
    pruneGossipNeighborForUserId,
    publishLocalEncodedFrameToGossip,
    teardownGossipDataLane,
  };
}
