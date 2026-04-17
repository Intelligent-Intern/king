function parseEnvFlag(value, fallback = false) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

export const VIDEOCHAT_DEBUG_LOGS = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_DEBUG_LOGS, false);

export function debugLog(...args) {
  if (!VIDEOCHAT_DEBUG_LOGS) return;
  console.log(...args);
}

export function debugWarn(...args) {
  if (!VIDEOCHAT_DEBUG_LOGS) return;
  console.warn(...args);
}

export function debugError(...args) {
  if (!VIDEOCHAT_DEBUG_LOGS) return;
  console.error(...args);
}

