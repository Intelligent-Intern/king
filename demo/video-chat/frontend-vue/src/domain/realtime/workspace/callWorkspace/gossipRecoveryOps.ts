import {
  GOSSIP_DATA_LANE_CONFIG,
  VIDEOCHAT_MEDIA_CARRIER_CONFIG,
} from '../../../../lib/gossipmesh/featureFlags';

export function createGossipRecoveryOps({
  activeCallId = () => '',
  activeRoomId = () => '',
  activeSocketCallId = () => '',
  callId = () => 'call',
  captureClientDiagnostic = () => {},
  emitGossipTelemetrySnapshot = () => false,
  ensureLiveGossipController = () => null,
  gossipDataPlaneAllowed = () => false,
  gossipRecoveryState,
  localPeerId = () => '',
  sendSocketFrame = () => false,
  strictGossipMediaDisabled = () => false,
}: Record<string, any> = {}) {
  function parkGossipNativeRecovery(reason = 'gossip_native_recovery', payload: Record<string, any> = {}) {
    if (!VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary) return false;
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'planned_gossip_native_recovery_request_parked',
      code: 'planned_gossip_native_recovery_request_parked',
      message: 'Gossip-primary one-connect media policy parked native Gossip recovery.',
      payload: {
        media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
        data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
        diagnostics_label: GOSSIP_DATA_LANE_CONFIG.diagnosticsLabel,
        reason: String(reason || 'gossip_native_recovery'),
        automatic_repair_allowed: false,
        next_connect_cycle_requires_new_participant: true,
        ...payload,
      },
      immediate: true,
    });
    return true;
  }

  function requestOverOpsLane(request: Record<string, any> = {}) {
    if (!GOSSIP_DATA_LANE_CONFIG.receive || !gossipDataPlaneAllowed()) return false;
    if (strictGossipMediaDisabled('disableGossipReceiveRecovery')) return false;
    if (parkGossipNativeRecovery(String(request?.reason || 'gossip_native_recovery'), {
      request_type: String(request?.request_type || ''),
      publisher_id: String(request?.publisher_id || ''),
      track_id: String(request?.track_id || ''),
    })) return false;
    if (!gossipRecoveryState?.shouldSendRecoveryRequest?.(request)) return false;
    const peerId = String(localPeerId() || '').trim();
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

  function requestForReceivedFrame(frame: Record<string, any> = {}, delivery: Record<string, any> = {}) {
    if (strictGossipMediaDisabled('disableGossipReceiveRecovery')) return false;
    const recoveryRequest = gossipRecoveryState?.recoveryRequestForReceivedFrame?.(frame);
    if (!recoveryRequest) return false;
    return requestOverOpsLane({
      ...recoveryRequest,
      from_peer_id: String(delivery?.from_peer_id || ''),
    });
  }

  function publishFrame(controller: any, peerId: string, frame: Record<string, any>, request: Record<string, any>, recoveryKind: string) {
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

  function handleOpsMessage(type: string, senderUserId: any, payload: Record<string, any> = {}) {
    const normalizedType = String(type || '').trim().toLowerCase();
    if (normalizedType !== 'call/gossip-recovery' && normalizedType !== 'gossip/recovery/request') return false;
    const body = payload?.payload && typeof payload.payload === 'object' ? payload.payload : payload;
    if (!body || typeof body !== 'object') return false;
    if (String(body.kind || '').trim().toLowerCase() !== 'gossip_recovery_request') return false;
    if (strictGossipMediaDisabled('disableGossipReceiveRecovery')) return true;
    if (parkGossipNativeRecovery(String(body.reason || 'gossip_native_recovery'), {
      request_id: String(body.request_id || ''),
      request_type: String(body.request_type || ''),
      publisher_id: String(body.publisher_id || ''),
      track_id: String(body.track_id || ''),
      inbound_ops_message: true,
    })) return true;
    const peerId = String(localPeerId() || '').trim();
    const publisherId = String(body.publisher_id || '').trim();
    const publisherUserId = String(body.publisher_user_id || publisherId).trim();
    if (peerId === '' || peerId === '0') return true;
    if (publisherId !== peerId && publisherUserId !== peerId) return true;

    const controller = ensureLiveGossipController();
    if (!controller) return true;
    const retransmitFrames = gossipRecoveryState?.cachedFramesForRequest?.(body) || [];
    let servedCount = 0;
    for (const frame of retransmitFrames) {
      if (publishFrame(controller, peerId, frame, body, 'retransmit')) servedCount += 1;
    }
    if (servedCount <= 0) {
      const keyframe = gossipRecoveryState?.cachedKeyframeForRequest?.(body);
      if (keyframe && publishFrame(controller, peerId, keyframe, body, 'keyframe')) servedCount = 1;
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

  return {
    handleOpsMessage,
    requestForReceivedFrame,
    requestOverOpsLane,
  };
}
