import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { normalizeDateTimeLocale } from '../../../support/dateTimeFormat.js';

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
  const fields = payload?.error?.details?.fields;
  const fieldMessage = formatValidationFields(fields);
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return fieldMessage === '' ? message.trim() : `${message.trim()} ${fieldMessage}`;
    }
  }

  return fieldMessage === '' ? fallback : `${fallback} ${fieldMessage}`;
}

function formatValidationFields(fields) {
  if (!fields || typeof fields !== 'object') return '';

  const messages = new Set();
  for (const [field, reason] of Object.entries(fields)) {
    const key = String(field || '');
    const value = String(reason || '');
    if (value === 'overlapping_blocks') {
      messages.add('Slots must not overlap.');
    } else if (value === 'overlaps_booked_slot') {
      messages.add('A saved slot overlaps an already booked call.');
    } else if (value === 'required_valid_future_range') {
      messages.add('Every slot needs a valid start and end time.');
    } else if (value === 'block_too_long') {
      messages.add('A single availability block can be at most one day long.');
    } else if (value === 'too_many_blocks') {
      messages.add('At most 200 slots can be saved at once.');
    } else if (value === 'required_array' || value === 'must_be_object') {
      messages.add('The slot data is incomplete.');
    } else if (value === 'field_not_supported') {
      messages.add(`Unsupported settings field: ${key}.`);
    }
  }

  return [...messages].join(' ');
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

export async function saveAppointmentBlocks(blocks, settings = null) {
  const payload = await appointmentApiRequest('/api/appointment-calendar/blocks', {
    method: 'PUT',
    body: settings && typeof settings === 'object' ? { blocks, settings } : { blocks },
  });
  return payload?.result || { blocks: [], public_path: '' };
}

export async function loadAppointmentSettings() {
  const payload = await appointmentApiRequest('/api/appointment-calendar/settings');
  return payload?.result || { settings: null, public_path: '' };
}

export async function saveAppointmentSettings(settings) {
  const payload = await appointmentApiRequest('/api/appointment-calendar/settings', {
    method: 'PATCH',
    body: settings && typeof settings === 'object' ? settings : {},
  });
  return payload?.result || { settings: null, public_path: '' };
}

export async function loadPublicAppointmentSlots(calendarId) {
  const publicCalendarId = encodeURIComponent(String(calendarId || '').trim());
  const payload = await appointmentApiRequest(`/api/appointment-calendar/public/${publicCalendarId}`, {
    auth: false,
  });
  return payload?.result || { owner: null, slots: [] };
}

export async function bookPublicAppointment(calendarId, form) {
  const publicCalendarId = encodeURIComponent(String(calendarId || '').trim());
  const payload = await appointmentApiRequest(`/api/appointment-calendar/public/${publicCalendarId}/book`, {
    method: 'POST',
    auth: false,
    body: form,
  });
  return payload?.result || { booking: null, join_path: '' };
}

function activeDocumentLocale() {
  if (typeof document === 'undefined') return '';
  return String(document.documentElement?.lang || '').trim();
}

export function toLocalSlotLabel(slot, options = {}) {
  const startsAt = new Date(String(slot?.starts_at || ''));
  const endsAt = new Date(String(slot?.ends_at || ''));
  if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) {
    return 'Unavailable slot';
  }
  const locale = normalizeDateTimeLocale(options.locale || activeDocumentLocale());

  const dateLabel = new Intl.DateTimeFormat(locale, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(startsAt);
  const timeFormatter = new Intl.DateTimeFormat(locale, {
    hour: '2-digit',
    minute: '2-digit',
  });
  const timeLabel = typeof timeFormatter.formatRange === 'function'
    ? timeFormatter.formatRange(startsAt, endsAt)
    : `${timeFormatter.format(startsAt)} - ${timeFormatter.format(endsAt)}`;
  return `${dateLabel}, ${timeLabel}`;
}
