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
    timeoutMs: options.timeoutMs,
    serialize: options.serialize,
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

function backgroundImageUploadTimeoutMs(files) {
  const rows = Array.isArray(files) ? files : [];
  const encodedBytes = rows.reduce((total, row) => total + String(row?.data_url || '').length, 0);
  const encodedMiB = Math.max(1, Math.ceil(encodedBytes / (1024 * 1024)));
  return Math.max(60_000, Math.min(300_000, 30_000 + encodedMiB * 12_000));
}

export function uploadWorkspaceBackgroundImages(files) {
  const rows = Array.isArray(files) ? files : [];
  return request('/api/admin/workspace-administration/background-images', {
    method: 'POST',
    body: { files: rows },
    serialize: false,
    timeoutMs: backgroundImageUploadTimeoutMs(rows),
  });
}

export function deleteWorkspaceBackgroundImage(id) {
  return request(`/api/admin/workspace-administration/background-images/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'DELETE',
  });
}
