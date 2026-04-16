function normalizeExplicitOrigin(rawOrigin, fallbackPort = '') {
  const sanitized = String(rawOrigin || '').trim();
  if (sanitized === '') return '';
  const normalizedFallbackPort = String(fallbackPort || '').trim();

  const candidate = /^[a-z]+:\/\//i.test(sanitized) ? sanitized : `http://${sanitized}`;

  try {
    const parsed = new URL(candidate);
    if (
      normalizedFallbackPort !== ''
      && parsed.port === ''
      && ['http:', 'https:', 'ws:', 'wss:'].includes(parsed.protocol)
    ) {
      parsed.port = normalizedFallbackPort;
    }
    if (
      typeof window !== 'undefined'
      && parsed.hostname.toLowerCase() === 'host.docker.internal'
      && window.location.hostname
    ) {
      parsed.hostname = window.location.hostname;
    }

    parsed.pathname = '';
    parsed.search = '';
    parsed.hash = '';
    return parsed.toString().replace(/\/+$/, '');
  } catch {
    return sanitized.replace(/\/+$/, '');
  }
}

let preferredBackendOrigin = '';
let preferredBackendWebSocketOrigin = '';
let preferredBackendSfuOrigin = '';

function hasExplicitBackendOriginConfig() {
  const explicitOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  return explicitOrigin !== '';
}

function hasExplicitBackendWebSocketConfig() {
  const explicitOrigin = String(import.meta.env.VITE_VIDEOCHAT_WS_ORIGIN || '').trim();
  const explicitPort = String(import.meta.env.VITE_VIDEOCHAT_WS_PORT || '').trim();
  return explicitOrigin !== '' || explicitPort !== '';
}

function hasExplicitBackendSfuConfig() {
  const explicitOrigin = String(import.meta.env.VITE_VIDEOCHAT_SFU_ORIGIN || '').trim();
  const explicitPort = String(import.meta.env.VITE_VIDEOCHAT_SFU_PORT || '').trim();
  return explicitOrigin !== '' || explicitPort !== '';
}

function deriveOffsetPort(basePort, offset, fallback) {
  const parsed = Number.parseInt(String(basePort || '').trim(), 10);
  if (Number.isInteger(parsed) && parsed > 0 && parsed + offset < 65536) {
    return String(parsed + offset);
  }
  return String(fallback || '').trim();
}

function detectDefaultBackendOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  const port = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';
  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin, port);
  }

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
    return `${protocol}://${host}:${port}`;
  }

  return `http://localhost:${port}`;
}

function detectDefaultBackendWebSocketOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_WS_ORIGIN || '').trim();
  const wsPort = String(import.meta.env.VITE_VIDEOCHAT_WS_PORT || '').trim();
  const backendPort = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';
  const inferredWsPort = wsPort || backendPort;
  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin, inferredWsPort);
  }

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
    return `${protocol}://${host}:${inferredWsPort}`;
  }

  return `http://localhost:${inferredWsPort}`;
}

function detectDefaultBackendSfuOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_SFU_ORIGIN || '').trim();
  const sfuPort = String(import.meta.env.VITE_VIDEOCHAT_SFU_PORT || '').trim();
  const wsPort = String(import.meta.env.VITE_VIDEOCHAT_WS_PORT || '').trim();
  const backendPort = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';

  const inferredSfuPort = sfuPort
    || (wsPort !== ''
      ? wsPort
      : backendPort);

  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin, inferredSfuPort);
  }

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
    return `${protocol}://${host}:${inferredSfuPort}`;
  }

  return `http://localhost:${inferredSfuPort}`;
}

export function resolveBackendOrigin() {
  if (preferredBackendOrigin !== '') {
    return preferredBackendOrigin;
  }
  preferredBackendOrigin = normalizeExplicitOrigin(detectDefaultBackendOrigin());
  return preferredBackendOrigin;
}

