const BUILD_VERSION = String(import.meta.env.VIDEOCHAT_ASSET_VERSION || '').trim();
const INVALIDATE_TYPES = new Set(['assets/invalidate', 'assets.invalidate']);
const VERSION_SIGNAL_TYPES = new Set([
  'system/welcome',
  'system/pong',
  'system/runtime',
  'sfu/welcome',
  'sfu/runtime',
]);

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
