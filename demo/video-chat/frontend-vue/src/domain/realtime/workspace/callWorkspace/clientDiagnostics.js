import { configureClientDiagnostics } from '../../../../support/clientDiagnostics';

function extractDiagnosticMessage(value, fallback = 'Client diagnostics event captured.') {
  if (value instanceof Error) {
    return String(value.message || fallback).trim() || fallback;
  }
  if (typeof value === 'string') {
    return value.trim() || fallback;
  }
  if (value && typeof value === 'object' && typeof value.message === 'string') {
    return String(value.message || fallback).trim() || fallback;
  }
  return fallback;
}

export { extractDiagnosticMessage };

export function createClientDiagnosticCapturer({
  reportClientDiagnostic,
  getCallId,
  getRoomId,
}) {
  function captureClientDiagnostic({
    category = 'media',
    level = 'error',
    eventType = '',
    code = '',
    message = '',
    payload = {},
    immediate = false,
  } = {}) {
    if (String(eventType || '').trim() === '') return;
    reportClientDiagnostic({
      category,
      level,
      eventType,
      code,
      message,
      callId: getCallId(),
      roomId: getRoomId(),
      payload,
      immediate,
    });
  }

  function captureClientDiagnosticError(eventType, error, payload = {}, options = {}) {
    captureClientDiagnostic({
      category: options.category || 'media',
      level: options.level || 'error',
      eventType,
      code: options.code || '',
      message: extractDiagnosticMessage(error, options.fallbackMessage || 'Client diagnostics error captured.'),
      payload: {
        ...payload,
        error,
      },
      immediate: Boolean(options.immediate),
    });
  }

  return {
    captureClientDiagnostic,
    captureClientDiagnosticError,
  };
}

export function configureCallWorkspaceClientDiagnosticsContext({
  callbacks,
  collections,
  refs,
}) {
  const {
    nativeAudioSecurityTelemetrySnapshot,
  } = callbacks;
  const {
    nativeAudioBridgeQuarantineByUserId,
  } = collections;
  const {
    activeCallId,
    activeRoomId,
    activeSocketCallId,
    connectedParticipantUsers,
    connectionReason,
    connectionState,
    currentUserId,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    mediaRuntimeReason,
    nativeAudioBridgeStatusVersion,
    nativePeerConnectionsRef,
    remotePeersRef,
    sfuClientRef,
    sfuConnected,
  } = refs;

  function callWorkspaceNativeBridgeDiagnosticsSnapshot() {
    const nativePeerConnections = nativePeerConnectionsRef.value instanceof Map
      ? nativePeerConnectionsRef.value
      : new Map();
    return {
      status_version: nativeAudioBridgeStatusVersion.value,
      quarantine_count: nativeAudioBridgeQuarantineByUserId.size,
      native_peer_count: nativePeerConnections.size,
      security: nativeAudioSecurityTelemetrySnapshot() || null,
    };
  }

  function callWorkspaceLastSfuTransportSample() {
    const client = sfuClientRef.value;
    return client && typeof client.getLastFrameTransportSample === 'function'
      ? client.getLastFrameTransportSample()
      : null;
  }

  configureClientDiagnostics(() => ({
    call_id: activeSocketCallId.value || activeCallId.value,
    room_id: activeRoomId.value,
    current_user_id: currentUserId.value,
    connection_state: connectionState.value,
    connection_reason: connectionReason.value,
    sfu_connected: sfuConnected.value,
    media_runtime_path: mediaRuntimePath.value,
    media_runtime_reason: mediaRuntimeReason.value,
    media_stage_a: Boolean(mediaRuntimeCapabilities.value.stageA),
    media_stage_b: Boolean(mediaRuntimeCapabilities.value.stageB),
    media_preferred_path: mediaRuntimeCapabilities.value.preferredPath,
    connected_participant_count: connectedParticipantUsers.value.length,
    remote_peer_count: remotePeersRef.value.size,
    native_bridge_state: callWorkspaceNativeBridgeDiagnosticsSnapshot(),
    last_sfu_transport_sample: callWorkspaceLastSfuTransportSample(),
    last_sfu_send_failure: sfuClientRef.value?.getLastSendFailure?.() || null,
  }));
}
