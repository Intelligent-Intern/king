const CAMERA_CAPTURE_RETRY_ERROR_NAMES = new Set([
  'AbortError',
  'NotFoundError',
  'NotReadableError',
  'OverconstrainedError',
]);

function normalizedCameraDeviceId(cameraDeviceId = '') {
  return String(cameraDeviceId || '').trim();
}

export function buildCallCameraVideoConstraints(cameraDeviceId = '', baseConstraints = {}) {
  const normalizedDeviceId = normalizedCameraDeviceId(cameraDeviceId);
  const constraints = {
    ...(baseConstraints && typeof baseConstraints === 'object' ? baseConstraints : {}),
  };

  if (normalizedDeviceId !== '') {
    constraints.deviceId = { ideal: normalizedDeviceId };
  } else if (!constraints.facingMode) {
    constraints.facingMode = { ideal: 'user' };
  }

  return constraints;
}

export function buildFallbackCallCameraVideoConstraints(baseConstraints = {}) {
  return {
    ...(baseConstraints && typeof baseConstraints === 'object' ? baseConstraints : {}),
    facingMode: { ideal: 'user' },
  };
}

export function shouldRetryCallCameraCapture(error) {
  const name = String(error?.name || '').trim();
  return CAMERA_CAPTURE_RETRY_ERROR_NAMES.has(name);
}

export async function capturePreviewMediaWithCameraFallback({
  audio,
  cameraDeviceId = '',
  mediaDevices = typeof navigator !== 'undefined' ? navigator.mediaDevices : null,
  videoBaseConstraints = {},
} = {}) {
  if (!mediaDevices || typeof mediaDevices.getUserMedia !== 'function') {
    throw new DOMException('Media devices are not supported in this browser.', 'NotSupportedError');
  }

  const video = buildCallCameraVideoConstraints(cameraDeviceId, videoBaseConstraints);
  try {
    return await mediaDevices.getUserMedia({ video, audio });
  } catch (error) {
    if (!shouldRetryCallCameraCapture(error)) throw error;
  }

  return mediaDevices.getUserMedia({
    video: buildFallbackCallCameraVideoConstraints(videoBaseConstraints),
    audio,
  });
}
