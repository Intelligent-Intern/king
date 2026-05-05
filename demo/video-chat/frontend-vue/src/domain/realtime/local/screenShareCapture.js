const DISPLAY_MEDIA_ERROR_MESSAGES = {
  AbortError: 'Screen sharing could not be started.',
  InvalidStateError: 'Screen sharing must be started from a user action.',
  NotAllowedError: 'Screen sharing permission was denied.',
  NotFoundError: 'No screen sharing source was available.',
  NotReadableError: 'The selected source could not be captured.',
  NotSupportedError: 'Screen sharing is not supported in this browser.',
  OverconstrainedError: 'The requested screen sharing constraints failed.',
  SecurityError: 'Screen sharing is blocked by browser security policy.',
  TypeError: 'The screen sharing constraints are invalid.',
};

export function hasGetDisplayMedia() {
  return typeof navigator !== 'undefined'
    && Boolean(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia);
}

export function buildDisplayMediaOptions(options = {}) {
  const constraints = {
    audio: typeof options.audio === 'undefined' ? true : options.audio,
    video: typeof options.video === 'undefined' ? true : options.video,
  };
  const videoOptions = normalizeVideoOptions(options);

  if (Object.keys(videoOptions).length > 0) {
    constraints.video = constraints.video === false ? false : videoOptions;
  }

  [
    'preferCurrentTab',
    'selfBrowserSurface',
    'surfaceSwitching',
    'systemAudio',
    'windowAudio',
    'monitorTypeSurfaces',
  ].forEach((key) => {
    if (typeof options[key] !== 'undefined') {
      constraints[key] = options[key];
    }
  });

  return constraints;
}

export function normalizeDisplayMediaError(captureError = {}) {
  const name = captureError.name || 'UnknownError';
  const fallback = DISPLAY_MEDIA_ERROR_MESSAGES[name]
    || captureError.message
    || 'Screen sharing failed.';
  const normalizedError = new Error(fallback);

  normalizedError.name = name;
  normalizedError.cause = captureError;
  normalizedError.details = {
    message: captureError.message || fallback,
    constraint: captureError.constraint,
  };

  return normalizedError;
}

function normalizeVideoOptions(options) {
  const videoOptions = typeof options.video === 'object' && options.video !== null
    ? { ...options.video }
    : {};

  [
    'displaySurface',
    'logicalSurface',
    'cursor',
    'width',
    'height',
    'frameRate',
    'aspectRatio',
  ].forEach((key) => {
    if (typeof options[key] !== 'undefined') {
      videoOptions[key] = options[key];
    }
  });

  return videoOptions;
}
