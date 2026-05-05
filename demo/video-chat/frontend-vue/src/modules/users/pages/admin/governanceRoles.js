function normalizeGovernanceRows(payload, resultKey) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const rows = Array.isArray(result.rows) ? result.rows : (Array.isArray(payload?.[resultKey]) ? payload[resultKey] : []);
  return rows.map((row) => normalizeGovernanceRow(row)).filter((row) => row.id !== '');
}

function normalizeGovernanceRow(row) {
  return {
    id: String(row?.id || '').trim(),
    key: String(row?.key || row?.id || '').trim(),
    name: String(row?.name || row?.key || row?.id || '').trim(),
    status: String(row?.status || 'active'),
  };
}

function normalizeUserRelationshipRows(user, key) {
  const relationships = user?.relationships && typeof user.relationships === 'object' ? user.relationships : {};
  const rows = Array.isArray(relationships[key]) ? relationships[key] : [];
  return rows.map((row) => normalizeGovernanceRow(row)).filter((row) => row.id !== '');
}

function governanceRelationshipPayload(rows, entityKey) {
  return (Array.isArray(rows) ? rows : []).map((row) => ({
    entity_key: entityKey,
    id: String(row?.id || '').trim(),
    key: String(row?.key || row?.id || '').trim(),
  })).filter((row) => row.id !== '');
}

export function normalizeGovernanceRoleRows(payload) {
  return normalizeGovernanceRows(payload, 'roles');
}

export async function loadGovernanceRoleOptions(apiRequest) {
  const payload = await apiRequest('/api/governance/roles');
  return normalizeGovernanceRoleRows(payload);
}

export function normalizeGovernanceGroupRows(payload) {
  return normalizeGovernanceRows(payload, 'groups');
}

export async function loadGovernanceGroupOptions(apiRequest) {
  const payload = await apiRequest('/api/governance/groups');
  return normalizeGovernanceGroupRows(payload);
}

export function normalizeUserGovernanceRoles(user) {
  return normalizeUserRelationshipRows(user, 'roles');
}

export function normalizeUserGovernanceGroups(user) {
  return normalizeUserRelationshipRows(user, 'groups');
}

export function governanceRoleRelationshipPayload(roles) {
  return governanceRelationshipPayload(roles, 'roles');
}

export function governanceGroupRelationshipPayload(groups) {
  return governanceRelationshipPayload(groups, 'groups');
}
