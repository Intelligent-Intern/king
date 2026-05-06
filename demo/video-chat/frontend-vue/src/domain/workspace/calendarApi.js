import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../auth/session';
import { t } from '../../modules/localization/i18nRuntime.js';

function requestHeaders(includeBody = false) {
  const token = String(sessionState.sessionToken || '').trim();
  const headers = { accept: 'application/json' };
  if (includeBody) headers['content-type'] = 'application/json';
  if (token !== '') headers.authorization = `Bearer ${token}`;
  return headers;
}

function errorMessage(payload, fallback) {
  const message = payload && typeof payload === 'object' ? payload?.error?.message : '';
  return typeof message === 'string' && message.trim() !== '' ? message.trim() : fallback;
}

async function calendarRequest(path, { method = 'GET', query = null, body = null } = {}, allowRefreshRetry = true) {
  let response = null;
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
      throw new Error(t('errors.api.backend_unreachable', { origin: currentBackendOrigin() }));
    }
    throw new Error(message);
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    if ((response.status === 401 || response.status === 403) && allowRefreshRetry) {
      const refreshResult = await refreshSession();
      if (refreshResult?.ok) return calendarRequest(path, { method, query, body }, false);
      await logoutSession();
      throw new Error(t('errors.api.session_expired'));
    }
    throw new Error(errorMessage(payload, t('errors.api.request_failed_status', { status: response.status })));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error(t('errors.api.invalid_payload'));
  }

  return payload;
}

export async function listWorkspaceCalendars({ query = '', page = 1, page_size = 10 } = {}) {
  return calendarRequest('/api/calendars', {
    query: {
      query,
      page,
      page_size,
    },
  });
}

export async function listCalendarDirectoryUsers({ query = '', page = 1, page_size = 10 } = {}) {
  return calendarRequest('/api/user/directory', {
    query: {
      query,
      page,
      page_size,
    },
  });
}

export async function createWorkspaceCalendar(payload) {
  return calendarRequest('/api/calendars', {
    method: 'POST',
    body: payload,
  });
}

export async function updateWorkspaceCalendar(calendarId, payload) {
  return calendarRequest(`/api/calendars/${encodeURIComponent(String(calendarId || ''))}`, {
    method: 'PATCH',
    body: payload,
  });
}

export async function deleteWorkspaceCalendar(calendarId) {
  return calendarRequest(`/api/calendars/${encodeURIComponent(String(calendarId || ''))}`, {
    method: 'DELETE',
  });
}
