import { isDataPortabilityActionKind, isDataPortabilityEntity } from './dataPortabilityUi.js';

export function downloadGovernanceRowsExport(entityKey = '', rows = []) {
  if (typeof document === 'undefined' || typeof URL === 'undefined') return false;
  const normalizedEntity = String(entityKey || 'governance-export').replace(/[^A-Za-z0-9_.-]/g, '_');
  const payload = {
    entity_key: entityKey,
    exported_at: new Date().toISOString(),
    rows: Array.isArray(rows) ? rows : [],
  };
  const blob = new Blob([`${JSON.stringify(payload, null, 2)}\n`], { type: 'application/json' });
  const link = document.createElement('a');
  const objectUrl = URL.createObjectURL(blob);
  link.href = objectUrl;
  link.download = `${normalizedEntity}.json`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(objectUrl);
  return true;
}

export function runGovernancePageAction(action = null, options = {}) {
  if (action?.kind === 'create') {
    options.openCreate?.();
    return;
  }
  if (isDataPortabilityActionKind(action?.kind) && isDataPortabilityEntity(options.entityKey)) {
    options.openPortability?.(action);
    return;
  }
  if (action?.kind === 'export') {
    downloadGovernanceRowsExport(options.entityKey, options.rows);
  }
}
