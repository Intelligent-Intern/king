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

function wait(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, Math.max(0, Number(ms) || 0));
  });
}

function buildBackendTimeoutError(timeoutMs) {
  const timeoutValue = Math.max(1, Math.round(Number(timeoutMs) || 0));
  const seconds = Number.isFinite(timeoutValue)
    ? `${Math.round(timeoutValue / 100) / 10}s`
    : 'the configured timeout';
  const error = new Error(`Backend request timed out after ${seconds}.`);
  error.name = 'TimeoutError';
  return error;
}

let backendRequestQueue = Promise.resolve();

async function performBackendFetch(path, options = {}) {
  const {
    method = 'GET',
    headers = {},
    body = undefined,
    query = null,
    retryOnNetworkError = true,
    networkRetryCount = 4,
    networkRetryBaseDelayMs = 120,
    timeoutMs = 10_000,
    ...rest
  } = options || {};

  const querySuffix = buildQueryString(query || {});
  const isAbsolute = isAbsoluteUrl(path);
  const origins = isAbsolute ? [''] : resolveBackendOriginCandidates();
  let firstError = null;

  for (const origin of origins) {
    const maxAttempts = Math.max(1, Number.parseInt(String(networkRetryCount), 10) || 1);

    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
      const endpoint = isAbsolute ? `${String(path || '').trim()}${querySuffix}` : `${origin}${path}${querySuffix}`;
      const controller = new AbortController();
      const timeout = Number.isFinite(Number(timeoutMs)) && Number(timeoutMs) > 0
        ? setTimeout(() => controller.abort(buildBackendTimeoutError(timeoutMs)), Number(timeoutMs))
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
        if (
          controller.signal.aborted
          && controller.signal.reason instanceof Error
          && error instanceof Error
          && (error.name === 'AbortError' || /aborted/i.test(String(error.message || '')))
        ) {
          error = controller.signal.reason;
        }
        if (!firstError) firstError = error;
        if (!retryOnNetworkError || !isNetworkError(error)) {
          throw error;
        }

        const isLastAttempt = attempt + 1 >= maxAttempts;
        if (isLastAttempt) {
          break;
        }

        const baseDelay = Math.max(0, Number(networkRetryBaseDelayMs) || 0);
        const backoffMs = Math.min(1_000, baseDelay * Math.pow(2, attempt));
        if (backoffMs > 0) {
          await wait(backoffMs);
        }
      } finally {
        if (timeout) {
          clearTimeout(timeout);
        }
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
