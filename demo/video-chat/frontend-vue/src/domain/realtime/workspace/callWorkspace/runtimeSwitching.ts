import {
  SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS,
  SFU_AUTO_QUALITY_RECOVERY_NEXT,
  SFU_AUTO_QUALITY_RECOVERY_PROBE_DELAYS_MS,
} from './runtimeConfig.ts';
import { publisherQualityTransitionDiagnosticSurface } from './publisherDiagnosticsSurface.ts';
import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../../lib/gossipmesh/featureFlags';

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
    sfuAutoQualityRecoveryProbeDelaysMs,
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
  const configuredQualityRecoveryProbeDelaysMs = Array.isArray(sfuAutoQualityRecoveryProbeDelaysMs)
    && sfuAutoQualityRecoveryProbeDelaysMs.length > 0
    ? sfuAutoQualityRecoveryProbeDelaysMs
      .map((delayMs) => Math.max(0, Number(delayMs || 0)))
      .filter((delayMs) => Number.isFinite(delayMs))
    : [];
  const qualityRecoveryProbeDelaysMs = configuredQualityRecoveryProbeDelaysMs.length > 0
    ? configuredQualityRecoveryProbeDelaysMs
    : SFU_AUTO_QUALITY_RECOVERY_PROBE_DELAYS_MS;
  const qualityRecoveryProbeState = {
    timer: null,
    startedAtMs: 0,
    nextAttemptIndex: 0,
    reason: '',
    details: {},
  };
  const qualityRecoveryProbePrerequisiteRetryMs = 1000;

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
    if (VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary) return false;
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

  function nextAutomaticRecoveryProfile(currentProfile) {
    const normalizedProfile = normalizeRequestedSfuVideoQualityProfile(currentProfile);
    if (normalizedProfile === '') return '';
    return normalizeRequestedSfuVideoQualityProfile(SFU_AUTO_QUALITY_RECOVERY_NEXT[normalizedProfile] || '');
  }

  function hasLiveLocalVideoTrackForQualityProbe() {
    const stream = refs.localStreamRef?.value || null;
    const tracks = typeof stream?.getVideoTracks === 'function' ? stream.getVideoTracks() : [];
    const track = Array.isArray(tracks) ? tracks[0] : null;
    if (!track || typeof track !== 'object') return false;
    return String(track.readyState || 'live').trim().toLowerCase() !== 'ended';
  }

  function canRunScheduledQualityRecoveryProbe() {
    if (String(refs.activeCallId?.value || '').trim() === '') return false;
    if (String(refs.activeRoomId?.value || '').trim() === '') return false;
    if (String(refs.mediaRuntimePath?.value || '').trim().toLowerCase() === 'unsupported') return false;
    return hasLiveLocalVideoTrackForQualityProbe();
  }

  function clearSfuVideoQualityRecoveryProbeTimer() {
    if (qualityRecoveryProbeState.timer !== null) {
      clearTimeout(qualityRecoveryProbeState.timer);
      qualityRecoveryProbeState.timer = null;
    }
  }

  function resetSfuVideoQualityRecoveryProbeSeries() {
    clearSfuVideoQualityRecoveryProbeTimer();
    qualityRecoveryProbeState.startedAtMs = 0;
    qualityRecoveryProbeState.nextAttemptIndex = 0;
    qualityRecoveryProbeState.reason = '';
    qualityRecoveryProbeState.details = {};
  }

  function scheduleNextSfuVideoQualityRecoveryProbe() {
    clearSfuVideoQualityRecoveryProbeTimer();
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    if (nextAutomaticRecoveryProfile(currentProfile) === '') {
      resetSfuVideoQualityRecoveryProbeSeries();
      return false;
    }
    if (qualityRecoveryProbeState.nextAttemptIndex >= qualityRecoveryProbeDelaysMs.length) {
      resetSfuVideoQualityRecoveryProbeSeries();
      return false;
    }

    const nowMs = Date.now();
    if (qualityRecoveryProbeState.startedAtMs <= 0) {
      qualityRecoveryProbeState.startedAtMs = nowMs;
    }
    const attemptIndex = qualityRecoveryProbeState.nextAttemptIndex;
    const targetAtMs = qualityRecoveryProbeState.startedAtMs + Number(qualityRecoveryProbeDelaysMs[attemptIndex] || 0);
    const delayMs = Math.max(0, targetAtMs - nowMs);
    qualityRecoveryProbeState.timer = setTimeout(() => {
      qualityRecoveryProbeState.timer = null;
      runScheduledSfuVideoQualityRecoveryProbe();
    }, delayMs);
    return true;
  }

  function retrySfuVideoQualityRecoveryProbePrerequisites() {
    clearSfuVideoQualityRecoveryProbeTimer();
    qualityRecoveryProbeState.timer = setTimeout(() => {
      qualityRecoveryProbeState.timer = null;
      runScheduledSfuVideoQualityRecoveryProbe();
    }, qualityRecoveryProbePrerequisiteRetryMs);
    return true;
  }

  function ensureSfuVideoQualityRecoveryProbeSeries(reason = 'automatic_quality_recovery', details = {}) {
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    if (nextAutomaticRecoveryProfile(currentProfile) === '') {
      resetSfuVideoQualityRecoveryProbeSeries();
      return false;
    }
    if (
      qualityRecoveryProbeState.startedAtMs > 0
      && qualityRecoveryProbeState.nextAttemptIndex < qualityRecoveryProbeDelaysMs.length
    ) {
      if (qualityRecoveryProbeState.timer === null) {
        scheduleNextSfuVideoQualityRecoveryProbe();
      }
      return true;
    }

    qualityRecoveryProbeState.startedAtMs = Date.now();
    qualityRecoveryProbeState.nextAttemptIndex = 0;
    qualityRecoveryProbeState.reason = String(reason || 'automatic_quality_recovery').trim().toLowerCase();
    qualityRecoveryProbeState.details = details && typeof details === 'object' ? { ...details } : {};
    return scheduleNextSfuVideoQualityRecoveryProbe();
  }

  function runScheduledSfuVideoQualityRecoveryProbe() {
    const currentProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
    const nextProfile = nextAutomaticRecoveryProfile(currentProfile);
    if (nextProfile === '') {
      resetSfuVideoQualityRecoveryProbeSeries();
      return false;
    }
    if (qualityRecoveryProbeState.nextAttemptIndex >= qualityRecoveryProbeDelaysMs.length) {
      resetSfuVideoQualityRecoveryProbeSeries();
      return false;
    }
    if (!canRunScheduledQualityRecoveryProbe()) {
      return retrySfuVideoQualityRecoveryProbePrerequisites();
    }

    const attemptIndex = qualityRecoveryProbeState.nextAttemptIndex;
    qualityRecoveryProbeState.nextAttemptIndex += 1;
    const attemptNumber = attemptIndex + 1;
    const probeReason = qualityRecoveryProbeState.reason || 'automatic_quality_recovery';
    const probed = probeSfuVideoQualityAfterStableReadback(probeReason, {
      ...qualityRecoveryProbeState.details,
      requested_video_quality_profile: nextProfile,
      bypass_quality_recovery_cooldown: true,
      automatic_probe_attempt: attemptNumber,
      automatic_probe_max_attempts: qualityRecoveryProbeDelaysMs.length,
      automatic_probe_delay_ms: Number(qualityRecoveryProbeDelaysMs[attemptIndex] || 0),
      automatic_probe_schedule_ms: [...qualityRecoveryProbeDelaysMs],
    });

    if (probed) {
      const activeProfile = String(refs.callMediaPrefs.outgoingVideoQualityProfile || '').trim().toLowerCase();
      if (nextAutomaticRecoveryProfile(activeProfile) === '') {
        resetSfuVideoQualityRecoveryProbeSeries();
        return true;
      }
    }

    scheduleNextSfuVideoQualityRecoveryProbe();
    return probed;
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

    const didSwitch = applySfuVideoQualityProfileSwitch({
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
    if (didSwitch && nextAutomaticRecoveryProfile(nextProfile) !== '') {
      ensureSfuVideoQualityRecoveryProbeSeries(normalizedReason, {
        ...details,
        continued_after_profile: nextProfile,
      });
    }
    return didSwitch;
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

    const didSwitch = applySfuVideoQualityProfileSwitch({
      currentProfile,
      nextProfile,
      reason: normalizedReason,
      eventType: 'sfu_encode_quality_downgraded',
      message: 'Outgoing SFU video quality was lowered after repeated encode failures.',
      payload: {
        failure_count: state.getWlvcEncodeFailureCount(),
      },
    });
    if (didSwitch) {
      ensureSfuVideoQualityRecoveryProbeSeries(normalizedReason, {
        ...options,
        downgraded_from_profile: currentProfile,
        downgraded_to_profile: nextProfile,
      });
    }
    return didSwitch;
  }

  return {
    clearSfuVideoQualityRecoveryProbeTimer: resetSfuVideoQualityRecoveryProbeSeries,
    currentSfuVideoProfile,
    downgradeSfuVideoQualityAfterEncodePressure,
    ensureSfuVideoQualityRecoveryProbeSeries,
    maybeFallbackToNativeRuntime,
    probeSfuVideoQualityAfterStableReadback,
    setMediaRuntimePath,
    switchMediaRuntimePath,
  };
}
