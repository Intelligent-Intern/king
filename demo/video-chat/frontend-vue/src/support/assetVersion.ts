import { fetchBackend } from './backendFetch';

const BUILD_VERSION = String(import.meta.env.VIDEOCHAT_ASSET_VERSION || '').trim();
const INVALIDATE_TYPES = new Set(['assets/invalidate', 'assets.invalidate']);
const VERSION_SIGNAL_TYPES = new Set([
  'system/welcome',
  'system/pong',
  'system/runtime',
  'sfu/welcome',
  'sfu/runtime',
]);
const ASSET_LOAD_FAILURE_RELOAD_STORAGE_KEY = 'ii.videocall.asset_load_failure_reload.v1';
const ASSET_RELOAD_ATTEMPT_STORAGE_KEY = 'ii.videocall.asset_reload_attempt.v1';
const ASSET_RELOAD_LAST_ATTEMPT_STORAGE_KEY = 'ii.videocall.asset_reload_last_attempt.v1';
const ASSET_LOAD_FAILURE_PATTERNS = [
  'failed to fetch dynamically imported module',
  'error loading dynamically imported module',
  'importing a module script failed',
  'loading chunk',
  'chunkloaderror',
  'css chunk load failed',
  'unable to preload css',
];

let assetReloadPending = false;
let assetVersionHintPending = false;
let runtimeAssetVersionProbeInFlight = null;

function currentPathname() {
  if (typeof window === 'undefined') return '';
  return String(window.location?.pathname || '');
}

function isCallWorkspacePath(path = '') {
  return String(path || '').startsWith('/workspace/call');
}

function shouldUseAssetVersionHintOnly() {
  return isCallWorkspacePath(currentPathname());
}

function assetReloadStorageScope(targetAssetVersion = '', reason = '') {
  return [
    ASSET_RELOAD_ATTEMPT_STORAGE_KEY,
    BUILD_VERSION || 'unknown',
    String(targetAssetVersion || 'unknown').trim() || 'unknown',
    currentPathname(),
    String(reason || 'asset_version_mismatch').trim() || 'asset_version_mismatch',
  ].join(':');
}

function assetReloadLastAttemptScope() {
  return [
    ASSET_RELOAD_LAST_ATTEMPT_STORAGE_KEY,
    BUILD_VERSION || 'unknown',
    currentPathname(),
  ].join(':');
}

function liveAssetVersionFromPayload(payload) {
  if (!payload || typeof payload !== 'object') return '';

  const directValue = String(payload.asset_version || '').trim();
  if (directValue !== '') return directValue;

  const runtime = payload.runtime && typeof payload.runtime === 'object' ? payload.runtime : null;
  return String(runtime?.asset_version || '').trim();
}

function claimAssetReloadAttempt(targetAssetVersion = '', reason = '') {
  if (typeof window === 'undefined') return false;

  const normalizedTargetAssetVersion = String(targetAssetVersion || '').trim();
  const attemptKey = assetReloadStorageScope(normalizedTargetAssetVersion, reason);
  const lastAttemptKey = assetReloadLastAttemptScope();

  try {
    if (normalizedTargetAssetVersion === '' && window.sessionStorage?.getItem(lastAttemptKey)) {
      return false;
    }
    if (window.sessionStorage?.getItem(attemptKey) === '1') {
      return false;
    }
    window.sessionStorage?.setItem(attemptKey, '1');
    window.sessionStorage?.setItem(lastAttemptKey, normalizedTargetAssetVersion || 'unknown');
    return true;
  } catch {
    return !assetReloadPending;
  }
}

function hasReloadAttemptedForCurrentAssetVersion() {
  if (typeof window === 'undefined') return false;

  try {
    return assetVersionHintPending
      || assetReloadPending
      || window.sessionStorage?.getItem(assetReloadLastAttemptScope()) !== null;
  } catch {
    return assetVersionHintPending || assetReloadPending;
  }
}

function emitAssetVersionHint(reason = 'asset_version_mismatch', targetAssetVersion = '') {
  if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') return;

  try {
    window.dispatchEvent(new CustomEvent('kingrt:asset-version-hint', {
      detail: {
        asset_version: BUILD_VERSION,
        target_asset_version: String(targetAssetVersion || '').trim(),
        path: currentPathname(),
        reason: String(reason || 'asset_version_mismatch').trim() || 'asset_version_mismatch',
        reload: false,
      },
    }));
  } catch {
    // Internal hint delivery is best-effort; reload suppression must not throw.
  }
}

function recordAssetVersionHint(reason = 'asset_version_mismatch', targetAssetVersion = '') {
  if (typeof window === 'undefined') return false;
  assetVersionHintPending = true;
  claimAssetReloadAttempt(targetAssetVersion, reason);
  emitAssetVersionHint(reason, targetAssetVersion);
  return true;
}

