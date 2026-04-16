import {
  resolveBackendOrigin,
  resolveBackendOriginCandidates,
  setBackendOrigin,
} from './backendOrigin';

function isAbsoluteUrl(value) {
  return /^[a-z]+:\/\//i.test(String(value || '').trim());
}

function buildQueryString(params) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params || {})) {
    if (value === undefined || value === null) continue;
    const text = String(value).trim();
    if (text === '') continue;
    query.set(key, text);
  }
  const encoded = query.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

function isNetworkError(error) {
  if (!(error instanceof Error)) return true;
  const text = String(error.message || '').toLowerCase();
  if (text.includes('failed to fetch')) return true;
  if (text.includes('networkerror')) return true;
  if (text.includes('socket')) return true;
  if (text.includes('connection')) return true;
  return error.name === 'TypeError';
}

let backendRequestQueue = Promise.resolve();

async function performBackendFetch(path, options = {}) {
  const {
    method = 'GET',
    headers = {},
    body = undefined,
    query = null,
    retryOnNetworkError = true,
    timeoutMs = 10_000,
    ...rest
  } = options || {};

  const querySuffix = buildQueryString(query || {});
  const isAbsolute = isAbsoluteUrl(path);
  const origins = isAbsolute ? [''] : resolveBackendOriginCandidates();
  let firstError = null;

  for (const origin of origins) {
    const endpoint = isAbsolute ? `${String(path || '').trim()}${querySuffix}` : `${origin}${path}${querySuffix}`;
    const controller = new AbortController();
    const timeout = Number.isFinite(Number(timeoutMs)) && Number(timeoutMs) > 0
      ? setTimeout(() => controller.abort(), Number(timeoutMs))
      : null;

    try {
      const response = await fetch(endpoint, {
        method,
        headers,
        body,
        signal: controller.signal,
        ...rest,
      });
      if (!isAbsolute && origin !== '' && origin !== resolveBackendOrigin()) {
        setBackendOrigin(origin);
      }
      return {
        response,
        origin: isAbsolute ? resolveBackendOrigin() : origin,
        endpoint,
      };
    } catch (error) {
      if (!firstError) firstError = error;
      if (!retryOnNetworkError || !isNetworkError(error)) {
        throw error;
      }
    } finally {
      if (timeout) {
        clearTimeout(timeout);
      }
    }
  }

  if (firstError) {
    throw firstError;
  }

  throw new Error('Backend request failed.');
}

export function currentBackendOrigin() {
  return resolveBackendOrigin();
}

export async function fetchBackend(path, options = {}) {
  const serialize = options?.serialize !== false;
  if (!serialize) {
    return performBackendFetch(path, options);
  }

  const run = () => performBackendFetch(path, options);
  const queued = backendRequestQueue.then(run, run);
  backendRequestQueue = queued.then(
    () => undefined,
    () => undefined,
  );
  return queued;
}
