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
    captureClientDiagnostic,
    mediaDebugLog,
    restartSfuAfterVideoStall,
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
        peer.stalledLoggedAtMs = nowMs;
        peer.freezeRecoveryCount = Number(peer.freezeRecoveryCount || 0) + 1;
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
        if (typeof peer.decoder?.reset === 'function' && receiveGapMs < remoteVideoFreezeThresholdMs) {
          try {
            peer.decoder.reset();
            peer.needsKeyframe = true;
          } catch {
          }
        }
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
            remote_peer_count: remotePeersRef.value.size,
            sfu_connected: sfuConnected.value,
            connection_state: connectionState.value,
            media_runtime_path: mediaRuntimePath.value,
          },
          immediate: true,
        });
        if (sfuClientRef.value) {
          sfuClientRef.value.subscribe(publisherId);
        }
        if (Number(peer.freezeRecoveryCount || 0) >= 2 || receiveGapMs >= remoteVideoFreezeThresholdMs * 2) {
          restartSfuAfterVideoStall('remote_video_frozen', {
            publisher_id: publisherId,
            publisher_user_id: Number(peer.userId || 0),
            frozen_age_ms: frozenAgeMs,
            receive_gap_ms: receiveGapMs,
            freeze_recovery_count: Number(peer.freezeRecoveryCount || 0),
          });
        }
        continue;
      }

      if ((nowMs - createdAtMs) < remoteVideoStallThresholdMs) continue;
      if (stalledLoggedAtMs > 0 && (nowMs - stalledLoggedAtMs) < remoteVideoStallThresholdMs) continue;

      const stalledAgeMs = Math.max(0, nowMs - createdAtMs);
      if (stalledAgeMs > remoteVideoStallThresholdMs * 3) {
        logSfuVideoRecoveryStatus(sfuNoVideoSignalConsoleLabel, {
          ageMs: stalledAgeMs,
          attempt: 3,
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
          remote_peer_count: remotePeersRef.value.size,
          connected_participant_count: connectedParticipantUsers.value.length,
          sfu_connected: sfuConnected.value,
          connection_state: connectionState.value,
          media_runtime_path: mediaRuntimePath.value,
        },
        immediate: true,
      });

      if (sfuClientRef.value && stalledAgeMs > remoteVideoStallThresholdMs * 2) {
        if (stalledAgeMs > remoteVideoStallThresholdMs * 3) {
          console.info(
            '[KingRT] Auto-resubscribe for stalled SFU publisher',
            `local_user=${currentUserId.value}`,
            `remote_user=${Number(peer.userId || 0)}`,
            `publisher=${publisherId}`,
          );
        }
        sfuClientRef.value.subscribe(publisherId);
      }
      if (stalledAgeMs > remoteVideoStallThresholdMs * 3) {
        restartSfuAfterVideoStall('remote_video_never_started', {
          publisher_id: publisherId,
          publisher_user_id: Number(peer.userId || 0),
          age_ms: stalledAgeMs,
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
