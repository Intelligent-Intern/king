import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../../../domain/auth/session';

function requestHeaders(includeBody) {
  const token = String(sessionState.sessionToken || '').trim();
  const headers = { accept: 'application/json' };
  if (includeBody) headers['content-type'] = 'application/json';
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }
  return headers;
}

function extractErrorMessage(payload, fallback) {
  const message = payload && typeof payload === 'object' ? payload?.error?.message : '';
  if (typeof message === 'string' && message.trim() !== '') return message.trim();
  return fallback;
}

export function normalizeMarketplaceWebsite(rawWebsite) {
  const value = String(rawWebsite || '').trim();
  if (value === '') return '';
  if (/^https?:\/\//i.test(value)) return value;
  return `${currentBackendOrigin()}${value}`;
}

export function createAdminMarketplaceApi({ router }) {
  return async function apiRequest(path, { method = 'GET', query = null, body = null } = {}, allowRefreshRetry = true) {
    let response;
    try {
      const result = await fetchBackend(path, {
        method,
        query,
        headers: requestHeaders(body !== null),
        body: body === null ? undefined : JSON.stringify(body),
      });
      response = result.response;
    } catch (error) {
      const message = error instanceof Error ? error.message.trim() : '';
      if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
        throw new Error(`Could not reach backend (${currentBackendOrigin()}).`);
      }
      throw new Error(message);
    }

    let payload;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    if (!response.ok) {
      if ((response.status === 401 || response.status === 403) && allowRefreshRetry) {
        const refreshResult = await refreshSession();
        if (refreshResult?.ok) {
          return apiRequest(path, { method, query, body }, false);
        }
        await logoutSession();
        await router.push('/login');
        throw new Error('Session expired. Please sign in again.');
      }
      throw new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
    }

    if (!payload || payload.status !== 'ok') {
      throw new Error('Backend returned an invalid payload.');
    }

    return payload;
  };
}
