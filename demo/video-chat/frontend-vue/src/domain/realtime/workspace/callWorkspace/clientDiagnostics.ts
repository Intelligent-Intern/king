function extractDiagnosticMessage(value, fallback = 'Client diagnostics event captured.') {
  if (value instanceof Error) {
    return String(value.message || fallback).trim() || fallback;
  }
  if (typeof value === 'string') {
    return value.trim() || fallback;
  }
  if (value && typeof value === 'object' && typeof value.message === 'string') {
    return String(value.message || fallback).trim() || fallback;
  }
  return fallback;
}

export { extractDiagnosticMessage };

export function createClientDiagnosticCapturer({
  reportClientDiagnostic,
  getCallId,
  getRoomId,
}) {
  function captureClientDiagnostic({
    category = 'media',
    level = 'error',
    eventType = '',
    code = '',
    message = '',
    payload = {},
    immediate = false,
  } = {}) {
    if (String(eventType || '').trim() === '') return;
    reportClientDiagnostic({
      category,
      level,
      eventType,
      code,
      message,
      callId: getCallId(),
      roomId: getRoomId(),
      payload,
      immediate,
    });
  }

  function captureClientDiagnosticError(eventType, error, payload = {}, options = {}) {
    captureClientDiagnostic({
      category: options.category || 'media',
      level: options.level || 'error',
      eventType,
      code: options.code || '',
      message: extractDiagnosticMessage(error, options.fallbackMessage || 'Client diagnostics error captured.'),
      payload: {
        ...payload,
        error,
      },
      immediate: Boolean(options.immediate),
    });
  }

  return {
    captureClientDiagnostic,
    captureClientDiagnosticError,
  };
}
