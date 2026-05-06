function positiveInteger(value) {
  const normalized = Math.floor(Number(value || 0));
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function frameDimension(frame, keys) {
  for (const key of keys) {
    const value = key.includes('.')
      ? key.split('.').reduce((current, part) => current?.[part], frame)
      : frame?.[key];
    const normalized = positiveInteger(value);
    if (normalized > 0) return normalized;
  }
  return 0;
}

export function canCopyVideoFrameToRgba(frame, frameSize = {}) {
  const width = positiveInteger(frameSize.frameWidth);
  const height = positiveInteger(frameSize.frameHeight);
  const sourceWidth = frameDimension(frame, ['displayWidth', 'codedWidth', 'visibleRect.width', 'width']);
  const sourceHeight = frameDimension(frame, ['displayHeight', 'codedHeight', 'visibleRect.height', 'height']);
  return Boolean(
    frame
      && typeof frame.copyTo === 'function'
      && width > 0
      && height > 0
      && sourceWidth === width
      && sourceHeight === height,
  );
}

export function resolveVideoFrameCopyFrameSize(frame, fallbackFrameSize = {}) {
  const sourceWidth = frameDimension(frame, ['displayWidth', 'codedWidth', 'visibleRect.width', 'width']);
  const sourceHeight = frameDimension(frame, ['displayHeight', 'codedHeight', 'visibleRect.height', 'height']);
  if (sourceWidth <= 0 || sourceHeight <= 0) return null;
  return {
    ...fallbackFrameSize,
    frameWidth: sourceWidth,
    frameHeight: sourceHeight,
    sourceWidth,
    sourceHeight,
    sourceAspectRatio: sourceWidth / sourceHeight,
    aspectMode: 'video_frame_copy_source',
    profileFrameWidth: positiveInteger(fallbackFrameSize.profileFrameWidth || fallbackFrameSize.frameWidth),
    profileFrameHeight: positiveInteger(fallbackFrameSize.profileFrameHeight || fallbackFrameSize.frameHeight),
  };
}

export async function copyVideoFrameToRgbaImageData({
  frame,
  frameSize,
  ImageDataCtor = typeof ImageData !== 'undefined' ? ImageData : null,
} = {}) {
  if (!canCopyVideoFrameToRgba(frame, frameSize)) {
    return { ok: false, reason: 'publisher_video_frame_copy_scale_required' };
  }
  if (typeof ImageDataCtor !== 'function') {
    return { ok: false, reason: 'publisher_video_frame_image_data_missing', fatal: true };
  }

  const width = positiveInteger(frameSize.frameWidth);
  const height = positiveInteger(frameSize.frameHeight);
  const rgba = new Uint8Array(width * height * 4);
  try {
    await frame.copyTo(rgba, { format: 'RGBA' });
  } catch (error) {
    return {
      ok: false,
      reason: 'publisher_video_frame_copy_to_rgba_failed',
      fatal: true,
      error,
    };
  }

  return {
    ok: true,
    imageData: new ImageDataCtor(new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, rgba.byteLength), width, height),
    readbackBytes: rgba.byteLength,
  };
}
