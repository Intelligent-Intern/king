import { sessionState } from '../auth/session';
import { fetchBackend } from '../../support/backendFetch';

function headers(withBody = false) {
  const result = { accept: 'application/json' };
  if (withBody) result['content-type'] = 'application/json';
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') result.authorization = `Bearer ${token}`;
  return result;
}

function errorMessage(payload, fallback) {
  const message = payload?.error?.message;
  return typeof message === 'string' && message.trim() !== '' ? message.trim() : fallback;
}

async function request(path, options = {}) {
  const response = await fetchBackend(path, {
    method: options.method || 'GET',
    headers: headers(options.body !== undefined),
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  }).then((result) => result.response);
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    const error = new Error(errorMessage(payload, `Request failed (${response.status}).`));
    error.fields = payload?.error?.details?.fields || {};
    throw error;
  }
  return payload.result || {};
}

export function loadWorkspaceAdministration() {
  return request('/api/admin/workspace-administration');
}

export function saveWorkspaceAdministration(body) {
  return request('/api/admin/workspace-administration', {
    method: 'PATCH',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function deleteWorkspaceTheme(themeId) {
  const id = String(themeId || '').trim();
  return request(`/api/admin/workspace-administration/themes/${encodeURIComponent(id)}`, {
    method: 'DELETE',
  });
}
