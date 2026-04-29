import { markRaw } from 'vue';
import { createHybridEncoder } from '../../../lib/wasm/wasm-codec';
import { planBackgroundSnapshotPatch, planSelectiveTilePatch } from '../../../lib/sfu/selectiveTileTransport';
import { sfuFrameTypeFromWlvcData } from '../sfu/wlvcFrameMetadata';

export function createLocalPublisherPipelineHelpers({
  backgroundBaselineCollector,
  backgroundFilterController,
  callbacks,
  captureClientDiagnosticError,
  constants,
  refs,
  state,
}) {
  const {
    applyCallOutputPreferences,
    canProtectCurrentSfuTargets,
    currentSfuVideoProfile,
    ensureMediaSecuritySession,
    getSfuClientBufferedAmount,
    handleWlvcEncodeBackpressure,
    handleWlvcFrameSendFailure,
    handleWlvcFramePayloadPressure,
    hintMediaSecuritySync,
    isSfuClientOpen,
    isWlvcRuntimePath,
    maybeFallbackToNativeRuntime,
    mediaDebugLog,
    reconfigureLocalTracksFromSelectedDevices,
    renderCallVideoLayout,
    resetBackgroundRuntimeMetrics,
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    shouldDelayWlvcFrameForBackpressure,
    shouldSendTransportOnlySfuFrame,
    shouldThrottleWlvcEncodeLoop,
    stopActivityMonitor,
    stopSfuTrackAnnounceTimer,
  } = callbacks;

  function currentSfuCodecId(encoder) {
    const constructorName = String(encoder?.constructor?.name || '').trim();
    if (constructorName === 'WasmWaveletVideoEncoder') return 'wlvc_wasm';
    if (constructorName === 'WaveletVideoEncoder') return 'wlvc_ts';
    return 'wlvc_unknown';
  }

  function highResolutionNowMs() {
    return typeof performance !== 'undefined' && typeof performance.now === 'function'
      ? performance.now()
      : Date.now();
  }

  function roundedStageMs(value) {
    const normalized = Number(value || 0);
    return Number.isFinite(normalized) ? Number(Math.max(0, normalized).toFixed(3)) : 0;
  }

  function uniqueLocalStreams(values) {
    const out = [];
    const seen = new Set();
    for (const value of values) {
      if (!(value instanceof MediaStream)) continue;
      if (seen.has(value)) continue;
      seen.add(value);
      out.push(value);
    }
    return out;
  }

  function unpublishSfuTracks(tracks) {
    if (!refs.sfuClientRef.value || !Array.isArray(tracks)) return;
    for (const track of tracks) {
      if (!track?.id) continue;
      try {
        refs.sfuClientRef.value.unpublishTrack(track.id);
      } catch {
        // best-effort cleanup for stale tracks
      }
    }
  }

  function stopRetiredLocalStreams(retiredStreams, preservedStreams = []) {
    const preserved = new Set();
    const preservedTrackIds = new Set();
    for (const stream of preservedStreams) {
      if (stream instanceof MediaStream) {
        preserved.add(stream);
        for (const track of stream.getTracks()) {
          if (track?.id) preservedTrackIds.add(track.id);
        }
      }
    }

    for (const stream of uniqueLocalStreams(retiredStreams)) {
      if (!(stream instanceof MediaStream)) continue;
      if (preserved.has(stream)) continue;
      for (const track of stream.getTracks()) {
        if (track?.id && preservedTrackIds.has(track.id)) continue;
        try {
          track.stop();
        } catch {
          // ignore stop failures during stream turnover
        }
      }
    }
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

    try {
      const nextEncoder = await createHybridEncoder({
        width: videoProfile.frameWidth,
        height: videoProfile.frameHeight,
        quality: videoProfile.frameQuality,
        keyFrameInterval: videoProfile.keyFrameInterval,
      });
      refs.videoEncoderRef.value = nextEncoder ? markRaw(nextEncoder) : null;
      if (!refs.videoEncoderRef.value) {
        mediaDebugLog('[SFU] WLVC encoder unavailable; falling back to native WebRTC path');
        void maybeFallbackToNativeRuntime('wlvc_encoder_unavailable');
        return;
      }
      mediaDebugLog('[SFU] Local encoder initialized', refs.videoEncoderRef.value?.constructor?.name || 'unknown_encoder');
      state.wlvcEncodeFailureCount = 0;
      state.wlvcEncodeWarmupUntilMs = Date.now() + constants.wlvcEncodeWarmupMs;
      state.wlvcEncodeFirstFailureAtMs = 0;
      state.wlvcEncodeLastErrorLogAtMs = 0;
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

    const canvas = document.createElement('canvas');
    canvas.width = videoProfile.frameWidth;
    canvas.height = videoProfile.frameHeight;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    let previousFullFrameImageData = null;
    let lastFullFrameSentAtMs = 0;
    let lastBackgroundSnapshotSentAtMs = 0;
    let selectiveTileCacheEpoch = 0;
    let forcedKeyframeRecoveryPending = false;
    let keyframeRetryBlockedUntilMs = 0;

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
      refs.videoPatchEncoderWidth.value = nextWidth;
      refs.videoPatchEncoderHeight.value = nextHeight;
      refs.videoPatchEncoderQuality.value = nextQuality;
      return refs.videoPatchEncoderRef.value;
    };

    const scheduleNextWlvcEncodeTick = (delayMs = videoProfile.encodeIntervalMs) => {
      if (!refs.videoEncoderRef.value || !isWlvcRuntimePath()) {
        refs.encodeIntervalRef.value = null;
        return;
      }
      refs.encodeIntervalRef.value = setTimeout(runWlvcEncodeTick, Math.max(0, Math.round(delayMs)));
    };

    const runWlvcEncodeTick = async () => {
      const startedAtMs = highResolutionNowMs();
      try {
        if (!isWlvcRuntimePath()) return;
        if (state.wlvcEncodeInFlight) return;
        if (!refs.videoEncoderRef.value || !refs.sfuClientRef.value || !isSfuClientOpen()) return;
        if (shouldThrottleWlvcEncodeLoop()) return;
        const bufferedAmount = getSfuClientBufferedAmount();
        if (shouldDelayWlvcFrameForBackpressure(bufferedAmount)) {
          handleWlvcEncodeBackpressure(bufferedAmount, videoTrack.id);
          return;
        }
        const timestamp = Date.now();
        const keyframeRetryDelayMs = Math.max(
          Number(videoProfile.encodeIntervalMs || 0),
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
        if (video.readyState < 2 || !ctx) return;

        state.wlvcEncodeInFlight = true;
        const drawStartedAtMs = highResolutionNowMs();
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const drawImageMs = roundedStageMs(highResolutionNowMs() - drawStartedAtMs);
        const readbackStartedAtMs = highResolutionNowMs();
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const readbackMs = roundedStageMs(highResolutionNowMs() - readbackStartedAtMs);
        const drawBudgetMs = Math.max(1, Number(videoProfile.maxDrawImageMs || 0));
        const readbackBudgetMs = Math.max(1, Number(videoProfile.maxReadbackMs || 0));
        if (drawImageMs > drawBudgetMs || readbackMs > readbackBudgetMs) {
          const readbackReason = drawImageMs > drawBudgetMs
            ? 'canvas_draw_image_budget_exceeded'
            : 'canvas_get_image_data_budget_exceeded';
          handleWlvcFrameSendFailure(
            getSfuClientBufferedAmount(),
            videoTrack.id,
            'sfu_source_readback_budget_exceeded',
            {
              reason: 'sfu_source_readback_budget_exceeded',
              stage: 'dom_canvas_readback',
              source: readbackReason,
              message: 'Publisher source readback exceeded the active SFU profile budget before WLVC encode.',
              transportPath: 'publisher_source_readback',
              bufferedAmount: getSfuClientBufferedAmount(),
              payloadBytes: 0,
              wirePayloadBytes: 0,
              timestamp,
            },
          );
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
          encoded = refs.videoEncoderRef.value.encodeFrame(imageData, timestamp);
          encodedFrameType = sfuFrameTypeFromWlvcData(encoded.data, encoded.type);
          if (encodedFrameType === 'keyframe') {
            outgoingCacheEpoch = selectiveTileCacheEpoch + 1;
          }
        }
        const encodedPayloadBytes = encoded?.data instanceof ArrayBuffer
          ? Number(encoded.data.byteLength || 0)
          : 0;
        const encodeMs = roundedStageMs(highResolutionNowMs() - encodeStartedAtMs);
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
        const profileId = String(videoProfile.id || '').trim() || 'balanced';
        const transportStageMetrics = {
          outgoing_video_quality_profile: profileId,
          capture_width: videoProfile.captureWidth,
          capture_height: videoProfile.captureHeight,
          capture_frame_rate: videoProfile.captureFrameRate,
          frame_width: videoProfile.frameWidth,
          frame_height: videoProfile.frameHeight,
          draw_image_ms: drawImageMs,
          readback_ms: readbackMs,
          encode_ms: encodeMs,
          local_stage_elapsed_ms: roundedStageMs(highResolutionNowMs() - startedAtMs),
          encoded_payload_bytes: encodedPayloadBytes,
          max_payload_bytes: maxEncodedPayloadBytes,
          budget_max_encoded_bytes_per_frame: maxEncodedFrameBudgetBytes,
          budget_max_keyframe_bytes_per_frame: maxEncodedKeyframeBudgetBytes,
          budget_max_wire_bytes_per_second: Math.max(1, Number(videoProfile.maxWireBytesPerSecond || 0)),
          budget_max_encode_ms: encodeBudgetMs,
          budget_max_draw_image_ms: drawBudgetMs,
          budget_max_readback_ms: readbackBudgetMs,
          budget_payload_soft_limit_bytes: payloadSoftLimitBytes,
          budget_payload_soft_limit_ratio: payloadSoftLimitRatio,
          budget_min_keyframe_retry_ms: keyframeRetryDelayMs,
          budget_max_queue_age_ms: Math.max(1, Number(videoProfile.maxQueueAgeMs || 0)),
          budget_max_buffered_bytes: Math.max(1, Number(videoProfile.maxBufferedBytes || 0)),
          budget_expected_recovery: String(videoProfile.expectedRecovery || ''),
        };

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
            const protectedFrame = await mediaSecurity.protectFrame({
              data: encoded.data,
              runtimePath: 'wlvc_sfu',
              codecId: outgoingFrame.codecId,
              trackKind: 'video',
              frameKind: encodedFrameType,
              trackId: videoTrack.id,
              timestamp: encoded.timestamp,
            });
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
          }
        } else {
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

        const frameSent = await refs.sfuClientRef.value.sendEncodedFrame(outgoingFrame);
        if (frameSent === false) {
          paceForcedKeyframeRecovery();
          const sfuSendFailureDetails = refs.sfuClientRef.value?.getLastSendFailure?.() || null;
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
          }
          lastFullFrameSentAtMs = timestamp;
        } else if (tilePatchMetadata.layoutMode === 'background_snapshot') {
          lastBackgroundSnapshotSentAtMs = timestamp;
        }
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
          if (refs.downgradeSfuVideoQualityAfterEncodePressure('wlvc_encode_runtime_error')) {
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
          const finishedAtMs = typeof performance !== 'undefined' && typeof performance.now === 'function'
            ? performance.now()
            : Date.now();
          const elapsedMs = Math.max(0, finishedAtMs - startedAtMs);
          scheduleNextWlvcEncodeTick(videoProfile.encodeIntervalMs - elapsedMs);
        }
      }
    };

    scheduleNextWlvcEncodeTick(0);
  }

  return {
    bindLocalTrackLifecycle,
    clearLocalPreviewElement,
    clearLocalTrackRecoveryTimer,
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
