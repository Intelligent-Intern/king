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

export function resolveBackendOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  if (envOrigin !== '') {
    return normalizeExplicitOrigin(envOrigin);
  }

  const port = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = window.location.hostname || '127.0.0.1';
    return `${protocol}://${host}:${port}`;
  }

  return `http://127.0.0.1:${port}`;
}

