export function createCallWorkspaceRuntimeSwitchingHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    appendMediaRuntimeTransitionEvent,
    captureClientDiagnostic,
    mediaDebugLog,
    resolveSfuVideoQualityProfile,
    setCallOutgoingVideoQualityProfile,
    startEncodingPipeline,
    stopLocalEncodingPipeline,
    syncNativePeerConnectionsWithRoster,
    syncNativePeerLocalTracks,
    synchronizeNativePeerMediaElements,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
    publishLocalTracks,
    shouldSyncNativeLocalTracksBeforeOffer,
    shouldUseNativeAudioBridge,
  } = callbacks;

  const {
    sfuAutoQualityDowngradeCooldownMs,
    sfuAutoQualityDowngradeNext,
    sfuRuntimeEnabled,
  } = constants;
  const immediateQualityPressureReasons = Object.freeze([
    'sfu_frame_send_failed',
    'sfu_high_motion_payload_pressure',
    'sfu_remote_quality_pressure',
    'sfu_remote_video_decoder_waiting_keyframe',
    'sfu_remote_video_frozen',
    'sfu_send_backpressure',
    'sfu_send_backpressure_critical',
    'send_buffer_drain_timeout',
  ]);

  function setMediaRuntimePath(nextPath, reason) {
    const previousPath = refs.mediaRuntimePath.value;
    const normalizedPath = String(nextPath || '').trim() || 'unsupported';
    const normalizedReason = String(reason || '').trim() || 'unspecified';

    refs.mediaRuntimePath.value = normalizedPath;
    refs.mediaRuntimeReason.value = normalizedReason;

    if (previousPath !== normalizedPath) {
      appendMediaRuntimeTransitionEvent({
        from_path: previousPath,
        to_path: normalizedPath,
        reason: normalizedReason,
        user_id: refs.currentUserId.value,
        call_id: refs.activeCallId.value,
        room_id: refs.activeRoomId.value,
        capabilities: {
          stage_a: refs.mediaRuntimeCapabilities.value.stageA,
          stage_b: refs.mediaRuntimeCapabilities.value.stageB,
          preferred_path: refs.mediaRuntimeCapabilities.value.preferredPath,
          reasons: refs.mediaRuntimeCapabilities.value.reasons,
        },
      });
    }
  }

  async function switchMediaRuntimePath(nextPath, reason = 'unspecified') {
    const normalizedNextPath = String(nextPath || '').trim();
    if (!['wlvc_wasm', 'webrtc_native', 'unsupported'].includes(normalizedNextPath)) {
      return false;
    }
    if (state.getRuntimeSwitchInFlight()) return false;
    if (normalizedNextPath === refs.mediaRuntimePath.value) return true;

    if (normalizedNextPath === 'wlvc_wasm' && !refs.mediaRuntimeCapabilities.value.stageA) {
      return false;
    }
    if (normalizedNextPath === 'webrtc_native' && !refs.mediaRuntimeCapabilities.value.stageB) {
      return false;
    }

    state.setRuntimeSwitchInFlight(true);
    try {
      setMediaRuntimePath(normalizedNextPath, reason);

      if (normalizedNextPath === 'webrtc_native') {
        stopLocalEncodingPipeline();
        teardownSfuRemotePeers();
        if (!(refs.localStreamRef.value instanceof MediaStream)) {
          await publishLocalTracks();
        } else {
          const videoTrack = refs.localStreamRef.value.getVideoTracks?.()[0] || null;
          if (videoTrack) {
            await startEncodingPipeline(videoTrack);
          }
        }
        syncNativePeerConnectionsWithRoster();
        for (const peer of refs.nativePeerConnectionsRef.value.values()) {
          if (shouldSyncNativeLocalTracksBeforeOffer(peer)) {
            void syncNativePeerLocalTracks(peer);
          }
        }
        state.resetWlvcEncodeCounters();
      } else if (normalizedNextPath === 'wlvc_wasm') {
        if (shouldUseNativeAudioBridge()) {
          teardownNativePeerConnections();
          syncNativePeerConnectionsWithRoster();
          for (const peer of refs.nativePeerConnectionsRef.value.values()) {
            synchronizeNativePeerMediaElements(peer);
            if (shouldSyncNativeLocalTracksBeforeOffer(peer)) {
              void syncNativePeerLocalTracks(peer);
            }
          }
        } else {
          teardownNativePeerConnections();
        }
        state.resetWlvcEncodeCounters();
        const localStream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
        const videoTrack = localStream?.getVideoTracks?.()[0] || null;
        if (videoTrack) {
          await startEncodingPipeline(videoTrack);
        }
      } else {
        stopLocalEncodingPipeline();
        teardownNativePeerConnections();
        teardownSfuRemotePeers();
      }

      mediaDebugLog('[MediaRuntime] switched to', normalizedNextPath, 'reason=', reason);
      return true;
    } finally {
      state.setRuntimeSwitchInFlight(false);
    }
  }

  async function maybeFallbackToNativeRuntime(reason) {
    if (sfuRuntimeEnabled) return false;
    if (!refs.mediaRuntimeCapabilities.value.stageB) return false;
    return switchMediaRuntimePath('webrtc_native', reason);
  }

  function currentSfuVideoProfile() {
    return resolveSfuVideoQualityProfile(refs.callMediaPrefs.outgoingVideoQualityProfile);
  }

  function downgradeSfuVideoQualityAfterEncodePressure(reason = 'encode_pressure') {
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    const normalizedReason = String(reason || 'encode_pressure').trim().toLowerCase();
    const bypassQualityDowngradeCooldown = immediateQualityPressureReasons.includes(normalizedReason);
    let nextProfile = sfuAutoQualityDowngradeNext[currentProfile] || '';
    if (nextProfile === '') return false;

    const nowMs = Date.now();
    if (
      !bypassQualityDowngradeCooldown
      && (nowMs - refs.sfuTransportState.sfuAutoQualityDowngradeLastAtMs) < sfuAutoQualityDowngradeCooldownMs
    ) {
      return false;
    }
    refs.sfuTransportState.sfuAutoQualityDowngradeLastAtMs = nowMs;

    console.warn(
      '[KingRT] SFU encode pressure - lowering outgoing video quality',
      `from=${currentProfile || 'default'}`,
      `to=${nextProfile}`,
      `reason=${normalizedReason}`,
    );
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_encode_quality_downgraded',
      code: 'sfu_encode_quality_downgraded',
      message: 'Outgoing SFU video quality was lowered after repeated encode failures.',
      payload: {
        from_profile: currentProfile,
        to_profile: nextProfile,
        reason: normalizedReason,
        failure_count: state.getWlvcEncodeFailureCount(),
        media_runtime_path: refs.mediaRuntimePath.value,
      },
      immediate: true,
    });
    setCallOutgoingVideoQualityProfile(nextProfile);
    return true;
  }

  return {
    currentSfuVideoProfile,
    downgradeSfuVideoQualityAfterEncodePressure,
    maybeFallbackToNativeRuntime,
    setMediaRuntimePath,
    switchMediaRuntimePath,
  };
}
