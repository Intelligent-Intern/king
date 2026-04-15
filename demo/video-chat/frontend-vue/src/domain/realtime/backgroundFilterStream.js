import { selectBackgroundFilterBackend } from './backgroundFilterBackendSelector';
import { createBackgroundSegmentationBackend } from './backgroundFilterBackend';
import { createMediaPipeSegmentationBackend } from './backgroundFilterBackendMediapipe';
import { createTfjsSegmentationBackend } from './backgroundFilterBackendTfjs';

function toNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function resolveProcessingSpec(sourceWidth, sourceHeight, sourceFps, maxProcessWidth, maxProcessFps) {
  const safeSourceWidth = Math.max(1, Math.round(toNumber(sourceWidth, 640)));
  const safeSourceHeight = Math.max(1, Math.round(toNumber(sourceHeight, 480)));
  const safeSourceFps = Math.max(8, Math.min(30, Math.round(toNumber(sourceFps, 24))));
  const widthLimit = Math.max(320, Math.min(1920, Math.round(toNumber(maxProcessWidth, 960))));
  const fpsLimit = Math.max(8, Math.min(30, Math.round(toNumber(maxProcessFps, 24))));

  const targetWidthRaw = Math.min(safeSourceWidth, widthLimit);
  const scale = targetWidthRaw / safeSourceWidth;
  const targetHeightRaw = Math.max(2, Math.round(safeSourceHeight * scale));

  // Keep processing dimensions even to avoid odd-pixel artifacts in some browsers.
  const width = Math.max(2, Math.round(targetWidthRaw / 2) * 2);
  const height = Math.max(2, Math.round(targetHeightRaw / 2) * 2);
  const fps = Math.max(8, Math.min(30, Math.round(Math.min(safeSourceFps, fpsLimit))));

  return { width, height, fps };
}

