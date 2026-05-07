export function normalizeGovernanceRows(payload, resultKey) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const rows = Array.isArray(result.rows) ? result.rows : (Array.isArray(payload?.[resultKey]) ? payload[resultKey] : []);
  return rows.map((row) => normalizeGovernanceRow(row)).filter((row) => row.id !== '');
}

export function normalizeGovernanceRow(row) {
  const normalized = {
    entity_key: String(row?.entity_key || '').trim(),
    id: String(row?.id || '').trim(),
    key: String(row?.key || row?.id || '').trim(),
    name: String(row?.name || row?.key || row?.id || '').trim(),
    status: String(row?.status || 'active'),
  };
  const relationships = normalizeGovernanceRelationships(row?.relationships);
  if (Object.keys(relationships).length > 0) {
    normalized.relationships = relationships;
  }
  return normalized;
}

export function normalizeGovernanceRelationships(source = {}) {
  if (!source || typeof source !== 'object') return {};
  return Object.fromEntries(Object.entries(source)
    .filter(([, rows]) => Array.isArray(rows))
    .map(([key, rows]) => [key, rows.map((row) => normalizeGovernanceRow(row)).filter((row) => row.id !== '')]));
}

export function normalizeUserRelationshipRows(user, key) {
  const relationships = user?.relationships && typeof user.relationships === 'object' ? user.relationships : {};
  const rows = Array.isArray(relationships[key]) ? relationships[key] : [];
  return rows.map((row) => normalizeGovernanceRow(row)).filter((row) => row.id !== '');
}

export function governanceRelationshipPayload(rows, entityKey) {
  return (Array.isArray(rows) ? rows : []).map((row) => {
    const payload = {
      entity_key: entityKey,
      id: String(row?.id || '').trim(),
      key: String(row?.key || row?.id || '').trim(),
    };
    const relationships = normalizeGovernanceRelationships(row?.relationships);
    if (Object.keys(relationships).length > 0) {
      payload.relationships = relationships;
    }
    return payload;
  }).filter((row) => row.id !== '');
}
