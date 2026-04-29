import { createNativeAudioBridgeStateHelpers } from '../../native/audioBridgeState';
import { createNativeAudioBridgeRecovery } from '../../native/audioBridgeRecovery';
import { createNativeBridgeRuntimeHelpers } from '../../native/bridgeRuntime';
import { createNativePeerLifecycleHelpers } from '../../native/peerLifecycle';
import { createNativePeerFactory } from '../../native/peerFactory';
import { createNativeSignalingHelpers } from '../../native/signaling';

export function createCallWorkspaceNativeStack(options) {
  const {
    refs,
    callbacks,
    constants,
    state,
    vue,
  } = options;
  const { markRaw } = vue;

  let audioBridgeState = null;
  let nativeAudioBridgeRecovery = null;
  let nativePeerLifecycle = null;
  let sendNativeOfferProxy = async () => {};

  const nativeBridgeRuntime = createNativeBridgeRuntimeHelpers({
    callbacks: {
      apiRequest: callbacks.apiRequest,
      attachMediaSecurityNativeReceiverBase: callbacks.attachMediaSecurityNativeReceiverBase,
      attachMediaSecurityNativeSenderBase: callbacks.attachMediaSecurityNativeSenderBase,
      bumpMediaRenderVersion: callbacks.bumpMediaRenderVersion,
      clearNativePeerAudioTrackDeadline: (...args) => audioBridgeState?.clearNativePeerAudioTrackDeadline?.(...args),
      createNativePeerAudioElement: callbacks.createNativePeerAudioElement,
      createNativePeerVideoElement: callbacks.createNativePeerVideoElement,
      currentNativeAudioBridgeFailureMessage: callbacks.currentNativeAudioBridgeFailureMessage,
      currentShouldUseNativeAudioBridge: callbacks.currentShouldUseNativeAudioBridge,
      currentUserId: callbacks.currentUserId,
      ensureLocalMediaForPublish: callbacks.ensureLocalMediaForPublish,
      ensureMediaSecuritySession: callbacks.ensureMediaSecuritySession,
      ensureNativeAudioBridgeSecurityReady: callbacks.ensureNativeAudioBridgeSecurityReady,
      extractDiagnosticMessage: callbacks.extractDiagnosticMessage,
      getPeerControlSnapshot: callbacks.getPeerControlSnapshot,
      isNativeWebRtcRuntimePath: callbacks.isNativeWebRtcRuntimePath,
      markParticipantActivity: callbacks.markParticipantActivity,
      mediaDebugLog: callbacks.mediaDebugLog,
      playNativePeerAudio: (...args) => nativeAudioBridgeRecovery?.playNativePeerAudio?.(...args),
      renderCallVideoLayout: callbacks.renderCallVideoLayout,
      renderNativeRemoteVideos: callbacks.renderNativeRemoteVideos,
      reportNativeAudioBridgeFailure: callbacks.reportNativeAudioBridgeFailure,
      reportNativeAudioSdpRejected: callbacks.reportNativeAudioSdpRejected,
      resetNativeAudioTrackRecovery: (...args) => nativeAudioBridgeRecovery?.resetNativeAudioTrackRecovery?.(...args),
      scheduleNativeOfferRetry: (...args) => nativePeerLifecycle?.scheduleNativeOfferRetry?.(...args),
      scheduleNativePeerAudioTrackDeadline: (...args) => nativeAudioBridgeRecovery?.scheduleNativePeerAudioTrackDeadline?.(...args),
      sendSocketFrame: callbacks.sendSocketFrame,
      shouldBypassNativeAudioProtectionForPeer: callbacks.shouldBypassNativeAudioProtectionForPeer,
      shouldMaintainNativePeerConnections: callbacks.shouldMaintainNativePeerConnections,
      shouldSendNativeTrackKind: callbacks.shouldSendNativeTrackKind,
      streamHasLiveTrackKind: callbacks.streamHasLiveTrackKind,
    },
    constants: {
      defaultNativeIceServers: constants.defaultNativeIceServers,
    },
    refs: {
      MediaSecuritySession: constants.MediaSecuritySession,
      activeRoomId: refs.activeRoomId,
      controlState: refs.controlState,
      dynamicIceServers: refs.dynamicIceServers,
      localStreamRef: refs.localStreamRef,
      mediaSecurityStateVersion: refs.mediaSecurityStateVersion,
      nativePeerConnectionTelemetry: refs.nativePeerConnectionTelemetry,
      nativeSdpAudioSummaries: refs.nativeSdpAudioSummaries,
      nativeSdpAudioSummary: refs.nativeSdpAudioSummary,
      nativeSdpHasSendableAudio: refs.nativeSdpHasSendableAudio,
      reconfigureLocalTracksFromSelectedDevices: callbacks.reconfigureLocalTracksFromSelectedDevices,
      sessionToken: callbacks.sessionToken,
      setNativePeerAudioBridgeState: (...args) => audioBridgeState?.setNativePeerAudioBridgeState?.(...args),
    },
    state: refs.nativeBridgeRuntimeState,
  });

  audioBridgeState = createNativeAudioBridgeStateHelpers(refs.nativeAudioBridgeStatusVersion);
  const shouldBlockNativeRuntimeSignaling = () => {
    if (typeof callbacks.shouldBlockNativeRuntimeSignaling === 'function') {
      return callbacks.shouldBlockNativeRuntimeSignaling();
    }
    const sfuEnabled = typeof callbacks.sfuRuntimeEnabled === 'function'
      ? callbacks.sfuRuntimeEnabled()
      : false;
    return Boolean(sfuEnabled) && refs.mediaRuntimePath.value === 'pending';
  };

  nativeAudioBridgeRecovery = createNativeAudioBridgeRecovery({
    captureClientDiagnostic: callbacks.captureClientDiagnostic,
    closeNativePeerConnection: callbacks.closeNativePeerConnection,
    currentMediaSecurityRuntimePath: callbacks.currentMediaSecurityRuntimePath,
    extractDiagnosticMessage: callbacks.extractDiagnosticMessage,
    getMediaRuntimePath: callbacks.getMediaRuntimePath,
    getPeerByUserId: callbacks.getPeerByUserId,
    nativeAudioPlaybackBlocked: refs.nativeAudioPlaybackBlocked,
    nativeAudioPlaybackInterrupted: refs.nativeAudioPlaybackInterrupted,
    nativeAudioTrackRecoveryAttemptsByUserId: refs.nativeAudioTrackRecoveryAttemptsByUserId,
    nativePeerConnectionTelemetry: refs.nativePeerConnectionTelemetry,
    nativeAudioTrackRecoveryDelayMs: constants.nativeAudioTrackRecoveryDelayMs,
    nativeAudioTrackRecoveryMaxAttempts: constants.nativeAudioTrackRecoveryMaxAttempts,
    nativeAudioTrackRecoveryRejoinDelayMs: constants.nativeAudioTrackRecoveryRejoinDelayMs,
    resyncNativeAudioBridgePeerAfterSecurityReady: callbacks.resyncNativeAudioBridgePeerAfterSecurityReady,
    setNativePeerAudioBridgeState: audioBridgeState.setNativePeerAudioBridgeState,
    shouldUseNativeAudioBridge: callbacks.shouldUseNativeAudioBridge,
    streamHasLiveTrackKind: callbacks.streamHasLiveTrackKind,
    syncMediaSecurityWithParticipants: callbacks.syncMediaSecurityWithParticipants,
    syncNativePeerConnectionsWithRoster: callbacks.syncNativePeerConnectionsWithRoster,
    telemetrySnapshotProvider: callbacks.telemetrySnapshotProvider,
  });

  nativePeerLifecycle = createNativePeerLifecycleHelpers({
    bumpMediaRenderVersion: callbacks.bumpMediaRenderVersion,
    clearNativePeerAudioTrackDeadline: audioBridgeState.clearNativePeerAudioTrackDeadline,
    clearRemoteVideoContainer: callbacks.clearRemoteVideoContainer,
    isNativeWebRtcRuntimePath: callbacks.isNativeWebRtcRuntimePath,
    mediaDebugLog: callbacks.mediaDebugLog,
    nativeAudioBridgeQuarantineByUserId: refs.nativeAudioBridgeQuarantineByUserId,
    nativeAudioTrackRecoveryAttemptsByUserId: refs.nativeAudioTrackRecoveryAttemptsByUserId,
    nativeOfferRetryDelaysMs: constants.nativeOfferRetryDelaysMs,
    nativePeerConnectionsRef: refs.nativePeerConnectionsRef,
    renderNativeRemoteVideos: callbacks.renderNativeRemoteVideos,
    sendNativeOffer: (peer) => sendNativeOfferProxy(peer),
    shouldMaintainNativePeerConnections: callbacks.shouldMaintainNativePeerConnections,
    shouldUseNativeAudioBridge: callbacks.shouldUseNativeAudioBridge,
  });
  sendNativeOfferProxy = nativeBridgeRuntime.sendNativeOffer;

  let ensureNativePeerConnection = () => null;
  let syncNativePeerConnectionsWithRoster = () => {};

  const nativePeerFactory = createNativePeerFactory({
    activeRoomId: callbacks.activeRoomId,
    attachMediaSecurityNativeReceiver: nativeBridgeRuntime.attachMediaSecurityNativeReceiver,
    bumpMediaRenderVersion: callbacks.bumpMediaRenderVersion,
    clearNativePeerAudioTrackDeadline: audioBridgeState.clearNativePeerAudioTrackDeadline,
    closeNativePeerConnection: nativePeerLifecycle.closeNativePeerConnection,
    createNativePeerAudioElement: callbacks.createNativePeerAudioElement,
    createNativePeerVideoElement: callbacks.createNativePeerVideoElement,
    currentUserId: callbacks.currentUserId,
    ensureNativeAudioBridgeSecurityReady: callbacks.ensureNativeAudioBridgeSecurityReady,
    ensureNativePeerConnectionRef: () => ensureNativePeerConnection,
    isNativeWebRtcRuntimePath: callbacks.isNativeWebRtcRuntimePath,
    markParticipantActivity: callbacks.markParticipantActivity,
    markRaw,
    nativeAudioBridgeFailureMessage: callbacks.nativeAudioBridgeFailureMessage,
    nativeAudioBridgeIsQuarantined: callbacks.nativeAudioBridgeIsQuarantined,
    nativeWebRtcConfig: nativeBridgeRuntime.nativeWebRtcConfig,
    playNativePeerAudio: nativeAudioBridgeRecovery.playNativePeerAudio,
    renderNativeRemoteVideos: callbacks.renderNativeRemoteVideos,
    reportNativeAudioBridgeFailure: callbacks.reportNativeAudioBridgeFailure,
    resetNativeAudioTrackRecovery: nativeAudioBridgeRecovery.resetNativeAudioTrackRecovery,
    resetNativeOfferRetry: nativePeerLifecycle.resetNativeOfferRetry,
    scheduleNativeOfferRetry: nativePeerLifecycle.scheduleNativeOfferRetry,
    scheduleNativePeerAudioTrackDeadline: nativeAudioBridgeRecovery.scheduleNativePeerAudioTrackDeadline,
    sendNativeOffer: nativeBridgeRuntime.sendNativeOffer,
    sendSocketFrame: callbacks.sendSocketFrame,
    setNativePeerAudioBridgeState: audioBridgeState.setNativePeerAudioBridgeState,
    setNativePeerConnection: nativePeerLifecycle.setNativePeerConnection,
    shouldBypassNativeAudioProtectionForPeer: callbacks.shouldBypassNativeAudioProtectionForPeer,
    shouldMaintainNativePeerConnections: callbacks.shouldMaintainNativePeerConnections,
    shouldUseNativeAudioBridge: callbacks.shouldUseNativeAudioBridge,
    shouldSyncNativeLocalTracksBeforeOffer: nativePeerLifecycle.shouldSyncNativeLocalTracksBeforeOffer,
    syncNativePeerConnectionsWithRosterRef: () => syncNativePeerConnectionsWithRoster,
    syncNativePeerLocalTracks: nativeBridgeRuntime.syncNativePeerLocalTracks,
    synchronizeNativePeerMediaElements: nativeBridgeRuntime.synchronizeNativePeerMediaElements,
    connectedParticipantUsers: refs.connectedParticipantUsers,
    nativePeerConnectionsRef: refs.nativePeerConnectionsRef,
    nativePeerRequiresAudioOnlyRebuild: nativePeerLifecycle.nativePeerRequiresAudioOnlyRebuild,
  });

  ensureNativePeerConnection = nativePeerFactory.ensureNativePeerConnection;
  syncNativePeerConnectionsWithRoster = nativePeerFactory.syncNativePeerConnectionsWithRoster;

  const nativeSignaling = createNativeSignalingHelpers({
    activeRoomId: callbacks.activeRoomId,
    currentUserId: callbacks.currentUserId,
    ensureLocalMediaForNativeNegotiation: nativeBridgeRuntime.ensureLocalMediaForNativeNegotiation,
    ensureNativeAudioBridgeSecurityReady: callbacks.ensureNativeAudioBridgeSecurityReady,
    ensureNativePeerConnection,
    flushNativePendingIce: nativeBridgeRuntime.flushNativePendingIce,
    loadDynamicIceServers: nativeBridgeRuntime.loadDynamicIceServers,
    mediaDebugLog: callbacks.mediaDebugLog,
    mediaRuntimeCapabilities: refs.mediaRuntimeCapabilities,
    mediaRuntimePath: refs.mediaRuntimePath,
    nativeAudioBridgeHasLocalAudioTrack: nativeBridgeRuntime.nativeAudioBridgeHasLocalAudioTrack,
    nativeAudioBridgeLocalTrackTelemetry: nativeBridgeRuntime.nativeAudioBridgeLocalTrackTelemetry,
    nativePeerConnectionTelemetry: refs.nativePeerConnectionTelemetry,
    nativePeerConnectionsRef: refs.nativePeerConnectionsRef,
    nativePeerHasLocalLiveAudioSender: callbacks.nativePeerHasLocalLiveAudioSender,
    nativeSdpAudioSummaries: refs.nativeSdpAudioSummaries,
    nativeSdpAudioSummary: refs.nativeSdpAudioSummary,
    nativeSdpHasSendableAudio: refs.nativeSdpHasSendableAudio,
    reportNativeAudioSdpRejected: callbacks.reportNativeAudioSdpRejected,
    resetNativeOfferRetry: nativePeerLifecycle.resetNativeOfferRetry,
    runtimeSwitchInFlightRef: state.getRuntimeSwitchInFlight,
    scheduleNativeOfferRetry: nativePeerLifecycle.scheduleNativeOfferRetry,
    scheduleNativeOfferRetryForUserId: nativePeerLifecycle.scheduleNativeOfferRetryForUserId,
    sendSocketFrame: callbacks.sendSocketFrame,
    shouldExpectLocalNativeAudioTrack: nativeBridgeRuntime.shouldExpectLocalNativeAudioTrack,
    shouldExpectRemoteNativeAudioTrack: nativeBridgeRuntime.shouldExpectRemoteNativeAudioTrack,
    shouldBlockNativeRuntimeSignaling,
    shouldMaintainNativePeerConnections: callbacks.shouldMaintainNativePeerConnections,
    shouldUseNativeAudioBridge: callbacks.shouldUseNativeAudioBridge,
    sfuRuntimeEnabled: callbacks.sfuRuntimeEnabled,
    switchMediaRuntimePath: callbacks.switchMediaRuntimePath,
    syncNativePeerLocalTracks: nativeBridgeRuntime.syncNativePeerLocalTracks,
  });

  return {
    ...nativeBridgeRuntime,
    ...audioBridgeState,
    ...nativeAudioBridgeRecovery,
    ...nativePeerLifecycle,
    ...nativeSignaling,
    ensureNativePeerConnection,
    syncNativePeerConnectionsWithRoster,
  };
}
