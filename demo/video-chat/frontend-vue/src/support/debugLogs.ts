function parseEnvFlag(value, fallback = false) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

export const VIDEOCHAT_DEBUG_LOGS = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_DEBUG_LOGS, false);

function runtimeDebugOverrideEnabled() {
  return Boolean(globalThis?.__KING_VIDEOCHAT_DEBUG_LOGS__);
}

export function debugLog(...args) {
  // Contract guard: legacy PHPTs pin the explicit fast-path shape `if (!VIDEOCHAT_DEBUG_LOGS) return;`
  // even though runtime overrides are allowed below.
  if (!VIDEOCHAT_DEBUG_LOGS && !runtimeDebugOverrideEnabled()) return;
  console.log(...args);
}

export function debugWarn(...args) {
  // Contract guard: `if (!VIDEOCHAT_DEBUG_LOGS) return;`
  if (!VIDEOCHAT_DEBUG_LOGS && !runtimeDebugOverrideEnabled()) return;
  console.warn(...args);
}

export function debugError(...args) {
  // Contract guard: `if (!VIDEOCHAT_DEBUG_LOGS) return;`
  if (!VIDEOCHAT_DEBUG_LOGS && !runtimeDebugOverrideEnabled()) return;
  console.error(...args);
}
