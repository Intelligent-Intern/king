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

function hasExplicitBackendOriginConfig() {
  const explicitOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  return explicitOrigin !== '';
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

  if (wsPort !== '') {
    if (typeof window !== 'undefined') {
      const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
      const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
      return `${protocol}://${host}:${wsPort}`;
    }
    return `http://localhost:${wsPort}`;
  }

  return resolveBackendOrigin();
}

function deriveBackendSiblingWebSocketOriginCandidate() {
  const explicitWsOrigin = String(import.meta.env.VITE_VIDEOCHAT_WS_ORIGIN || '').trim();
  const explicitWsPort = String(import.meta.env.VITE_VIDEOCHAT_WS_PORT || '').trim();
  if (explicitWsOrigin !== '' || explicitWsPort !== '') {
    return '';
  }

  try {
    const parsedBackendOrigin = new URL(resolveBackendOrigin());
    if (parsedBackendOrigin.port === '') {
      return '';
    }

    const backendPort = Number.parseInt(parsedBackendOrigin.port, 10);
    if (!Number.isInteger(backendPort) || backendPort <= 0 || backendPort >= 65535) {
      return '';
    }

    parsedBackendOrigin.port = String(backendPort + 1);
    return normalizeExplicitOrigin(parsedBackendOrigin.toString());
  } catch {
    return '';
  }
}

function derivePortSiblingOrigin(rawOrigin, delta = 1) {
  try {
    const parsed = new URL(rawOrigin);
    if (parsed.port === '') {
      return '';
    }

    const port = Number.parseInt(parsed.port, 10);
    if (!Number.isInteger(port) || port <= 0 || port >= 65535) {
      return '';
    }

    const siblingPort = port + Number(delta || 0);
    if (!Number.isInteger(siblingPort) || siblingPort <= 0 || siblingPort > 65535) {
      return '';
    }

    parsed.port = String(siblingPort);
    return normalizeExplicitOrigin(parsed.toString());
  } catch {
    return '';
  }
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

export function resolveBackendWebSocketOrigin() {
  if (preferredBackendWebSocketOrigin !== '') {
    return preferredBackendWebSocketOrigin;
  }

  preferredBackendWebSocketOrigin = normalizeExplicitOrigin(detectDefaultBackendWebSocketOrigin());
  return preferredBackendWebSocketOrigin;
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

function pushLoopbackAlias(candidates, origin) {
  try {
    const parsed = new URL(origin);
    if (!isLoopbackHost(parsed.hostname)) {
      return;
    }

    const alternate = new URL(origin);
    alternate.hostname = parsed.hostname === 'localhost' ? '127.0.0.1' : 'localhost';
    pushUniqueCandidate(candidates, alternate.toString());
  } catch {
    // Ignore parse errors.
  }
}

export function resolveBackendWebSocketOriginCandidates() {
  const candidates = [];
  pushUniqueCandidate(candidates, resolveBackendWebSocketOrigin());
  pushUniqueCandidate(candidates, resolveBackendOrigin());
  pushUniqueCandidate(candidates, deriveBackendSiblingWebSocketOriginCandidate());

  const firstPassSnapshot = [...candidates];
  for (const origin of firstPassSnapshot) {
    pushUniqueCandidate(candidates, derivePortSiblingOrigin(origin, 1));
    pushLoopbackAlias(candidates, origin);
  }

  const secondPassSnapshot = [...candidates];
  for (const origin of secondPassSnapshot) {
    pushUniqueCandidate(candidates, derivePortSiblingOrigin(origin, 1));
  }

  return candidates;
}
