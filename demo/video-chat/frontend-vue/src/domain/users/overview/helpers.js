export function normalizeArray(value) {
  return Array.isArray(value) ? value : [];
}

export function tagClassForStatus(status) {
  const normalized = String(status || '').trim().toLowerCase();
  if (['ok', 'healthy', 'live', 'running', 'connected', 'configured', 'detected'].includes(normalized)) return 'ok';
  if (['warning', 'warn', 'degraded', 'high load', 'error'].includes(normalized)) return 'warn';
  return 'warn';
}

export function normalizeNonNegativeInteger(value) {
  if (Number.isInteger(value)) return Math.max(0, value);
  const parsed = Number.parseInt(String(value ?? '').trim(), 10);
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
}

export function formatUptimeSeconds(value) {
  const seconds = normalizeNonNegativeInteger(value);
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;
  return [
    String(hours).padStart(2, '0'),
    String(minutes).padStart(2, '0'),
    String(remainingSeconds).padStart(2, '0'),
  ].join(':');
}

export function normalizeOwnerHost(call) {
  const host = String(call?.host || '').trim();
  if (host !== '') return host;
  const displayName = String(call?.owner?.display_name || '').trim();
  if (displayName !== '') return displayName;
  const email = String(call?.owner?.email || '').trim();
  return email !== '' ? email : 'unknown';
}

export function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

export function localInputToIso(localValue) {
  if (typeof localValue !== 'string' || localValue.trim() === '') return '';
  const date = new Date(localValue);
  if (Number.isNaN(date.getTime())) return '';
  return date.toISOString();
}

function uuidFromBytes(bytes) {
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20, 32),
  ].join('-');
}

let roomUuidFallbackCounter = 0;

function fallbackDeterministicUuid() {
  const bytes = new Uint8Array(16);
  const now = BigInt(Date.now());
  roomUuidFallbackCounter = (roomUuidFallbackCounter + 1) >>> 0;
  const counter = BigInt(roomUuidFallbackCounter);

  for (let i = 0; i < 8; i += 1) {
    bytes[7 - i] = Number((now >> BigInt(i * 8)) & 0xffn);
  }
  for (let i = 0; i < 4; i += 1) {
    bytes[15 - i] = Number((counter >> BigInt(i * 8)) & 0xffn);
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  return uuidFromBytes(bytes);
}

export function generateRoomUuid() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    return uuidFromBytes(bytes);
  }

  return fallbackDeterministicUuid();
}

export function deriveStatus(startIso, endIso) {
  const now = Date.now();
  const start = Date.parse(String(startIso || ''));
  const end = Date.parse(String(endIso || ''));
  if (Number.isFinite(start) && Number.isFinite(end) && start <= now && now < end) {
    return { label: 'running', tagClass: 'ok' };
  }
  if (Number.isFinite(end) && end <= now) {
    return { label: 'ended', tagClass: 'warn' };
  }
  return { label: 'scheduled', tagClass: 'warn' };
}