export function setBackendOrigin(nextOrigin) {
  const normalized = normalizeExplicitOrigin(nextOrigin);
  if (normalized === '') return;
  preferredBackendOrigin = normalized;
}

export function setBackendWebSocketOrigin(nextOrigin) {
  const normalized = normalizeExplicitOrigin(nextOrigin);
  if (normalized === '') return;
  preferredBackendWebSocketOrigin = normalized;
}

export function setBackendSfuOrigin(nextOrigin) {
  const normalized = normalizeExplicitOrigin(nextOrigin);
  if (normalized === '') return;
  preferredBackendSfuOrigin = normalized;
}

export function resolveBackendWebSocketOrigin() {
  if (preferredBackendWebSocketOrigin !== '') {
    return preferredBackendWebSocketOrigin;
  }

  preferredBackendWebSocketOrigin = normalizeExplicitOrigin(detectDefaultBackendWebSocketOrigin());
  return preferredBackendWebSocketOrigin;
}

export function resolveBackendSfuOrigin() {
  if (preferredBackendSfuOrigin !== '') {
    return preferredBackendSfuOrigin;
  }

  preferredBackendSfuOrigin = normalizeExplicitOrigin(detectDefaultBackendSfuOrigin());
  return preferredBackendSfuOrigin;
}

function isLoopbackHost(hostname) {
  const value = String(hostname || '').trim().toLowerCase();
  if (value === 'localhost') return true;
  if (value === '127.0.0.1') return true;
  if (value === '[::1]' || value === '::1') return true;
  return false;
}

export function resolveBackendOriginCandidates() {
  const primary = resolveBackendOrigin();
  const candidates = [primary];

  if (hasExplicitBackendOriginConfig()) {
    return candidates;
  }

  try {
    const parsed = new URL(primary);
    if (isLoopbackHost(parsed.hostname)) {
      const alternate = new URL(primary);
      if (parsed.hostname === 'localhost') {
        alternate.hostname = '127.0.0.1';
      } else {
        alternate.hostname = 'localhost';
      }
      const alternateOrigin = alternate.toString().replace(/\/+$/, '');
      if (!candidates.includes(alternateOrigin)) {
        candidates.push(alternateOrigin);
      }
    }
  } catch {
    // Ignore parse failures and keep primary origin only.
  }

  return candidates;
}

function pushUniqueCandidate(candidates, origin) {
  const normalized = normalizeExplicitOrigin(origin);
  if (normalized === '') return;
  if (!candidates.includes(normalized)) {
    candidates.push(normalized);
  }
}

export function resolveBackendWebSocketOriginCandidates() {
  const candidates = [];

  const primaryWsOrigin = resolveBackendWebSocketOrigin();
  pushUniqueCandidate(candidates, primaryWsOrigin);

  if (hasExplicitBackendWebSocketConfig()) {
    return candidates;
  }

  const primaryBackendOrigin = resolveBackendOrigin();
  pushUniqueCandidate(candidates, primaryBackendOrigin);

  return candidates;
}

export function resolveBackendSfuOriginCandidates() {
  const candidates = [];

  const primarySfuOrigin = resolveBackendSfuOrigin();
  pushUniqueCandidate(candidates, primarySfuOrigin);

  if (hasExplicitBackendSfuConfig()) {
    return candidates;
  }

  const websocketOrigin = resolveBackendWebSocketOrigin();
  const backendOrigin = resolveBackendOrigin();
  pushUniqueCandidate(candidates, websocketOrigin);
  pushUniqueCandidate(candidates, backendOrigin);

  try {
    const parsed = new URL(primarySfuOrigin);
    if (isLoopbackHost(parsed.hostname)) {
      const alternate = new URL(primarySfuOrigin);
      alternate.hostname = parsed.hostname === 'localhost' ? '127.0.0.1' : 'localhost';
      pushUniqueCandidate(candidates, alternate.toString());
    }
  } catch {
    // Ignore parse failures and keep current candidates.
  }

  return candidates;
}
