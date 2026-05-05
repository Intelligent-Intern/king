export function normalizeGovernanceRoleRows(payload) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const rows = Array.isArray(result.rows) ? result.rows : (Array.isArray(payload?.roles) ? payload.roles : []);
  return rows.map((role) => ({
    id: String(role?.id || '').trim(),
    key: String(role?.key || role?.id || '').trim(),
    name: String(role?.name || role?.key || role?.id || '').trim(),
    status: String(role?.status || 'active'),
  })).filter((role) => role.id !== '');
}

export async function loadGovernanceRoleOptions(apiRequest) {
  const payload = await apiRequest('/api/governance/roles');
  return normalizeGovernanceRoleRows(payload);
}

export function normalizeUserGovernanceRoles(user) {
  const relationships = user?.relationships && typeof user.relationships === 'object' ? user.relationships : {};
  const roles = Array.isArray(relationships.roles) ? relationships.roles : [];
  return roles.map((role) => ({
    id: String(role?.id || '').trim(),
    key: String(role?.key || role?.id || '').trim(),
    name: String(role?.name || role?.key || role?.id || '').trim(),
    status: String(role?.status || 'active'),
  })).filter((role) => role.id !== '');
}

export function governanceRoleRelationshipPayload(roles) {
  return (Array.isArray(roles) ? roles : []).map((role) => ({
    entity_key: 'roles',
    id: String(role?.id || '').trim(),
    key: String(role?.key || role?.id || '').trim(),
  })).filter((role) => role.id !== '');
}
