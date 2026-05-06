import { createWorkerSegmenterBackend } from './backendWorkerSegmenter';
import { createMediaPipeSegmentationBackend } from './backendMediapipe';
import { toNumber } from './math';
import { createBackgroundPipelineController } from './pipeline/controller';
import { createBackgroundCompositorStage } from './pipeline/compositorStage';
import { shouldUseReactiveBackgroundPipeline } from './pipeline/featureFlags';
import { BACKGROUND_PIPELINE_MESSAGE_TYPES } from './pipeline/messages';
import { createVideoFrameScheduler } from './pipeline/scheduler';
import { createBackgroundSegmenterStage } from './pipeline/segmenterStage';
import { BACKGROUND_PIPELINE_STAGE_NAMES, BACKGROUND_PIPELINE_STAGE_STATES } from './pipeline/stages';

const LONG_RAF_FRAME_MS = 300;
const BACKGROUND_FILTER_READY_TIMEOUT_MS = 500;
function resolveProcessingSpec(sourceWidth, sourceHeight, sourceFps, maxProcessWidth, maxProcessFps) {
  const inW = Math.max(1, Math.round(toNumber(sourceWidth, 1280)));
  const inH = Math.max(1, Math.round(toNumber(sourceHeight, 720)));
  const inFps = Math.max(8, Math.min(30, Math.round(toNumber(sourceFps, 24))));
  const capW = Math.max(320, Math.round(toNumber(maxProcessWidth, 960)));
  const capFps = Math.max(8, Math.min(30, Math.round(toNumber(maxProcessFps, 24))));
  const ratio = Math.min(1, capW / inW);
  return {
    width: Math.max(1, Math.round(inW * ratio)),
    height: Math.max(1, Math.round(inH * ratio)),
    fps: Math.max(8, Math.min(capFps, inFps))
  };
}
function normalizeBackgroundFilterRuntimeConfig(options = {}) {
  const requestedMode = String(options.mode || 'off').trim().toLowerCase();
  const mode = requestedMode === 'replace'
    ? 'replace'
    : requestedMode === 'blur'
      ? 'blur'
      : 'off';
  return {
    autoDisableOnOverload: options.autoDisableOnOverload === true,
    backgroundColor: String(options.backgroundColor ?? '').trim(),
    backgroundImageUrl: String(options.backgroundImageUrl ?? '').trim(),
    blurPx: Math.max(1, Math.min(28, Math.round(toNumber(options.blurPx, 3)))),
    detectIntervalMs: Math.max(1, Math.min(1200, Math.round(toNumber(options.detectIntervalMs, 1)))),
    mode,
    overloadConsecutiveFrames: Math.max(3, Math.min(60, Math.round(toNumber(options.overloadConsecutiveFrames, 12)))),
    overloadFrameMs: Math.max(40, Math.min(400, toNumber(options.overloadFrameMs, 90))),
    sourceActive: options.sourceActive !== false,
    statsIntervalMs: Math.max(500, Math.min(5e3, Math.round(toNumber(options.statsIntervalMs, 1e3)))),
  };
}
async function waitForVideoReady(video) {
  if (video.readyState >= 2) return;
  await new Promise((resolve) => {
    const onReady = () => {
      video.removeEventListener("loadedmetadata", onReady);
      video.removeEventListener("canplay", onReady);
      resolve();
    };
    video.addEventListener("loadedmetadata", onReady);
    video.addEventListener("canplay", onReady);
    setTimeout(onReady, 500);
  });
}
async function createBackgroundFilterStreamLegacy(sourceStream, options = {}) {
  const videoTrack = sourceStream.getVideoTracks()[0] ?? null;
  if (!videoTrack) {
    return {
      stream: sourceStream, active: false, reason: "no_video_track", backend: "none", dispose: () => {
      }
    };
  }
  if (typeof document === "undefined") {
    return {
      stream: sourceStream, active: false, reason: "unsupported", backend: "none", dispose: () => {
      }
    };
  }
  const pipelineController = options.pipelineController || null;
  const runtimeConfig = normalizeBackgroundFilterRuntimeConfig(options);
  const settings = videoTrack.getSettings?.() ?? {};
  const sourceWidth = Math.max(1, Math.round(toNumber(settings.width, 1280)));
  const sourceHeight = Math.max(1, Math.round(toNumber(settings.height, 720)));
  const sourceFps = Math.max(8, Math.min(30, Math.round(toNumber(settings.frameRate, 24))));
  const processing = resolveProcessingSpec(
    sourceWidth,
    sourceHeight,
    sourceFps,
    toNumber(options.maxProcessWidth, 960),
    toNumber(options.maxProcessFps, 24)
  );
  const width = processing.width;
  const height = processing.height;
  const fps = processing.fps;
  const onStats = typeof options.onStats === "function" ? options.onStats : null;
  const onOverload = typeof options.onOverload === "function" ? options.onOverload : null;
  let disposed = false;
  const video = document.createElement("video");
  video.autoplay = true;
  video.playsInline = true;
  video.muted = true;
  video.srcObject = new MediaStream([videoTrack]);
  try {
    await waitForVideoReady(video);
    await video.play().catch(() => void 0);
  } catch {
    return {
      stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
      }
    };
  }
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, width);
  canvas.height = Math.max(1, height);
  const ctx = canvas.getContext("2d", { alpha: true, desynchronized: true });
  if (!ctx) {
    video.pause();
    video.srcObject = null;
    return {
      stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
      }
    };
  }
  const captureStream = canvas.captureStream;
  if (typeof captureStream !== "function") {
    video.pause();
    video.srcObject = null;
    return {
      stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
      }
    };
  }
  const filteredVideoStream = captureStream.call(canvas, fps);
  const out = new MediaStream();
  const filteredTrack = filteredVideoStream.getVideoTracks()[0] ?? null;
  if (filteredTrack) out.addTrack(filteredTrack);
  for (const audioTrack of sourceStream.getAudioTracks()) out.addTrack(audioTrack);
  let readyResolved = false;
  let frameCount = 0;
  let detectCount = 0;
  let detectDurationSum = 0;
  let processDurationSum = 0;
  let statsWindowStartAt = performance.now();
  const targetFps = Math.max(8, Math.min(30, Math.round(fps)));
  let segmentationBackend = null;
  let segmentationBackendKind = 'none';
  let resolveReady = () => { };
  let sourceStopReason = '';
  const markReady = () => {
    if (readyResolved) return;
    readyResolved = true;
    resolveReady();
  };
  const ready = new Promise((resolve) => {
    resolveReady = resolve;
  });
  const readyTimer = setTimeout(
    markReady,
    Math.max(BACKGROUND_FILTER_READY_TIMEOUT_MS, runtimeConfig.detectIntervalMs + 100),
  );
  console.log('[BackgroundFilter] Stream setup complete, initializing segmentation backend');
  console.log('[BackgroundFilter] timer set to mark ready in', Math.max(BACKGROUND_FILTER_READY_TIMEOUT_MS, runtimeConfig.detectIntervalMs + 100), 'ms');
  const segmenterStage = createBackgroundSegmenterStage({
    width: canvas.width,
    height: canvas.height,
  });
  const compositorStage = createBackgroundCompositorStage({
    canvas,
    ctx,
    getBackgroundColor: () => runtimeConfig.backgroundColor,
    getBackgroundImageUrl: () => runtimeConfig.backgroundImageUrl,
    getBlurPx: () => runtimeConfig.blurPx,
    video,
  });

  async function ensureSegmentationBackend() {
    if (segmentationBackend || runtimeConfig.mode === 'off' || !runtimeConfig.sourceActive) return segmentationBackend;
    const initFailures = [];
    console.log('[BackgroundFilter] Initializing segmentation backend', {
      backend: 'worker-segmenter',
      fallback: 'mediapipe',
    });
    console.log('[BackgroundFilter] Attempting to initialize worker segmenter backend');
    try {
      segmentationBackend = await createWorkerSegmenterBackend({
        detectIntervalMs: runtimeConfig.detectIntervalMs,
      });
    } catch (error) {
      initFailures.push(`worker-segmenter: ${error?.message || 'init_failed'}`);
      segmentationBackend = null;
    }

    if (!segmentationBackend) {
      console.log('[BackgroundFilter] Falling back to MediaPipe selfie segmentation backend');
      try {
        segmentationBackend = await createMediaPipeSegmentationBackend({
          detectIntervalMs: runtimeConfig.detectIntervalMs,
        });
      } catch (error) {
        initFailures.push(`mediapipe: ${error?.message || 'init_failed'}`);
        segmentationBackend = null;
      }
    }

    segmentationBackendKind = segmentationBackend?.kind || 'none';
    console.log('[BackgroundFilter] Segmentation backend initialization result', {
      selected: segmentationBackendKind,
      requested: 'worker-segmenter',
      fallback: 'mediapipe',
      failures: initFailures,
    });
    if (segmentationBackendKind === 'none' && initFailures.length > 0) {
      console.warn('[BackgroundFilter] Segmentation backend failed to initialize', {
        selected: segmentationBackendKind,
        requested: 'worker-segmenter',
        fallback: 'mediapipe',
        failures: initFailures,
      });
    }
    return segmentationBackend;
  }

  function syncPipelineStageStates() {
    if (!pipelineController) return;
    pipelineController.updateStage(
      BACKGROUND_PIPELINE_STAGE_NAMES.SOURCE,
      runtimeConfig.sourceActive ? BACKGROUND_PIPELINE_STAGE_STATES.RUNNING : BACKGROUND_PIPELINE_STAGE_STATES.PAUSED,
      { reason: sourceStopReason || (runtimeConfig.sourceActive ? 'active' : 'inactive') },
    );
    pipelineController.updateStage(
      BACKGROUND_PIPELINE_STAGE_NAMES.SEGMENTER,
      runtimeConfig.sourceActive && runtimeConfig.mode !== 'off'
        ? BACKGROUND_PIPELINE_STAGE_STATES.RUNNING
        : BACKGROUND_PIPELINE_STAGE_STATES.IDLE,
      { mode: runtimeConfig.mode },
    );
    pipelineController.updateStage(
      BACKGROUND_PIPELINE_STAGE_NAMES.COMPOSITOR,
      runtimeConfig.sourceActive ? BACKGROUND_PIPELINE_STAGE_STATES.RUNNING : BACKGROUND_PIPELINE_STAGE_STATES.PAUSED,
      { mode: runtimeConfig.mode },
    );
  }

  function applyConfigUpdate(nextOptions = {}) {
    const nextConfig = normalizeBackgroundFilterRuntimeConfig(nextOptions);
    if (!Object.prototype.hasOwnProperty.call(nextOptions, 'sourceActive')) {
      nextConfig.sourceActive = runtimeConfig.sourceActive;
    }
    Object.assign(runtimeConfig, nextConfig);
    if (pipelineController) {
      pipelineController.emit(BACKGROUND_PIPELINE_MESSAGE_TYPES.CONFIG_UPDATE, {
        mode: runtimeConfig.mode,
        sourceActive: runtimeConfig.sourceActive,
        backgroundImageUrl: runtimeConfig.backgroundImageUrl !== '',
      });
    }
    if (runtimeConfig.mode === 'off') {
      segmenterStage.reset();
    }
    syncPipelineStageStates();
  }

  function setSourceActive(active, reason = '') {
    runtimeConfig.sourceActive = active !== false;
    sourceStopReason = String(reason || '').trim();
    if (!runtimeConfig.sourceActive) {
      segmenterStage.reset();
      compositorStage.reset();
    }
    if (pipelineController) {
      pipelineController.emit(
        runtimeConfig.sourceActive
          ? BACKGROUND_PIPELINE_MESSAGE_TYPES.SOURCE_STARTED
          : BACKGROUND_PIPELINE_MESSAGE_TYPES.SOURCE_STOPPED,
        { reason: sourceStopReason || (runtimeConfig.sourceActive ? 'source_active' : 'source_inactive') },
      );
    }
    syncPipelineStageStates();
  }

  const draw = (frameStartedAt = performance.now()) => {
    if (disposed) return;
    if (!runtimeConfig.sourceActive) return;
    const vw = video.videoWidth || canvas.width;
    const vh = video.videoHeight || canvas.height;
    if (vw <= 1 || vh <= 1) {
      return;
    }
    const now = performance.now();
    const effectEnabled = runtimeConfig.mode !== 'off';
    //const canRunSegmentation = effectEnabled && now >= overloadCooldownUntil && Boolean(segmentationBackend);
    const canRunSegmentation = effectEnabled && Boolean(segmentationBackend);
    const segmentationWidth = Math.max(1, Math.round(Math.min(vw, canvas.width)));
    const segmentationHeight = Math.max(1, Math.round(Math.min(vh, canvas.height)));

    const segmentation = canRunSegmentation
      ? segmentationBackend.nextFaces(video, segmentationWidth, segmentationHeight, now)
      : { detectSampleMs: null, matteMaskBitmap: null, matteMaskValues: null };
    if (typeof segmentation.detectSampleMs === "number" && Number.isFinite(segmentation.detectSampleMs)) {
      detectCount += 1;
      detectDurationSum += Math.max(0, segmentation.detectSampleMs);
    }
    const underLoad = false;
    const segmenterState = effectEnabled
      ? segmenterStage.update(segmentation, {
        underLoad,
      })
      : (segmenterStage.reset(), segmenterStage.getState());
    compositorStage.render({
      hasMatteMask: segmenterState.hasMatteMask,
      maskBitmap: segmenterState.maskBitmap,
      maskHeight: segmenterState.maskHeight,
      maskUpdated: segmenterState.maskUpdated,
      maskValues: segmenterState.maskValues,
      maskWidth: segmenterState.maskWidth,
      mode: runtimeConfig.mode,
      now,
      sourceFrame: segmenterState.sourceFrame,
    });
    markReady();
    frameCount += 1;
    const frameProcessMs = Math.max(0, performance.now() - frameStartedAt);
    processDurationSum += frameProcessMs;
    if (runtimeConfig.autoDisableOnOverload && frameProcessMs >= runtimeConfig.overloadFrameMs && onOverload) {
      try {
        onOverload({
          avgProcessMs: frameProcessMs,
          targetFps,
          thresholdMs: runtimeConfig.overloadFrameMs
        });
      } catch {
      }
    }
    if (onStats) {
      const elapsedMs = now - statsWindowStartAt;
      if (elapsedMs >= runtimeConfig.statsIntervalMs) {
        const elapsedSec = Math.max(1e-3, elapsedMs / 1e3);
        const avgProcessMs = frameCount > 0 ? processDurationSum / frameCount : 0;
        const processLoad = Math.max(0, Math.min(1, avgProcessMs * (frameCount / elapsedSec) / 1e3));
        const stats = {
          fps: frameCount / elapsedSec,
          detectFps: detectCount / elapsedSec,
          avgDetectMs: detectCount > 0 ? detectDurationSum / detectCount : 0,
          avgProcessMs,
          processLoad,
          width: canvas.width,
          height: canvas.height,
          targetFps,
          sourceWidth,
          sourceHeight,
          sourceFps
        };
        try {
          onStats(stats);
        } catch {
        }
        frameCount = 0;
        detectCount = 0;
        detectDurationSum = 0;
        processDurationSum = 0;
        statsWindowStartAt = now;
      }
    }
  };
  const scheduler = createVideoFrameScheduler({ onFrame: draw, video });
  applyConfigUpdate(options);
  await ensureSegmentationBackend();
  setSourceActive(runtimeConfig.sourceActive, 'initial');
  scheduler.start();
  const onTrackEnded = () => setSourceActive(false, 'track_ended');
  const onTrackMute = () => setSourceActive(false, 'track_muted');
  const onTrackUnmute = () => {
    setSourceActive(true, 'track_unmuted');
    void ensureSegmentationBackend();
  };
  videoTrack.addEventListener?.('ended', onTrackEnded);
  videoTrack.addEventListener?.('mute', onTrackMute);
  videoTrack.addEventListener?.('unmute', onTrackUnmute);
  const dispose = () => {
    if (disposed) return;
    disposed = true;
    scheduler.stop();
    clearTimeout(readyTimer);
    markReady();
    videoTrack.removeEventListener?.('ended', onTrackEnded);
    videoTrack.removeEventListener?.('mute', onTrackMute);
    videoTrack.removeEventListener?.('unmute', onTrackUnmute);
    try {
      video.pause();
      video.srcObject = null;
    } catch {
    }
    for (const track of filteredVideoStream.getTracks()) {
      try {
        track.stop();
      } catch {
      }
    }
    segmentationBackend?.dispose?.();
    compositorStage.reset();
    segmenterStage.reset();
    pipelineController?.emit(BACKGROUND_PIPELINE_MESSAGE_TYPES.PIPELINE_STOP, {});
  };
  const handle = {
    sourceStream,
    stream: out,
    active: runtimeConfig.mode !== 'off',
    reason: runtimeConfig.mode === 'off' ? 'off' : 'ok',
    backend: segmentationBackendKind,
    mode: runtimeConfig.mode,
    sourceActive: runtimeConfig.sourceActive,
    ready,
    getMatteMaskSnapshot: () => compositorStage.getMatteMaskSnapshot(),
    async updateConfig(nextOptions = {}) {
      applyConfigUpdate(nextOptions);
      if (runtimeConfig.mode !== 'off') {
        await ensureSegmentationBackend();
      }
      handle.active = runtimeConfig.mode !== 'off';
      handle.reason = runtimeConfig.mode === 'off' ? 'off' : 'ok';
      handle.backend = segmentationBackendKind;
      handle.mode = runtimeConfig.mode;
      handle.sourceActive = runtimeConfig.sourceActive;
      return handle;
    },
    setSourceActive,
    dispose
  };
  return handle;
}

async function createBackgroundFilterStream(sourceStream, options = {}) {
  const pipelineController = createBackgroundPipelineController({
    sourceId: String(options.sourceId || 'local-video').trim() || 'local-video',
  });
  pipelineController.emit(BACKGROUND_PIPELINE_MESSAGE_TYPES.PIPELINE_START, {
    reactive: shouldUseReactiveBackgroundPipeline(),
  });
  pipelineController.updateStage(
    BACKGROUND_PIPELINE_STAGE_NAMES.SOURCE,
    BACKGROUND_PIPELINE_STAGE_STATES.RUNNING,
  );

  const result = await createBackgroundFilterStreamLegacy(sourceStream, {
    ...options,
    pipelineController,
  });

  return {
    ...result,
    pipeline: {
      controller: pipelineController,
      mode: shouldUseReactiveBackgroundPipeline() ? 'reactive' : 'legacy_fallback',
      reactive: shouldUseReactiveBackgroundPipeline(),
    },
  };
}

export {
  createBackgroundFilterStream,
  resolveProcessingSpec
};
