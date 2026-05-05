import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch.js';
import { logoutSession, refreshSession, sessionState } from '../../domain/auth/session.js';
import { buildLocalizedApiError } from '../localization/apiErrorMessages.js';
import {
  governanceCrudRowFromPayload,
  governanceCrudRowsFromPayload,
} from './governanceCrudPersistenceHelpers.js';

function requestHeaders(includeBody) {
  const token = String(sessionState.sessionToken || '').trim();
  const headers = { accept: 'application/json' };
  if (includeBody) headers['content-type'] = 'application/json';
  if (token !== '') headers.authorization = `Bearer ${token}`;
  return headers;
}

function descriptorEndpoint(descriptor) {
  return String(descriptor?.endpoint || '').trim();
}

export function createGovernanceCrudPersistence({ router } = {}) {
  async function apiRequest(path, { method = 'GET', body = null, query = null } = {}, allowRefreshRetry = true) {
    let response;
    try {
      const result = await fetchBackend(path, {
        method,
        headers: requestHeaders(body !== null),
        body: body === null ? undefined : JSON.stringify(body),
        query,
      });
      response = result.response;
    } catch (error) {
      const message = error instanceof Error ? error.message.trim() : '';
      if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
        throw new Error(`Could not reach backend (${currentBackendOrigin()}).`);
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
        if (refreshResult?.ok) return apiRequest(path, { method, body, query }, false);
        await logoutSession();
        await router?.push?.('/login');
        throw new Error('Session expired. Please sign in again.');
      }
      throw buildLocalizedApiError(payload, `Request failed (${response.status}).`, response.status);
    }
    if (!payload || payload.status !== 'ok') {
      throw new Error('Backend returned an invalid payload.');
    }
    return payload;
  }

  async function listRows(descriptor) {
    const endpoint = descriptorEndpoint(descriptor);
    if (endpoint === '') return [];
    const payload = await apiRequest(endpoint);
    return governanceCrudRowsFromPayload(payload, descriptor?.entity_key || '');
  }

  async function listUserSummaries({ search = '', status = 'active' } = {}) {
    const payload = await apiRequest('/api/governance/users', {
      query: {
        query: search,
        status,
        page: 1,
        page_size: 100,
        order: 'role_then_name_asc',
      },
    });
    return governanceCrudRowsFromPayload(payload, 'users');
  }

  async function createRow(descriptor, body) {
    const payload = await apiRequest(descriptorEndpoint(descriptor), { method: 'POST', body });
    return governanceCrudRowFromPayload(payload);
  }

  async function updateRow(descriptor, id, body) {
    const endpoint = `${descriptorEndpoint(descriptor)}/${encodeURIComponent(String(id || ''))}`;
    const payload = await apiRequest(endpoint, { method: 'PATCH', body });
    return governanceCrudRowFromPayload(payload);
  }

  async function deleteRow(descriptor, id) {
    const endpoint = `${descriptorEndpoint(descriptor)}/${encodeURIComponent(String(id || ''))}`;
    await apiRequest(endpoint, { method: 'DELETE' });
  }

  return {
    listRows,
    listUserSummaries,
    createRow,
    updateRow,
    deleteRow,
  };
}
