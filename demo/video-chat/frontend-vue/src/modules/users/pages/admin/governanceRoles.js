import {
  governanceRelationshipPayload,
  normalizeGovernanceRows,
  normalizeUserRelationshipRows,
} from '../../../governance/governanceRelationshipPayload.js';

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
