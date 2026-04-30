import { closeProtectedBrowserVideoDecoders } from './remoteBrowserEncodedVideo';

export function createSfuLifecycleHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    captureClientDiagnostic,
    captureClientDiagnosticError,
    createOrUpdateSfuRemotePeer,
    currentUserId,
    deleteSfuRemotePeer,
    handleSFUEncodedFrame,
    handleSfuPublisherPressure,
    isWlvcRuntimePath,
    maybeFallbackToNativeRuntime,
    mediaDebugLog,
    normalizeSfuPublisherId,
    clearMediaSecuritySfuPublisherSeen,
    noteMediaSecuritySfuPublisherSeen,
    publishLocalTracks,
    publishLocalTracksToSfuIfReady,
    renderCallVideoLayout,
    requestWlvcFullFrameKeyframe,
    requestSfuConnect,
    resetWlvcBackpressureCounters,
    scheduleMediaSecurityParticipantSync,
    setSfuRemotePeer,
    sfuTrackListHasVideo,
    sfuTrackRows,
    teardownSfuRemotePeers,
  } = callbacks;

  const {
    mediaSecuritySfuTargetSettleMs,
    sfuConnectMaxRetries,
    sfuConnectRetryDelayMs,
    sfuPublishMaxRetries,
    sfuPublishRetryDelayMs,
    sfuTrackAnnounceIntervalMs,
  } = constants;

  function scheduleLocalTrackPublish(attempt = 0) {
    if (!refs.sfuClientRef.value || !refs.sfuConnected.value) return;
    void publishLocalTracks();
    if (refs.localStreamRef.value instanceof MediaStream && state.localTracksPublishedToSfu) return;
    if (attempt >= sfuPublishMaxRetries) return;
    setTimeout(() => {
      scheduleLocalTrackPublish(attempt + 1);
    }, sfuPublishRetryDelayMs);
  }

  function initSFU() {
    if (refs.sfuClientRef.value) return;

    const token = String(refs.sessionState.sessionToken || '').trim();
    if (!token) return;
    if (!refs.shouldConnectSfu.value) return;

    const socketCallId = refs.activeSocketCallId.value;
    if (socketCallId === '') return;

    refs.sfuClientRef.value = new refs.SFUClient({
      onTracks: (event) => handleSFUTracks(event),
      onUnpublished: (publisherId, trackId) => handleSFUUnpublished(publisherId, trackId),
      onPublisherLeft: (publisherId) => handleSFUPublisherLeft(publisherId),
      onConnected: () => {
        refs.sfuConnected.value = true;
        state.sfuConnectRetryCount = 0;
        resetWlvcBackpressureCounters();
        if (typeof requestWlvcFullFrameKeyframe === 'function') {
          requestWlvcFullFrameKeyframe('sfu_socket_connected', {
            requested_action: 'force_full_keyframe',
            request_full_keyframe: true,
          });
        }
        startSfuTrackAnnounceTimer();
        scheduleLocalTrackPublish();
      },
      onDisconnect: () => {
        const hadActiveConnection = refs.sfuConnected.value;
        refs.sfuConnected.value = false;
        state.localTracksPublishedToSfu = false;
        stopSfuTrackAnnounceTimer();
        refs.sfuClientRef.value = null;
        if (refs.isManualSocketClose()) {
          state.sfuConnectRetryCount = 0;
          return;
        }
        if (!refs.shouldConnectSfu.value) {
          state.sfuConnectRetryCount = 0;
          return;
        }
        if (!hadActiveConnection) {
          if (state.sfuConnectRetryCount < sfuConnectMaxRetries) {
            state.sfuConnectRetryCount += 1;
            captureClientDiagnostic({
              category: 'media',
              level: 'warning',
              eventType: 'sfu_connect_retry_scheduled',
              code: 'sfu_connect_retry_scheduled',
              message: 'SFU connection retry was scheduled before the call became active.',
              payload: {
                retry_count: state.sfuConnectRetryCount,
                retry_max: sfuConnectMaxRetries,
                retry_delay_ms: sfuConnectRetryDelayMs,
                media_runtime_path: refs.mediaRuntimePath.value,
              },
            });
            setTimeout(() => requestSfuConnect(), sfuConnectRetryDelayMs);
            return;
          }
          captureClientDiagnostic({
            category: 'media',
            level: 'error',
            eventType: 'sfu_connect_exhausted',
            code: 'sfu_connect_exhausted',
            message: 'SFU connection retries were exhausted before the call could become active.',
            payload: {
              retry_count: state.sfuConnectRetryCount,
              media_runtime_path: refs.mediaRuntimePath.value,
              connection_state: refs.connectionState.value,
            },
            immediate: true,
          });
          state.sfuConnectRetryCount = 0;
          void maybeFallbackToNativeRuntime('sfu_connect_failed');
          return;
        }
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_disconnected_after_connect',
          code: 'sfu_disconnected_after_connect',
          message: 'SFU disconnected after the call was already connected.',
          payload: {
            media_runtime_path: refs.mediaRuntimePath.value,
            connection_state: refs.connectionState.value,
            remote_peer_count: refs.remotePeersRef.value.size,
          },
        });
        state.sfuConnectRetryCount = 0;
        setTimeout(() => requestSfuConnect(), 2000);
      },
      onEncodedFrame: (frame) => handleSFUEncodedFrame(frame),
      onPublisherPressure: (details) => handleSfuPublisherPressureMessage(details),
    });

    refs.sfuClientRef.value.connect(
      { userId: String(refs.sessionState.userId), token, name: refs.sessionState.displayName || 'User' },
      refs.activeRoomId.value,
      socketCallId,
    );
    refs.sfuConnected.value = false;
  }

  function handleSfuPublisherPressureMessage(details = {}) {
    const requestedAction = String(details?.requested_action || details?.requestedAction || '').trim().toLowerCase();
    const requestFullKeyframe = Boolean(details?.request_full_keyframe || details?.requestFullKeyframe)
      || requestedAction === 'force_full_keyframe';
    if (requestFullKeyframe && typeof requestWlvcFullFrameKeyframe === 'function') {
      return requestWlvcFullFrameKeyframe(String(details?.reason || 'sfu_publisher_recovery_request'), {
        ...details,
        senderUserId: Number(details?.requester_user_id || details?.requesterUserId || 0),
      });
    }
    return handleSfuPublisherPressure?.(details);
  }

  function teardownRemotePeer(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (peer.pc) {
      try { peer.pc.close(); } catch {}
    }
    if (peer.decoder) {
      try { peer.decoder.destroy(); } catch {}
    }
    if (peer.patchDecoder) {
      try { peer.patchDecoder.destroy(); } catch {}
    }
    closeProtectedBrowserVideoDecoders(peer);
    if (peer.video instanceof HTMLElement) {
      peer.video.remove();
    }
    if (peer.decodedCanvas instanceof HTMLElement) {
      peer.decodedCanvas.remove();
    }
  }

  function removeSfuRemotePeersForUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;

    let removedAny = false;
    for (const [publisherId, peer] of Array.from(refs.remotePeersRef.value.entries())) {
      if (Number(peer?.userId || 0) !== normalizedUserId) continue;
      teardownRemotePeer(peer);
      removedAny = deleteSfuRemotePeer(publisherId) || removedAny;
    }
    if (removedAny) {
      if (clearMediaSecuritySfuPublisherSeen(normalizedUserId)) {
        scheduleMediaSecurityParticipantSync('sfu_publisher_removed');
      }
      renderCallVideoLayout();
    }
    return removedAny;
  }

  function handleSFUTracks(event) {
    if (!isWlvcRuntimePath()) return;
    const publisherId = normalizeSfuPublisherId(event?.publisherId);
    const publisherUserId = Number(event?.publisherUserId || 0);
    if (publisherId === '') return;
    const init = createOrUpdateSfuRemotePeer({
      publisherId,
      publisherUserId,
      publisherName: event?.publisherName,
      tracks: event?.tracks,
    }).catch((error) => {
      mediaDebugLog('[SFU] Could not subscribe to publisher', publisherId, error);
      captureClientDiagnosticError('sfu_subscribe_failed', error, {
        publisher_id: publisherId,
        publisher_user_id: publisherUserId,
        track_count: Array.isArray(event?.tracks) ? event.tracks.length : 0,
      }, {
        code: 'sfu_subscribe_failed',
        immediate: true,
      });
      return null;
    });
    refs.pendingSfuRemotePeerInitializers.set(
      publisherId,
      init.finally(() => refs.pendingSfuRemotePeerInitializers.delete(publisherId)),
    );
    if (Number.isInteger(publisherUserId) && publisherUserId > 0 && publisherUserId !== currentUserId()) {
      if (noteMediaSecuritySfuPublisherSeen(publisherUserId)) {
        setTimeout(() => {
          scheduleMediaSecurityParticipantSync('sfu_tracks_discovered');
        }, mediaSecuritySfuTargetSettleMs);
      } else {
        scheduleMediaSecurityParticipantSync('sfu_tracks_seen_again');
      }
    }
  }

  function handleSFUUnpublished(publisherId, trackId) {
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    const peer = refs.remotePeersRef.value.get(normalizedPublisherId);
    if (!peer) return;

    const normalizedTrackId = String(trackId || '').trim();
    const nextTracks = sfuTrackRows(peer.tracks)
      .filter((track) => String(track?.id || '').trim() !== normalizedTrackId);

    if (nextTracks.length > 0 && sfuTrackListHasVideo(nextTracks)) {
      setSfuRemotePeer(normalizedPublisherId, {
        ...peer,
        tracks: nextTracks,
      });
      renderCallVideoLayout();
      return;
    }

    teardownRemotePeer(peer);
    deleteSfuRemotePeer(normalizedPublisherId);
    const peerUserId = Number(peer?.userId || 0);
    if (Number.isInteger(peerUserId) && peerUserId > 0 && clearMediaSecuritySfuPublisherSeen(peerUserId)) {
      scheduleMediaSecurityParticipantSync('sfu_publisher_unpublished');
    }
    renderCallVideoLayout();
  }

  function handleSFUPublisherLeft(publisherId) {
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    const peer = refs.remotePeersRef.value.get(normalizedPublisherId);
    if (peer) {
      teardownRemotePeer(peer);
      deleteSfuRemotePeer(normalizedPublisherId);
      const peerUserId = Number(peer?.userId || 0);
      if (Number.isInteger(peerUserId) && peerUserId > 0 && clearMediaSecuritySfuPublisherSeen(peerUserId)) {
        scheduleMediaSecurityParticipantSync('sfu_publisher_left');
      }
      renderCallVideoLayout();
    }
  }

  function clearRemoteVideoContainer() {
    if (typeof document === 'undefined') return;
    const container = document.getElementById('remote-video-container');
    if (container) {
      container.replaceChildren();
    }
  }

  function stopSfuTrackAnnounceTimer() {
    if (state.sfuTrackAnnounceTimer !== null) {
      clearInterval(state.sfuTrackAnnounceTimer);
      state.sfuTrackAnnounceTimer = null;
    }
  }

  function startSfuTrackAnnounceTimer() {
    stopSfuTrackAnnounceTimer();
    if (!refs.sfuConnected.value) return;
    state.sfuTrackAnnounceTimer = setInterval(() => {
      publishLocalTracksToSfuIfReady({ force: true });
    }, sfuTrackAnnounceIntervalMs);
  }

  return {
    clearRemoteVideoContainer,
    handleSFUPublisherLeft,
    handleSFUTracks,
    handleSFUUnpublished,
    initSFU,
    removeSfuRemotePeersForUserId,
    scheduleLocalTrackPublish,
    startSfuTrackAnnounceTimer,
    stopSfuTrackAnnounceTimer,
    teardownRemotePeer,
  };
}
