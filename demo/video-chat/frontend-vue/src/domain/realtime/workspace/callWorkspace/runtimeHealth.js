import {
  logSfuVideoRecoveryStatus,
  shouldExposeSfuVideoRecoveryAttempt,
} from '../../sfu/videoConnectionStatus';

export function createCallWorkspaceRuntimeHealthHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    bumpMediaRenderVersion,
    captureClientDiagnostic,
    mediaDebugLog,
    restartSfuAfterVideoStall,
    sendSocketFrame,
  } = callbacks;
  const {
    mediaSecuritySessionClass,
    defaultNativeAudioBridgeFailureMessage,
    remoteVideoFreezeThresholdMs,
    remoteVideoStallCheckIntervalMs,
    remoteVideoStallThresholdMs,
    sfuRuntimeEnabled,
  } = constants;
  const {
    connectedParticipantUsers,
    connectionState,
    currentUserId,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    remotePeersRef,
    sfuClientRef,
    sfuConnected,
    shouldConnectSfu,
    videoEncoderRef,
  } = refs;
  const sfuRemoteVideoFrozenConsoleLabel = '[KingRT] SFU remote video frozen';
  const sfuNoVideoSignalConsoleLabel = '[KingRT] 📵 No video signal from SFU publisher';
  const {
    getRemoteVideoStallTimer,
    setRemoteVideoStallTimer,
  } = state;

  function resetWlvcEncoderAfterDroppedEncodedFrame(reason = 'dropped_encoded_frame') {
    const encoder = videoEncoderRef.value;
    if (!encoder || typeof encoder.reset !== 'function') return;
    try {
      encoder.reset();
    } catch (error) {
      mediaDebugLog('[SFU] WLVC encoder reset after dropped encoded frame failed', reason, error);
    }
  }

  function isWlvcRuntimePath() {
    return mediaRuntimePath.value === 'wlvc_wasm';
  }

  function isNativeWebRtcRuntimePath() {
    return mediaRuntimePath.value === 'webrtc_native';
  }

  function shouldUseNativeAudioBridge() {
    if (!mediaSecuritySessionClass.supportsNativeTransforms()) {
      return false;
    }
    return sfuRuntimeEnabled
      && isWlvcRuntimePath()
      && Boolean(mediaRuntimeCapabilities.value.stageB);
  }

  function nativeAudioBridgeFailureMessage() {
    return defaultNativeAudioBridgeFailureMessage();
  }

  function shouldMaintainNativePeerConnections() {
    return isNativeWebRtcRuntimePath() || shouldUseNativeAudioBridge();
  }

  function shouldSendNativeTrackKind(kind) {
    const normalizedKind = String(kind || '').trim().toLowerCase();
    if (normalizedKind === 'audio') return shouldMaintainNativePeerConnections();
    if (normalizedKind === 'video') return isNativeWebRtcRuntimePath();
    return false;
  }

  function shouldBlockNativeRuntimeSignaling() {
    return sfuRuntimeEnabled && mediaRuntimePath.value === 'pending';
  }

  function setRemoteVideoStatus(peer, stateName, message, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return false;
    const normalizedState = String(stateName || '').trim().toLowerCase();
    const normalizedMessage = String(message || '').trim();
    if (
      String(peer.mediaConnectionState || '') === normalizedState
      && String(peer.mediaConnectionMessage || '') === normalizedMessage
    ) {
      return false;
    }
    peer.mediaConnectionState = normalizedState;
    peer.mediaConnectionMessage = normalizedMessage;
    peer.mediaConnectionUpdatedAtMs = nowMs;
    if (typeof bumpMediaRenderVersion === 'function') {
      bumpMediaRenderVersion();
    }
    return true;
  }

  function retrySfuSubscription(publisherId, peer, reason, nowMs = Date.now()) {
    if (!sfuClientRef.value) return false;
    sfuClientRef.value.subscribe(publisherId);
    if (peer && typeof peer === 'object') {
      peer.lastSubscribeRetryAtMs = nowMs;
      peer.lastSubscribeRetryReason = String(reason || '').trim();
    }
    return true;
  }

  function remoteVideoReconnectThresholdMs() {
    return Math.max(remoteVideoFreezeThresholdMs * 3, remoteVideoStallThresholdMs * 2);
  }

  function remoteVideoSocketRestartBackoffMs(attempt) {
    const normalizedAttempt = Math.max(1, Math.floor(Number(attempt || 1)));
    const baseMs = Math.max(remoteVideoReconnectThresholdMs(), remoteVideoStallThresholdMs * 3);
    return Math.min(60_000, baseMs * (2 ** Math.min(3, normalizedAttempt - 1)));
  }

  function canRequestSfuSocketRestartForPeer(peer, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return false;
    const nextAllowedAtMs = Number(peer.nextSfuSocketRestartAllowedAtMs || 0);
    return nextAllowedAtMs <= 0 || nowMs >= nextAllowedAtMs;
  }

  function sfuSocketRestartBackoffRemainingMs(peer, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return 0;
    const nextAllowedAtMs = Number(peer.nextSfuSocketRestartAllowedAtMs || 0);
    return nextAllowedAtMs > 0 ? Math.max(0, nextAllowedAtMs - nowMs) : 0;
  }

  function requestSfuSocketRestartForPeer(reason, peer, payload = {}, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return false;
    if (!canRequestSfuSocketRestartForPeer(peer, nowMs)) return false;

    const restartAttempt = Math.max(1, Number(peer.sfuSocketRestartCount || 0) + 1);
    const restartBackoffMs = remoteVideoSocketRestartBackoffMs(restartAttempt);
    const restarted = restartSfuAfterVideoStall(reason, {
      ...payload,
      sfu_socket_restart_attempt: restartAttempt,
      sfu_socket_restart_backoff_ms: restartBackoffMs,
    });
    if (!restarted) return false;

    peer.sfuSocketRestartCount = restartAttempt;
    peer.lastSfuSocketRestartAtMs = nowMs;
    peer.nextSfuSocketRestartAllowedAtMs = nowMs + restartBackoffMs;
    peer.stalledLoggedAtMs = nowMs;
    setRemoteVideoStatus(peer, 'recovering', 'Reconnecting video', nowMs);
    return true;
  }

  function sendRemoteSfuVideoQualityPressure(peer, publisherId, reason, nowMs, payload = {}) {
    if (typeof sendSocketFrame !== 'function') return false;
    const targetUserId = Number(peer?.userId || 0);
    const localUserId = Number(currentUserId.value || 0);
    if (!Number.isInteger(targetUserId) || targetUserId <= 0 || targetUserId === localUserId) return false;
    const normalizedReason = String(reason || 'sfu_remote_video_frozen').trim().toLowerCase();
    const requestFullKeyframe = normalizedReason === 'sfu_remote_video_decoder_waiting_keyframe';
    const requestedAction = String(
      payload?.requested_action || (requestFullKeyframe ? 'force_full_keyframe' : 'downgrade_outgoing_video'),
    ).trim().toLowerCase();

    const lastSentAtMs = Number(peer.lastQualityPressureSentAtMs || 0);
    const minIntervalMs = Math.max(remoteVideoFreezeThresholdMs * 2, 4000);
    if (lastSentAtMs > 0 && (nowMs - lastSentAtMs) < minIntervalMs) return false;

    const sent = sendSocketFrame({
      type: 'call/media-quality-pressure',
      target_user_id: targetUserId,
        payload: {
          kind: 'sfu-video-quality-pressure',
          requested_action: requestedAction,
          request_full_keyframe: Boolean(payload?.request_full_keyframe) || requestFullKeyframe,
          reason: normalizedReason,
          publisher_id: String(publisherId || ''),
        requester_user_id: localUserId,
        media_runtime_path: mediaRuntimePath.value,
        ...payload,
      },
    });
    if (sent) {
      peer.lastQualityPressureSentAtMs = nowMs;
      peer.lastQualityPressureReason = String(reason || '').trim();
    }
    return sent;
  }

  function checkRemoteVideoStalls() {
    if (!isWlvcRuntimePath() || !shouldConnectSfu.value) return;

    const nowMs = Date.now();
    for (const [publisherId, peer] of remotePeersRef.value.entries()) {
      if (!peer || typeof peer !== 'object' || !peer.decoder) continue;
      const trackCount = Array.isArray(peer.tracks) ? peer.tracks.length : 0;
      if (trackCount <= 0) continue;

      const createdAtMs = Number(peer.createdAtMs || 0);
      const frameCount = Number(peer.frameCount || 0);
      const stalledLoggedAtMs = Number(peer.stalledLoggedAtMs || 0);
      const lastFrameAtMs = Number(peer.lastFrameAtMs || 0);
      const lastReceivedFrameAtMs = Number(peer.lastReceivedFrameAtMs || 0);
      if (createdAtMs <= 0) continue;

      if (frameCount > 0) {
        if (lastFrameAtMs <= 0 || (nowMs - lastFrameAtMs) < remoteVideoFreezeThresholdMs) {
          continue;
        }
        if (stalledLoggedAtMs > 0 && (nowMs - stalledLoggedAtMs) < remoteVideoFreezeThresholdMs) {
          continue;
        }

        const frozenAgeMs = Math.max(0, nowMs - lastFrameAtMs);
        const receiveGapMs = lastReceivedFrameAtMs > 0 ? Math.max(0, nowMs - lastReceivedFrameAtMs) : frozenAgeMs;
        const receivingFreshFrames = lastReceivedFrameAtMs > 0 && receiveGapMs < remoteVideoFreezeThresholdMs;
        const shouldRestartFrozenVideo = receiveGapMs >= remoteVideoReconnectThresholdMs();
        const socketRestartBackoffRemainingMs = sfuSocketRestartBackoffRemainingMs(peer, nowMs);
        const canRestartFrozenVideo = shouldRestartFrozenVideo
          && canRequestSfuSocketRestartForPeer(peer, nowMs);
        peer.stalledLoggedAtMs = nowMs;
        peer.freezeRecoveryCount = Number(peer.freezeRecoveryCount || 0) + 1;
        setRemoteVideoStatus(peer, 'recovering', 'Reconnecting video', nowMs);
        const freezeQualityDowngradeReason = receivingFreshFrames
          ? 'sfu_remote_video_decoder_waiting_keyframe'
          : 'sfu_remote_video_frozen';
        const shouldSendRemoteQualityPressure = receivingFreshFrames || peer.freezeRecoveryCount >= 2;
        const remoteQualityPressureSent = shouldSendRemoteQualityPressure
          ? sendRemoteSfuVideoQualityPressure(
            peer,
            publisherId,
            freezeQualityDowngradeReason,
            nowMs,
            {
              frozen_age_ms: frozenAgeMs,
              receive_gap_ms: receiveGapMs,
              freeze_recovery_count: Number(peer.freezeRecoveryCount || 0),
              frame_count: frameCount,
              received_frame_count: Number(peer.receivedFrameCount || 0),
            }
          )
          : false;
        if (shouldExposeSfuVideoRecoveryAttempt(peer.freezeRecoveryCount)) {
          logSfuVideoRecoveryStatus(sfuRemoteVideoFrozenConsoleLabel, {
            ageMs: frozenAgeMs,
            attempt: peer.freezeRecoveryCount,
            localUserId: currentUserId.value,
            peer,
            publisherId,
            receiveGapMs,
            runtime: mediaRuntimePath.value,
            state: 'frozen',
          });
        }
        if (typeof peer.decoder?.reset === 'function' && receivingFreshFrames) {
          try {
            peer.decoder.reset();
            peer.needsKeyframe = true;
          } catch {
          }
        }
        if (receivingFreshFrames) {
          captureClientDiagnostic({
            category: 'media',
            level: 'warning',
            eventType: 'sfu_remote_video_decoder_waiting_keyframe',
            code: 'sfu_remote_video_decoder_waiting_keyframe',
            message: 'Remote SFU video is receiving frames but waiting for a renderable keyframe before restarting transport.',
            payload: {
              publisher_id: publisherId,
              publisher_user_id: Number(peer.userId || 0),
              publisher_name: String(peer.displayName || '').trim(),
              track_count: trackCount,
              frame_count: frameCount,
              received_frame_count: Number(peer.receivedFrameCount || 0),
              frozen_age_ms: frozenAgeMs,
              receive_gap_ms: receiveGapMs,
              freeze_recovery_count: Number(peer.freezeRecoveryCount || 0),
              remote_quality_pressure_sent: remoteQualityPressureSent,
              remote_peer_count: remotePeersRef.value.size,
              sfu_connected: sfuConnected.value,
              connection_state: connectionState.value,
              media_runtime_path: mediaRuntimePath.value,
            },
            immediate: true,
          });
          retrySfuSubscription(publisherId, peer, 'remote_video_decoder_waiting_keyframe', nowMs);
          continue;
        }
        retrySfuSubscription(publisherId, peer, 'remote_video_frozen', nowMs);
        const socketRestarted = canRestartFrozenVideo
          ? requestSfuSocketRestartForPeer('remote_video_frozen', peer, {
            publisher_id: publisherId,
            publisher_user_id: Number(peer.userId || 0),
            frozen_age_ms: frozenAgeMs,
            receive_gap_ms: receiveGapMs,
            freeze_recovery_count: Number(peer.freezeRecoveryCount || 0),
          }, nowMs)
          : false;
        captureClientDiagnostic({
          category: 'media',
          level: 'error',
          eventType: 'sfu_remote_video_frozen',
          code: 'sfu_remote_video_frozen',
          message: 'Remote SFU video rendered earlier but stopped producing fresh decoded frames.',
          payload: {
            publisher_id: publisherId,
            publisher_user_id: Number(peer.userId || 0),
            publisher_name: String(peer.displayName || '').trim(),
            track_count: trackCount,
            frame_count: frameCount,
            received_frame_count: Number(peer.receivedFrameCount || 0),
            frozen_age_ms: frozenAgeMs,
            receive_gap_ms: receiveGapMs,
            freeze_recovery_count: Number(peer.freezeRecoveryCount || 0),
            remote_quality_pressure_sent: remoteQualityPressureSent,
            socket_restart_attempted: socketRestarted,
            socket_restart_deferred: shouldRestartFrozenVideo && !socketRestarted,
            socket_restart_backoff_remaining_ms: socketRestartBackoffRemainingMs,
            sfu_socket_restart_count: Number(peer.sfuSocketRestartCount || 0),
            next_sfu_socket_restart_allowed_at_ms: Number(peer.nextSfuSocketRestartAllowedAtMs || 0),
            remote_video_reconnect_threshold_ms: remoteVideoReconnectThresholdMs(),
            remote_peer_count: remotePeersRef.value.size,
            sfu_connected: sfuConnected.value,
            connection_state: connectionState.value,
            media_runtime_path: mediaRuntimePath.value,
          },
          immediate: true,
        });
        continue;
      }

      if ((nowMs - createdAtMs) < remoteVideoStallThresholdMs) {
        setRemoteVideoStatus(peer, 'connecting', 'Connecting media', nowMs);
        continue;
      }
      if (stalledLoggedAtMs > 0 && (nowMs - stalledLoggedAtMs) < remoteVideoStallThresholdMs) continue;

      const stalledAgeMs = Math.max(0, nowMs - createdAtMs);
      peer.stallRecoveryCount = Number(peer.stallRecoveryCount || 0) + 1;
      setRemoteVideoStatus(peer, 'recovering', 'Reconnecting video', nowMs);
      const shouldRestartNeverStartedVideo = stalledAgeMs >= remoteVideoStallThresholdMs * 2;
      const socketRestartBackoffRemainingMs = sfuSocketRestartBackoffRemainingMs(peer, nowMs);
      const canRestartNeverStartedVideo = shouldRestartNeverStartedVideo
        && canRequestSfuSocketRestartForPeer(peer, nowMs);
      const remoteQualityPressureSent = peer.stallRecoveryCount >= 2
        ? sendRemoteSfuVideoQualityPressure(peer, publisherId, 'sfu_remote_video_never_started', nowMs, {
          age_ms: stalledAgeMs,
          stall_recovery_count: Number(peer.stallRecoveryCount || 0),
          received_frame_count: Number(peer.receivedFrameCount || 0),
        })
        : false;
      if (stalledAgeMs > remoteVideoStallThresholdMs * 3) {
        logSfuVideoRecoveryStatus(sfuNoVideoSignalConsoleLabel, {
          ageMs: stalledAgeMs,
          attempt: Math.max(3, Number(peer.stallRecoveryCount || 0)),
          localUserId: currentUserId.value,
          peer,
          publisherId,
          runtime: mediaRuntimePath.value,
          state: 'never_started',
        });
      }

      peer.stalledLoggedAtMs = nowMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'error',
        eventType: 'sfu_remote_video_stalled',
        code: 'sfu_remote_video_stalled',
        message: 'Remote publisher advertised tracks but no decoded video frames arrived.',
        payload: {
          publisher_id: publisherId,
          publisher_user_id: Number(peer.userId || 0),
          publisher_name: String(peer.displayName || '').trim(),
          track_count: trackCount,
          frame_count: frameCount,
          received_frame_count: Number(peer.receivedFrameCount || 0),
          age_ms: stalledAgeMs,
          remote_quality_pressure_sent: remoteQualityPressureSent,
          remote_peer_count: remotePeersRef.value.size,
          connected_participant_count: connectedParticipantUsers.value.length,
          sfu_connected: sfuConnected.value,
          connection_state: connectionState.value,
          media_runtime_path: mediaRuntimePath.value,
        },
        immediate: true,
      });

      if (retrySfuSubscription(publisherId, peer, 'remote_video_never_started', nowMs)) {
        // The preceding backend diagnostic carries the stalled publisher details.
      }
      const socketRestarted = canRestartNeverStartedVideo
        ? requestSfuSocketRestartForPeer('remote_video_never_started', peer, {
          publisher_id: publisherId,
          publisher_user_id: Number(peer.userId || 0),
          age_ms: stalledAgeMs,
          stall_recovery_count: Number(peer.stallRecoveryCount || 0),
        }, nowMs)
        : false;
      if (shouldRestartNeverStartedVideo || socketRestartBackoffRemainingMs > 0) {
        captureClientDiagnostic({
          category: 'media',
          level: socketRestarted ? 'warning' : 'info',
          eventType: 'sfu_remote_video_reconnect_gate',
          code: 'sfu_remote_video_reconnect_gate',
          message: 'Remote SFU video recovery gated hard socket reconnect behind per-peer backoff.',
          payload: {
            publisher_id: publisherId,
            publisher_user_id: Number(peer.userId || 0),
            age_ms: stalledAgeMs,
            stall_recovery_count: Number(peer.stallRecoveryCount || 0),
            socket_restart_attempted: socketRestarted,
            socket_restart_deferred: shouldRestartNeverStartedVideo && !socketRestarted,
            socket_restart_backoff_remaining_ms: socketRestartBackoffRemainingMs,
            sfu_socket_restart_count: Number(peer.sfuSocketRestartCount || 0),
            next_sfu_socket_restart_allowed_at_ms: Number(peer.nextSfuSocketRestartAllowedAtMs || 0),
            remote_video_reconnect_threshold_ms: remoteVideoReconnectThresholdMs(),
            sfu_connected: sfuConnected.value,
            connection_state: connectionState.value,
            media_runtime_path: mediaRuntimePath.value,
          },
          immediate: socketRestarted,
        });
      }
    }
  }

  function startRemoteVideoStallTimer() {
    const timer = getRemoteVideoStallTimer();
    if (timer !== null) {
      clearInterval(timer);
    }
    setRemoteVideoStallTimer(setInterval(checkRemoteVideoStalls, remoteVideoStallCheckIntervalMs));
  }

  function clearRemoteVideoStallTimer() {
    const timer = getRemoteVideoStallTimer();
    if (timer === null) return;
    clearInterval(timer);
    setRemoteVideoStallTimer(null);
  }

  return {
    checkRemoteVideoStalls,
    clearRemoteVideoStallTimer,
    isNativeWebRtcRuntimePath,
    isWlvcRuntimePath,
    nativeAudioBridgeFailureMessage,
    resetWlvcEncoderAfterDroppedEncodedFrame,
    shouldBlockNativeRuntimeSignaling,
    shouldMaintainNativePeerConnections,
    shouldSendNativeTrackKind,
    shouldUseNativeAudioBridge,
    startRemoteVideoStallTimer,
  };
}
