function positiveFiniteNumber(value) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function evenFloor(value, fallback = 2) {
  const normalized = Math.floor(positiveFiniteNumber(value));
  if (normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
}

function normalizedFrameAspectRatio(value, fallback = 1) {
  const normalized = positiveFiniteNumber(value);
  return normalized > 0 ? Math.max(0.2, Math.min(5, normalized)) : fallback;
}

function roundedCropValue(value) {
  const normalized = positiveFiniteNumber(value);
  return normalized > 0 ? Number(normalized.toFixed(3)) : 0;
}

function sourceDimensionsFromVideoSource(source = {}) {
  const videoWidth = positiveFiniteNumber(source?.videoWidth);
  const videoHeight = positiveFiniteNumber(source?.videoHeight);
  if (videoWidth > 0 && videoHeight > 0) {
    return { width: videoWidth, height: videoHeight };
  }

  const settings = source?.trackSettings && typeof source.trackSettings === 'object'
    ? source.trackSettings
    : {};
  const settingsWidth = positiveFiniteNumber(settings.width);
  const settingsHeight = positiveFiniteNumber(settings.height);
  if (settingsWidth > 0 && settingsHeight > 0) {
    return { width: settingsWidth, height: settingsHeight };
  }

  const width = positiveFiniteNumber(source?.width);
  const height = positiveFiniteNumber(source?.height);
  if (width > 0 && height > 0) {
    return { width, height };
  }

  return { width: 0, height: 0 };
}

export function resolveContainFrameSizeFromDimensions(sourceWidth, sourceHeight, maxWidth, maxHeight) {
  const normalizedMaxWidth = evenFloor(maxWidth, 2);
  const normalizedMaxHeight = evenFloor(maxHeight, 2);
  const normalizedSourceWidth = positiveFiniteNumber(sourceWidth);
  const normalizedSourceHeight = positiveFiniteNumber(sourceHeight);

  if (normalizedSourceWidth <= 0 || normalizedSourceHeight <= 0) {
    return {
      frameWidth: normalizedMaxWidth,
      frameHeight: normalizedMaxHeight,
      sourceWidth: 0,
      sourceHeight: 0,
      sourceAspectRatio: normalizedMaxWidth / normalizedMaxHeight,
      aspectMode: 'fallback_profile',
      sourceCropX: 0,
      sourceCropY: 0,
      sourceCropWidth: 0,
      sourceCropHeight: 0,
      framingMode: 'contain',
      targetAspectRatio: normalizedMaxWidth / normalizedMaxHeight,
    };
  }

  const scale = Math.min(
    normalizedMaxWidth / normalizedSourceWidth,
    normalizedMaxHeight / normalizedSourceHeight,
  );
  const frameWidth = evenFloor(normalizedSourceWidth * scale, normalizedMaxWidth);
  const frameHeight = evenFloor(normalizedSourceHeight * scale, normalizedMaxHeight);

  return {
    frameWidth: Math.min(normalizedMaxWidth, frameWidth),
    frameHeight: Math.min(normalizedMaxHeight, frameHeight),
    sourceWidth: normalizedSourceWidth,
    sourceHeight: normalizedSourceHeight,
    sourceAspectRatio: normalizedSourceWidth / normalizedSourceHeight,
    aspectMode: 'source_contain',
    sourceCropX: 0,
    sourceCropY: 0,
    sourceCropWidth: normalizedSourceWidth,
    sourceCropHeight: normalizedSourceHeight,
    framingMode: 'contain',
    targetAspectRatio: normalizedSourceWidth / normalizedSourceHeight,
  };
}

export function normalizePublisherFramingTarget(value = {}) {
  const raw = value && typeof value === 'object' ? value : {};
  const mode = String(raw.mode || raw.framingMode || '').trim().toLowerCase();
  const targetAspectRatio = normalizedFrameAspectRatio(
    raw.targetAspectRatio || raw.aspectRatio,
    0,
  );
  if (mode !== 'cover' || targetAspectRatio <= 0) {
    return { mode: 'contain', targetAspectRatio: 0 };
  }
  return { mode: 'cover', targetAspectRatio };
}

export function resolvePublisherFramingTarget(surface = {}) {
  const dataset = surface?.dataset && typeof surface.dataset === 'object' ? surface.dataset : {};
  return normalizePublisherFramingTarget({
    mode: dataset.callVideoFramingMode,
    targetAspectRatio: dataset.callVideoTargetAspectRatio,
  });
}

export function resolveCoverFrameSizeFromDimensions(sourceWidth, sourceHeight, maxWidth, maxHeight, targetAspectRatio) {
  const normalizedMaxWidth = evenFloor(maxWidth, 2);
  const normalizedMaxHeight = evenFloor(maxHeight, 2);
  const normalizedSourceWidth = positiveFiniteNumber(sourceWidth);
  const normalizedSourceHeight = positiveFiniteNumber(sourceHeight);
  const normalizedTargetAspectRatio = normalizedFrameAspectRatio(
    targetAspectRatio,
    normalizedMaxWidth / normalizedMaxHeight,
  );

  if (normalizedSourceWidth <= 0 || normalizedSourceHeight <= 0) {
    return {
      ...resolveContainFrameSizeFromDimensions(sourceWidth, sourceHeight, maxWidth, maxHeight),
      aspectMode: 'fallback_profile_cover',
      framingMode: 'cover',
      targetAspectRatio: normalizedTargetAspectRatio,
    };
  }

  let frameWidth = normalizedMaxWidth;
  let frameHeight = evenFloor(frameWidth / normalizedTargetAspectRatio, normalizedMaxHeight);
  if (frameHeight > normalizedMaxHeight) {
    frameHeight = normalizedMaxHeight;
    frameWidth = evenFloor(frameHeight * normalizedTargetAspectRatio, normalizedMaxWidth);
  }

  const sourceAspectRatio = normalizedSourceWidth / normalizedSourceHeight;
  let sourceCropWidth = normalizedSourceWidth;
  let sourceCropHeight = normalizedSourceHeight;
  if (sourceAspectRatio > normalizedTargetAspectRatio) {
    sourceCropWidth = Math.min(normalizedSourceWidth, Math.max(2, Math.round(sourceCropHeight * normalizedTargetAspectRatio)));
  } else if (sourceAspectRatio < normalizedTargetAspectRatio) {
    sourceCropHeight = Math.min(normalizedSourceHeight, Math.max(2, Math.round(sourceCropWidth / normalizedTargetAspectRatio)));
  }
  const sourceCropX = Math.max(0, (normalizedSourceWidth - sourceCropWidth) / 2);
  const sourceCropY = Math.max(0, (normalizedSourceHeight - sourceCropHeight) / 2);

  return {
    frameWidth: Math.min(normalizedMaxWidth, frameWidth),
    frameHeight: Math.min(normalizedMaxHeight, frameHeight),
    sourceWidth: normalizedSourceWidth,
    sourceHeight: normalizedSourceHeight,
    sourceAspectRatio,
    aspectMode: 'source_cover_crop',
    sourceCropX: roundedCropValue(sourceCropX),
    sourceCropY: roundedCropValue(sourceCropY),
    sourceCropWidth: roundedCropValue(sourceCropWidth),
    sourceCropHeight: roundedCropValue(sourceCropHeight),
    framingMode: 'cover',
    targetAspectRatio: normalizedTargetAspectRatio,
  };
}

export function resolveFramedFrameSizeFromDimensions(sourceWidth, sourceHeight, maxWidth, maxHeight, framingTarget = {}) {
  const normalizedTarget = normalizePublisherFramingTarget(framingTarget);
  if (normalizedTarget.mode === 'cover') {
    return resolveCoverFrameSizeFromDimensions(
      sourceWidth,
      sourceHeight,
      maxWidth,
      maxHeight,
      normalizedTarget.targetAspectRatio,
    );
  }
  return resolveContainFrameSizeFromDimensions(sourceWidth, sourceHeight, maxWidth, maxHeight);
}

export function resolvePublisherFrameSize(video, videoProfile = {}, videoTrack = null) {
  const trackSettings = videoTrack && typeof videoTrack.getSettings === 'function'
    ? videoTrack.getSettings() || {}
    : {};
  const source = sourceDimensionsFromVideoSource({
    videoWidth: video?.videoWidth,
    videoHeight: video?.videoHeight,
    trackSettings,
  });
  const maxWidth = positiveFiniteNumber(videoProfile?.frameWidth);
  const maxHeight = positiveFiniteNumber(videoProfile?.frameHeight);
  const framingTarget = resolvePublisherFramingTarget(video);

  return {
    ...resolveFramedFrameSizeFromDimensions(
      source.width,
      source.height,
      maxWidth,
      maxHeight,
      framingTarget,
    ),
    profileFrameWidth: evenFloor(maxWidth, 2),
    profileFrameHeight: evenFloor(maxHeight, 2),
  };
}

export function resolveProfileReadbackIntervalMs(videoProfile = {}) {
  const readbackIntervalMs = positiveFiniteNumber(videoProfile.readbackIntervalMs);
  return readbackIntervalMs || positiveFiniteNumber(videoProfile.encodeIntervalMs) || 1;
}
