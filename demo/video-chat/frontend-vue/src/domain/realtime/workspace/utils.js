import { formatLocalizedTimestampDisplay } from '../../../support/dateTimeFormat.js';

const DIRECTORY_USERS_ORDER_VALUES = ['role_then_name_asc', 'role_then_name_desc'];
const DIRECTORY_USERS_STATUS_VALUES = ['all', 'active', 'disabled'];

export function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

export function normalizeOptionalRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return '';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : '';
}

export function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'admin') return role;
  return 'user';
}

export function normalizeUsersDirectoryOrder(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (DIRECTORY_USERS_ORDER_VALUES.includes(normalized)) return normalized;
  return 'role_then_name_asc';
}

export function normalizeUsersDirectoryStatus(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (DIRECTORY_USERS_STATUS_VALUES.includes(normalized)) return normalized;
  return 'all';
}

export function parseUsersDirectoryQuery(rawValue) {
  const input = String(rawValue || '').trim();
  if (input === '') {
    return {
      query: '',
      status: 'all',
      order: 'role_then_name_asc',
    };
  }

  const queryTerms = [];
  let status = 'all';
  let order = 'role_then_name_asc';
  for (const token of input.split(/\s+/).filter(Boolean)) {
    const normalized = token.trim().toLowerCase();
    if (normalized === 'status:active' || normalized === 'is:active') {
      status = 'active';
      continue;
    }
    if (normalized === 'status:disabled' || normalized === 'is:disabled' || normalized === 'is:inactive') {
      status = 'disabled';
      continue;
    }
    if (
      normalized === 'sort:desc'
      || normalized === 'sort:za'
      || normalized === 'order:desc'
      || normalized === 'order:za'
    ) {
      order = 'role_then_name_desc';
      continue;
    }
    if (
      normalized === 'sort:asc'
      || normalized === 'sort:az'
      || normalized === 'order:asc'
      || normalized === 'order:az'
    ) {
      order = 'role_then_name_asc';
      continue;
    }

    queryTerms.push(token);
  }

  return {
    query: queryTerms.join(' ').trim(),
    status: normalizeUsersDirectoryStatus(status),
    order: normalizeUsersDirectoryOrder(order),
  };
}

export function roleRank(role) {
  if (role === 'admin') return 0;
  return 1;
}

export function normalizeCallRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'owner' || role === 'moderator') return role;
  return 'participant';
}

export function callRoleRank(role) {
  if (role === 'owner') return 0;
  if (role === 'moderator') return 1;
  return 2;
}

export function normalizeSocketCallId(value) {
  const normalized = String(value || '').trim();
  if (normalized === '') return '';
  return /^[A-Za-z0-9._-]{1,200}$/.test(normalized) ? normalized : '';
}

export function formatTimestamp(value) {
  if (typeof value !== 'string' || value.trim() === '') return '--';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return formatLocalizedTimestampDisplay(date, { fallback: '--' });
}

export function initials(name) {
  const raw = String(name || '').trim();
  if (raw === '') return 'U';
  const parts = raw.split(/\s+/).filter(Boolean);
  if (parts.length === 1) {
    return parts[0].slice(0, 2).toUpperCase();
  }
  return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

export function miniVideoSlotId(userId) {
  const normalizedUserId = Number(userId);
  return `workspace-mini-video-slot-${Number.isInteger(normalizedUserId) && normalizedUserId > 0 ? normalizedUserId : 0}`;
}
