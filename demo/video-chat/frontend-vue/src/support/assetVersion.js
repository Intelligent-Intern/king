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

function liveAssetVersionFromPayload(payload) {
  if (!payload || typeof payload !== 'object') return '';

  const directValue = String(payload.asset_version || '').trim();
  if (directValue !== '') return directValue;

  const runtime = payload.runtime && typeof payload.runtime === 'object' ? payload.runtime : null;
  return String(runtime?.asset_version || '').trim();
}

function hardReload() {
  if (assetReloadPending || typeof window === 'undefined') return false;
  assetReloadPending = true;
  window.location.reload();
  return true;
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
  if (BUILD_VERSION === '' || query.has('asset_version')) return query;
  query.set('asset_version', BUILD_VERSION);
  return query;
}

export function currentAssetVersion() {
  return BUILD_VERSION;
}

export function handleAssetVersionSocketPayload(payload) {
  if (import.meta.env.DEV || !payload || typeof payload !== 'object') return false;

  const type = String(payload.type || '').trim().toLowerCase();
  if (INVALIDATE_TYPES.has(type)) {
    return hardReload();
  }

  if (!VERSION_SIGNAL_TYPES.has(type) || BUILD_VERSION === '') {
    return false;
  }

  const liveAssetVersion = liveAssetVersionFromPayload(payload);
  if (liveAssetVersion === '' || liveAssetVersion === BUILD_VERSION) {
    return false;
  }

  return hardReload();
}

export function handleAssetVersionSocketClose(event) {
  if (import.meta.env.DEV) return false;
  const closeReason = String(event?.reason || '').trim().toLowerCase();
  if (closeReason !== 'asset_version_mismatch') {
    return false;
  }

  return hardReload();
}

export function handleAssetLoadFailure(error, payload = {}) {
  if (import.meta.env.DEV || !isAssetLoadFailure(error, payload)) return false;
  if (!claimAssetLoadFailureReload()) return false;
  return hardReload();
}
