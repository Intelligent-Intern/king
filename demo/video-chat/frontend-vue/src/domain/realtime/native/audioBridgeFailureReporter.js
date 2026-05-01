const audioBridgeFailureCounts = new Map();

function audioBridgeFailureKey(peer, code) {
  return [
    Number(peer?.userId || 0),
    String(code || 'native_audio_bridge_failed').trim() || 'native_audio_bridge_failed',
  ].join(':');
}

export function reportNativeAudioBridgeFailure({
  captureClientDiagnostic,
  code,
  defaultMessage,
  extraPayload = {},
  isSocketOnline,
  mediaRuntimePath,
  message,
  nativeAudioSecurityTelemetrySnapshot,
  peer,
  setNativePeerAudioBridgeState,
  shouldUseNativeAudioBridge,
  syncMediaSecurityWithParticipants,
}) {
  const normalizedCode = String(code || 'native_audio_bridge_failed').trim() || 'native_audio_bridge_failed';
  const normalizedMessage = String(message || '').trim() || String(defaultMessage || '').trim();
  const finalMessage = normalizedMessage || 'Protected remote audio bridge failed.';
  setNativePeerAudioBridgeState(peer, 'transform_attach_failed', finalMessage);

  const failureKey = audioBridgeFailureKey(peer, normalizedCode);
  const failureCount = Number(audioBridgeFailureCounts.get(failureKey) || 0) + 1;
  audioBridgeFailureCounts.set(failureKey, failureCount);
  if (peer && typeof peer === 'object') {
    peer.audioBridgeFailureCount = failureCount;
  }

  captureClientDiagnostic({
    category: 'media',
    level: 'error',
    eventType: normalizedCode,
    code: normalizedCode,
    message: finalMessage,
    payload: {
      target_user_id: Number(peer?.userId || 0),
      connection_state: String(peer?.pc?.connectionState || '').trim().toLowerCase(),
      failure_count: failureCount,
      media_runtime_path: mediaRuntimePath.value,
      security: nativeAudioSecurityTelemetrySnapshot(),
      ...extraPayload,
    },
    immediate: true,
  });

  setTimeout(() => {
    if (!isSocketOnline.value || !shouldUseNativeAudioBridge()) return;
    void syncMediaSecurityWithParticipants(true);
  }, 1500);
}
