export function createNativePeerFactory({
  activeRoomId,
  attachMediaSecurityNativeReceiver = () => false,
  bumpMediaRenderVersion = () => {},
  clearNativePeerAudioTrackDeadline = () => {},
  closeNativePeerConnection = () => {},
  createNativePeerAudioElement,
  createNativePeerVideoElement,
  currentUserId,
  ensureNativeAudioBridgeSecurityReady = async () => false,
  ensureNativePeerConnectionRef,
  bindGossipDataChannelForNativePeer = () => false,
  isNativeWebRtcRuntimePath,
  markParticipantActivity = () => {},
  markRaw,
  nativeAudioBridgeFailureMessage = () => '',
  nativeAudioBridgeIsQuarantined = () => false,
  nativeWebRtcConfig,
  playNativePeerAudio = async () => false,
  renderNativeRemoteVideos = () => {},
  reportNativeAudioBridgeFailure = () => {},
  resetNativeAudioTrackRecovery = () => {},
  resetNativeOfferRetry = () => {},
  scheduleNativeOfferRetry = () => {},
  scheduleNativePeerAudioTrackDeadline = () => {},
  sendNativeOffer = async () => false,
  sendSocketFrame = () => false,
  setNativePeerAudioBridgeState = () => false,
  setNativePeerConnection = () => {},
  shouldBypassNativeAudioProtectionForPeer = () => false,
  shouldMaintainNativePeerConnections = () => false,
  shouldSyncNativeLocalTracksBeforeOffer = () => false,
  shouldUseNativeAudioBridge = () => false,
  syncNativePeerConnectionsWithRosterRef,
  syncNativePeerLocalTracks = async () => false,
  synchronizeNativePeerMediaElements = () => {},
  connectedParticipantUsers,
  nativePeerConnectionsRef,
  nativePeerRequiresAudioOnlyRebuild = () => false,
}) {
  function ensureNativePeerConnection(targetUserId) {
    const normalizedTargetUserId = Number(targetUserId);
    if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return null;
    if (normalizedTargetUserId === currentUserId()) return null;
    if (typeof RTCPeerConnection !== 'function') return null;

    const existing = nativePeerConnectionsRef.value.get(normalizedTargetUserId);
    if (existing) {
      bindGossipDataChannelForNativePeer(existing);
      synchronizeNativePeerMediaElements(existing);
      scheduleNativeOfferRetry(existing, 'peer_roster_sync');
      return existing;
    }

    const pc = new RTCPeerConnection(nativeWebRtcConfig());
    const remoteStream = new MediaStream();
    const video = isNativeWebRtcRuntimePath()
      ? createNativePeerVideoElement(normalizedTargetUserId)
      : null;
    const audio = shouldUseNativeAudioBridge()
      ? createNativePeerAudioElement(normalizedTargetUserId)
      : null;
    if (video instanceof HTMLVideoElement) {
      video.srcObject = remoteStream;
    }
    if (audio instanceof HTMLAudioElement) {
      audio.srcObject = remoteStream;
    }

    const peer = {
      userId: normalizedTargetUserId,
      initiator: currentUserId() > 0 && currentUserId() < normalizedTargetUserId,
      negotiating: false,
      needsRenegotiate: false,
      offerRetryCount: 0,
      offerRetryTimer: null,
      lastOfferSentAtMs: 0,
      pendingIce: [],
      pc,
      video,
      audio,
      remoteStream,
      audioBridgeState: 'new',
      audioBridgeErrorMessage: '',
      audioTrackDeadlineTimer: null,
      senderKinds: markRaw(new Map()),
    };

    pc.addEventListener('icecandidate', (event) => {
      if (!shouldMaintainNativePeerConnections()) return;
      if (!event?.candidate) return;
      sendSocketFrame({
        type: 'call/ice',
        target_user_id: normalizedTargetUserId,
        payload: {
          kind: 'webrtc_ice',
          runtime_path: 'webrtc_native',
          room_id: activeRoomId(),
          candidate: event.candidate.toJSON(),
        },
      });
    });

    pc.addEventListener('track', (event) => {
      markParticipantActivity(normalizedTargetUserId, 'media_track');
      const trackKind = String(event?.track?.kind || '').trim().toLowerCase();
      const bypassProtectedAudio = trackKind === 'audio' && shouldBypassNativeAudioProtectionForPeer(normalizedTargetUserId);

      const bindTrackAndPlayback = () => {
        if (shouldUseNativeAudioBridge() && trackKind === 'video') {
          return;
        }
        const incoming = event?.streams?.[0];
        if (incoming instanceof MediaStream) {
          for (const track of incoming.getTracks()) {
            if (shouldUseNativeAudioBridge() && String(track?.kind || '').trim().toLowerCase() === 'video') continue;
            if (!remoteStream.getTracks().some((row) => row.id === track.id)) {
              remoteStream.addTrack(track);
              if (String(track?.kind || '').trim().toLowerCase() === 'video') {
                track.addEventListener?.('ended', bumpMediaRenderVersion, { once: true });
              }
            }
          }
        } else if (event?.track) {
          if (shouldUseNativeAudioBridge() && trackKind === 'video') return;
          if (!remoteStream.getTracks().some((row) => row.id === event.track.id)) {
            remoteStream.addTrack(event.track);
            if (trackKind === 'video') {
              event.track.addEventListener?.('ended', bumpMediaRenderVersion, { once: true });
            }
          }
        }
        if (shouldUseNativeAudioBridge() && trackKind === 'audio') {
          clearNativePeerAudioTrackDeadline(peer);
          resetNativeAudioTrackRecovery(normalizedTargetUserId);
          setNativePeerAudioBridgeState(peer, 'track_received', '');
          event?.track?.addEventListener?.('ended', () => {
            setNativePeerAudioBridgeState(peer, 'waiting_track', '');
            scheduleNativePeerAudioTrackDeadline(peer);
          }, { once: true });
        }
        synchronizeNativePeerMediaElements(peer);
        if (!shouldUseNativeAudioBridge()) {
          bumpMediaRenderVersion();
          renderNativeRemoteVideos();
        }
        if (peer.video instanceof HTMLVideoElement) {
          peer.video.play().catch(() => {});
        }
        if (peer.audio instanceof HTMLAudioElement) {
          void playNativePeerAudio(peer, 'remote_track');
        }
      };

      if ((trackKind === 'audio' || isNativeWebRtcRuntimePath()) && !bypassProtectedAudio) {
        const attached = attachMediaSecurityNativeReceiver(event?.receiver, normalizedTargetUserId, event?.track);
        if (!attached && shouldUseNativeAudioBridge() && trackKind === 'audio') {
          setNativePeerAudioBridgeState(peer, 'waiting_security', '');
          void ensureNativeAudioBridgeSecurityReady(peer, 'native_audio_receiver_track')
            .then((ready) => {
              if (!ready) {
                scheduleNativePeerAudioTrackDeadline(peer);
                return;
              }
              const reattached = attachMediaSecurityNativeReceiver(
                event?.receiver,
                normalizedTargetUserId,
                event?.track
              );
              if (reattached) {
                bindTrackAndPlayback();
                return;
              }
              clearNativePeerAudioTrackDeadline(peer);
              reportNativeAudioBridgeFailure(
                peer,
                'native_audio_receiver_transform_failed',
                nativeAudioBridgeFailureMessage(),
                {
                  track_id: String(event?.track?.id || ''),
                  sender_user_id: normalizedTargetUserId,
                  recovery_reason: 'receiver_track_after_security_ready',
                },
              );
            })
            .catch(() => {
              scheduleNativePeerAudioTrackDeadline(peer);
            });
          return;
        }
      }
      if (bypassProtectedAudio) {
        clearNativePeerAudioTrackDeadline(peer);
        resetNativeAudioTrackRecovery(normalizedTargetUserId);
        setNativePeerAudioBridgeState(peer, 'protected_frames_temporarily_disabled', '');
      }
      bindTrackAndPlayback();
    });

    pc.addEventListener('connectionstatechange', () => {
      const state = String(pc.connectionState || '').toLowerCase();
      if (state === 'connected' || state === 'completed') {
        scheduleNativePeerAudioTrackDeadline(peer);
        resetNativeOfferRetry(peer);
        return;
      }
      clearNativePeerAudioTrackDeadline(peer);
      if (state === 'closed') {
        closeNativePeerConnection(normalizedTargetUserId);
        return;
      }
      if (state === 'failed') {
        closeNativePeerConnection(normalizedTargetUserId);
        setTimeout(() => {
          if (!shouldMaintainNativePeerConnections()) return;
          syncNativePeerConnectionsWithRosterRef()();
        }, 250);
      }
    });

    pc.addEventListener('negotiationneeded', () => {
      if (!peer.initiator) return;
      void sendNativeOffer(peer);
    });

    setNativePeerConnection(normalizedTargetUserId, peer);
    bindGossipDataChannelForNativePeer(peer);
    synchronizeNativePeerMediaElements(peer);
    if (shouldSyncNativeLocalTracksBeforeOffer(peer)) {
      void syncNativePeerLocalTracks(peer);
    }
    renderNativeRemoteVideos();
    if (peer.initiator) {
      void sendNativeOffer(peer);
    }
    return peer;
  }

  function syncNativePeerConnectionsWithRoster() {
    if (!shouldMaintainNativePeerConnections()) return;

    const activePeerIds = new Set();
    for (const row of connectedParticipantUsers.value) {
      const userId = Number(row?.userId || 0);
      if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId()) continue;
      activePeerIds.add(userId);
      const existing = nativePeerConnectionsRef.value.get(userId);
      if (nativePeerRequiresAudioOnlyRebuild(existing)) {
        closeNativePeerConnection(userId);
      }
      if (shouldUseNativeAudioBridge() && nativeAudioBridgeIsQuarantined(userId)) continue;
      ensureNativePeerConnectionRef()(userId);
    }

    for (const [userId] of nativePeerConnectionsRef.value) {
      if (!activePeerIds.has(userId)) {
        closeNativePeerConnection(userId);
        resetNativeAudioTrackRecovery(userId);
      }
    }
  }

  return {
    ensureNativePeerConnection,
    syncNativePeerConnectionsWithRoster,
  };
}
