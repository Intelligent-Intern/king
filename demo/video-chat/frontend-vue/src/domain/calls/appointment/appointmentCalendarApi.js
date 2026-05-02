import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';

function requestHeaders(withBody = false, withAuth = true) {
  const headers = { accept: 'application/json' };
  if (withBody) {
    headers['content-type'] = 'application/json';
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (withAuth && token !== '') {
    headers.authorization = `Bearer ${token}`;
  }

  return headers;
}

function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }

  return fallback;
}

export async function appointmentApiRequest(path, {
  method = 'GET',
  body = null,
  auth = true,
} = {}) {
  let response = null;
  try {
    const result = await fetchBackend(path, {
      method,
      headers: requestHeaders(body !== null, auth),
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

  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const error = new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
    error.fields = payload?.error?.details?.fields || {};
    throw error;
  }
  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

export async function loadAppointmentBlocks() {
  const payload = await appointmentApiRequest('/api/appointment-calendar/blocks');
  return payload?.result || { blocks: [], public_path: '' };
}

export async function saveAppointmentBlocks(blocks) {
  const payload = await appointmentApiRequest('/api/appointment-calendar/blocks', {
    method: 'PUT',
    body: { blocks },
  });
  return payload?.result || { blocks: [], public_path: '' };
}

export async function loadPublicAppointmentSlots(ownerUserId) {
  const ownerId = Number.parseInt(String(ownerUserId), 10) || 0;
  const payload = await appointmentApiRequest(`/api/appointment-calendar/public/${ownerId}`, {
    auth: false,
  });
  return payload?.result || { owner: null, slots: [] };
}

export async function bookPublicAppointment(ownerUserId, form) {
  const ownerId = Number.parseInt(String(ownerUserId), 10) || 0;
  const payload = await appointmentApiRequest(`/api/appointment-calendar/public/${ownerId}/book`, {
    method: 'POST',
    auth: false,
    body: form,
  });
  return payload?.result || { booking: null, join_path: '' };
}

export function toLocalSlotLabel(slot) {
  const startsAt = new Date(String(slot?.starts_at || ''));
  const endsAt = new Date(String(slot?.ends_at || ''));
  if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) {
    return 'Unavailable slot';
  }

  const dateLabel = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(startsAt);
  const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
  });
  const timeLabel = typeof timeFormatter.formatRange === 'function'
    ? timeFormatter.formatRange(startsAt, endsAt)
    : `${timeFormatter.format(startsAt)} - ${timeFormatter.format(endsAt)}`;
  return `${dateLabel}, ${timeLabel}`;
}
