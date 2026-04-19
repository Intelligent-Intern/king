import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import { buildWebSocketUrl } from '../../support/backendOrigin';
import { normalizeRoomId, normalizeSocketCallId } from './callWorkspaceUtils';

export function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) headers['content-type'] = 'application/json';

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }

  return headers;
}

export function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }
  return fallback;
}

export function buildApiRequestError(payload, fallbackMessage, responseStatus = 0) {
  const error = new Error(extractErrorMessage(payload, fallbackMessage));
  error.responseStatus = Number(responseStatus) || 0;
  error.responseCode = String(payload?.error?.code || '').trim().toLowerCase();
  return error;
}

export async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
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
    throw buildApiRequestError(payload, `Request failed (${response.status}).`, response.status);
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

export function socketUrlForRoom(roomId, socketOrigin, callId = '') {
  const query = new URLSearchParams();
  query.set('room', normalizeRoomId(roomId));
  const normalizedCallId = normalizeSocketCallId(callId);
  if (normalizedCallId !== '') {
    query.set('call_id', normalizedCallId);
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    query.set('session', token);
  }

  return buildWebSocketUrl(socketOrigin, '/ws', query);
}
