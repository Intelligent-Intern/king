function functionRef(value) {
  return typeof value === 'function' ? value : null;
}

function positiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function evenInteger(value, fallback = 2) {
  const normalized = Number(value || 0);
  if (!Number.isFinite(normalized) || normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
}

function nestedFrameDimension(frame, path) {
  const value = String(path || '').split('.').reduce((current, key) => current?.[key], frame);
  return positiveInteger(value, 0);
}

export function videoFrameSourceDimensions(frame) {
  const width = positiveInteger(frame?.displayWidth, 0)
    || nestedFrameDimension(frame, 'visibleRect.width')
    || positiveInteger(frame?.codedWidth, 0)
    || positiveInteger(frame?.width, 0);
  const height = positiveInteger(frame?.displayHeight, 0)
    || nestedFrameDimension(frame, 'visibleRect.height')
    || positiveInteger(frame?.codedHeight, 0)
    || positiveInteger(frame?.height, 0);
  return {
    width,
    height,
  };
}

function buildVideoFrameInitFromSource(frame) {
  const init = {};
  const timestamp = Number(frame?.timestamp);
  const duration = Number(frame?.duration);
  if (Number.isFinite(timestamp) && timestamp >= 0) init.timestamp = timestamp;
  if (Number.isFinite(duration) && duration > 0) init.duration = duration;
  return init;
}

export function createBrowserVideoFrameScaler({
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
  errorPrefix = 'sfu_browser_video_frame',
} = {}) {
  const VideoFrameCtor = functionRef(globalScope.VideoFrame);
  const OffscreenCanvasCtor = functionRef(globalScope.OffscreenCanvas);
  const documentRef = globalScope?.document || null;
  let canvas = null;
  let context = null;

  function ensureCanvas(width, height) {
    const targetWidth = evenInteger(width, width);
    const targetHeight = evenInteger(height, height);
    if (!canvas) {
      if (typeof OffscreenCanvasCtor === 'function') {
        canvas = new OffscreenCanvasCtor(targetWidth, targetHeight);
      } else if (documentRef && typeof documentRef.createElement === 'function') {
        canvas = documentRef.createElement('canvas');
      } else {
        throw new Error(`${errorPrefix}_canvas_unavailable`);
      }
      context = canvas?.getContext?.('2d', {
        alpha: false,
        desynchronized: true,
      }) || null;
      if (!context || typeof context.drawImage !== 'function') {
        throw new Error(`${errorPrefix}_canvas_context_unavailable`);
      }
    }
    if (canvas.width !== targetWidth) canvas.width = targetWidth;
    if (canvas.height !== targetHeight) canvas.height = targetHeight;
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    return { canvas, context, width: targetWidth, height: targetHeight };
  }

  function createScaledFrame(sourceFrame, {
    width,
    height,
    sourceCropX = 0,
    sourceCropY = 0,
    sourceCropWidth = 0,
    sourceCropHeight = 0,
  } = {}) {
    if (typeof VideoFrameCtor !== 'function') {
      throw new Error(`${errorPrefix}_video_frame_unavailable`);
    }
    const targetWidth = evenInteger(width, width);
    const targetHeight = evenInteger(height, height);
    if (targetWidth <= 0 || targetHeight <= 0) {
      throw new Error(`${errorPrefix}_dimensions_invalid`);
    }
    const sourceDimensions = videoFrameSourceDimensions(sourceFrame);
    const cropWidth = positiveInteger(sourceCropWidth, sourceDimensions.width);
    const cropHeight = positiveInteger(sourceCropHeight, sourceDimensions.height);
    const cropX = Math.max(0, Math.floor(Number(sourceCropX || 0)));
    const cropY = Math.max(0, Math.floor(Number(sourceCropY || 0)));
    const surface = ensureCanvas(targetWidth, targetHeight);
    surface.context.clearRect(0, 0, targetWidth, targetHeight);
    surface.context.drawImage(
      sourceFrame,
      cropX,
      cropY,
      cropWidth,
      cropHeight,
      0,
      0,
      targetWidth,
      targetHeight,
    );
    return new VideoFrameCtor(surface.canvas, buildVideoFrameInitFromSource(sourceFrame));
  }

  return {
    createScaledFrame,
  };
}
