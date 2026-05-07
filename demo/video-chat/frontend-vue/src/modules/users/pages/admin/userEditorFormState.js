import {
  normalizeUserGovernanceGroups,
  normalizeUserGovernanceRoles,
} from './governanceRoles';

export function resetUserEditorForm(form, mode = 'create') {
  Object.assign(form, {
    mode,
    id: 0,
    email: '',
    display_name: '',
    password: '',
    password_repeat: '',
    role: 'user',
    status: 'active',
    time_format: '24h',
    theme: 'dark',
    theme_editor_enabled: false,
    avatar_path: '',
    governance_groups: [],
    governance_roles: [],
  });
}

export function populateUserEditorForm(form, user = {}) {
  Object.assign(form, {
    id: Number(user.id || 0),
    email: String(user.email || ''),
    display_name: String(user.display_name || ''),
    role: String(user.role || 'user'),
    status: String(user.status || 'active'),
    time_format: String(user.time_format || '24h'),
    theme: String(user.theme || 'dark'),
    theme_editor_enabled: Boolean(user.theme_editor_enabled),
    avatar_path: String(user.avatar_path || ''),
    governance_groups: normalizeUserGovernanceGroups(user),
    governance_roles: normalizeUserGovernanceRoles(user),
  });
}
