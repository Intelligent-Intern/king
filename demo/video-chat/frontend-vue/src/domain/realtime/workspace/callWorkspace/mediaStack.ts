import { createLocalPublisherPipelineHelpers } from '../../local/publisherPipeline';
import { createLocalMediaOrchestrationHelpers } from '../../local/mediaOrchestration';
import { createScreenShareParticipantPublisher } from '../../local/screenSharePublisher';
import {
  SCREEN_SHARE_MEDIA_SOURCE,
  SCREEN_SHARE_TRACK_LABEL,
  isScreenShareMediaSource,
  isScreenShareUserId,
  screenShareDisplayName,
  screenShareOwnerOrUserId,
  screenShareUserIdForOwner,
} from '../../screenShareIdentity.js';
import { createSfuFrameDecodeHelpers } from '../../sfu/frameDecode';
import { createSfuRemotePeerHelpers } from '../../sfu/remotePeers';
import {
  normalizeSfuRecoveryReason,
  resolveSfuRecoveryRequestedAction,
  shouldRequestSfuCompatibilityCodecFallback,
  shouldRequestSfuFullKeyframeForReason,
} from '../../sfu/recoveryReasons';
import { createCallWorkspaceRuntimeHealthHelpers } from './runtimeHealth';
import { createCallWorkspaceVideoLayoutHelpers } from './videoLayout';
import { createSfuTransportController } from './sfuTransport';
import { createHybridDecoder } from '../../../../lib/wasm/wasm-codec';
import { createDecoder as createTsDecoder } from '../../../../lib/wavelet/codec.ts';

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

  function localScreenSharePublisherId(ownerUserId) {
    const normalizedOwnerUserId = Number(ownerUserId || 0);
    return Number.isInteger(normalizedOwnerUserId) && normalizedOwnerUserId > 0
      ? `local_screen_share:${normalizedOwnerUserId}`
      : '';
  }

  function peerMatchesLocalScreenShareLoopback(peer, ownerUserId, screenUserId) {
    if (!peer || typeof peer !== 'object' || peer.localScreenSharePreview === true) return false;
    const peerUserId = Number(peer.userId || peer.user_id || 0);
    const peerPublisherUserId = Number(peer.publisherUserId || peer.publisher_user_id || 0);
    const peerOwnerUserId = Number(peer.screenShareOwnerUserId || peer.screen_share_owner_user_id || 0);
    if (peerUserId === screenUserId) return true;
    if (!isScreenShareMediaSource(peer.mediaSource || peer.media_source)) return false;
    return peerOwnerUserId === ownerUserId
      || peerPublisherUserId === ownerUserId
      || peerPublisherUserId === screenUserId;
  }

  function removeLocalScreenShareLoopbackPeers(ownerUserId, screenUserId) {
    const localPublisherId = localScreenSharePublisherId(ownerUserId);
    let removedAny = false;
    for (const [publisherId, peer] of Array.from(refs.remotePeersRef.value.entries())) {
      if (String(publisherId || '') === localPublisherId) continue;
      if (!peerMatchesLocalScreenShareLoopback(peer, ownerUserId, screenUserId)) continue;
      callbacks.teardownRemotePeer(peer);
      removedAny = deleteSfuRemotePeer(publisherId) || removedAny;
    }
    return removedAny;
  }

  function registerLocalScreenSharePeer({ stream = null, videoElement = null, videoTrack = null } = {}) {
    const ownerUserId = Number(refs.currentUserId.value || refs.sessionState.userId || 0);
    const screenUserId = screenShareUserIdForOwner(ownerUserId);
    const publisherId = localScreenSharePublisherId(ownerUserId);
    if (!Number.isInteger(ownerUserId) || ownerUserId <= 0 || screenUserId <= 0 || publisherId === '') {
      return null;
    }
    const video = typeof HTMLVideoElement !== 'undefined' && videoElement instanceof HTMLVideoElement
      ? videoElement
      : null;
    if (!video) return null;

    video.dataset.userId = String(screenUserId);
    video.dataset.publisherUserId = String(ownerUserId);
    video.dataset.mediaSource = SCREEN_SHARE_MEDIA_SOURCE;
    video.dataset.callScreenSharePreview = '1';
    video.muted = true;
    video.playsInline = true;
    video.autoplay = true;

    const nowMs = Date.now();
    removeLocalScreenShareLoopbackPeers(ownerUserId, screenUserId);
    const existingPeer = refs.remotePeersRef.value.get(publisherId) || null;
    const displayName = screenShareDisplayName(
      refs.sessionState.displayName || refs.sessionState.email || '',
      ownerUserId,
    );
    const trackId = String(videoTrack?.id || '').trim();
    const tracks = trackId !== ''
      ? [{ id: trackId, kind: 'video', label: SCREEN_SHARE_TRACK_LABEL }]
      : [];
    const peer = {
      ...(existingPeer && typeof existingPeer === 'object' ? existingPeer : {}),
      userId: screenUserId,
      publisherUserId: ownerUserId,
      displayName,
      mediaSource: SCREEN_SHARE_MEDIA_SOURCE,
      screenShareOwnerUserId: ownerUserId,
      localScreenSharePreview: true,
      pc: null,
      video,
      tracks,
      stream,
      remoteStream: stream,
      decoder: null,
      createdAtMs: Number(existingPeer?.createdAtMs || nowMs),
      frameCount: Math.max(1, Number(existingPeer?.frameCount || 0)),
      receivedFrameCount: Math.max(1, Number(existingPeer?.receivedFrameCount || 0)),
      lastFrameAtMs: nowMs,
      lastReceivedFrameAtMs: nowMs,
      mediaConnectionState: 'live',
      mediaConnectionMessage: '',
      mediaConnectionUpdatedAtMs: nowMs,
    };

    setSfuRemotePeer(publisherId, peer);
    removeLocalScreenShareLoopbackPeers(ownerUserId, screenUserId);
    callbacks.markParticipantActivity?.(screenUserId, 'media_track', nowMs);
    renderCallVideoLayout();
    return peer;
  }

  function unregisterLocalScreenSharePeer() {
    const ownerUserId = Number(refs.currentUserId.value || refs.sessionState.userId || 0);
    const publisherId = localScreenSharePublisherId(ownerUserId);
    if (publisherId === '') return false;
    const peer = refs.remotePeersRef.value.get(publisherId);
    if (!peer) return false;
    callbacks.teardownRemotePeer(peer);
    deleteSfuRemotePeer(publisherId);
    renderCallVideoLayout();
    return true;
  }

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
      const requestedVideoLayer = String(payload?.requested_video_layer || '').trim().toLowerCase();
      const requestedAction = String(
        payload?.requested_action || (requestedVideoLayer === 'primary'
          ? 'prefer_primary_video_layer'
          : (requestedVideoLayer === 'thumbnail' ? 'prefer_thumbnail_video_layer' : '')),
      ).trim().toLowerCase();
      const isLayerPreference = requestedVideoLayer === 'primary' || requestedVideoLayer === 'thumbnail';
      let sfuLayerPreferenceSent = false;
      if (isLayerPreference) {
        sfuLayerPreferenceSent = refs.sfuClientRef.value
          && typeof refs.sfuClientRef.value.setSubscriberLayerPreference === 'function'
          ? refs.sfuClientRef.value.setSubscriberLayerPreference(String(publisherId || ''), {
            ...payload,
            requested_video_layer: requestedVideoLayer,
            requested_action: requestedAction,
            reason,
          })
          : false;
        if (
          sfuLayerPreferenceSent
          && requestedAction === 'prefer_thumbnail_video_layer'
        ) {
          return true;
        }
      }
      const localUserId = Number(refs.currentUserId.value || 0);
      const normalizedReason = normalizeSfuRecoveryReason(reason, 'sfu_receiver_feedback');
      const requestFullKeyframe = shouldRequestSfuFullKeyframeForReason(normalizedReason);
      const feedbackAction = resolveSfuRecoveryRequestedAction(normalizedReason, payload?.requested_action);
      const compatibilityCodecRequested = shouldRequestSfuCompatibilityCodecFallback(feedbackAction, payload || {});
      const sfuRecoverySent = !compatibilityCodecRequested
        && refs.sfuClientRef.value
        && typeof refs.sfuClientRef.value.requestPublisherMediaRecovery === 'function'
        ? refs.sfuClientRef.value.requestPublisherMediaRecovery(String(publisherId || ''), {
          ...payload,
          requested_action: requestedAction || feedbackAction,
          request_full_keyframe: Boolean(payload?.request_full_keyframe)
            || requestFullKeyframe,
          requested_video_layer: requestedVideoLayer,
          reason: normalizedReason,
        })
        : false;
      if (sfuLayerPreferenceSent && !requestFullKeyframe && !compatibilityCodecRequested) return true;

      const peerUserId = Number(peer?.userId || 0);
      const peerPublisherUserId = Number(peer?.publisherUserId || peer?.publisher_user_id || 0);
      const payloadPublisherUserId = Number(payload?.publisher_user_id || payload?.publisherUserId || 0);
      const screenShareOwnerUserId = Number(peer?.screenShareOwnerUserId || peer?.screen_share_owner_user_id || 0);
      const peerIsScreenShare = isScreenShareUserId(peerUserId)
        || isScreenShareUserId(peerPublisherUserId)
        || isScreenShareUserId(payloadPublisherUserId)
        || isScreenShareMediaSource(peer?.mediaSource || peer?.media_source)
        || isScreenShareMediaSource(payload?.publisher_media_source || payload?.publisherMediaSource);
      const targetUserId = Number(peerIsScreenShare
        ? screenShareOwnerOrUserId(
          screenShareOwnerUserId
            || peerPublisherUserId
            || payloadPublisherUserId
            || peerUserId
        )
        : (peerUserId || payloadPublisherUserId));
      const socketRecoverySent = Number.isInteger(targetUserId)
        && targetUserId > 0
        && targetUserId !== localUserId
        && typeof callbacks.sendSocketFrame === 'function'
        ? callbacks.sendSocketFrame({
          type: 'call/media-quality-pressure',
          target_user_id: targetUserId,
          payload: {
            ...payload,
            kind: 'sfu-video-quality-pressure',
            requested_action: feedbackAction,
            request_full_keyframe: Boolean(payload?.request_full_keyframe) || requestFullKeyframe,
            reason: normalizedReason,
            publisher_id: String(publisherId || ''),
            requester_user_id: localUserId,
            media_runtime_path: refs.mediaRuntimePath.value,
          },
        })
        : false;
      return Boolean(sfuRecoverySent || socketRecoverySent || sfuLayerPreferenceSent);
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
      publishLocalEncodedFrameToGossip: callbacks.publishLocalEncodedFrameToGossip,
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

  const screenSharePublisher = createScreenShareParticipantPublisher({
    callbacks: {
      applyCallOutputPreferences: callbacks.applyCallOutputPreferences,
      canProtectCurrentSfuTargets: callbacks.canProtectCurrentSfuTargets,
      captureClientDiagnostic: callbacks.captureClientDiagnostic,
      captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
      currentSfuVideoProfile: callbacks.currentSfuVideoProfile,
      ensureMediaSecuritySession: callbacks.ensureMediaSecuritySession,
      handleWlvcEncodeBackpressure: sfuTransport.handleWlvcEncodeBackpressure,
      handleWlvcFramePayloadPressure: sfuTransport.handleWlvcFramePayloadPressure,
      handleWlvcFrameSendFailure: sfuTransport.handleWlvcFrameSendFailure,
      handleWlvcRuntimeEncodeError: sfuTransport.handleWlvcRuntimeEncodeError,
      hintMediaSecuritySync: callbacks.hintMediaSecuritySync,
      isWlvcRuntimePath: runtimeHealth.isWlvcRuntimePath,
      maybeFallbackToNativeRuntime: callbacks.maybeFallbackToNativeRuntime,
      mediaDebugLog: callbacks.mediaDebugLog,
      noteWlvcSourceReadbackSuccess: sfuTransport.noteWlvcSourceReadbackSuccess,
      onScreenShareStopped: (reason) => {
        unregisterLocalScreenSharePeer();
        refs.controlState.screenEnabled = false;
        callbacks.onLocalScreenShareStateChanged?.(false, reason);
      },
      registerLocalScreenSharePeer,
      renderCallVideoLayout: () => renderCallVideoLayout(),
      requestWlvcFullFrameKeyframe: sfuTransport.requestWlvcFullFrameKeyframe,
      resetWlvcBackpressureCounters: sfuTransport.resetWlvcBackpressureCounters,
      resetWlvcFrameSendFailureCounters: sfuTransport.resetWlvcFrameSendFailureCounters,
      resolveWlvcEncodeIntervalMs: sfuTransport.resolveWlvcEncodeIntervalMs,
      shouldDelayWlvcFrameForBackpressure: sfuTransport.shouldDelayWlvcFrameForBackpressure,
      shouldSendTransportOnlySfuFrame: callbacks.shouldSendTransportOnlySfuFrame,
      shouldThrottleWlvcEncodeLoop: sfuTransport.shouldThrottleWlvcEncodeLoop,
      unregisterLocalScreenSharePeer,
    },
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
      SFUClient: refs.SFUClient,
      activeRoomId: refs.activeRoomId,
      activeSocketCallId: refs.activeSocketCallId,
      mediaRuntimeCapabilities: refs.mediaRuntimeCapabilities,
      mediaRuntimePath: refs.mediaRuntimePath,
      sessionState: refs.sessionState,
      sfuTransportState: refs.sfuTransportState,
      shouldConnectSfu: refs.shouldConnectSfu,
    },
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
      onLocalScreenShareStateChanged: callbacks.onLocalScreenShareStateChanged,
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
        startScreenShareParticipant: screenSharePublisher.start,
        stopLocalEncodingPipeline: localPublisherPipeline.stopLocalEncodingPipeline,
        stopScreenShareParticipant: screenSharePublisher.stop,
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

  function teardownLocalPublisherForWorkspace() {
    localMediaOrchestration.cancelPendingLocalMediaCapture();
    localPublisherPipeline.teardownLocalPublisher();
  }

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
      fullscreenVideoUserId: () => refs.fullscreenVideoUserId.value,
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
    teardownLocalPublisher: teardownLocalPublisherForWorkspace,
    teardownSfuRemotePeers,
    updateSfuRemotePeerUserId,
    bumpMediaRenderVersion,
  };
}
