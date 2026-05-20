export const SCREEN_SHARE_CONNECT_TIMEOUT_MS = 10_000;
export const SCREEN_SHARE_CAPTURE_MAX_WIDTH = 960;
export const SCREEN_SHARE_CAPTURE_MAX_HEIGHT = 540;
export const SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE = 6;
export const SCREEN_SHARE_FRAME_MAX_WIDTH = 960;
export const SCREEN_SHARE_FRAME_MAX_HEIGHT = 540;
export const SCREEN_SHARE_ENCODE_INTERVAL_MS = 250;
export const SCREEN_SHARE_FRAME_QUALITY = 32;
export const SCREEN_SHARE_KEYFRAME_INTERVAL = 24;
export const SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES = 900 * 1024;
export const SCREEN_SHARE_MAX_KEYFRAME_BYTES = 1280 * 1024;
export const SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND = 1200 * 1024;
export const SCREEN_SHARE_MAX_BUFFERED_BYTES = 1024 * 1024;
export const SCREEN_SHARE_MAX_QUEUE_AGE_MS = 220;
export const SCREEN_SHARE_MAX_ENCODE_MS = 70;
export const SCREEN_SHARE_MAX_DRAW_IMAGE_MS = 24;
export const SCREEN_SHARE_MAX_READBACK_MS = 34;
export const SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO = 0.94;
export const SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS = 1300;
export const SCREEN_SHARE_RECONNECT_MAX_ATTEMPTS = 5;
export const SCREEN_SHARE_RECONNECT_BASE_DELAY_MS = 750;
export const SCREEN_SHARE_RECONNECT_MAX_DELAY_MS = 5000;

function positiveNumber(value, fallback = 0) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : fallback;
}

function cappedPositiveNumber(value, fallback, max) {
  const normalized = positiveNumber(value, fallback);
  return Math.max(1, Math.min(max, normalized));
}

export function screenShareProfileFrom(baseProfile = {}) {
  const baseId = String(baseProfile?.id || '').trim().toLowerCase();
  const profileId = baseId === 'rescue' ? 'rescue' : 'realtime';
  const captureWidth = cappedPositiveNumber(
    baseProfile.captureWidth,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
  );
  const captureHeight = cappedPositiveNumber(
    baseProfile.captureHeight,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
  );
  const frameWidth = cappedPositiveNumber(
    baseProfile.frameWidth || captureWidth,
    SCREEN_SHARE_FRAME_MAX_WIDTH,
    SCREEN_SHARE_FRAME_MAX_WIDTH,
  );
  const frameHeight = cappedPositiveNumber(
    baseProfile.frameHeight || captureHeight,
    SCREEN_SHARE_FRAME_MAX_HEIGHT,
    SCREEN_SHARE_FRAME_MAX_HEIGHT,
  );
  const captureFrameRate = cappedPositiveNumber(
    baseProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  const encodeIntervalMs = Math.max(
    SCREEN_SHARE_ENCODE_INTERVAL_MS,
    positiveNumber(baseProfile.encodeIntervalMs || baseProfile.readbackIntervalMs, SCREEN_SHARE_ENCODE_INTERVAL_MS),
  );

  return {
    ...baseProfile,
    id: profileId,
    label: 'Screen share',
    captureWidth,
    captureHeight,
    captureFrameRate,
    frameWidth,
    frameHeight,
    frameQuality: Math.min(
      SCREEN_SHARE_FRAME_QUALITY,
      positiveNumber(baseProfile.frameQuality, SCREEN_SHARE_FRAME_QUALITY),
    ),
    keyFrameInterval: Math.max(
      SCREEN_SHARE_KEYFRAME_INTERVAL,
      positiveNumber(baseProfile.keyFrameInterval, SCREEN_SHARE_KEYFRAME_INTERVAL),
    ),
    encodeIntervalMs,
    readbackIntervalMs: encodeIntervalMs,
    readbackFrameRate: Number((1000 / encodeIntervalMs).toFixed(3)),
    maxEncodedBytesPerFrame: Math.min(
      SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES,
      positiveNumber(baseProfile.maxEncodedBytesPerFrame, SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES),
    ),
    maxKeyframeBytesPerFrame: Math.min(
      SCREEN_SHARE_MAX_KEYFRAME_BYTES,
      positiveNumber(baseProfile.maxKeyframeBytesPerFrame, SCREEN_SHARE_MAX_KEYFRAME_BYTES),
    ),
    maxWireBytesPerSecond: Math.min(
      SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND,
      positiveNumber(baseProfile.maxWireBytesPerSecond, SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND),
    ),
    maxEncodeMs: Math.min(
      SCREEN_SHARE_MAX_ENCODE_MS,
      positiveNumber(baseProfile.maxEncodeMs, SCREEN_SHARE_MAX_ENCODE_MS),
    ),
    maxDrawImageMs: Math.min(
      SCREEN_SHARE_MAX_DRAW_IMAGE_MS,
      positiveNumber(baseProfile.maxDrawImageMs, SCREEN_SHARE_MAX_DRAW_IMAGE_MS),
    ),
    maxReadbackMs: Math.min(
      SCREEN_SHARE_MAX_READBACK_MS,
      positiveNumber(baseProfile.maxReadbackMs, SCREEN_SHARE_MAX_READBACK_MS),
    ),
    maxQueueAgeMs: Math.min(
      SCREEN_SHARE_MAX_QUEUE_AGE_MS,
      positiveNumber(baseProfile.maxQueueAgeMs, SCREEN_SHARE_MAX_QUEUE_AGE_MS),
    ),
    maxBufferedBytes: Math.min(
      SCREEN_SHARE_MAX_BUFFERED_BYTES,
      positiveNumber(baseProfile.maxBufferedBytes, SCREEN_SHARE_MAX_BUFFERED_BYTES),
    ),
    payloadSoftLimitRatio: Math.min(
      SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO,
      positiveNumber(baseProfile.payloadSoftLimitRatio, SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO),
    ),
    minKeyframeRetryMs: Math.max(
      SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS,
      positiveNumber(baseProfile.minKeyframeRetryMs, SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS),
    ),
    expectedRecovery: 'hold_screen_share_until_socket_low_water',
    preservePublisherMediaProfile: true,
  };
}

export function screenShareDisplayMediaVideoOptions(videoProfile = {}) {
  const captureFrameRate = cappedPositiveNumber(
    videoProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  return {
    cursor: 'always',
    frameRate: { ideal: captureFrameRate, max: captureFrameRate },
  };
}

export function screenShareTrackConstraints(videoProfile = {}) {
  const captureWidth = cappedPositiveNumber(
    videoProfile.captureWidth,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
  );
  const captureHeight = cappedPositiveNumber(
    videoProfile.captureHeight,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
  );
  const captureFrameRate = cappedPositiveNumber(
    videoProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  return {
    width: { ideal: captureWidth, max: captureWidth },
    height: { ideal: captureHeight, max: captureHeight },
    frameRate: { ideal: captureFrameRate, max: captureFrameRate },
  };
}
