function normalizeString(value) {
  return String(value || '').trim();
}

function titleFromKey(value) {
  return normalizeString(value)
    .replace(/[._-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function moduleKeyFromRelationRow(row) {
  const explicit = normalizeString(row?.module_key || row?.key);
  if (explicit !== '') return explicit.replace(/^module:/, '');
  return normalizeString(row?.id).replace(/^module:/, '');
}

export function permissionModuleKey(row) {
  return normalizeString(row?.module_key) || normalizeString(row?.key).split('.')[0];
}

export function permissionModuleLabel(row) {
  return normalizeString(row?.module_name) || titleFromKey(permissionModuleKey(row));
}

export function isPermissionRow(row) {
  return normalizeString(row?.entity_key) === 'permissions' || normalizeString(row?.id).startsWith('permission:');
}

export function relationRowSections(rows, currentTargetEntity = '') {
  if (!Array.isArray(rows) || rows.length === 0) return [];
  if (normalizeString(currentTargetEntity) !== 'permissions') {
    return [{ key: 'default', label: '', rows }];
  }
  const sections = new Map();
  for (const row of rows) {
    const moduleKey = permissionModuleKey(row) || 'unknown';
    if (!sections.has(moduleKey)) {
      sections.set(moduleKey, {
        key: `permission-module:${moduleKey}`,
        label: permissionModuleLabel(row),
        rows: [],
      });
    }
    sections.get(moduleKey).rows.push(row);
  }
  return [...sections.values()];
}
