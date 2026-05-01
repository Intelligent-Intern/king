export function createNativePeerLifecycleHelpers({
  bumpMediaRenderVersion,
  clearNativePeerAudioTrackDeadline,
  clearRemoteVideoContainer,
  isNativeWebRtcRuntimePath,
  mediaDebugLog,
  nativeAudioBridgeQuarantineByUserId,
  nativeAudioTrackRecoveryAttemptsByUserId,
  nativeOfferRetryDelaysMs,
  nativePeerConnectionsRef,
  renderNativeRemoteVideos,
  sendNativeOffer,
  shouldMaintainNativePeerConnections,
  shouldUseNativeAudioBridge,
}) {
  function clearNativeOfferRetry(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (peer.offerRetryTimer !== null && peer.offerRetryTimer !== undefined) {
      clearTimeout(peer.offerRetryTimer);
    }
    peer.offerRetryTimer = null;
  }

  function resetNativeOfferRetry(peer) {
    if (!peer || typeof peer !== 'object') return;
    clearNativeOfferRetry(peer);
    peer.offerRetryCount = 0;
  }

  function nativePeerHasRemoteAnswer(peer) {
    return Boolean(peer?.pc?.remoteDescription?.type);
  }

  function shouldSyncNativeLocalTracksBeforeOffer(peer) {
    if (!peer?.pc) return false;
    if (!shouldMaintainNativePeerConnections()) return false;
    if (!shouldUseNativeAudioBridge()) return true;
    if (peer.initiator) return true;
    return Boolean(peer.pc.remoteDescription?.type);
  }

  function nativePeerConnectionIsFinal(peer) {
    const state = String(peer?.pc?.connectionState || '').trim().toLowerCase();
    return state === 'connected' || state === 'completed' || state === 'closed';
  }

  function shouldRetryNativeOffer(peer) {
    if (!peer?.initiator || !peer?.pc) return false;
    if (!shouldMaintainNativePeerConnections()) return false;
    if (nativePeerHasRemoteAnswer(peer)) return false;
    if (nativePeerConnectionIsFinal(peer)) return false;
    const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
    return signalingState === 'stable' || signalingState === 'have-local-offer';
  }

  function scheduleNativeOfferRetry(peer, reason = 'retry') {
    if (!shouldRetryNativeOffer(peer)) return;
    if (peer.offerRetryTimer !== null && peer.offerRetryTimer !== undefined) return;

    const retryCount = Number.isInteger(peer.offerRetryCount) ? peer.offerRetryCount : 0;
    if (retryCount >= nativeOfferRetryDelaysMs.length) return;

    const delayMs = nativeOfferRetryDelaysMs[retryCount] || 6000;
    peer.offerRetryCount = retryCount + 1;
    peer.offerRetryTimer = setTimeout(() => {
      peer.offerRetryTimer = null;
      if (!shouldRetryNativeOffer(peer)) return;
      mediaDebugLog('[WebRTC] retrying native offer', { userId: peer.userId, reason, retry: peer.offerRetryCount });
      void sendNativeOffer(peer);
    }, delayMs);
  }

  function scheduleNativeOfferRetryForUserId(userId, reason = 'signaling') {
    const normalizedUserId = Number(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const peer = nativePeerConnectionsRef.value.get(normalizedUserId);
    if (!peer) return;
    scheduleNativeOfferRetry(peer, reason);
  }

  function setNativePeerConnection(targetUserId, peer) {
    const normalizedTargetUserId = Number(targetUserId);
    if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0 || !peer) return;
    const nextPeers = new Map(nativePeerConnectionsRef.value);
    nextPeers.set(normalizedTargetUserId, peer);
    nativePeerConnectionsRef.value = nextPeers;
    bumpMediaRenderVersion();
  }

  function takeNativePeerConnection(targetUserId) {
    const normalizedTargetUserId = Number(targetUserId);
    if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return null;
    const peer = nativePeerConnectionsRef.value.get(normalizedTargetUserId) || null;
    if (!peer) return null;
    const nextPeers = new Map(nativePeerConnectionsRef.value);
    nextPeers.delete(normalizedTargetUserId);
    nativePeerConnectionsRef.value = nextPeers;
    bumpMediaRenderVersion();
    return peer;
  }

  function closeNativePeerConnection(targetUserId) {
    const normalizedTargetUserId = Number(targetUserId);
    if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return;
    const peer = takeNativePeerConnection(normalizedTargetUserId);
    if (!peer) return;

    clearNativePeerAudioTrackDeadline(peer);
    clearNativeOfferRetry(peer);
    if (peer.pc) {
      try {
        peer.pc.close();
      } catch {
        // ignore close errors
      }
    }
    if (peer.video instanceof HTMLVideoElement) {
      peer.video.srcObject = null;
      peer.video.remove();
    }
    if (peer.audio instanceof HTMLAudioElement) {
      peer.audio.srcObject = null;
      peer.audio.remove();
    }
    renderNativeRemoteVideos();
  }

  function nativePeerRequiresAudioOnlyRebuild(peer) {
    if (!peer || typeof peer !== 'object') return false;
    if (!shouldUseNativeAudioBridge()) return false;
    if (peer?.senderKinds instanceof Map) {
      for (const kind of peer.senderKinds.values()) {
        if (String(kind || '').trim().toLowerCase() === 'video') {
          return true;
        }
      }
    }
    const pc = peer?.pc || null;
    if (!pc || typeof pc.getSenders !== 'function') return false;
    return pc.getSenders().some((sender) => {
      const trackKind = String(sender?.track?.kind || '').trim().toLowerCase();
      return trackKind === 'video';
    });
  }

  function teardownNativePeerConnections() {
    for (const targetUserId of Array.from(nativePeerConnectionsRef.value.keys())) {
      closeNativePeerConnection(targetUserId);
    }
    nativeAudioTrackRecoveryAttemptsByUserId.clear();
    nativeAudioBridgeQuarantineByUserId.clear();
    if (nativePeerConnectionsRef.value.size > 0) {
      nativePeerConnectionsRef.value = new Map();
    }
    if (isNativeWebRtcRuntimePath()) {
      clearRemoteVideoContainer();
    }
  }

  return {
    clearNativeOfferRetry,
    closeNativePeerConnection,
    nativePeerRequiresAudioOnlyRebuild,
    resetNativeOfferRetry,
    scheduleNativeOfferRetry,
    scheduleNativeOfferRetryForUserId,
    setNativePeerConnection,
    shouldSyncNativeLocalTracksBeforeOffer,
    teardownNativePeerConnections,
  };
}