function hardReload(reason = 'asset_version_mismatch', targetAssetVersion = '') {
  if (assetReloadPending || typeof window === 'undefined') return false;
  if (!claimAssetReloadAttempt(targetAssetVersion, reason)) return false;
  assetReloadPending = true;
  window.location.reload();
  return true;
}

function handleStaleAssetVersion(reason = 'asset_version_mismatch', targetAssetVersion = '', options = {}) {
  if (shouldUseAssetVersionHintOnly()) {
    recordAssetVersionHint(reason, targetAssetVersion);
    return !Boolean(options?.allowReconnect);
  }

  return hardReload(reason, targetAssetVersion);
}

function assetLoadFailureText(error, payload = {}) {
  const parts = [];
  const append = (value) => {
    const normalized = String(value ?? '').trim();
    if (normalized !== '') parts.push(normalized);
  };
  append(error?.message);
  append(error?.stack);
  append(error?.name);
  append(error);
  append(payload?.message);
  append(payload?.source_file);
  append(payload?.source_url);
  append(payload?.href);
  return parts.join(' ').toLowerCase();
}

function isAssetLoadFailure(error, payload = {}) {
  const text = assetLoadFailureText(error, payload);
  if (text === '') return false;
  if (ASSET_LOAD_FAILURE_PATTERNS.some((pattern) => text.includes(pattern))) return true;
  return text.includes('/assets/')
    && (text.includes('.js') || text.includes('.css'))
    && (text.includes('failed') || text.includes('error'));
}

function claimAssetLoadFailureReload() {
  if (typeof window === 'undefined') return false;
  const key = [
    ASSET_LOAD_FAILURE_RELOAD_STORAGE_KEY,
    BUILD_VERSION || 'unknown',
    String(window.location?.pathname || ''),
  ].join(':');
  try {
    if (window.sessionStorage?.getItem(key) === '1') return false;
    window.sessionStorage?.setItem(key, '1');
    return true;
  } catch {
    return false;
  }
}

export function appendAssetVersionQuery(query) {
  if (!(query instanceof URLSearchParams)) return query;
  if (BUILD_VERSION !== '' && !query.has('asset_version')) {
    query.set('asset_version', BUILD_VERSION);
  }
  if (hasReloadAttemptedForCurrentAssetVersion() && !query.has('asset_reload_attempted')) {
    query.set('asset_reload_attempted', '1');
  }
  return query;
}

export function currentAssetVersion() {
  return BUILD_VERSION;
}

export function handleAssetVersionSocketPayload(payload) {
  if (import.meta.env.DEV || !payload || typeof payload !== 'object') return false;

  const type = String(payload.type || '').trim().toLowerCase();
  if (INVALIDATE_TYPES.has(type)) {
    return handleStaleAssetVersion('asset_invalidation', liveAssetVersionFromPayload(payload));
  }

  if (!VERSION_SIGNAL_TYPES.has(type) || BUILD_VERSION === '') {
    return false;
  }

  const liveAssetVersion = liveAssetVersionFromPayload(payload);
  if (liveAssetVersion === '' || liveAssetVersion === BUILD_VERSION) {
    return false;
  }

  return handleStaleAssetVersion('asset_version_mismatch', liveAssetVersion);
}

export function handleAssetVersionSocketClose(event, options = {}) {
  if (import.meta.env.DEV) return false;
  const closeReason = String(event?.reason || '').trim().toLowerCase();
  if (closeReason !== 'asset_version_mismatch') {
    return false;
  }

  return handleStaleAssetVersion('asset_version_mismatch_close', '', options);
}

export async function handleAssetVersionConnectionFailure(options = {}) {
  if (import.meta.env.DEV || BUILD_VERSION === '') return false;
  if (assetReloadPending) return true;
  if (!runtimeAssetVersionProbeInFlight) {
    runtimeAssetVersionProbeInFlight = fetchBackend('/api/runtime', {
      method: 'GET',
      headers: { accept: 'application/json' },
      cache: 'no-store',
      networkRetryCount: 1,
      retryOnNetworkError: false,
      serialize: false,
      timeoutMs: 2500,
    })
      .then(async ({ response }) => {
        if (!response?.ok) return '';
        const payload = await response.json();
        const liveAssetVersion = liveAssetVersionFromPayload(payload);
        if (liveAssetVersion === '' || liveAssetVersion === BUILD_VERSION) return '';
        return liveAssetVersion;
      })
      .catch(() => '')
      .finally(() => {
        runtimeAssetVersionProbeInFlight = null;
      });
  }

  const liveAssetVersion = await runtimeAssetVersionProbeInFlight;
  if (liveAssetVersion === '') return false;
  return handleStaleAssetVersion('asset_connection_mismatch', liveAssetVersion, options);
}

export function handleAssetLoadFailure(error, payload = {}) {
  if (import.meta.env.DEV || !isAssetLoadFailure(error, payload)) return false;
  if (!claimAssetLoadFailureReload()) return false;
  return handleStaleAssetVersion('asset_load_failure');
}