function uniqueMediaStreams(values) {
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

function stopStreamTracks(stream) {
  if (!(stream instanceof MediaStream)) return;
  for (const track of stream.getTracks()) {
    try {
      track.stop();
    } catch {
      // ignore
    }
  }
}

async function waitForVideoReady(video) {
  if (!(video instanceof HTMLVideoElement)) return false;
  if (video.readyState >= 2) return true;
  return await new Promise((resolve) => {
    let done = false;
    const finish = (value) => {
      if (done) return;
      done = true;
      cleanup();
      resolve(Boolean(value));
    };
    const cleanup = () => {
      video.removeEventListener('loadeddata', handleLoadedData);
      video.removeEventListener('canplay', handleCanPlay);
      video.removeEventListener('error', handleError);
    };
    const handleLoadedData = () => finish(true);
    const handleCanPlay = () => finish(true);
    const handleError = () => finish(false);
    video.addEventListener('loadeddata', handleLoadedData);
    video.addEventListener('canplay', handleCanPlay);
    video.addEventListener('error', handleError);
    setTimeout(() => finish(video.readyState >= 2), 2500);
  });
}

function resolveFps(track) {
  const settings = typeof track?.getSettings === 'function' ? track.getSettings() : null;
  return Math.max(8, Math.min(30, Math.round(toNumber(settings?.frameRate, 15))));
}

function scaleFaceBox(face, srcW, srcH, dstW, dstH) {
  const sx = dstW / Math.max(1, srcW);
  const sy = dstH / Math.max(1, srcH);
  const x = Math.max(0, Math.round(toNumber(face?.x, 0) * sx));
  const y = Math.max(0, Math.round(toNumber(face?.y, 0) * sy));
  const width = Math.max(0, Math.round(toNumber(face?.width, 0) * sx));
  const height = Math.max(0, Math.round(toNumber(face?.height, 0) * sy));
  return { x, y, width, height };
}

function drawFacePatches(ctx, rawCanvas, faces, sourceW, sourceH, width, height) {
  if (!Array.isArray(faces) || faces.length === 0) return;
  for (const face of faces) {
    const box = scaleFaceBox(face, sourceW, sourceH, width, height);
    if (box.width <= 0 || box.height <= 0) continue;
    ctx.drawImage(rawCanvas, box.x, box.y, box.width, box.height, box.x, box.y, box.width, box.height);
  }
}

function drawMatteMaskedPerson(ctx, rawCanvas, matteMask, width, height, personCanvas, personCtx) {
  if (!matteMask || !(personCanvas instanceof HTMLCanvasElement) || !personCtx) return false;
  personCanvas.width = width;
  personCanvas.height = height;
  personCtx.clearRect(0, 0, width, height);
  personCtx.globalCompositeOperation = 'source-over';
  personCtx.drawImage(rawCanvas, 0, 0, width, height);
  personCtx.globalCompositeOperation = 'destination-in';
  try {
    personCtx.drawImage(matteMask, 0, 0, width, height);
  } catch {
    personCtx.globalCompositeOperation = 'source-over';
    return false;
  }
  personCtx.globalCompositeOperation = 'source-over';
  ctx.drawImage(personCanvas, 0, 0, width, height);
  return true;
}

async function resolveSegmentationBackend(selection, opts) {
  if (selection.backend === 'face_detector') {
    try {
      return createBackgroundSegmentationBackend('face_detector', opts);
    } catch {
      // fall through to async backends.
    }
  }

  try {
    const mediapipe = await createMediaPipeSegmentationBackend(opts);
    if (mediapipe) return mediapipe;
  } catch {
    // ignore and continue fallback chain.
  }

  try {
    const tfjs = await createTfjsSegmentationBackend(opts);
    if (tfjs) return tfjs;
  } catch {
    // ignore and continue fallback chain.
  }

  return createBackgroundSegmentationBackend('center_mask_fallback', opts);
}

export async function createBackgroundFilterStream(sourceStream, options = {}) {
  if (!(sourceStream instanceof MediaStream)) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  const mode = String(options.mode || 'off').trim().toLowerCase();
  if (mode !== 'blur') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'off',
      backend: 'none',
      dispose: () => {},
    };
  }

  const videoTrack = sourceStream.getVideoTracks()[0] || null;
  if (!videoTrack) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'no_video_track',
      backend: 'none',
      dispose: () => {},
    };
  }

  if (typeof document === 'undefined') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }
  const selection = selectBackgroundFilterBackend();
  if (!selection.supported) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const video = document.createElement('video');
  video.autoplay = true;
  video.playsInline = true;
  video.muted = true;
  video.srcObject = new MediaStream([videoTrack]);

  const ready = await waitForVideoReady(video);
  if (!ready) {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  try {
    await video.play();
  } catch {
    // keep processing attempt; frame loop guards readyState.
  }

  const settings = typeof videoTrack.getSettings === 'function' ? videoTrack.getSettings() : {};
  const sourceWidth = Math.max(1, Math.round(toNumber(settings?.width, 640)));
  const sourceHeight = Math.max(1, Math.round(toNumber(settings?.height, 480)));
  const sourceFps = resolveFps(videoTrack);
  const processing = resolveProcessingSpec(
    sourceWidth,
    sourceHeight,
    sourceFps,
    toNumber(options.maxProcessWidth, 960),
    toNumber(options.maxProcessFps, 24),
  );
  const width = processing.width;
  const height = processing.height;
  const fps = processing.fps;

  const blurPx = Math.max(4, Math.min(28, Math.round(toNumber(options.blurPx, 12))));
  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(toNumber(options.detectIntervalMs, 220))));
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(toNumber(options.facePaddingPx, 14))));
  const statsIntervalMs = Math.max(500, Math.min(5000, Math.round(toNumber(options.statsIntervalMs, 1000))));
  const onStats = typeof options.onStats === 'function' ? options.onStats : null;
  const autoDisableOnOverload = options.autoDisableOnOverload !== false;
  const overloadFrameMs = Math.max(40, Math.min(400, toNumber(options.overloadFrameMs, 90)));
  const overloadConsecutiveFrames = Math.max(3, Math.min(60, Math.round(toNumber(options.overloadConsecutiveFrames, 12))));
  const onOverload = typeof options.onOverload === 'function' ? options.onOverload : null;

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });
  if (!ctx || typeof canvas.captureStream !== 'function') {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const rawCanvas = document.createElement('canvas');
  rawCanvas.width = width;
  rawCanvas.height = height;
  const rawCtx = rawCanvas.getContext('2d', { alpha: false, desynchronized: true });
  const personCanvas = document.createElement('canvas');
  personCanvas.width = width;
  personCanvas.height = height;
  const personCtx = personCanvas.getContext('2d', { alpha: true, desynchronized: true });
  if (!rawCtx || !personCtx) {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  const segmentationBackend = await resolveSegmentationBackend(selection, {
    detectIntervalMs,
    facePaddingPx,
  });

  const captured = canvas.captureStream(fps);
  const output = new MediaStream();
  const filteredVideoTrack = captured.getVideoTracks()[0] || null;
  if (filteredVideoTrack) {
    output.addTrack(filteredVideoTrack);
  }
  for (const audioTrack of sourceStream.getAudioTracks()) {
    output.addTrack(audioTrack);
  }

  let disposed = false;
  let rafId = 0;
  let frameCount = 0;
  let detectCount = 0;
  let detectDurationSum = 0;
  let processDurationSum = 0;
  let statsWindowStartAt = performance.now();
  const frameBudgetMs = 1000 / Math.max(1, fps);
  let nextFrameDueAt = performance.now();
  let overloadCooldownUntil = 0;
  let overloadStreak = 0;
  let overloadDisabled = false;
  let lastFaces = [];
  let lastMatteMask = null;

  const draw = () => {
    if (disposed) return;
    const frameStartedAt = performance.now();
    if (frameStartedAt < nextFrameDueAt) {
      rafId = requestAnimationFrame(draw);
      return;
    }

    if (video.readyState >= 2) {
      const vw = video.videoWidth || width;
      const vh = video.videoHeight || height;
      if (vw > 1 && vh > 1) {
        if (overloadDisabled) {
          ctx.save();
          ctx.filter = 'none';
          ctx.drawImage(video, 0, 0, width, height);
          ctx.restore();
        } else {
          const now = performance.now();
          const canRunSegmentation = now >= overloadCooldownUntil;
          let segmentation = { faces: lastFaces, detectSampleMs: null, matteMask: lastMatteMask };
          if (canRunSegmentation) {
            try {
              segmentation = segmentationBackend.nextFaces(video, vw, vh, now);
            } catch {
              segmentation = { faces: lastFaces, detectSampleMs: null, matteMask: lastMatteMask };
            }
          }
          const faces = Array.isArray(segmentation?.faces) ? segmentation.faces : [];
          const matteMask = segmentation?.matteMask || null;

          lastFaces = faces;
          lastMatteMask = matteMask;

          if (typeof segmentation?.detectSampleMs === 'number' && Number.isFinite(segmentation.detectSampleMs)) {
            detectCount += 1;
            detectDurationSum += Math.max(0, segmentation.detectSampleMs);
          }

          rawCtx.drawImage(video, 0, 0, width, height);
          ctx.save();
          ctx.filter = `blur(${blurPx}px)`;
          ctx.drawImage(rawCanvas, 0, 0, width, height);
          ctx.restore();

          if (!drawMatteMaskedPerson(ctx, rawCanvas, matteMask, width, height, personCanvas, personCtx)) {
            drawFacePatches(ctx, rawCanvas, faces, vw, vh, width, height);
          }

          frameCount += 1;
          const frameProcessMs = Math.max(0, performance.now() - frameStartedAt);
          processDurationSum += frameProcessMs;

          if (frameProcessMs >= overloadFrameMs) {
            overloadStreak += 1;
          } else {
            overloadStreak = Math.max(0, overloadStreak - 1);
          }

          if (autoDisableOnOverload && overloadStreak >= overloadConsecutiveFrames) {
            overloadDisabled = true;
            if (onOverload) {
              try {
                onOverload({
                  avgProcessMs: frameProcessMs,
                  targetFps: fps,
                  thresholdMs: overloadFrameMs,
                });
              } catch {
                // ignore onOverload consumer errors
              }
            }
          }

          if (frameProcessMs > frameBudgetMs * 1.5) {
            overloadCooldownUntil = now + Math.max(frameBudgetMs * 3, 180);
          }

          if (onStats) {
            const elapsedMs = now - statsWindowStartAt;
            if (elapsedMs >= statsIntervalMs) {
              const elapsedSec = Math.max(0.001, elapsedMs / 1000);
              const avgProcessMs = frameCount > 0 ? processDurationSum / frameCount : 0;
              const processLoad = Math.max(0, Math.min(1, (avgProcessMs * (frameCount / elapsedSec)) / 1000));
              try {
                onStats({
                  fps: frameCount / elapsedSec,
                  detectFps: detectCount / elapsedSec,
                  avgDetectMs: detectCount > 0 ? detectDurationSum / detectCount : 0,
                  avgProcessMs,
                  processLoad,
                  width,
                  height,
                  targetFps: fps,
                  sourceWidth,
                  sourceHeight,
                  sourceFps,
                });
              } catch {
                // ignore onStats consumer errors
              }

              frameCount = 0;
              detectCount = 0;
              detectDurationSum = 0;
              processDurationSum = 0;
              statsWindowStartAt = now;
            }
          }
        }
      }
    }

    nextFrameDueAt = frameStartedAt + frameBudgetMs;
    rafId = requestAnimationFrame(draw);
  };
  rafId = requestAnimationFrame(draw);

  const dispose = () => {
    if (disposed) return;
    disposed = true;
    if (rafId) cancelAnimationFrame(rafId);
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    try {
      segmentationBackend.dispose();
    } catch {
      // ignore
    }
    for (const stream of uniqueMediaStreams([captured])) {
      stopStreamTracks(stream);
    }
  };

  return {
    stream: output,
    active: true,
    reason: segmentationBackend.kind === 'face_detector' ? 'ok' : 'ok_fallback',
    backend: segmentationBackend.kind,
    dispose,
  };
}
