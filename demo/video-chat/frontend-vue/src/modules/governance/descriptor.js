const governanceRoutes = [
  ['users', 'Nutzer', 'Nutzer', 'Nutzer', 'modules/users/pages/admin/UsersView.vue'],
  ['groups', 'Gruppen', 'Gruppe', 'Gruppen', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['organizations', 'Organisationen', 'Organisation', 'Organisationen', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['modules', 'Module', 'Modul', 'Module', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['permissions', 'Rechte', 'Recht', 'Rechte', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['roles', 'Rollen', 'Rolle', 'Rollen', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['grants', 'Freigaben', 'Freigabe', 'Freigaben', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['policies', 'Richtlinien', 'Richtlinie', 'Richtlinien', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['audit-log', 'Audit Log', 'Audit Entry', 'Audit Entries', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['data-portability', 'Export / Import', 'Export / Import Job', 'Export / Import Jobs', 'modules/governance/pages/GovernanceCrudView.vue'],
  ['compliance', 'Compliance', 'Compliance Rule', 'Compliance Rules', 'modules/governance/pages/GovernanceCrudView.vue'],
];

const governanceLabelKeys = {
  users: 'navigation.governance.users',
  groups: 'navigation.governance.groups',
  organizations: 'navigation.governance.organizations',
  modules: 'navigation.governance.modules',
  permissions: 'navigation.governance.permissions',
  roles: 'navigation.governance.roles',
  grants: 'navigation.governance.grants',
  policies: 'navigation.governance.policies',
  'audit-log': 'navigation.governance.audit_log',
  'data-portability': 'navigation.governance.data_portability',
  compliance: 'navigation.governance.compliance',
};

function governanceLoader(sourcePath) {
  if (sourcePath === 'modules/users/pages/admin/UsersView.vue') {
    return () => import('../users/pages/admin/UsersView.vue');
  }
  return () => import('./pages/GovernanceCrudView.vue');
}

export default {
  module_key: 'governance',
  version: '0.1.0',
  permissions: ['governance.read'],
  routes: governanceRoutes.map(([slug, pageTitle, entitySingular, entityPlural, sourcePath]) => ({
    path: `/admin/governance/${slug}`,
    name: `admin-governance-${slug}`,
    roles: ['admin'],
    pageTitle,
    entitySingular,
    entityPlural,
    source_path: sourcePath,
    loader: governanceLoader(sourcePath),
  })),
  navigation: governanceRoutes.map(([slug, label], index) => ({
    group: 'governance',
    to: `/admin/governance/${slug}`,
    label,
    label_key: governanceLabelKeys[slug] || '',
    order: (index + 1) * 10,
    roles: ['admin'],
  })),
  settings_panels: [],
  i18n_namespaces: ['governance'],
};
