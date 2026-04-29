import { createLocalPublisherPipelineHelpers } from '../../local/publisherPipeline';
import { createLocalMediaOrchestrationHelpers } from '../../local/mediaOrchestration';
import { createSfuFrameDecodeHelpers } from '../../sfu/frameDecode';
import { createSfuRemotePeerHelpers } from '../../sfu/remotePeers';
import { createCallWorkspaceRuntimeHealthHelpers } from './runtimeHealth';
import { createCallWorkspaceVideoLayoutHelpers } from './videoLayout';
import { createSfuTransportController } from './sfuTransport';
import { createHybridDecoder } from '../../../../lib/wasm/wasm-codec';
import { createDecoder as createTsDecoder } from '../../../../lib/wavelet/codec.js';

export function createCallWorkspaceMediaStack(options) {
  const {
    refs,
    callbacks,
    constants,
    state,
    vue,
  } = options;
  const { markRaw, nextTick } = vue;

  let renderCallVideoLayout = () => {};
  let markRemotePeerRenderable = () => {};

  function bumpMediaRenderVersion() {
    refs.mediaRenderVersion.value = refs.mediaRenderVersion.value >= 1_000_000
      ? 0
      : refs.mediaRenderVersion.value + 1;
  }

  const {
    createOrUpdateSfuRemotePeer,
    deleteSfuRemotePeer,
    ensureSfuRemotePeerForFrame,
    findSfuRemotePeerEntryByUserId,
    getSfuRemotePeerByFrameIdentity,
    normalizeSfuPublisherId,
    promotePeerToTsDecoder: promotePeerToTsDecoderInner,
    remoteDecoderRuntimeName,
    setSfuRemotePeer,
    sfuTrackListHasVideo,
    sfuTrackRows,
    updateSfuRemotePeerUserId,
  } = createSfuRemotePeerHelpers({
    bumpMediaRenderVersion,
    captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
    createHybridDecoder,
    currentUserId: () => refs.currentUserId.value,
    isWlvcRuntimePath: callbacks.isWlvcRuntimePath,
    markRaw,
    maybeFallbackToNativeRuntime: callbacks.maybeFallbackToNativeRuntime,
    mediaDebugLog: callbacks.mediaDebugLog,
    nextTick,
    pendingSfuRemotePeerInitializers: refs.pendingSfuRemotePeerInitializers,
    remotePeersRef: refs.remotePeersRef,
    renderCallVideoLayout: () => renderCallVideoLayout(),
    sfuFrameHeight: constants.sfuFrameHeight,
    sfuFrameQuality: constants.sfuFrameQuality,
    sfuFrameWidth: constants.sfuFrameWidth,
    teardownRemotePeer: callbacks.teardownRemotePeer,
  });

  const promotePeerToTsDecoder = (peer) => promotePeerToTsDecoderInner(peer, createTsDecoder);

  const {
    handleSFUEncodedFrame,
  } = createSfuFrameDecodeHelpers({
    captureClientDiagnostic: callbacks.captureClientDiagnostic,
    captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
    currentUserId: () => refs.currentUserId.value,
    ensureMediaSecuritySession: callbacks.ensureMediaSecuritySession,
    ensureSfuRemotePeerForFrame,
    getSfuRemotePeerByFrameIdentity,
    isWlvcRuntimePath: callbacks.isWlvcRuntimePath,
    markParticipantActivity: callbacks.markParticipantActivity,
    markRemotePeerRenderable: (peer) => markRemotePeerRenderable(peer),
    bumpMediaRenderVersion,
    mediaDebugLog: callbacks.mediaDebugLog,
    mediaRuntimePathRef: refs.mediaRuntimePath,
    normalizeSfuPublisherId,
    promotePeerToTsDecoder,
    recoverMediaSecurityForPublisher: callbacks.recoverMediaSecurityForPublisher,
    remoteDecoderRuntimeName,
    remoteFrameActivityMarkIntervalMs: constants.remoteFrameActivityMarkIntervalMs,
    remoteFrameActivityLastByUserId: refs.remoteFrameActivityLastByUserId,
    remoteSfuFrameDropLogCooldownMs: constants.remoteSfuFrameDropLogCooldownMs,
    remoteSfuFrameStaleTtlMs: constants.remoteSfuFrameStaleTtlMs,
    remoteVideoKeyframeWaitLogCooldownMs: constants.remoteVideoKeyframeWaitLogCooldownMs,
    renderCallVideoLayout: () => renderCallVideoLayout(),
    remotePeersRef: refs.remotePeersRef,
    sendMediaSecurityHello: callbacks.sendMediaSecurityHello,
    sendRemoteSfuVideoQualityPressure: (peer, publisherId, reason, nowMs, payload = {}) => {
      if (typeof callbacks.sendSocketFrame !== 'function') return false;
      const targetUserId = Number(peer?.userId || 0);
      const localUserId = Number(refs.currentUserId.value || 0);
      if (!Number.isInteger(targetUserId) || targetUserId <= 0 || targetUserId === localUserId) return false;
      const normalizedReason = String(reason || 'sfu_receiver_feedback').trim().toLowerCase();
      const requestFullKeyframe = normalizedReason === 'sfu_remote_video_decoder_waiting_keyframe';
      const requestedAction = String(
        payload?.requested_action || (requestFullKeyframe ? 'force_full_keyframe' : 'downgrade_outgoing_video'),
      ).trim().toLowerCase();
      return callbacks.sendSocketFrame({
        type: 'call/media-quality-pressure',
        target_user_id: targetUserId,
        payload: {
          kind: 'sfu-video-quality-pressure',
          requested_action: requestedAction,
          request_full_keyframe: Boolean(payload?.request_full_keyframe) || requestFullKeyframe,
          reason: normalizedReason,
          publisher_id: String(publisherId || ''),
          requester_user_id: localUserId,
          media_runtime_path: refs.mediaRuntimePath.value,
          ...payload,
        },
      });
    },
    sfuFrameHeight: constants.sfuFrameHeight,
    sfuFrameQuality: constants.sfuFrameQuality,
    sfuFrameWidth: constants.sfuFrameWidth,
    shouldRecoverMediaSecurityFromFrameError: callbacks.shouldRecoverMediaSecurityFromFrameError,
    updateSfuRemotePeerUserId,
  });

  const runtimeHealth = createCallWorkspaceRuntimeHealthHelpers({
    callbacks: {
      bumpMediaRenderVersion,
      captureClientDiagnostic: callbacks.captureClientDiagnostic,
      mediaDebugLog: callbacks.mediaDebugLog,
      restartSfuAfterVideoStall: callbacks.restartSfuAfterVideoStall,
      sendSocketFrame: callbacks.sendSocketFrame,
    },
    constants: {
      defaultNativeAudioBridgeFailureMessage: constants.defaultNativeAudioBridgeFailureMessage,
      mediaSecuritySessionClass: constants.MediaSecuritySession,
      remoteVideoFreezeThresholdMs: constants.remoteVideoFreezeThresholdMs,
      remoteVideoStallCheckIntervalMs: constants.remoteVideoStallCheckIntervalMs,
      remoteVideoStallThresholdMs: constants.remoteVideoStallThresholdMs,
      sfuRuntimeEnabled: constants.sfuRuntimeEnabled,
    },
    refs: {
      connectedParticipantUsers: refs.connectedParticipantUsers,
      connectionState: refs.connectionState,
      currentUserId: refs.currentUserId,
      mediaRuntimeCapabilities: refs.mediaRuntimeCapabilities,
      mediaRuntimePath: refs.mediaRuntimePath,
      remotePeersRef: refs.remotePeersRef,
      sfuClientRef: refs.sfuClientRef,
      sfuConnected: refs.sfuConnected,
      shouldConnectSfu: refs.shouldConnectSfu,
      videoEncoderRef: refs.videoEncoderRef,
    },
    state: {
      getRemoteVideoStallTimer: state.getRemoteVideoStallTimer,
      setRemoteVideoStallTimer: state.setRemoteVideoStallTimer,
    },
  });

  const sfuTransport = createSfuTransportController({
    callMediaPrefs: refs.callMediaPrefs,
    captureClientDiagnostic: callbacks.captureClientDiagnostic,
    downgradeSfuVideoQualityAfterEncodePressure: callbacks.downgradeSfuVideoQualityAfterEncodePressure,
    getMediaRuntimePath: () => refs.mediaRuntimePath.value,
    getSfuSendFailureDetails: () => refs.sfuClientRef.value?.getLastSendFailure?.() || null,
    getRemotePeerCount: () => refs.remotePeersRef.value.size,
    getShouldConnectSfu: () => refs.shouldConnectSfu.value,
    onRestartSfu: callbacks.onRestartSfu,
    resetWlvcEncoderAfterDroppedEncodedFrame: runtimeHealth.resetWlvcEncoderAfterDroppedEncodedFrame,
    sfuAutoQualityDowngradeBackpressureWindowMs: constants.sfuAutoQualityDowngradeBackpressureWindowMs,
    sfuAutoQualityDowngradeSendFailureThreshold: constants.sfuAutoQualityDowngradeSendFailureThreshold,
    sfuAutoQualityDowngradeSkipThreshold: constants.sfuAutoQualityDowngradeSkipThreshold,
    sfuBackpressureLogCooldownMs: constants.sfuBackpressureLogCooldownMs,
    sfuClientRef: refs.sfuClientRef,
    sfuConnectRetryDelayMs: constants.sfuConnectRetryDelayMs,
    sfuConnected: refs.sfuConnected,
    sfuVideoRecoveryReconnectCooldownMs: constants.sfuVideoRecoveryReconnectCooldownMs,
    sfuWlvcBackpressureHardResetAfterMs: constants.sfuWlvcBackpressureHardResetAfterMs,
    sfuWlvcBackpressureMaxPauseMs: constants.sfuWlvcBackpressureMaxPauseMs,
    sfuWlvcBackpressureMinPauseMs: constants.sfuWlvcBackpressureMinPauseMs,
    sfuWlvcEncodeFailureThreshold: constants.wlvcEncodeFailureThreshold,
    sfuWlvcSendBufferCriticalBytes: constants.sfuWlvcSendBufferCriticalBytes,
    sfuWlvcSendBufferHighWaterBytes: constants.sfuWlvcSendBufferHighWaterBytes,
    sfuWlvcSendBufferLowWaterBytes: constants.sfuWlvcSendBufferLowWaterBytes,
    state: refs.sfuTransportState,
  });

  const localPublisherPipeline = createLocalPublisherPipelineHelpers({
    backgroundBaselineCollector: refs.backgroundBaselineCollector,
    backgroundFilterController: refs.backgroundFilterController,
    callbacks: {
      applyCallOutputPreferences: callbacks.applyCallOutputPreferences,
      canProtectCurrentSfuTargets: callbacks.canProtectCurrentSfuTargets,
      captureClientDiagnostic: callbacks.captureClientDiagnostic,
      currentSfuVideoProfile: callbacks.currentSfuVideoProfile,
      ensureMediaSecuritySession: callbacks.ensureMediaSecuritySession,
      getSfuClientBufferedAmount: sfuTransport.getSfuClientBufferedAmount,
      handleWlvcEncodeBackpressure: sfuTransport.handleWlvcEncodeBackpressure,
      handleWlvcFrameSendFailure: sfuTransport.handleWlvcFrameSendFailure,
      handleWlvcFramePayloadPressure: sfuTransport.handleWlvcFramePayloadPressure,
      handleWlvcRuntimeEncodeError: sfuTransport.handleWlvcRuntimeEncodeError,
      hintMediaSecuritySync: callbacks.hintMediaSecuritySync,
      isSfuClientOpen: sfuTransport.isSfuClientOpen,
      isWlvcRuntimePath: runtimeHealth.isWlvcRuntimePath,
      maybeFallbackToNativeRuntime: callbacks.maybeFallbackToNativeRuntime,
      mediaDebugLog: callbacks.mediaDebugLog,
      noteWlvcSourceReadbackSuccess: sfuTransport.noteWlvcSourceReadbackSuccess,
      reconfigureLocalTracksFromSelectedDevices: callbacks.reconfigureLocalTracksFromSelectedDevices,
      renderCallVideoLayout: () => renderCallVideoLayout(),
      resetBackgroundRuntimeMetrics: callbacks.resetBackgroundRuntimeMetrics,
      resolveWlvcEncodeIntervalMs: sfuTransport.resolveWlvcEncodeIntervalMs,
      resetWlvcBackpressureCounters: sfuTransport.resetWlvcBackpressureCounters,
      resetWlvcFrameSendFailureCounters: sfuTransport.resetWlvcFrameSendFailureCounters,
      shouldDelayWlvcFrameForBackpressure: sfuTransport.shouldDelayWlvcFrameForBackpressure,
      shouldSendTransportOnlySfuFrame: callbacks.shouldSendTransportOnlySfuFrame,
      shouldThrottleWlvcEncodeLoop: sfuTransport.shouldThrottleWlvcEncodeLoop,
      stopActivityMonitor: callbacks.stopActivityMonitor,
      stopSfuTrackAnnounceTimer: callbacks.stopSfuTrackAnnounceTimer,
    },
    captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
    constants: {
      backgroundSnapshotEnabled: constants.backgroundSnapshotEnabled,
      backgroundSnapshotMaxChangedRatio: constants.backgroundSnapshotMaxChangedRatio,
      backgroundSnapshotMaxPatchAreaRatio: constants.backgroundSnapshotMaxPatchAreaRatio,
      backgroundSnapshotMinChangedRatio: constants.backgroundSnapshotMinChangedRatio,
      backgroundSnapshotMinIntervalMs: constants.backgroundSnapshotMinIntervalMs,
      backgroundSnapshotSampleStride: constants.backgroundSnapshotSampleStride,
      backgroundSnapshotTileDiffThreshold: constants.backgroundSnapshotTileDiffThreshold,
      backgroundSnapshotTileHeight: constants.backgroundSnapshotTileHeight,
      backgroundSnapshotTileWidth: constants.backgroundSnapshotTileWidth,
      localTrackRecoveryBaseDelayMs: constants.localTrackRecoveryBaseDelayMs,
      localTrackRecoveryMaxAttempts: constants.localTrackRecoveryMaxAttempts,
      localTrackRecoveryMaxDelayMs: constants.localTrackRecoveryMaxDelayMs,
      protectedMediaEnabled: constants.protectedMediaEnabled,
      selectiveTileBaseRefreshMs: constants.selectiveTileBaseRefreshMs,
      selectiveTileDiffThreshold: constants.selectiveTileDiffThreshold,
      selectiveTileEnabled: constants.selectiveTileEnabled,
      selectiveTileHeight: constants.selectiveTileHeight,
      selectiveTileMaxChangedRatio: constants.selectiveTileMaxChangedRatio,
      selectiveTileMaxPatchAreaRatio: constants.selectiveTileMaxPatchAreaRatio,
      selectiveTileSampleStride: constants.selectiveTileSampleStride,
      selectiveTileWidth: constants.selectiveTileWidth,
      sendBufferHighWaterBytes: constants.sendBufferHighWaterBytes,
      sfuWlvcFrameQuality: constants.sfuFrameQuality,
      sfuWlvcMaxDeltaFrameBytes: constants.sfuWlvcMaxDeltaFrameBytes,
      sfuWlvcMaxKeyframeFrameBytes: constants.sfuWlvcMaxKeyframeFrameBytes,
      wlvcEncodeErrorLogCooldownMs: constants.wlvcEncodeErrorLogCooldownMs,
      wlvcEncodeFailureThreshold: constants.wlvcEncodeFailureThreshold,
      wlvcEncodeFailureWindowMs: constants.wlvcEncodeFailureWindowMs,
      wlvcEncodeWarmupMs: constants.wlvcEncodeWarmupMs,
    },
    refs: {
      currentUserId: () => refs.sessionState.userId,
      encodeIntervalRef: refs.encodeIntervalRef,
      localFilteredStreamRef: refs.localFilteredStreamRef,
      localRawStreamRef: refs.localRawStreamRef,
      localStreamRef: refs.localStreamRef,
      localTracksRef: refs.localTracksRef,
      localVideoElement: refs.localVideoElement,
      mediaRuntimeCapabilitiesRef: refs.mediaRuntimeCapabilities,
      mediaRuntimePathRef: refs.mediaRuntimePath,
      sfuClientRef: refs.sfuClientRef,
      sfuTransportState: refs.sfuTransportState,
      videoEncoderRef: refs.videoEncoderRef,
      videoPatchEncoderHeight: refs.videoPatchEncoderHeight,
      videoPatchEncoderQuality: refs.videoPatchEncoderQuality,
      videoPatchEncoderRef: refs.videoPatchEncoderRef,
      videoPatchEncoderWidth: refs.videoPatchEncoderWidth,
    },
    state: refs.localPublisherPipelineState,
  });

  const localMediaOrchestration = createLocalMediaOrchestrationHelpers({
    backgroundBaselineCollector: refs.backgroundBaselineCollector,
    backgroundFilterController: refs.backgroundFilterController,
    callbacks: {
      clearTransientActivityPublishErrorNotice: callbacks.clearTransientActivityPublishErrorNotice,
      captureClientDiagnostic: callbacks.captureClientDiagnostic,
      currentSfuVideoProfile: callbacks.currentSfuVideoProfile,
      evaluateBackgroundFilterGates: callbacks.evaluateBackgroundFilterGates,
      isSfuClientOpen: sfuTransport.isSfuClientOpen,
      isWlvcRuntimePath: runtimeHealth.isWlvcRuntimePath,
      markParticipantActivity: callbacks.markParticipantActivity,
      mediaDebugLog: callbacks.mediaDebugLog,
      normalizeRoomId: callbacks.normalizeRoomId,
      refreshCallMediaDevices: callbacks.refreshCallMediaDevices,
      resetCallBackgroundRuntimeState: callbacks.resetCallBackgroundRuntimeState,
      sendSocketFrame: callbacks.sendSocketFrame,
      shouldMaintainNativePeerConnections: runtimeHealth.shouldMaintainNativePeerConnections,
      shouldSyncNativeLocalTracksBeforeOffer: callbacks.shouldSyncNativeLocalTracksBeforeOffer,
      syncNativePeerConnectionsWithRoster: callbacks.syncNativePeerConnectionsWithRoster,
      syncNativePeerLocalTracks: callbacks.syncNativePeerLocalTracks,
      sendNativeOffer: callbacks.sendNativeOffer,
      localPublisher: {
        applyControlStateToLocalTracks: callbacks.applyControlStateToLocalTracks,
        bindLocalTrackLifecycle: localPublisherPipeline.bindLocalTrackLifecycle,
        clearLocalPreviewElement: localPublisherPipeline.clearLocalPreviewElement,
        scheduleLocalTrackRecovery: localPublisherPipeline.scheduleLocalTrackRecovery,
        startEncodingPipeline: localPublisherPipeline.startEncodingPipeline,
        stopLocalEncodingPipeline: localPublisherPipeline.stopLocalEncodingPipeline,
        stopRetiredLocalStreams: localPublisherPipeline.stopRetiredLocalStreams,
        unpublishSfuTracks: localPublisherPipeline.unpublishSfuTracks,
      },
    },
    callMediaPrefs: refs.callMediaPrefs,
    captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
    constants: {
      activityMotionSampleMs: constants.activityMotionSampleMs,
      activityPublishIntervalMs: constants.activityPublishIntervalMs,
      sfuRuntimeEnabled: constants.sfuRuntimeEnabled,
    },
    controlState: refs.controlState,
    refs: {
      activeRoomId: refs.activeRoomId,
      activeSocketCallId: refs.activeSocketCallId,
      currentUserId: refs.currentUserId,
      desiredRoomId: refs.desiredRoomId,
      encodeIntervalRef: refs.encodeIntervalRef,
      isSocketOnline: refs.isSocketOnline,
      localFilteredStreamRef: refs.localFilteredStreamRef,
      localRawStreamRef: refs.localRawStreamRef,
      localStreamRef: refs.localStreamRef,
      localTracksRef: refs.localTracksRef,
      localVideoElement: refs.localVideoElement,
      mediaRuntimePathRef: refs.mediaRuntimePath,
      nativePeerConnectionsRef: refs.nativePeerConnectionsRef,
      normalizedCallLayout: refs.normalizedCallLayout,
      sfuClientRef: refs.sfuClientRef,
    },
    state: refs.localMediaOrchestrationState,
  });

  function teardownSfuRemotePeers() {
    for (const [, peer] of refs.remotePeersRef.value) {
      const peerUserId = Number(peer?.userId || 0);
      if (Number.isInteger(peerUserId) && peerUserId > 0) {
        callbacks.clearMediaSecuritySfuPublisherSeen?.(peerUserId);
      }
      callbacks.teardownRemotePeer(peer);
    }
    refs.remotePeersRef.value = new Map();
    refs.pendingSfuRemotePeerInitializers.clear();
    refs.remoteFrameActivityLastByUserId.clear();
    callbacks.clearRemoteVideoContainer();
    bumpMediaRenderVersion();
  }

  const videoLayout = createCallWorkspaceVideoLayoutHelpers({
    callbacks: {
      applyCallOutputPreferences: callbacks.applyCallOutputPreferences,
      bumpMediaRenderVersion,
      currentLayoutMode: () => refs.currentLayoutMode.value,
      gridVideoParticipants: () => refs.gridVideoParticipants.value,
      gridVideoSlotId: constants.gridVideoSlotId,
      hasRenderableMediaForParticipant: callbacks.hasRenderableMediaForParticipant,
      lookupMediaNodeForUserId: callbacks.lookupMediaNodeForUserId,
      miniVideoParticipants: () => refs.miniVideoParticipants.value,
      miniVideoSlotId: constants.miniVideoSlotId,
      primaryVideoUserId: () => refs.primaryVideoUserId.value,
      remotePeerMediaNode: callbacks.remotePeerMediaNode,
    },
    refs: {
      currentUserId: refs.currentUserId,
      localFilteredStreamRef: refs.localFilteredStreamRef,
      localRawStreamRef: refs.localRawStreamRef,
      localStreamRef: refs.localStreamRef,
      localVideoElement: refs.localVideoElement,
      mediaRenderVersion: refs.mediaRenderVersion,
      nativePeerConnectionsRef: refs.nativePeerConnectionsRef,
      remotePeersRef: refs.remotePeersRef,
      shouldMaintainNativePeerConnections: runtimeHealth.shouldMaintainNativePeerConnections,
    },
  });

  renderCallVideoLayout = videoLayout.renderCallVideoLayout;
  markRemotePeerRenderable = videoLayout.markRemotePeerRenderable;

  return {
    ...runtimeHealth,
    ...sfuTransport,
    ...localPublisherPipeline,
    ...localMediaOrchestration,
    ...videoLayout,
    createOrUpdateSfuRemotePeer,
    deleteSfuRemotePeer,
    ensureSfuRemotePeerForFrame,
    findSfuRemotePeerEntryByUserId,
    getSfuRemotePeerByFrameIdentity,
    handleSFUEncodedFrame,
    normalizeSfuPublisherId,
    remoteDecoderRuntimeName,
    renderCallVideoLayout,
    setSfuRemotePeer,
    sfuTrackListHasVideo,
    sfuTrackRows,
    teardownSfuRemotePeers,
    updateSfuRemotePeerUserId,
    bumpMediaRenderVersion,
  };
}
