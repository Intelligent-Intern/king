export function createNativeSignalingHelpers({
  activeRoomId,
  currentUserId,
  ensureLocalMediaForNativeNegotiation,
  ensureNativeAudioBridgeSecurityReady,
  ensureNativePeerConnection,
  flushNativePendingIce,
  loadDynamicIceServers,
  mediaDebugLog,
  mediaRuntimeCapabilities,
  mediaRuntimePath,
  nativeAudioBridgeHasLocalAudioTrack,
  nativeAudioBridgeLocalTrackTelemetry,
  nativePeerConnectionTelemetry,
  nativePeerConnectionsRef,
  nativePeerHasLocalLiveAudioSender,
  nativeSdpAudioSummaries,
  nativeSdpAudioSummary,
  nativeSdpHasSendableAudio,
  reportNativeAudioSdpRejected,
  resetNativeOfferRetry,
  runtimeSwitchInFlightRef,
  scheduleNativeOfferRetry,
  scheduleNativeOfferRetryForUserId,
  sendSocketFrame,
  shouldBlockNativeRuntimeSignaling,
  shouldExpectLocalNativeAudioTrack,
  shouldExpectRemoteNativeAudioTrack,
  shouldMaintainNativePeerConnections,
  shouldUseNativeAudioBridge,
  sfuRuntimeEnabled,
  switchMediaRuntimePath,
  syncNativePeerLocalTracks,
}) {
  function normalizeNativeSdpForRemoteDescription(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    const normalized = raw.replace(/\r?\n/g, '\r\n');
    return normalized.endsWith('\r\n') ? normalized : `${normalized}\r\n`;
  }

  async function handleNativeOfferSignal(senderUserId, payloadBody) {
    await loadDynamicIceServers();
    const peer = ensureNativePeerConnection(senderUserId);
    if (!peer?.pc) return;

    const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
    const type = String(sdpPayload?.type || '').trim().toLowerCase();
    const sdp = normalizeNativeSdpForRemoteDescription(sdpPayload?.sdp);
    if (type !== 'offer' || sdp === '') return;
    if (
      shouldUseNativeAudioBridge()
      && shouldExpectRemoteNativeAudioTrack(senderUserId)
      && !nativeSdpHasSendableAudio(sdp)
    ) {
      reportNativeAudioSdpRejected(
        peer,
        'native_audio_remote_offer_without_send_audio',
        'Native protected audio bridge ignored an offer without send-capable audio.',
        {
          sender_user_id: Number(senderUserId || 0),
          sdp_summary: nativeSdpAudioSummary(sdp),
          sdp_audio_summaries: nativeSdpAudioSummaries(sdp),
        },
      );
      return;
    }
    if (shouldUseNativeAudioBridge() && !(await ensureNativeAudioBridgeSecurityReady(peer, 'native_offer_received'))) {
      scheduleNativeOfferRetryForUserId(senderUserId, 'media_security_not_ready');
      return;
    }

    try {
      resetNativeOfferRetry(peer);
      const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
      if (signalingState === 'have-local-offer') {
        const remoteOfferHasPriority = Number(senderUserId || 0) < currentUserId();
        if (!remoteOfferHasPriority) {
          mediaDebugLog('[WebRTC] ignoring colliding native offer from lower-priority peer', senderUserId);
          return;
        }
        try {
          await peer.pc.setLocalDescription({ type: 'rollback' });
        } catch (rollbackError) {
          mediaDebugLog('[WebRTC] native offer collision rollback failed', senderUserId, rollbackError);
          return;
        }
      } else if (signalingState !== 'stable' && signalingState !== '') {
        mediaDebugLog('[WebRTC] ignoring native offer while signaling state is not stable', senderUserId, signalingState);
        return;
      }
      const mediaReady = await ensureLocalMediaForNativeNegotiation();
      if (
        shouldUseNativeAudioBridge()
        && shouldExpectLocalNativeAudioTrack()
        && (!mediaReady || !nativeAudioBridgeHasLocalAudioTrack())
      ) {
        reportNativeAudioSdpRejected(
          peer,
          'native_audio_answer_without_local_track',
          'Native protected audio bridge cannot answer without a live local mic track.',
          {
            sender_user_id: Number(senderUserId || 0),
            media_ready: Boolean(mediaReady),
            mic_enabled: shouldExpectLocalNativeAudioTrack(),
          },
        );
        return;
      }
      await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp }));
      await syncNativePeerLocalTracks(peer);
      await flushNativePendingIce(peer);
      if (
        shouldUseNativeAudioBridge()
        && shouldExpectLocalNativeAudioTrack()
        && !nativePeerHasLocalLiveAudioSender(peer)
      ) {
        reportNativeAudioSdpRejected(
          peer,
          'native_audio_answer_without_sender_track',
          'Native protected audio bridge cannot answer because the local microphone sender is not attached.',
          {
            sender_user_id: Number(senderUserId || 0),
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: nativePeerConnectionTelemetry(peer),
          },
        );
        return;
      }
      const answer = await peer.pc.createAnswer();
      await peer.pc.setLocalDescription(answer);
      const local = peer.pc.localDescription;
      if (!local || !local.sdp) return;
      if (
        shouldUseNativeAudioBridge()
        && shouldExpectLocalNativeAudioTrack()
        && !nativeSdpHasSendableAudio(local.sdp)
      ) {
        reportNativeAudioSdpRejected(
          peer,
          'native_audio_answer_without_send_audio',
          'Native protected audio bridge blocked an answer without send-capable audio.',
          {
            sender_user_id: Number(senderUserId || 0),
            sdp_summary: nativeSdpAudioSummary(local.sdp),
            sdp_audio_summaries: nativeSdpAudioSummaries(local.sdp),
            signaling_state: String(peer.pc.signalingState || ''),
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: nativePeerConnectionTelemetry(peer),
          },
        );
        return;
      }
      sendSocketFrame({
        type: 'call/answer',
        target_user_id: senderUserId,
        payload: {
          kind: 'webrtc_answer',
          runtime_path: 'webrtc_native',
          room_id: activeRoomId(),
          sdp: {
            type: local.type,
            sdp: local.sdp,
          },
        },
      });
    } catch (error) {
      mediaDebugLog('[WebRTC] Failed to handle offer from peer', senderUserId, error);
    }
  }

  async function handleNativeAnswerSignal(senderUserId, payloadBody) {
    const peer = nativePeerConnectionsRef.value.get(senderUserId);
    if (!peer?.pc) return;
    const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
    const type = String(sdpPayload?.type || '').trim().toLowerCase();
    const sdp = normalizeNativeSdpForRemoteDescription(sdpPayload?.sdp);
    if (type !== 'answer' || sdp === '') return;
    if (
      shouldUseNativeAudioBridge()
      && shouldExpectRemoteNativeAudioTrack(senderUserId)
      && !nativeSdpHasSendableAudio(sdp)
    ) {
      reportNativeAudioSdpRejected(
        peer,
        'native_audio_remote_answer_without_send_audio',
        'Native protected audio bridge ignored an answer without send-capable audio.',
        {
          sender_user_id: Number(senderUserId || 0),
          sdp_summary: nativeSdpAudioSummary(sdp),
          sdp_audio_summaries: nativeSdpAudioSummaries(sdp),
          signaling_state: String(peer.pc.signalingState || ''),
        },
      );
      scheduleNativeOfferRetry(peer, 'answer_without_send_audio');
      return;
    }
    if (shouldUseNativeAudioBridge() && !(await ensureNativeAudioBridgeSecurityReady(peer, 'native_answer_received'))) {
      scheduleNativeOfferRetry(peer, 'media_security_not_ready');
      return;
    }

    try {
      await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp }));
      await flushNativePendingIce(peer);
      resetNativeOfferRetry(peer);
    } catch (error) {
      mediaDebugLog('[WebRTC] Failed to handle answer from peer', senderUserId, error);
    }
  }

  async function handleNativeIceSignal(senderUserId, payloadBody) {
    const peer = ensureNativePeerConnection(senderUserId);
    if (!peer?.pc) return;
    const candidatePayload = payloadBody ? payloadBody.candidate : null;
    if (!candidatePayload || typeof candidatePayload !== 'object') return;

    if (peer.pc.remoteDescription && peer.pc.remoteDescription.type) {
      try {
        await peer.pc.addIceCandidate(new RTCIceCandidate(candidatePayload));
      } catch {
        // ignore stale candidate failures
      }
      return;
    }
    peer.pendingIce.push(candidatePayload);
  }

  function waitForNativeRuntimeTick(ms = 50) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  async function waitForNativeCapabilityForSignaling() {
    if (mediaRuntimeCapabilities.value.stageB) return true;

    for (let attempt = 0; attempt < 100; attempt += 1) {
      const path = String(mediaRuntimePath.value || '').trim();
      if (mediaRuntimeCapabilities.value.stageB) return true;
      if (path !== 'pending' && !runtimeSwitchInFlightRef()) return false;
      await waitForNativeRuntimeTick();
    }

    return mediaRuntimeCapabilities.value.stageB;
  }

  async function ensureNativeRuntimeForSignaling() {
    if (shouldMaintainNativePeerConnections() && !runtimeSwitchInFlightRef()) return true;
    if (shouldBlockNativeRuntimeSignaling()) return false;
    if (!(await waitForNativeCapabilityForSignaling())) return false;
    if (sfuRuntimeEnabled() && String(mediaRuntimePath.value || '').trim() !== 'webrtc_native') {
      return shouldMaintainNativePeerConnections() && !runtimeSwitchInFlightRef();
    }

    if (!runtimeSwitchInFlightRef()) {
      await switchMediaRuntimePath('webrtc_native', 'inbound_native_signaling');
    }

    for (let attempt = 0; attempt < 40; attempt += 1) {
      if (shouldMaintainNativePeerConnections() && !runtimeSwitchInFlightRef()) return true;
      await waitForNativeRuntimeTick();
    }

    return shouldMaintainNativePeerConnections() && !runtimeSwitchInFlightRef();
  }

  async function handleNativeSignalingEvent(type, senderUserId, payloadBody) {
    if (!(await ensureNativeRuntimeForSignaling())) return;

    if (type === 'call/offer') {
      await handleNativeOfferSignal(senderUserId, payloadBody || {});
      return;
    }
    if (type === 'call/answer') {
      await handleNativeAnswerSignal(senderUserId, payloadBody || {});
      return;
    }
    if (type === 'call/ice') {
      await handleNativeIceSignal(senderUserId, payloadBody || {});
    }
  }

  return {
    ensureNativeRuntimeForSignaling,
    handleNativeAnswerSignal,
    handleNativeIceSignal,
    handleNativeOfferSignal,
    handleNativeSignalingEvent,
    normalizeNativeSdpForRemoteDescription,
    waitForNativeCapabilityForSignaling,
    waitForNativeRuntimeTick,
  };
}
