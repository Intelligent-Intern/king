export function selectBackgroundFilterBackend() {
  if (typeof window === 'undefined') {
    return {
      backend: 'unsupported',
      supported: false,
      reason: 'no_window',
    };
  }

  if (typeof window.FaceDetector === 'function') {
    return {
      backend: 'face_detector',
      supported: true,
      reason: 'ok',
    };
  }

  return {
    backend: 'center_mask_fallback',
    supported: true,
    reason: 'fallback_center_mask',
  };
}
