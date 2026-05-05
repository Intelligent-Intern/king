import {
  SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS,
  SFU_AUTO_QUALITY_RECOVERY_NEXT,
} from './runtimeConfig.ts';
import { publisherQualityTransitionDiagnosticSurface } from './publisherDiagnosticsSurface.ts';

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
    resetSfuOutboundMediaAfterProfileSwitch,
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
    'sfu_protected_media_budget_pressure',
    'sfu_wlvc_rate_budget_pressure',
    'sfu_remote_quality_pressure',
    'sfu_remote_video_decoder_waiting_keyframe',
    'sfu_remote_video_frozen',
    'sfu_buffer_budget_exceeded',
    'sfu_frame_send_pressure',
    'sfu_projected_buffer_budget_exceeded',
    'sfu_send_backpressure',
    'sfu_send_backpressure_critical',
    'sfu_wire_rate_budget_exceeded',
    'send_buffer_drain_timeout',
    'sfu_source_readback_budget_exceeded',
    'sfu_remote_thumbnail_layer_requested',
  ]);
  const sfuQualityProfileRank = Object.freeze({
    rescue: 0,
    realtime: 1,
    balanced: 2,
    quality: 3,
  });

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

  function normalizeRequestedSfuVideoQualityProfile(value) {
    const requestedProfileId = String(value || '').trim().toLowerCase();
    if (!Object.prototype.hasOwnProperty.call(sfuQualityProfileRank, requestedProfileId)) return '';
    const requestedProfile = resolveSfuVideoQualityProfile(requestedProfileId);
    const resolvedProfileId = String(requestedProfile?.id || '').trim().toLowerCase();
    return Object.prototype.hasOwnProperty.call(sfuQualityProfileRank, requestedProfileId)
      && resolvedProfileId === requestedProfileId
      ? resolvedProfileId
      : '';
  }

  function requestedProfileForDirection(currentProfile, requestedProfile, direction, fallbackProfile) {
    const currentRank = sfuQualityProfileRank[currentProfile];
    const requestedRank = sfuQualityProfileRank[requestedProfile];
    if (!Number.isFinite(currentRank) || !Number.isFinite(requestedRank)) return fallbackProfile;
    if (direction === 'up' && requestedRank > currentRank) return requestedProfile;
    if (direction === 'down' && requestedRank < currentRank) return requestedProfile;
    return fallbackProfile;
  }

  function applySfuVideoQualityProfileSwitch({
    currentProfile,
    nextProfile,
    reason,
    eventType,
    level = 'warning',
    message,
    payload = {},
  }) {
    const direction = eventType === 'sfu_source_readback_profile_upshift' ? 'up' : 'down';
    const transitionCount = Math.max(0, Number(refs.sfuTransportState.sfuAutomaticQualityTransitionCount || 0)) + 1;
    refs.sfuTransportState.sfuAutomaticQualityTransitionCount = transitionCount;
    refs.sfuTransportState.sfuAutomaticQualityTransitionLastAtMs = Date.now();
    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      payload: {
        ...payload,
        ...publisherQualityTransitionDiagnosticSurface({
          transitionCount,
          direction,
          fromProfile: currentProfile,
          toProfile: nextProfile,
        }),
        from_profile: currentProfile,
        to_profile: nextProfile,
        reason,
        media_runtime_path: refs.mediaRuntimePath.value,
      },
      immediate: true,
    });
    if (typeof resetSfuOutboundMediaAfterProfileSwitch === 'function') {
      resetSfuOutboundMediaAfterProfileSwitch({
        fromProfile: currentProfile,
        toProfile: nextProfile,
        reason,
      });
    }
    stopLocalEncodingPipeline();
    setCallOutgoingVideoQualityProfile(nextProfile);
    return true;
  }

  function probeSfuVideoQualityAfterStableReadback(reason = 'sfu_source_readback_recovered', details = {}) {
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    const normalizedReason = String(reason || 'sfu_source_readback_recovered').trim().toLowerCase();
    const requestedProfile = normalizeRequestedSfuVideoQualityProfile(
      details?.requestedVideoQualityProfile || details?.requested_video_quality_profile,
    );
    const nextProfile = requestedProfileForDirection(
      currentProfile,
      requestedProfile,
      'up',
      SFU_AUTO_QUALITY_RECOVERY_NEXT[currentProfile] || '',
    );
    if (nextProfile === '') return false;

    const nowMs = Date.now();
    const bypassQualityRecoveryCooldown = Boolean(
      details?.bypassQualityRecoveryCooldown
        || details?.bypass_quality_recovery_cooldown
        || String(details?.requested_video_layer || details?.requestedVideoLayer || '').trim().toLowerCase() === 'primary',
    );
    const lastQualityChangeAtMs = Math.max(
      Number(refs.sfuTransportState.sfuAutoQualityDowngradeLastAtMs || 0),
      Number(refs.sfuTransportState.sfuAutoQualityRecoveryLastAtMs || 0),
    );
    if (!bypassQualityRecoveryCooldown && (nowMs - lastQualityChangeAtMs) < SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS) {
      return false;
    }
    refs.sfuTransportState.sfuAutoQualityRecoveryLastAtMs = nowMs;

    return applySfuVideoQualityProfileSwitch({
      currentProfile,
      nextProfile,
      reason: normalizedReason,
      eventType: 'sfu_source_readback_profile_upshift',
      level: 'info',
      message: 'Outgoing SFU video quality is probing one tier up after stable source-readback timing.',
      payload: {
        ...details,
        failure_count: state.getWlvcEncodeFailureCount(),
      },
    });
  }

  function downgradeSfuVideoQualityAfterEncodePressure(reason = 'encode_pressure', options = {}) {
    const qualityDirection = String(options?.direction || options?.qualityDirection || '').trim().toLowerCase();
    if (qualityDirection === 'up') {
      return probeSfuVideoQualityAfterStableReadback(reason, options);
    }
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    const normalizedReason = String(reason || 'encode_pressure').trim().toLowerCase();
    const bypassQualityDowngradeCooldown = immediateQualityPressureReasons.includes(normalizedReason);
    const requestedProfile = normalizeRequestedSfuVideoQualityProfile(
      options?.requestedVideoQualityProfile || options?.requested_video_quality_profile,
    );
    let nextProfile = requestedProfileForDirection(
      currentProfile,
      requestedProfile,
      'down',
      sfuAutoQualityDowngradeNext[currentProfile] || '',
    );
    if (nextProfile === '') return false;

    const nowMs = Date.now();
    if (
      !bypassQualityDowngradeCooldown
      && (nowMs - refs.sfuTransportState.sfuAutoQualityDowngradeLastAtMs) < sfuAutoQualityDowngradeCooldownMs
    ) {
      return false;
    }
    refs.sfuTransportState.sfuAutoQualityDowngradeLastAtMs = nowMs;

    return applySfuVideoQualityProfileSwitch({
      currentProfile,
      nextProfile,
      reason: normalizedReason,
      eventType: 'sfu_encode_quality_downgraded',
      message: 'Outgoing SFU video quality was lowered after repeated encode failures.',
      payload: {
        failure_count: state.getWlvcEncodeFailureCount(),
      },
    });
  }

  return {
    currentSfuVideoProfile,
    downgradeSfuVideoQualityAfterEncodePressure,
    maybeFallbackToNativeRuntime,
    probeSfuVideoQualityAfterStableReadback,
    setMediaRuntimePath,
    switchMediaRuntimePath,
  };
}
