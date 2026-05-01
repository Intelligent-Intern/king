export function createNativeBridgeRuntimeHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    apiRequest,
    attachMediaSecurityNativeReceiverBase,
    attachMediaSecurityNativeSenderBase,
    bumpMediaRenderVersion,
    clearNativePeerAudioTrackDeadline,
    createNativePeerAudioElement,
    createNativePeerVideoElement,
    currentNativeAudioBridgeFailureMessage,
    currentShouldUseNativeAudioBridge,
    currentUserId,
    ensureLocalMediaForPublish,
    ensureMediaSecuritySession,
    ensureNativeAudioBridgeSecurityReady,
    extractDiagnosticMessage,
    getPeerControlSnapshot,
    isNativeWebRtcRuntimePath,
    markParticipantActivity,
    mediaDebugLog,
    playNativePeerAudio,
    renderCallVideoLayout,
    renderNativeRemoteVideos,
    reportNativeAudioBridgeFailure,
    reportNativeAudioSdpRejected,
    resetNativeAudioTrackRecovery,
    scheduleNativeOfferRetry,
    scheduleNativePeerAudioTrackDeadline,
    sendSocketFrame,
    shouldBypassNativeAudioProtectionForPeer,
    shouldMaintainNativePeerConnections,
    shouldSendNativeTrackKind,
    streamHasLiveTrackKind,
  } = callbacks;

  const {
    defaultNativeIceServers,
  } = constants;

  function nativeWebRtcConfig() {
    const config = {
      iceServers: currentNativeIceServers(),
      iceCandidatePoolSize: 4,
    };
    if (refs.MediaSecuritySession.supportsNativeTransforms()) {
      config.encodedInsertableStreams = true;
    }
    return config;
  }

  function normalizeIceServerEntry(value) {
    if (!value || typeof value !== 'object') return null;
    const urlsValue = Array.isArray(value.urls)
      ? value.urls.map((entry) => String(entry || '').trim()).filter(Boolean)
      : String(value.urls || '').trim();
    if ((Array.isArray(urlsValue) && urlsValue.length === 0) || (!Array.isArray(urlsValue) && urlsValue === '')) {
      return null;
    }

    const server = { urls: urlsValue };
    const username = String(value.username || '').trim();
    const credential = String(value.credential || '').trim();
    if (username !== '') server.username = username;
    if (credential !== '') server.credential = credential;
    return server;
  }

  function currentNativeIceServers() {
    return refs.dynamicIceServers.value.length > 0 ? refs.dynamicIceServers.value : defaultNativeIceServers;
  }

  async function loadDynamicIceServers(force = false) {
    const token = String(refs.sessionToken() || '').trim();
    if (token === '') return currentNativeIceServers();

    const nowMs = Date.now();
    if (!force && refs.dynamicIceServers.value.length > 0 && state.dynamicIceServersExpiresAtMs > nowMs + 60_000) {
      return currentNativeIceServers();
    }
    if (state.dynamicIceServersPromise) return state.dynamicIceServersPromise;

    state.dynamicIceServersPromise = apiRequest('/api/user/media/ice-servers')
      .then((payload) => {
        const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
        const servers = Array.isArray(result.ice_servers)
          ? result.ice_servers.map(normalizeIceServerEntry).filter(Boolean)
          : [];
        if (servers.length > 0) {
          refs.dynamicIceServers.value = servers;
        }

        const expiresAtMs = Date.parse(String(result.expires_at || ''));
        state.dynamicIceServersExpiresAtMs = Number.isFinite(expiresAtMs)
          ? expiresAtMs
          : (Date.now() + 30 * 60_000);
        return currentNativeIceServers();
      })
      .catch((error) => {
        mediaDebugLog('[WebRTC] ICE server discovery failed; using static ICE config', error);
        return currentNativeIceServers();
      })
      .finally(() => {
        state.dynamicIceServersPromise = null;
      });

    return state.dynamicIceServersPromise;
  }

  function localTracksByKind(stream) {
    const out = {};
    if (!(stream instanceof MediaStream)) return out;
    for (const track of stream.getTracks()) {
      if (track?.readyState === 'ended') continue;
      if (track.kind === 'audio' || track.kind === 'video') {
        out[track.kind] = track;
      }
    }
    return out;
  }

  function nativeAudioBridgeHasLocalAudioTrack() {
    return refs.localStreamRef.value instanceof MediaStream
      && streamHasLiveTrackKind(refs.localStreamRef.value, 'audio');
  }

  function nativeAudioBridgeLocalTrackTelemetry() {
    const stream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
    return {
      stream_present: stream instanceof MediaStream,
      mic_enabled: refs.controlState.micEnabled !== false,
      tracks: stream instanceof MediaStream
        ? stream.getTracks().map((track) => ({
            kind: String(track?.kind || '').trim().toLowerCase(),
            id: String(track?.id || '').trim(),
            ready_state: String(track?.readyState || '').trim().toLowerCase(),
            enabled: Boolean(track?.enabled),
          }))
        : [],
    };
  }

  function shouldExpectLocalNativeAudioTrack() {
    return refs.controlState.micEnabled !== false;
  }

  function shouldExpectRemoteNativeAudioTrack(userId) {
    return getPeerControlSnapshot(userId).micEnabled !== false;
  }

  function attachMediaSecurityNativeSender(sender, track) {
    if (!sender || !track || !shouldMaintainNativePeerConnections()) return false;
    const session = ensureMediaSecuritySession();
    try {
      if (attachMediaSecurityNativeSenderBase(session, sender, track)) {
        refs.mediaSecurityStateVersion.value += 1;
        return true;
      }
    } catch (error) {
      mediaDebugLog('[MediaSecurity] native sender transform attach failed', error);
    }
    return false;
  }

  function attachMediaSecurityNativeReceiver(receiver, senderUserId, track) {
    if (!receiver || !shouldMaintainNativePeerConnections()) return false;
    const session = ensureMediaSecuritySession();
    const normalizedSenderUserId = Number(senderUserId || 0);
    const trackKind = String(track?.kind || '').trim().toLowerCase();
    if (trackKind === 'audio' && shouldBypassNativeAudioProtectionForPeer(normalizedSenderUserId)) {
      return false;
    }
    if (Number.isInteger(normalizedSenderUserId) && normalizedSenderUserId > 0) {
      void session.ensureReady?.();
    }
    try {
      if (attachMediaSecurityNativeReceiverBase(session, receiver, normalizedSenderUserId, track)) {
        refs.mediaSecurityStateVersion.value += 1;
        return true;
      }
    } catch (error) {
      mediaDebugLog('[MediaSecurity] native receiver transform attach failed', error);
    }
    return false;
  }

  function attachMediaSecurityNativeReceiversForPeer(peer) {
    if (!peer?.pc || !shouldMaintainNativePeerConnections()) return false;
    const senderUserId = Number(peer.userId || 0);
    if (!Number.isInteger(senderUserId) || senderUserId <= 0 || senderUserId === currentUserId()) return false;

    let attachedAny = false;
    const receivers = typeof peer.pc.getReceivers === 'function' ? peer.pc.getReceivers() : [];
    for (const receiver of receivers) {
      const track = receiver?.track || null;
      const trackKind = String(track?.kind || '').trim().toLowerCase();
      if (trackKind !== 'audio' && trackKind !== 'video') continue;
      if (trackKind === 'audio') {
        if (shouldBypassNativeAudioProtectionForPeer(senderUserId)) continue;
      }
      if (!attachMediaSecurityNativeReceiver(receiver, senderUserId, track)) continue;
      attachedAny = true;

      if (currentShouldUseNativeAudioBridge() && trackKind === 'video') continue;
      if (track && !peer.remoteStream.getTracks().some((row) => row.id === track.id)) {
        peer.remoteStream.addTrack(track);
        if (trackKind === 'video') {
          track.addEventListener?.('ended', bumpMediaRenderVersion, { once: true });
        }
      }
      if (currentShouldUseNativeAudioBridge() && trackKind === 'audio') {
        clearNativePeerAudioTrackDeadline(peer);
        resetNativeAudioTrackRecovery(senderUserId);
        refs.setNativePeerAudioBridgeState(peer, 'track_received', '');
        track?.addEventListener?.('ended', () => {
          refs.setNativePeerAudioBridgeState(peer, 'waiting_track', '');
          scheduleNativePeerAudioTrackDeadline(peer);
        }, { once: true });
      }
    }

    if (!attachedAny) return false;
    synchronizeNativePeerMediaElements(peer);
    if (!currentShouldUseNativeAudioBridge()) {
      bumpMediaRenderVersion();
      renderNativeRemoteVideos();
    }
    if (peer.video instanceof HTMLVideoElement) {
      peer.video.play().catch(() => {});
    }
    if (peer.audio instanceof HTMLAudioElement) {
      void playNativePeerAudio(peer, 'security_ready');
    }
    return true;
  }

  function findNativePeerAudioTransceiver(peer) {
    const transceivers = typeof peer?.pc?.getTransceivers === 'function' ? peer.pc.getTransceivers() : [];
    for (const transceiver of transceivers) {
      const receiverKind = String(transceiver?.receiver?.track?.kind || '').trim().toLowerCase();
      const mid = String(transceiver?.mid || '').trim();
      const currentDirection = String(transceiver?.currentDirection || '').trim().toLowerCase();
      if (receiverKind === 'audio' && (mid !== '' || currentDirection !== '')) return transceiver;
    }
    for (const transceiver of transceivers) {
      const sender = transceiver?.sender || null;
      const senderKind = String(sender?.track?.kind || peer?.senderKinds?.get?.(sender) || '').trim().toLowerCase();
      const receiverKind = String(transceiver?.receiver?.track?.kind || '').trim().toLowerCase();
      if (senderKind === 'audio' || receiverKind === 'audio') return transceiver;
    }
    return null;
  }

  function ensureNativePeerAudioTransceiver(peer) {
    if (!peer?.pc || typeof peer.pc.addTransceiver !== 'function') return false;
    const existing = findNativePeerAudioTransceiver(peer);
    if (existing) {
      const sender = existing?.sender || null;
      if (sender) {
        peer?.senderKinds?.set?.(sender, 'audio');
      }
      try {
        if (existing.direction === 'recvonly' || existing.direction === 'inactive') {
          existing.direction = 'sendrecv';
        }
      } catch {
        // ignore transceiver direction updates on read-only browser shims
      }
      return existing;
    }
    for (const sender of peer.pc.getSenders()) {
      const senderKind = String(sender?.track?.kind || peer?.senderKinds?.get?.(sender) || '').trim().toLowerCase();
      if (senderKind === 'audio') {
        peer?.senderKinds?.set?.(sender, 'audio');
        return sender;
      }
    }
    try {
      const transceiver = peer.pc.addTransceiver('audio', { direction: 'sendrecv' });
      peer?.senderKinds?.set?.(transceiver?.sender, 'audio');
      return transceiver;
    } catch {
      return false;
    }
  }

  async function replaceNativePeerSenderTrack(peer, sender, nextTrack, senderKind, reason = 'sync') {
    try {
      await sender.replaceTrack(nextTrack);
      return true;
    } catch (error) {
      const normalizedKind = String(senderKind || '').trim().toLowerCase();
      if (currentShouldUseNativeAudioBridge() && normalizedKind === 'audio') {
        reportNativeAudioBridgeFailure(
          peer,
          'native_audio_sender_replace_track_failed',
          'Audio is unavailable because the browser could not attach the local microphone to the protected audio bridge.',
          {
            reason: String(reason || 'sync'),
            target_track_id: String(nextTrack?.id || ''),
            target_track_state: String(nextTrack?.readyState || '').trim().toLowerCase(),
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: refs.nativePeerConnectionTelemetry(peer),
            error_name: String(error?.name || '').trim(),
            error_message: extractDiagnosticMessage(error, 'replaceTrack failed'),
          },
        );
      }
      return false;
    }
  }

  function synchronizeNativePeerMediaElements(peer) {
    if (!peer || typeof peer !== 'object') return;

    if (currentShouldUseNativeAudioBridge()) {
      if (peer.video instanceof HTMLVideoElement) {
        peer.video.srcObject = null;
        peer.video.remove();
        peer.video = null;
      }
      if (!(peer.audio instanceof HTMLAudioElement)) {
        peer.audio = createNativePeerAudioElement(peer.userId);
      }
      if (peer.audio instanceof HTMLAudioElement) {
        const nextAudioStream = peer.remoteStream instanceof MediaStream ? peer.remoteStream : null;
        const audioNeedsRebind = peer.audio.srcObject !== nextAudioStream;
        if (audioNeedsRebind) {
          peer.audio.srcObject = nextAudioStream;
        }
        const audioBridgeState = String(peer.audioBridgeState || '').trim().toLowerCase();
        const shouldAttemptPlayback = streamHasLiveTrackKind(peer.remoteStream, 'audio')
          && (
            audioNeedsRebind
            || peer.audio.paused
            || audioBridgeState === 'track_received'
            || audioBridgeState === 'waiting_track'
            || audioBridgeState === 'stalled_no_track'
            || audioBridgeState === 'play_failed'
          );
        if (shouldAttemptPlayback) {
          void playNativePeerAudio(peer, audioNeedsRebind ? 'bind_stream' : 'resume_stream');
        }
      }
      if (peer.remoteStream instanceof MediaStream) {
        for (const track of peer.remoteStream.getVideoTracks()) {
          peer.remoteStream.removeTrack(track);
        }
      }
      return;
    }

    if (peer.audio instanceof HTMLAudioElement) {
      peer.audio.srcObject = null;
      peer.audio.remove();
      peer.audio = null;
    }
    if (!(peer.video instanceof HTMLVideoElement)) {
      peer.video = createNativePeerVideoElement(peer.userId);
    }
    if (peer.video instanceof HTMLVideoElement) {
      peer.video.srcObject = peer.remoteStream instanceof MediaStream ? peer.remoteStream : null;
    }
  }

  async function syncNativePeerLocalTracks(peer) {
    if (!peer?.pc || peer.pc.signalingState === 'closed') return;
    const audioTransceiver = ensureNativePeerAudioTransceiver(peer);
    const stream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
    const byKind = localTracksByKind(stream);
    const primaryAudioSender = audioTransceiver?.sender || null;

    if (
      currentShouldUseNativeAudioBridge()
      && shouldSendNativeTrackKind('audio')
      && byKind.audio
      && primaryAudioSender
    ) {
      const audioSender = primaryAudioSender;
      peer?.senderKinds?.set?.(audioSender, 'audio');
      try {
        if (audioTransceiver.direction === 'recvonly' || audioTransceiver.direction === 'inactive') {
          audioTransceiver.direction = 'sendrecv';
        }
      } catch {
        // read-only shims still expose the sender, which is enough for tests
      }
      if (audioSender.track?.id !== byKind.audio.id) {
        const replaced = await replaceNativePeerSenderTrack(peer, audioSender, byKind.audio, 'audio', 'audio_transceiver_sync');
        if (!replaced) return;
      }
    }

    const senders = peer.pc.getSenders();
    for (const sender of senders) {
      const senderKind = String(sender?.track?.kind || peer?.senderKinds?.get?.(sender) || '').toLowerCase();
      if (senderKind !== 'audio' && senderKind !== 'video') continue;
      const isStaleNativeAudioSender = currentShouldUseNativeAudioBridge()
        && senderKind === 'audio'
        && primaryAudioSender
        && sender !== primaryAudioSender;
      const nextTrack = isStaleNativeAudioSender ? null : shouldSendNativeTrackKind(senderKind) ? (byKind[senderKind] || null) : null;
      if (nextTrack && sender.track?.id === nextTrack.id) {
        const attached = attachMediaSecurityNativeSender(sender, nextTrack);
        if (!attached && currentShouldUseNativeAudioBridge() && senderKind === 'audio') {
          try {
            peer.pc.removeTrack?.(sender);
          } catch {
            try {
              await sender.replaceTrack(null);
            } catch {
            }
          }
          reportNativeAudioBridgeFailure(peer, 'native_audio_sender_transform_failed', currentNativeAudioBridgeFailureMessage(), {
            track_id: String(nextTrack?.id || ''),
            sender_kind: senderKind,
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: refs.nativePeerConnectionTelemetry(peer),
          });
          continue;
        }
        delete byKind[senderKind];
        continue;
      }
      const replaced = await replaceNativePeerSenderTrack(peer, sender, nextTrack, senderKind, 'sender_sync');
      if (!replaced) continue;
      if (nextTrack) {
        const attached = attachMediaSecurityNativeSender(sender, nextTrack);
        if (!attached && currentShouldUseNativeAudioBridge() && senderKind === 'audio') {
          try {
            peer.pc.removeTrack?.(sender);
          } catch {
            try {
              await sender.replaceTrack(null);
            } catch {
            }
          }
          reportNativeAudioBridgeFailure(peer, 'native_audio_sender_transform_failed', currentNativeAudioBridgeFailureMessage(), {
            track_id: String(nextTrack?.id || ''),
            sender_kind: senderKind,
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: refs.nativePeerConnectionTelemetry(peer),
          });
          continue;
        }
        delete byKind[senderKind];
      }
    }

    if (!(stream instanceof MediaStream)) return;
    for (const kind of ['audio', 'video']) {
      if (!shouldSendNativeTrackKind(kind)) continue;
      const track = byKind[kind] || null;
      if (!track) continue;
      try {
        const sender = peer.pc.addTrack(track, stream);
        peer?.senderKinds?.set?.(sender, kind);
        const attached = attachMediaSecurityNativeSender(sender, track);
        if (!attached && currentShouldUseNativeAudioBridge() && kind === 'audio') {
          try {
            peer.pc.removeTrack?.(sender);
          } catch {
            try {
              await sender.replaceTrack(null);
            } catch {
            }
          }
          reportNativeAudioBridgeFailure(peer, 'native_audio_sender_transform_failed', currentNativeAudioBridgeFailureMessage(), {
            track_id: String(track?.id || ''),
            sender_kind: kind,
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: refs.nativePeerConnectionTelemetry(peer),
          });
        }
      } catch (error) {
        if (currentShouldUseNativeAudioBridge() && kind === 'audio') {
          reportNativeAudioBridgeFailure(
            peer,
            'native_audio_sender_add_track_failed',
            'Audio is unavailable because the browser could not add the local microphone to the protected audio bridge.',
            {
              track_id: String(track?.id || ''),
              track_state: String(track?.readyState || '').trim().toLowerCase(),
              local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
              peer: refs.nativePeerConnectionTelemetry(peer),
              error_name: String(error?.name || '').trim(),
              error_message: extractDiagnosticMessage(error, 'addTrack failed'),
            },
          );
        }
      }
    }
  }

  async function ensureLocalMediaForNativeNegotiation() {
    if (!shouldMaintainNativePeerConnections()) return false;
    if (refs.localStreamRef.value instanceof MediaStream) {
      if (refs.controlState.micEnabled !== false && !streamHasLiveTrackKind(refs.localStreamRef.value, 'audio')) {
        return refs.reconfigureLocalTracksFromSelectedDevices();
      }
      return true;
    }
    return ensureLocalMediaForPublish();
  }

  async function flushNativePendingIce(peer) {
    if (!peer?.pc) return;
    if (!peer.pc.remoteDescription || !peer.pc.remoteDescription.type) return;
    const pending = Array.isArray(peer.pendingIce) ? [...peer.pendingIce] : [];
    peer.pendingIce = [];
    for (const candidate of pending) {
      try {
        await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch {
      }
    }
  }

  async function sendNativeOffer(peer) {
    if (!peer?.pc) return;
    if (!shouldMaintainNativePeerConnections()) return;
    if (peer.negotiating) {
      peer.needsRenegotiate = true;
      return;
    }
    if (peer.pc.signalingState === 'closed') return;
    if (currentShouldUseNativeAudioBridge() && !(await ensureNativeAudioBridgeSecurityReady(peer, 'native_offer'))) {
      scheduleNativeOfferRetry(peer, 'media_security_not_ready');
      return;
    }

    peer.negotiating = true;
    try {
      const mediaReady = await ensureLocalMediaForNativeNegotiation();
      if (
        currentShouldUseNativeAudioBridge()
        && shouldExpectLocalNativeAudioTrack()
        && (!mediaReady || !nativeAudioBridgeHasLocalAudioTrack())
      ) {
        reportNativeAudioSdpRejected(
          peer,
          'native_audio_offer_without_local_track',
          'Native protected audio bridge cannot create an offer without a live local mic track.',
          {
            media_ready: Boolean(mediaReady),
            mic_enabled: shouldExpectLocalNativeAudioTrack(),
          },
        );
        return;
      }
      let local = peer.pc.localDescription;
      if (!(peer.pc.signalingState === 'have-local-offer' && local?.type === 'offer' && local?.sdp)) {
        await syncNativePeerLocalTracks(peer);
        const offer = await peer.pc.createOffer();
        await peer.pc.setLocalDescription(offer);
        local = peer.pc.localDescription;
      }
      if (!local || !local.sdp) return;
      if (
        currentShouldUseNativeAudioBridge()
        && shouldExpectLocalNativeAudioTrack()
        && !refs.nativeSdpHasSendableAudio(local.sdp)
      ) {
        reportNativeAudioSdpRejected(
          peer,
          'native_audio_offer_without_send_audio',
          'Native protected audio bridge blocked an offer without send-capable audio.',
          {
            sdp_summary: refs.nativeSdpAudioSummary(local.sdp),
            sdp_audio_summaries: refs.nativeSdpAudioSummaries(local.sdp),
            signaling_state: String(peer.pc.signalingState || ''),
            local_tracks: nativeAudioBridgeLocalTrackTelemetry(),
            peer: refs.nativePeerConnectionTelemetry(peer),
          },
        );
        return;
      }

      const sent = sendSocketFrame({
        type: 'call/offer',
        target_user_id: peer.userId,
        payload: {
          kind: 'webrtc_offer',
          runtime_path: 'webrtc_native',
          room_id: refs.activeRoomId.value,
          sdp: {
            type: local.type,
            sdp: local.sdp,
          },
        },
      });
      peer.lastOfferSentAtMs = Date.now();
      if (sent) {
        scheduleNativeOfferRetry(peer, 'offer_unanswered');
      } else {
        scheduleNativeOfferRetry(peer, 'socket_not_ready');
      }
    } catch (error) {
      mediaDebugLog('[WebRTC] Could not create/send offer for peer', peer.userId, error);
      scheduleNativeOfferRetry(peer, 'offer_failed');
    } finally {
      peer.negotiating = false;
      if (peer.needsRenegotiate) {
        peer.needsRenegotiate = false;
        void sendNativeOffer(peer);
      }
    }
  }

  return {
    attachMediaSecurityNativeReceiver,
    attachMediaSecurityNativeReceiversForPeer,
    currentNativeIceServers,
    ensureLocalMediaForNativeNegotiation,
    flushNativePendingIce,
    loadDynamicIceServers,
    nativeAudioBridgeHasLocalAudioTrack,
    nativeAudioBridgeLocalTrackTelemetry,
    nativeWebRtcConfig,
    sendNativeOffer,
    shouldExpectLocalNativeAudioTrack,
    shouldExpectRemoteNativeAudioTrack,
    syncNativePeerLocalTracks,
    synchronizeNativePeerMediaElements,
  };
}
