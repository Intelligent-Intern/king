export function createNativeAudioBridgeRecovery({
  captureClientDiagnostic,
  closeNativePeerConnection,
  currentMediaSecurityRuntimePath,
  extractDiagnosticMessage,
  getMediaRuntimePath,
  getPeerByUserId,
  nativeAudioPlaybackBlocked,
  nativeAudioPlaybackInterrupted,
  nativeAudioTrackRecoveryAttemptsByUserId,
  nativePeerConnectionTelemetry,
  nativeAudioTrackRecoveryDelayMs,
  nativeAudioTrackRecoveryMaxAttempts,
  nativeAudioTrackRecoveryRejoinDelayMs,
  resyncNativeAudioBridgePeerAfterSecurityReady,
  setNativePeerAudioBridgeState,
  shouldUseNativeAudioBridge,
  streamHasLiveTrackKind,
  syncMediaSecurityWithParticipants,
  syncNativePeerConnectionsWithRoster,
  telemetrySnapshotProvider,
}) {
  function nativeAudioSecurityTelemetrySnapshot() {
    return telemetrySnapshotProvider(currentMediaSecurityRuntimePath());
  }

  function resetNativeAudioTrackRecovery(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    nativeAudioTrackRecoveryAttemptsByUserId.delete(normalizedUserId);
  }

  function scheduleNativeAudioTrackRecovery(peer, reason = 'missing_track', options = {}) {
    const normalizedUserId = Number(peer?.userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    if (!shouldUseNativeAudioBridge()) return false;
    const requireMissingTrack = options?.requireMissingTrack !== false;
    const recoveryKind = requireMissingTrack ? 'missing_track' : 'frame_transform';

    const currentAttempt = Number(nativeAudioTrackRecoveryAttemptsByUserId.get(normalizedUserId) || 0);
    if (currentAttempt >= nativeAudioTrackRecoveryMaxAttempts) {
      console.error(
        '[KingRT] native audio bridge recovery exhausted',
        `user=${normalizedUserId}`,
        `reason=${String(reason || 'missing_track')}`,
        nativePeerConnectionTelemetry(peer),
      );
      return false;
    }

    const nextAttempt = currentAttempt + 1;
    nativeAudioTrackRecoveryAttemptsByUserId.set(normalizedUserId, nextAttempt);
    console.warn(
      requireMissingTrack
        ? '[KingRT] native audio bridge missing track - rebuilding peer'
        : '[KingRT] native audio bridge media-security frame failed - rebuilding peer',
      `user=${normalizedUserId}`,
      `attempt=${nextAttempt}/${nativeAudioTrackRecoveryMaxAttempts}`,
      nativePeerConnectionTelemetry(peer),
    );
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'native_audio_track_recovery',
      code: 'native_audio_track_recovery',
      message: requireMissingTrack
        ? 'Native protected audio bridge connected without a remote audio track; rebuilding peer connection.'
        : 'Native protected audio bridge received an unprotected or malformed frame; rebuilding peer connection.',
      payload: {
        reason: String(reason || 'missing_track'),
        recovery_kind: recoveryKind,
        attempt: nextAttempt,
        media_runtime_path: getMediaRuntimePath(),
        security: nativeAudioSecurityTelemetrySnapshot(),
        peer: nativePeerConnectionTelemetry(peer),
      },
      immediate: true,
    });

    setTimeout(() => {
      if (!shouldUseNativeAudioBridge()) return;
      const currentPeer = getPeerByUserId(normalizedUserId);
      if (requireMissingTrack && currentPeer && streamHasLiveTrackKind(currentPeer.remoteStream, 'audio')) {
        resetNativeAudioTrackRecovery(normalizedUserId);
        return;
      }
      void syncMediaSecurityWithParticipants(true);
      closeNativePeerConnection(normalizedUserId);
      setTimeout(() => {
        if (!shouldUseNativeAudioBridge()) return;
        syncNativePeerConnectionsWithRoster();
        resyncNativeAudioBridgePeerAfterSecurityReady(
          normalizedUserId,
          'native_audio_track_recovery_rejoin',
          true
        );
      }, nativeAudioTrackRecoveryRejoinDelayMs);
    }, nativeAudioTrackRecoveryDelayMs);

    return true;
  }

  async function playNativePeerAudio(peer, reason = 'unknown') {
    if (!(peer?.audio instanceof HTMLAudioElement)) return false;
    if (!streamHasLiveTrackKind(peer.remoteStream, 'audio')) return false;

    try {
      await peer.audio.play();
      setNativePeerAudioBridgeState(peer, 'playing', '');
      resetNativeAudioTrackRecovery(peer.userId);
      return true;
    } catch (error) {
      if (nativeAudioPlaybackInterrupted(error)) {
        setNativePeerAudioBridgeState(peer, 'track_received', '');
        return false;
      }
      const blocked = nativeAudioPlaybackBlocked(error);
      const errorMessage = extractDiagnosticMessage(
        error,
        blocked ? 'The browser blocked remote audio playback.' : 'Remote protected audio playback failed.'
      );
      const nextState = blocked ? 'blocked_playback' : 'play_failed';
      if (setNativePeerAudioBridgeState(peer, nextState, errorMessage)) {
        captureClientDiagnostic({
          category: 'media',
          level: blocked ? 'warning' : 'error',
          eventType: blocked ? 'native_audio_play_blocked' : 'native_audio_play_failed',
          code: blocked ? 'native_audio_play_blocked' : 'native_audio_play_failed',
          message: blocked
            ? 'The browser blocked remote protected audio playback.'
            : 'Remote protected audio playback failed.',
          payload: {
            target_user_id: Number(peer?.userId || 0),
            reason: String(reason || 'unknown'),
            connection_state: String(peer?.pc?.connectionState || '').trim().toLowerCase(),
            error_name: String(error?.name || '').trim(),
            error_message: errorMessage,
            media_runtime_path: getMediaRuntimePath(),
            security: nativeAudioSecurityTelemetrySnapshot(),
          },
          immediate: !blocked,
        });
      }
      return false;
    }
  }

  function scheduleNativePeerAudioTrackDeadline(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (peer.audioTrackDeadlineTimer !== null && peer.audioTrackDeadlineTimer !== undefined) {
      clearTimeout(peer.audioTrackDeadlineTimer);
    }
    peer.audioTrackDeadlineTimer = null;
    if (!shouldUseNativeAudioBridge()) return;
    if (!peer?.pc || peer.pc.signalingState === 'closed') return;

    const connectionState = String(peer.pc.connectionState || '').trim().toLowerCase();
    if (connectionState !== 'connected' && connectionState !== 'completed') return;
    if (streamHasLiveTrackKind(peer.remoteStream, 'audio')) return;

    setNativePeerAudioBridgeState(peer, 'waiting_track', '');
    peer.audioTrackDeadlineTimer = setTimeout(() => {
      peer.audioTrackDeadlineTimer = null;
      if (!shouldUseNativeAudioBridge()) return;
      if (!peer?.pc || peer.pc.signalingState === 'closed') return;
      const currentConnectionState = String(peer.pc.connectionState || '').trim().toLowerCase();
      if (currentConnectionState !== 'connected' && currentConnectionState !== 'completed') return;
      if (streamHasLiveTrackKind(peer.remoteStream, 'audio')) return;

      if (setNativePeerAudioBridgeState(peer, 'stalled_no_track', 'No protected remote audio track arrived.')) {
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'native_audio_track_missing',
          code: 'native_audio_track_missing',
          message: 'Protected remote audio track did not arrive after the native audio bridge connected.',
          payload: {
            target_user_id: Number(peer?.userId || 0),
            connection_state: currentConnectionState,
            media_runtime_path: getMediaRuntimePath(),
            security: nativeAudioSecurityTelemetrySnapshot(),
          },
          immediate: true,
        });
        scheduleNativeAudioTrackRecovery(peer, 'deadline_no_remote_audio_track');
      }
    }, 6000);
  }

  return {
    nativeAudioSecurityTelemetrySnapshot,
    playNativePeerAudio,
    resetNativeAudioTrackRecovery,
    scheduleNativeAudioTrackRecovery,
    scheduleNativePeerAudioTrackDeadline,
  };
}
