const PERSISTED_ENTITIES = Object.freeze(['groups', 'organizations', 'grants', 'data-portability']);

function normalizeString(value) {
  return String(value || '').trim();
}

export function isPersistedGovernanceEntity(entityKey) {
  return PERSISTED_ENTITIES.includes(normalizeString(entityKey));
}

export function normalizeGovernanceCrudRow(row = {}) {
  const source = row && typeof row === 'object' ? row : {};
  const {
    database_id: _databaseId,
    parent_organization_database_id: _parentOrganizationDatabaseId,
    organization_database_id: _organizationDatabaseId,
    ...publicSource
  } = source;
  const id = normalizeString(source.id || source.public_id || source.uuid || source.key);
  return {
    ...publicSource,
    id,
    key: normalizeString(source.key || source.public_id || source.email || id),
    name: normalizeString(source.name || source.display_name || source.email || source.label || id),
    description: normalizeString(source.description),
    status: normalizeString(source.status || 'active'),
    updatedAt: normalizeString(source.updatedAt || source.updated_at || source.created_at),
  };
}

export function governanceCrudRowsFromPayload(payload = {}, entityKey = '') {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const candidates = [
    result.rows,
    payload?.[entityKey],
    result.included?.[entityKey],
  ];
  const rows = candidates.find((candidate) => Array.isArray(candidate)) || [];
  return rows.map((row) => normalizeGovernanceCrudRow(row)).filter((row) => row.id !== '');
}

export function governanceCrudRowFromPayload(payload = {}) {
  const row = payload?.result?.row;
  return row && typeof row === 'object' ? normalizeGovernanceCrudRow(row) : null;
}
