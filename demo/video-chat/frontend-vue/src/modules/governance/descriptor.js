const governanceRoutes = [
  ['users', 'Nutzer', 'Nutzer', 'Nutzer', 'domain/users/admin/UsersView.vue'],
  ['groups', 'Gruppen', 'Gruppe', 'Gruppen', 'domain/governance/GovernanceCrudView.vue'],
  ['organizations', 'Organisationen', 'Organisation', 'Organisationen', 'domain/governance/GovernanceCrudView.vue'],
  ['modules', 'Module', 'Modul', 'Module', 'domain/governance/GovernanceCrudView.vue'],
  ['permissions', 'Rechte', 'Recht', 'Rechte', 'domain/governance/GovernanceCrudView.vue'],
  ['roles', 'Rollen', 'Rolle', 'Rollen', 'domain/governance/GovernanceCrudView.vue'],
  ['grants', 'Freigaben', 'Freigabe', 'Freigaben', 'domain/governance/GovernanceCrudView.vue'],
  ['policies', 'Richtlinien', 'Richtlinie', 'Richtlinien', 'domain/governance/GovernanceCrudView.vue'],
  ['audit-log', 'Audit Log', 'Audit Entry', 'Audit Entries', 'domain/governance/GovernanceCrudView.vue'],
  ['data-portability', 'Export / Import', 'Export / Import Job', 'Export / Import Jobs', 'domain/governance/GovernanceCrudView.vue'],
  ['compliance', 'Compliance', 'Compliance Rule', 'Compliance Rules', 'domain/governance/GovernanceCrudView.vue'],
];

function governanceLoader(sourcePath) {
  if (sourcePath === 'domain/users/admin/UsersView.vue') {
    return () => import('../../domain/users/admin/UsersView.vue');
  }
  return () => import('../../domain/governance/GovernanceCrudView.vue');
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
    order: (index + 1) * 10,
    roles: ['admin'],
  })),
  settings_panels: [],
  i18n_namespaces: ['governance'],
};
