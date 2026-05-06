import { sessionState } from '../auth/session';
import { fetchBackend } from '../../support/backendFetch';
import { buildLocalizedApiError } from '../../modules/localization/apiErrorMessages.js';

function headers(withBody = false) {
  const result = { accept: 'application/json' };
  if (withBody) result['content-type'] = 'application/json';
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') result.authorization = `Bearer ${token}`;
  return result;
}

async function request(path, options = {}) {
  const response = await fetchBackend(path, {
    method: options.method || 'GET',
    headers: headers(options.body !== undefined),
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    query: options.query || null,
  }).then((result) => result.response);
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    const error = buildLocalizedApiError(payload, `Request failed (${response.status}).`, response.status);
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

export function listWorkspaceEmailTexts(query = {}) {
  return request('/api/admin/workspace-administration/email-texts', {
    query,
  });
}

export function createWorkspaceEmailText(body) {
  return request('/api/admin/workspace-administration/email-texts', {
    method: 'POST',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function updateWorkspaceEmailText(id, body) {
  return request(`/api/admin/workspace-administration/email-texts/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'PATCH',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function deleteWorkspaceEmailText(id) {
  return request(`/api/admin/workspace-administration/email-texts/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'DELETE',
  });
}

export function listWorkspaceBackgroundImages(query = {}) {
  return request('/api/admin/workspace-administration/background-images', {
    query,
  });
}

export function uploadWorkspaceBackgroundImages(files) {
  return request('/api/admin/workspace-administration/background-images', {
    method: 'POST',
    body: { files: Array.isArray(files) ? files : [] },
  });
}

export function deleteWorkspaceBackgroundImage(id) {
  return request(`/api/admin/workspace-administration/background-images/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'DELETE',
  });
}
