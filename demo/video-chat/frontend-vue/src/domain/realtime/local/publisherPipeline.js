import { markRaw } from 'vue';
import { createHybridEncoder } from '../../../lib/wasm/wasm-codec';
import { planBackgroundSnapshotPatch, planSelectiveTilePatch } from '../../../lib/sfu/selectiveTileTransport';
import { measureProtectedSfuFrameBudget } from '../media/protectedFrameBudget';
import { sfuFrameTypeFromWlvcData } from '../sfu/wlvcFrameMetadata';
import {
  stopRetiredLocalStreams,
  uniqueLocalStreams,
  unpublishSfuTracksForClient,
} from './localStreamLifecycle';
import {
  buildPublisherTransportStageMetrics,
  createPublisherFrameTrace,
  currentSfuCodecId,
  highResolutionNowMs,
  markPublisherFrameTraceStage,
  publisherFrameTraceMetrics,
  roundedStageMs,
} from './publisherFrameTrace';
import { createPublisherSourceReadbackController } from './publisherSourceReadback';
import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';
import { resolveProfileReadbackIntervalMs, resolvePublisherFrameSize } from './videoFrameSizing';

export function createLocalPublisherPipelineHelpers({
  backgroundBaselineCollector,
  backgroundFilterController,
  callbacks,
  captureClientDiagnosticError,
  constants,
  refs,
  state,
}) {
  let activeSourceReadbackController = null;

  const {
    applyCallOutputPreferences,
    canProtectCurrentSfuTargets,
    currentSfuVideoProfile,
    ensureMediaSecuritySession,
    getSfuClientBufferedAmount,
    handleWlvcEncodeBackpressure,
    handleWlvcFrameSendFailure,
    handleWlvcFramePayloadPressure,
    handleWlvcRuntimeEncodeError,
    hintMediaSecuritySync,
    isSfuClientOpen,
    isWlvcRuntimePath,
    maybeFallbackToNativeRuntime,
    mediaDebugLog,
    noteWlvcSourceReadbackSuccess = () => false,
    reconfigureLocalTracksFromSelectedDevices,
    renderCallVideoLayout,
    resetBackgroundRuntimeMetrics,
    resolveWlvcEncodeIntervalMs = (intervalMs) => intervalMs,
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    shouldDelayWlvcFrameForBackpressure,
    shouldSendTransportOnlySfuFrame,
    shouldThrottleWlvcEncodeLoop,
    stopActivityMonitor,
    stopSfuTrackAnnounceTimer,
  } = callbacks;

  function unpublishSfuTracks(tracks) {
    unpublishSfuTracksForClient(refs.sfuClientRef.value, tracks);
  }

  function clearLocalTrackRecoveryTimer() {
    if (state.localTrackRecoveryTimer !== null) {
      clearTimeout(state.localTrackRecoveryTimer);
      state.localTrackRecoveryTimer = null;
    }
  }

  function hasLiveLocalMedia() {
    const stream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
    if (!(stream instanceof MediaStream)) return false;
    return stream.getTracks().some((track) => String(track?.readyState || '').trim().toLowerCase() === 'live');
  }

  function scheduleLocalTrackRecovery(reason = 'track_ended') {
    if (state.localPublisherTeardownInProgress) return;
    if (state.localTrackRecoveryTimer !== null) return;
    if (state.localTrackRecoveryAttempts >= constants.localTrackRecoveryMaxAttempts) return;

    const attempt = state.localTrackRecoveryAttempts;
    const delayMs = Math.min(
      constants.localTrackRecoveryBaseDelayMs * Math.max(1, 2 ** attempt),
      constants.localTrackRecoveryMaxDelayMs,
    );

    state.localTrackRecoveryTimer = setTimeout(async () => {
      state.localTrackRecoveryTimer = null;
      state.localTrackRecoveryAttempts += 1;

      const recovered = await reconfigureLocalTracksFromSelectedDevices();
      if (recovered || hasLiveLocalMedia()) {
        state.localTrackRecoveryAttempts = 0;
        return;
      }

      scheduleLocalTrackRecovery(reason);
    }, delayMs);
  }

  function bindLocalTrackLifecycle(stream) {
    if (!(stream instanceof MediaStream)) return;
    for (const track of stream.getTracks()) {
      track.addEventListener('ended', () => {
        if (state.localPublisherTeardownInProgress) return;
        const currentStream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
        if (!(currentStream instanceof MediaStream)) return;
        const isCurrentTrack = currentStream.getTracks().some((row) => row.id === track.id);
        if (!isCurrentTrack) return;
        scheduleLocalTrackRecovery(`track_${String(track?.kind || 'media').toLowerCase()}_ended`);
      });
    }
  }

  function stopLocalEncodingPipeline() {
    if (activeSourceReadbackController) {
      void activeSourceReadbackController.close();
      activeSourceReadbackController = null;
    }
    if (refs.encodeIntervalRef.value) {
      clearTimeout(refs.encodeIntervalRef.value);
      refs.encodeIntervalRef.value = null;
    }
    state.wlvcEncodeInFlight = false;
    resetWlvcBackpressureCounters();
    if (refs.videoEncoderRef.value) {
      if (typeof refs.videoEncoderRef.value.destroy === 'function') {
        refs.videoEncoderRef.value.destroy();
      } else if (typeof refs.videoEncoderRef.value.reset === 'function') {
        refs.videoEncoderRef.value.reset();
      }
      refs.videoEncoderRef.value = null;
    }
    if (refs.videoPatchEncoderRef.value) {
      if (typeof refs.videoPatchEncoderRef.value.destroy === 'function') {
        refs.videoPatchEncoderRef.value.destroy();
      } else if (typeof refs.videoPatchEncoderRef.value.reset === 'function') {
        refs.videoPatchEncoderRef.value.reset();
      }
      refs.videoPatchEncoderRef.value = null;
    }
    refs.videoPatchEncoderWidth.value = 0;
    refs.videoPatchEncoderHeight.value = 0;
    refs.videoPatchEncoderQuality.value = 0;
    state.wlvcEncodeFailureCount = 0;
    state.wlvcEncodeWarmupUntilMs = 0;
    state.wlvcEncodeFirstFailureAtMs = 0;
    state.wlvcEncodeLastErrorLogAtMs = 0;
  }

  function clearLocalPreviewElement() {
    const node = refs.localVideoElement.value;
    if (node instanceof HTMLVideoElement) {
      try {
        node.pause();
      } catch {
        // ignore
      }
      node.srcObject = null;
      node.remove();
    }
    refs.localVideoElement.value = null;

    const container = document.getElementById('local-video-container');
    if (container) {
      container.innerHTML = '';
    }
    renderCallVideoLayout();
  }

  function unpublishAndStopLocalTracks() {
    state.localPublisherTeardownInProgress = true;
    clearLocalTrackRecoveryTimer();
    state.backgroundRuntimeToken += 1;
    backgroundBaselineCollector.reset();
    state.backgroundBaselineCaptured = false;
    resetBackgroundRuntimeMetrics('idle');
    backgroundFilterController.dispose();

    try {
      const tracks = Array.isArray(refs.localTracksRef.value) ? [...refs.localTracksRef.value] : [];
      if (refs.sfuClientRef.value) {
        for (const track of tracks) {
          if (track?.id) {
            refs.sfuClientRef.value.unpublishTrack(track.id);
          }
        }
      }

      for (const track of tracks) {
        try {
          track.stop();
        } catch {
          // ignore
        }
      }
      refs.localTracksRef.value = [];
      state.localTracksPublishedToSfu = false;

      const streamsToStop = uniqueLocalStreams([
        refs.localStreamRef.value,
        refs.localRawStreamRef.value,
        refs.localFilteredStreamRef.value,
      ]);
      for (const stream of streamsToStop) {
        for (const track of stream.getTracks()) {
          try {
            track.stop();
          } catch {
            // ignore
          }
        }
      }

      refs.localRawStreamRef.value = null;
      refs.localFilteredStreamRef.value = null;
      refs.localStreamRef.value = null;
    } finally {
      state.localPublisherTeardownInProgress = false;
    }
  }

  function teardownLocalPublisher() {
    clearLocalTrackRecoveryTimer();
    stopSfuTrackAnnounceTimer();
    stopActivityMonitor();
    stopLocalEncodingPipeline();
    unpublishAndStopLocalTracks();
    clearLocalPreviewElement();
  }

  async function startEncodingPipeline(videoTrack) {
    stopLocalEncodingPipeline();
    resetWlvcBackpressureCounters();

    let video = refs.localVideoElement.value;
    if (!(video instanceof HTMLVideoElement)) {
      video = document.createElement('video');
      video.muted = true;
      video.playsInline = true;
      video.autoplay = true;
      refs.localVideoElement.value = video;
    }
    video.srcObject = new MediaStream([videoTrack]);
    const container = document.getElementById('local-video-container');
    if (container && video.parentElement !== container) {
      container.replaceChildren(video);
    }
    try {
      await video.play();
    } catch {
      // keep preview node mounted even when autoplay policy blocks playback.
    }
    renderCallVideoLayout();
    applyCallOutputPreferences();

    if (!isWlvcRuntimePath()) {
      return;
    }

    const videoProfile = currentSfuVideoProfile();
    const pipelineProfileId = String(videoProfile.id || '').trim() || 'balanced';
    const hasPipelineProfileChanged = () => String(currentSfuVideoProfile()?.id || '').trim() !== pipelineProfileId;
    const stopIfPipelineProfileChanged = () => hasPipelineProfileChanged() && (stopLocalEncodingPipeline(), true);

    const sourceReadbackController = createPublisherSourceReadbackController({
      video,
      videoTrack,
      videoProfile,
      mediaDebugLog,
    });
    activeSourceReadbackController = sourceReadbackController;
    const initialFrameSize = sourceReadbackController.initialFrameSize || resolvePublisherFrameSize(video, videoProfile, videoTrack);
    let previousFullFrameImageData = null;
    let lastFullFrameSentAtMs = 0;
    let lastBackgroundSnapshotSentAtMs = 0;
    let selectiveTileCacheEpoch = 0;
    let forcedKeyframeRecoveryPending = false;
    let keyframeRetryBlockedUntilMs = 0;
    let fullFrameEncoderWidth = 0;
    let fullFrameEncoderHeight = 0;
    let fullFrameEncoderQuality = 0;
    let fullFrameEncoderKeyFrameInterval = 0;

    const resetFullFrameContinuity = () => {
      previousFullFrameImageData = null;
      lastFullFrameSentAtMs = 0;
      lastBackgroundSnapshotSentAtMs = 0;
      selectiveTileCacheEpoch += 1;
      forcedKeyframeRecoveryPending = false;
      keyframeRetryBlockedUntilMs = 0;
      if (refs.videoPatchEncoderRef.value) {
        try {
          refs.videoPatchEncoderRef.value.destroy?.();
        } catch {
          // patch state is rebuilt after the next full-frame base
        }
        refs.videoPatchEncoderRef.value = null;
      }
      refs.videoPatchEncoderWidth.value = 0;
      refs.videoPatchEncoderHeight.value = 0;
      refs.videoPatchEncoderQuality.value = 0;
    };

    const destroyFullFrameEncoder = () => {
      if (!refs.videoEncoderRef.value) return;
      try {
        refs.videoEncoderRef.value.destroy?.();
      } catch {
        // ignore stale encoder cleanup failures during source aspect switches
      }
      refs.videoEncoderRef.value = null;
    };

    const markFullFrameEncoderReady = (width, height, quality, keyFrameInterval) => {
      fullFrameEncoderWidth = width;
      fullFrameEncoderHeight = height;
      fullFrameEncoderQuality = quality;
      fullFrameEncoderKeyFrameInterval = keyFrameInterval;
      state.wlvcEncodeFailureCount = 0;
      state.wlvcEncodeWarmupUntilMs = Date.now() + constants.wlvcEncodeWarmupMs;
      state.wlvcEncodeFirstFailureAtMs = 0;
      state.wlvcEncodeLastErrorLogAtMs = 0;
    };

    const ensureFullFrameEncoder = async (frameSize) => {
      const nextWidth = Math.max(1, Math.floor(Number(frameSize?.frameWidth || videoProfile.frameWidth || 0)));
      const nextHeight = Math.max(1, Math.floor(Number(frameSize?.frameHeight || videoProfile.frameHeight || 0)));
      const nextQuality = Math.max(1, Math.floor(Number(videoProfile.frameQuality || constants.sfuWlvcFrameQuality)));
      const nextKeyFrameInterval = Math.max(1, Math.floor(Number(videoProfile.keyFrameInterval || 1)));
      if (
        refs.videoEncoderRef.value
        && fullFrameEncoderWidth === nextWidth
        && fullFrameEncoderHeight === nextHeight
        && fullFrameEncoderQuality === nextQuality
        && fullFrameEncoderKeyFrameInterval === nextKeyFrameInterval
      ) {
        return refs.videoEncoderRef.value;
      }

      destroyFullFrameEncoder();
      resetFullFrameContinuity();
      const nextEncoder = await createHybridEncoder({
        width: nextWidth,
        height: nextHeight,
        quality: nextQuality,
        keyFrameInterval: nextKeyFrameInterval,
      });
      if (stopIfPipelineProfileChanged()) return null;
      refs.videoEncoderRef.value = nextEncoder ? markRaw(nextEncoder) : null;
      if (!refs.videoEncoderRef.value) return null;
      markFullFrameEncoderReady(nextWidth, nextHeight, nextQuality, nextKeyFrameInterval);
      mediaDebugLog(
        '[SFU] Local encoder initialized',
        refs.videoEncoderRef.value?.constructor?.name || 'unknown_encoder',
        `${nextWidth}x${nextHeight}`,
      );
      return refs.videoEncoderRef.value;
    };

    try {
      const nextEncoder = await ensureFullFrameEncoder(initialFrameSize);
      if (stopIfPipelineProfileChanged()) return;
      if (!nextEncoder) {
        mediaDebugLog('[SFU] WLVC encoder unavailable; falling back to native WebRTC path');
        void maybeFallbackToNativeRuntime('wlvc_encoder_unavailable');
        return;
      }
    } catch (error) {
      mediaDebugLog('[SFU] WLVC encoder init error; falling back to native WebRTC path:', error);
      captureClientDiagnosticError('wlvc_encoder_init_failed', error, {
        media_runtime_path: refs.mediaRuntimePathRef.value,
        stage_a: refs.mediaRuntimeCapabilitiesRef.value.stageA,
        stage_b: refs.mediaRuntimeCapabilitiesRef.value.stageB,
      }, {
        code: 'wlvc_encoder_init_failed',
        immediate: true,
      });
      void maybeFallbackToNativeRuntime('wlvc_encoder_init_error');
      return;
    }

    const ensureSelectivePatchEncoder = async (width, height, quality) => {
      const nextWidth = Math.max(1, Math.floor(Number(width || 0)));
      const nextHeight = Math.max(1, Math.floor(Number(height || 0)));
      const nextQuality = Math.max(1, Math.floor(Number(quality || constants.sfuWlvcFrameQuality)));
      if (
        refs.videoPatchEncoderRef.value
        && Number(refs.videoPatchEncoderWidth.value || 0) === nextWidth
        && Number(refs.videoPatchEncoderHeight.value || 0) === nextHeight
        && Number(refs.videoPatchEncoderQuality.value || 0) === nextQuality
      ) {
        return refs.videoPatchEncoderRef.value;
      }
      if (refs.videoPatchEncoderRef.value) {
        try {
          refs.videoPatchEncoderRef.value.destroy?.();
        } catch {
          // ignore stale patch encoder cleanup failures during size switches
        }
        refs.videoPatchEncoderRef.value = null;
      }
      const nextPatchEncoder = await createHybridEncoder({
        width: nextWidth,
        height: nextHeight,
        quality: nextQuality,
        keyFrameInterval: 1,
      });
      refs.videoPatchEncoderRef.value = nextPatchEncoder ? markRaw(nextPatchEncoder) : null;
      refs.videoPatchEncoderWidth.value = nextWidth; refs.videoPatchEncoderHeight.value = nextHeight; refs.videoPatchEncoderQuality.value = nextQuality;
      return refs.videoPatchEncoderRef.value;
    };

    const resolveActiveEncodeIntervalMs = () => resolveWlvcEncodeIntervalMs(
      resolveProfileReadbackIntervalMs(videoProfile),
      {
        profileId: pipelineProfileId,
        trackId: videoTrack.id,
      },
    );

    const scheduleNextWlvcEncodeTick = (delayMs = resolveActiveEncodeIntervalMs()) => {
      if (!refs.videoEncoderRef.value || !isWlvcRuntimePath()) {
        refs.encodeIntervalRef.value = null;
        return;
      }
      refs.encodeIntervalRef.value = setTimeout(runWlvcEncodeTick, Math.max(0, Math.round(delayMs)));
    };

    const currentOpenSfuClient = () => {
      const client = refs.sfuClientRef.value;
      if (!client || !isSfuClientOpen() || typeof client.sendEncodedFrame !== 'function') return null;
      return client;
    };

    const runWlvcEncodeTick = async () => {
      const startedAtMs = highResolutionNowMs();
      try {
        if (!isWlvcRuntimePath()) return;
        if (stopIfPipelineProfileChanged()) return;
        if (state.wlvcEncodeInFlight) return;
        if (!refs.videoEncoderRef.value || !currentOpenSfuClient()) return;
        if (shouldThrottleWlvcEncodeLoop()) return;
        const bufferedAmount = getSfuClientBufferedAmount();
        if (shouldDelayWlvcFrameForBackpressure(bufferedAmount)) {
          handleWlvcEncodeBackpressure(bufferedAmount, videoTrack.id);
          return;
        }
        const timestamp = Date.now();
        const remoteKeyframeRequestPending = timestamp < Number(refs.sfuTransportState.wlvcRemoteKeyframeRequestUntilMs || 0);
        const keyframeRetryDelayMs = Math.max(
          resolveProfileReadbackIntervalMs(videoProfile),
          Number(videoProfile.minKeyframeRetryMs || 0),
        );
        if (forcedKeyframeRecoveryPending && timestamp < keyframeRetryBlockedUntilMs) {
          refs.sfuTransportState.wlvcBackpressurePauseUntilMs = Math.max(
            refs.sfuTransportState.wlvcBackpressurePauseUntilMs,
            keyframeRetryBlockedUntilMs,
          );
          return;
        }
        if (refs.sfuTransportState.wlvcBackpressureSkipCount > 0) {
          resetWlvcBackpressureCounters();
        }
        if (video.readyState < 2) return;

        state.wlvcEncodeInFlight = true;
        const frameSize = resolvePublisherFrameSize(video, videoProfile, videoTrack);
        const trace = createPublisherFrameTrace({
          timestamp,
          startedAtMs,
          pipelineProfileId,
          video,
          videoTrack,
          frameSize,
        });
        markPublisherFrameTraceStage(trace, 'get_user_media_frame_delivery', highResolutionNowMs() - startedAtMs);
        const sourceReadback = await sourceReadbackController.readFrame({
          trace,
          timestamp,
          videoProfile,
          videoTrack,
        });
        if (!sourceReadback) return;
        if (sourceReadback.budgetExceeded) {
          handleWlvcFrameSendFailure(
            getSfuClientBufferedAmount(),
            videoTrack.id,
            'sfu_source_readback_budget_exceeded',
            {
              ...sourceReadback.details,
              bufferedAmount: getSfuClientBufferedAmount(),
            },
          );
          return;
        }
        const {
          imageData,
          frameSize: readbackFrameSize,
          drawImageMs,
          readbackMs,
          drawBudgetMs,
          readbackBudgetMs,
        } = sourceReadback;
        const frameSizeForMetrics = readbackFrameSize || frameSize;
        const fullFrameEncoder = await ensureFullFrameEncoder(frameSizeForMetrics);
        if (!fullFrameEncoder) {
          mediaDebugLog('[SFU] WLVC encoder unavailable during source aspect sizing');
          return;
        }
        let frameImageData = imageData;
        let tilePatchMetadata = null;
        let tilePatchTransportMetrics = null;
        let encoded = null;
        let encodedFrameType = 'keyframe';
        let outgoingCacheEpoch = selectiveTileCacheEpoch;
        const matteMaskImageData = backgroundFilterController.getCurrentMatteMaskSnapshot();
        const encodeStartedAtMs = highResolutionNowMs();
        const canAttemptSelectivePatch = constants.selectiveTileEnabled
          && !forcedKeyframeRecoveryPending
          && !remoteKeyframeRequestPending
          && previousFullFrameImageData instanceof ImageData
          && (timestamp - lastFullFrameSentAtMs) <= constants.selectiveTileBaseRefreshMs
          && refs.sfuTransportState.wlvcBackpressureSkipCount === 0
          && refs.sfuTransportState.wlvcFrameSendFailureCount === 0;

        if (canAttemptSelectivePatch) {
          const selectivePatchPlan = planSelectiveTilePatch(imageData, previousFullFrameImageData, {
            tileWidth: constants.selectiveTileWidth,
            tileHeight: constants.selectiveTileHeight,
            maxChangedTileRatio: constants.selectiveTileMaxChangedRatio,
            maxPatchAreaRatio: constants.selectiveTileMaxPatchAreaRatio,
            sampleStride: constants.selectiveTileSampleStride,
            diffThreshold: constants.selectiveTileDiffThreshold,
            cacheEpoch: selectiveTileCacheEpoch,
            matteMaskImageData,
          });
          if (selectivePatchPlan) {
            const patchEncoder = await ensureSelectivePatchEncoder(
              selectivePatchPlan.patchImageData.width,
              selectivePatchPlan.patchImageData.height,
              videoProfile.frameQuality,
            );
            if (patchEncoder) {
              frameImageData = selectivePatchPlan.patchImageData;
              tilePatchMetadata = selectivePatchPlan.tilePatch;
              tilePatchTransportMetrics = {
                selection_tile_count: selectivePatchPlan.changedTileCount,
                selection_total_tile_count: selectivePatchPlan.totalTileCount,
                selection_tile_ratio: Number(selectivePatchPlan.selectedTileRatio.toFixed(6)),
                selection_mask_guided: selectivePatchPlan.matteGuided,
              };
              encoded = patchEncoder.encodeFrame(frameImageData, timestamp);
              encodedFrameType = 'keyframe';
            }
          }
        }

        if (
          !encoded
          && canAttemptSelectivePatch
          && constants.backgroundSnapshotEnabled
          && (timestamp - lastBackgroundSnapshotSentAtMs) >= constants.backgroundSnapshotMinIntervalMs
        ) {
          const backgroundSnapshotPlan = planBackgroundSnapshotPatch(imageData, previousFullFrameImageData, {
            tileWidth: constants.backgroundSnapshotTileWidth,
            tileHeight: constants.backgroundSnapshotTileHeight,
            minChangedTileRatio: constants.backgroundSnapshotMinChangedRatio,
            maxChangedTileRatio: constants.backgroundSnapshotMaxChangedRatio,
            maxPatchAreaRatio: constants.backgroundSnapshotMaxPatchAreaRatio,
            sampleStride: constants.backgroundSnapshotSampleStride,
            diffThreshold: constants.backgroundSnapshotTileDiffThreshold,
            cacheEpoch: selectiveTileCacheEpoch,
            matteMaskImageData,
          });
          if (backgroundSnapshotPlan) {
            const patchEncoder = await ensureSelectivePatchEncoder(
              backgroundSnapshotPlan.patchImageData.width,
              backgroundSnapshotPlan.patchImageData.height,
              videoProfile.frameQuality,
            );
            if (patchEncoder) {
              frameImageData = backgroundSnapshotPlan.patchImageData;
              tilePatchMetadata = backgroundSnapshotPlan.tilePatch;
              tilePatchTransportMetrics = {
                selection_tile_count: backgroundSnapshotPlan.changedTileCount,
                selection_total_tile_count: backgroundSnapshotPlan.totalTileCount,
                selection_tile_ratio: Number(backgroundSnapshotPlan.selectedTileRatio.toFixed(6)),
                selection_mask_guided: backgroundSnapshotPlan.matteGuided,
              };
              encoded = patchEncoder.encodeFrame(frameImageData, timestamp);
              encodedFrameType = 'keyframe';
            }
          }
        }

        if (!encoded) {
          encoded = fullFrameEncoder.encodeFrame(imageData, timestamp);
          encodedFrameType = sfuFrameTypeFromWlvcData(encoded.data, encoded.type);
          if (encodedFrameType === 'keyframe') {
            outgoingCacheEpoch = selectiveTileCacheEpoch + 1;
          }
        }
        const encodedPayloadBytes = encoded?.data instanceof ArrayBuffer
          ? Number(encoded.data.byteLength || 0)
          : 0;
        const encodeMs = roundedStageMs(highResolutionNowMs() - encodeStartedAtMs);
        markPublisherFrameTraceStage(trace, 'wlvc_encode', encodeMs);
        const maxEncodedFrameBudgetBytes = Math.max(
          1,
          Number(videoProfile.maxEncodedBytesPerFrame || constants.sfuWlvcMaxDeltaFrameBytes || 0)
        );
        const maxEncodedKeyframeBudgetBytes = Math.max(
          1,
          Number(videoProfile.maxKeyframeBytesPerFrame || constants.sfuWlvcMaxKeyframeFrameBytes || 0)
        );
        const maxEncodedPayloadBytes = encodedFrameType === 'delta' || tilePatchMetadata
          ? maxEncodedFrameBudgetBytes
          : maxEncodedKeyframeBudgetBytes;
        const paceForcedKeyframeRecovery = () => {
          forcedKeyframeRecoveryPending = true;
          keyframeRetryBlockedUntilMs = timestamp + keyframeRetryDelayMs;
        };
        if (encodedPayloadBytes > maxEncodedPayloadBytes) {
          paceForcedKeyframeRecovery();
          handleWlvcFramePayloadPressure(encodedPayloadBytes, videoTrack.id, encodedFrameType, {
            layout_mode: tilePatchMetadata?.layoutMode || 'full_frame',
            max_payload_bytes: maxEncodedPayloadBytes,
            keyframe_retry_after_ms: keyframeRetryDelayMs,
          });
          return;
        }
        const encodeBudgetMs = Math.max(1, Number(videoProfile.maxEncodeMs || 0));
        const payloadSoftLimitRatio = Math.max(0.5, Math.min(0.98, Number(videoProfile.payloadSoftLimitRatio || 0.86)));
        const payloadSoftLimitBytes = Math.max(1, Math.floor(maxEncodedPayloadBytes * payloadSoftLimitRatio));
        if (encodedPayloadBytes >= payloadSoftLimitBytes || encodeMs > encodeBudgetMs) {
          paceForcedKeyframeRecovery();
          handleWlvcFramePayloadPressure(encodedPayloadBytes, videoTrack.id, encodedFrameType, {
            reason: 'sfu_wlvc_rate_budget_pressure',
            layout_mode: tilePatchMetadata?.layoutMode || 'full_frame',
            max_payload_bytes: maxEncodedPayloadBytes,
            payload_soft_limit_bytes: payloadSoftLimitBytes,
            payload_soft_limit_ratio: payloadSoftLimitRatio,
            keyframe_retry_after_ms: keyframeRetryDelayMs,
            encode_ms: encodeMs,
            budget_max_encode_ms: encodeBudgetMs,
          });
          return;
        }
        const transportStageMetrics = buildPublisherTransportStageMetrics({
          trace,
          pipelineProfileId,
          videoProfile,
          frameSize: frameSizeForMetrics,
          drawImageMs,
          readbackMs,
          encodeMs,
          encodedPayloadBytes,
          maxEncodedFrameBudgetBytes,
          maxEncodedKeyframeBudgetBytes,
          maxEncodedPayloadBytes,
          encodeBudgetMs,
          drawBudgetMs,
          readbackBudgetMs,
          payloadSoftLimitBytes,
          payloadSoftLimitRatio,
          keyframeRetryDelayMs,
        });

        const outgoingFrame = {
          publisherId: String(refs.currentUserId()),
          publisherUserId: String(refs.currentUserId()),
          trackId: videoTrack.id,
          timestamp: encoded.timestamp,
          transportMetrics: {
            ...transportStageMetrics,
            ...(tilePatchTransportMetrics || {}),
          },
          data: encoded.data,
          type: encodedFrameType,
          codecId: currentSfuCodecId(tilePatchMetadata ? refs.videoPatchEncoderRef.value : refs.videoEncoderRef.value),
          runtimeId: 'wlvc_sfu',
          protectionMode: 'transport_only',
          ...(tilePatchMetadata ? {
            layoutMode: tilePatchMetadata.layoutMode,
            layerId: tilePatchMetadata.layerId,
            cacheEpoch: tilePatchMetadata.cacheEpoch,
            tileColumns: tilePatchMetadata.tileColumns,
            tileRows: tilePatchMetadata.tileRows,
            tileWidth: tilePatchMetadata.tileWidth,
            tileHeight: tilePatchMetadata.tileHeight,
            tileIndices: tilePatchMetadata.tileIndices,
            roiNormX: tilePatchMetadata.roiNormX,
            roiNormY: tilePatchMetadata.roiNormY,
            roiNormWidth: tilePatchMetadata.roiNormWidth,
            roiNormHeight: tilePatchMetadata.roiNormHeight,
          } : {
            layoutMode: 'full_frame',
            layerId: 'full',
            cacheEpoch: outgoingCacheEpoch,
          }),
        };

        if (constants.protectedMediaEnabled && canProtectCurrentSfuTargets()) {
          try {
            const mediaSecurity = ensureMediaSecuritySession();
            const protectStartedAtMs = highResolutionNowMs();
            const protectedFrame = await mediaSecurity.protectFrame({
              data: encoded.data,
              runtimePath: 'wlvc_sfu',
              codecId: outgoingFrame.codecId,
              trackKind: 'video',
              frameKind: encodedFrameType,
              trackId: videoTrack.id,
              timestamp: encoded.timestamp,
            });
            markPublisherFrameTraceStage(trace, 'protected_frame_wrap', highResolutionNowMs() - protectStartedAtMs);
            const securityBudget = measureProtectedSfuFrameBudget({ protectedFrame, plaintextBytes: encodedPayloadBytes, maxPayloadBytes: maxEncodedPayloadBytes });
            outgoingFrame.transportMetrics = { ...outgoingFrame.transportMetrics, ...securityBudget.metrics, ...publisherFrameTraceMetrics(trace) };
            if (!securityBudget.ok) {
              paceForcedKeyframeRecovery();
              handleWlvcFramePayloadPressure(securityBudget.metrics.protected_envelope_bytes, videoTrack.id, encodedFrameType, {
                reason: 'sfu_protected_media_budget_pressure',
                max_payload_bytes: maxEncodedPayloadBytes,
                ...securityBudget.metrics,
              });
              return;
            }
            outgoingFrame.data = new ArrayBuffer(0);
            outgoingFrame.protectedFrame = protectedFrame.protectedFrame;
            outgoingFrame.protectionMode = 'protected';
          } catch (securityError) {
            if (!shouldSendTransportOnlySfuFrame(securityError)) {
              throw securityError;
            }
            mediaDebugLog('[MediaSecurity] protected SFU frame unavailable; sending transport-only frame', securityError);
            hintMediaSecuritySync('protect_frame_unavailable', {
              track_id: videoTrack.id,
              media_runtime_path: refs.mediaRuntimePathRef.value,
            });
            outgoingFrame.transportMetrics = { ...outgoingFrame.transportMetrics, ...publisherFrameTraceMetrics(trace) };
          }
        } else {
          markPublisherFrameTraceStage(trace, 'protected_frame_skipped', 0);
          outgoingFrame.transportMetrics = { ...outgoingFrame.transportMetrics, ...publisherFrameTraceMetrics(trace) };
          hintMediaSecuritySync(
            constants.protectedMediaEnabled
              ? 'peer_handshake_not_ready'
              : 'protected_frames_temporarily_disabled',
            {
              track_id: videoTrack.id,
              media_runtime_path: refs.mediaRuntimePathRef.value,
            }
          );
        }

        if (stopIfPipelineProfileChanged()) return;
        const sendClient = currentOpenSfuClient();
        if (!sendClient) {
          reportSfuClientUnavailableAfterEncode({
            getSfuClientBufferedAmount,
            handleWlvcFrameSendFailure,
            trackId: videoTrack.id,
            trace,
            timestamp,
          });
          return;
        }
        const frameSent = await sendClient.sendEncodedFrame(outgoingFrame);
        if (frameSent === false) {
          paceForcedKeyframeRecovery();
          const sfuSendFailureDetails = sendClient.getLastSendFailure?.() || null;
          handleWlvcFrameSendFailure(
            getSfuClientBufferedAmount(),
            videoTrack.id,
            String(sfuSendFailureDetails?.reason || 'sfu_frame_send_failed'),
            sfuSendFailureDetails,
          );
          return;
        }
        if (getSfuClientBufferedAmount() < constants.sendBufferHighWaterBytes) {
          resetWlvcBackpressureCounters();
        }
        resetWlvcFrameSendFailureCounters();
        state.wlvcEncodeFailureCount = 0;
        state.wlvcEncodeFirstFailureAtMs = 0;
        previousFullFrameImageData = imageData;
        if (!tilePatchMetadata) {
          if (encodedFrameType === 'keyframe') {
            selectiveTileCacheEpoch = outgoingCacheEpoch;
            forcedKeyframeRecoveryPending = false;
            keyframeRetryBlockedUntilMs = 0;
            refs.sfuTransportState.wlvcRemoteKeyframeRequestUntilMs = 0;
          }
          lastFullFrameSentAtMs = timestamp;
        } else if (tilePatchMetadata.layoutMode === 'background_snapshot') {
          lastBackgroundSnapshotSentAtMs = timestamp;
        }
        noteWlvcSourceReadbackSuccess({
          timestamp, trackId: videoTrack.id, sourceBackend, readbackMethod,
          drawImageMs, readbackMs, drawBudgetMs, readbackBudgetMs,
          encodedPayloadBytes,
          maxEncodedPayloadBytes,
          payloadSoftLimitBytes,
          payloadSoftLimitRatio,
          encodeMs,
          frameType: encodedFrameType,
          layoutMode: tilePatchMetadata?.layoutMode || 'full_frame',
          readbackBytes: Math.max(0, Number(sourceReadback.readbackBytes || 0)),
          frameWidth: Math.max(0, Number(frameSizeForMetrics?.frameWidth || 0)),
          frameHeight: Math.max(0, Number(frameSizeForMetrics?.frameHeight || 0)),
        });
      } catch (error) {
        const nowMs = Date.now();
        if (nowMs - state.wlvcEncodeLastErrorLogAtMs >= constants.wlvcEncodeErrorLogCooldownMs) {
          state.wlvcEncodeLastErrorLogAtMs = nowMs;
          mediaDebugLog('[SFU] WASM encode frame failed', error);
          captureClientDiagnosticError('wlvc_encode_frame_failed', error, {
            failure_count: state.wlvcEncodeFailureCount,
            media_runtime_path: refs.mediaRuntimePathRef.value,
            track_id: videoTrack.id,
          }, {
            code: 'wlvc_encode_frame_failed',
          });
        }

        if (nowMs < state.wlvcEncodeWarmupUntilMs) {
          return;
        }

        if (
          state.wlvcEncodeFirstFailureAtMs === 0
          || nowMs - state.wlvcEncodeFirstFailureAtMs > constants.wlvcEncodeFailureWindowMs
        ) {
          state.wlvcEncodeFirstFailureAtMs = nowMs;
          state.wlvcEncodeFailureCount = 1;
          return;
        }

        state.wlvcEncodeFailureCount += 1;
        if (state.wlvcEncodeFailureCount >= constants.wlvcEncodeFailureThreshold) {
          if (handleWlvcRuntimeEncodeError({
            encodeFailureCount: state.wlvcEncodeFailureCount,
            reason: 'wlvc_encode_runtime_error',
            trackId: videoTrack.id,
            mediaRuntimePath: refs.mediaRuntimePathRef.value,
          })) {
            state.wlvcEncodeFailureCount = 0;
            state.wlvcEncodeFirstFailureAtMs = 0;
            return;
          }
          state.wlvcEncodeFailureCount = 0;
          state.wlvcEncodeFirstFailureAtMs = 0;
          void maybeFallbackToNativeRuntime('wlvc_encode_runtime_error');
        }
      } finally {
        state.wlvcEncodeInFlight = false;
        if (refs.encodeIntervalRef.value !== null) {
          const finishedAtMs = highResolutionNowMs();
          const elapsedMs = Math.max(0, finishedAtMs - startedAtMs);
          scheduleNextWlvcEncodeTick(resolveActiveEncodeIntervalMs() - elapsedMs);
        }
      }
    };

    scheduleNextWlvcEncodeTick(0);
  }

  return {
    bindLocalTrackLifecycle,
    clearLocalPreviewElement,
    clearLocalTrackRecoveryTimer,
    hasLiveLocalMedia,
    scheduleLocalTrackRecovery,
    startEncodingPipeline,
    stopLocalEncodingPipeline,
    stopRetiredLocalStreams,
    teardownLocalPublisher,
    uniqueLocalStreams,
    unpublishAndStopLocalTracks,
    unpublishSfuTracks,
  };
}
