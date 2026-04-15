function normalizeExplicitOrigin(rawOrigin) {
  const sanitized = String(rawOrigin || '').trim();
  if (sanitized === '') return '';

  const candidate = /^[a-z]+:\/\//i.test(sanitized) ? sanitized : `http://${sanitized}`;

  try {
    const parsed = new URL(candidate);
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

function detectDefaultBackendOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin);
  }

  const port = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
    return `${protocol}://${host}:${port}`;
  }

  return `http://localhost:${port}`;
}

function detectDefaultBackendWebSocketOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_WS_ORIGIN || '').trim();
  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin);
  }

  const wsPort = String(import.meta.env.VITE_VIDEOCHAT_WS_PORT || '').trim();
  if (wsPort !== '') {
    if (typeof window !== 'undefined') {
      const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
      const host = String(window.location.hostname || 'localhost').trim() || 'localhost';
      return `${protocol}://${host}:${wsPort}`;
    }
    return `http://localhost:${wsPort}`;
  }

  try {
    const parsedBackendOrigin = new URL(resolveBackendOrigin());
    const backendPort = Number.parseInt(parsedBackendOrigin.port, 10);
    if (Number.isInteger(backendPort) && backendPort > 0 && backendPort < 65535) {
      parsedBackendOrigin.port = String(backendPort + 1);
      return normalizeExplicitOrigin(parsedBackendOrigin.toString());
    }
  } catch {
    // Ignore parse failures and fall back to backend origin.
  }

  return resolveBackendOrigin();
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

  const snapshot = [...candidates];
  for (const origin of snapshot) {
    pushLoopbackAlias(candidates, origin);
  }

  return candidates;
}
