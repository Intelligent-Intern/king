function positiveFiniteNumber(value) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function evenFloor(value, fallback = 2) {
  const normalized = Math.floor(positiveFiniteNumber(value));
  if (normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
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
  };
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

  return {
    ...resolveContainFrameSizeFromDimensions(
      source.width,
      source.height,
      maxWidth,
      maxHeight,
    ),
    profileFrameWidth: evenFloor(maxWidth, 2),
    profileFrameHeight: evenFloor(maxHeight, 2),
  };
}

export function resolveProfileReadbackIntervalMs(videoProfile = {}) {
  const readbackIntervalMs = positiveFiniteNumber(videoProfile.readbackIntervalMs);
  return readbackIntervalMs || positiveFiniteNumber(videoProfile.encodeIntervalMs) || 1;
}
