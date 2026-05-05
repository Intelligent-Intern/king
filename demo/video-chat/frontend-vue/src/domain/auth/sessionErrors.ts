import { currentBackendOrigin } from '../../support/backendFetch';

export function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }

  return fallback;
}

export function normalizeNetworkErrorMessage(error, fallback) {
  const message = error instanceof Error ? error.message.trim() : '';
  if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
    return `Could not reach backend (${currentBackendOrigin()}).`;
  }
  return message || fallback;
}
