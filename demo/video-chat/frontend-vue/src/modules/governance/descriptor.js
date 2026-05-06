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

const governanceEntityKeys = {
  users: ['governance.entity.user', 'navigation.governance.users'],
  groups: ['governance.entity.group', 'navigation.governance.groups'],
  organizations: ['governance.entity.organization', 'navigation.governance.organizations'],
  modules: ['governance.entity.module', 'navigation.governance.modules'],
  permissions: ['governance.entity.permission', 'navigation.governance.permissions'],
  roles: ['governance.entity.role', 'navigation.governance.roles'],
  grants: ['governance.entity.grant', 'navigation.governance.grants'],
  policies: ['governance.entity.policy', 'navigation.governance.policies'],
  'audit-log': ['governance.entity.audit_entry', 'governance.entity.audit_entries'],
  'data-portability': ['governance.entity.export_import_job', 'governance.entity.export_import_jobs'],
  compliance: ['governance.entity.compliance_rule', 'governance.entity.compliance_rules'],
};

const governanceReadonlyReasons = {
  modules: 'governance.readonly.system_catalog',
  permissions: 'governance.readonly.system_catalog',
};

const governanceActions = {
  users: [
    {
      key: 'governance.users.create',
      label_key: 'users.new_user',
      kind: 'create',
      resource_type: 'user',
      required_permissions: ['users.create'],
    },
  ],
  groups: [
    {
      key: 'governance.groups.create',
      label_key: 'governance.action.create_group',
      kind: 'create',
      resource_type: 'group',
      required_permissions: ['governance.groups.create'],
    },
  ],
  organizations: [
    {
      key: 'governance.organizations.create',
      label_key: 'governance.action.create_organization',
      kind: 'create',
      resource_type: 'organization',
      required_permissions: ['governance.organizations.create'],
    },
  ],
  modules: [
    {
      key: 'governance.modules.inspect',
      label_key: 'governance.action.inspect_catalog',
      kind: 'inspect',
      resource_type: 'module',
      required_permissions: ['governance.read'],
      readonly_reason_key: 'governance.readonly.system_catalog',
    },
  ],
  permissions: [
    {
      key: 'governance.permissions.inspect',
      label_key: 'governance.action.inspect_catalog',
      kind: 'inspect',
      resource_type: 'permission',
      required_permissions: ['governance.read'],
      readonly_reason_key: 'governance.readonly.system_catalog',
    },
  ],
  roles: [
    {
      key: 'governance.roles.create',
      label_key: 'governance.action.create_role',
      kind: 'create',
      resource_type: 'role',
      required_permissions: ['governance.roles.create'],
    },
  ],
  grants: [
    {
      key: 'governance.grants.create',
      label_key: 'governance.action.add_grant',
      kind: 'create',
      resource_type: 'permission_grant',
      required_permissions: ['governance.grants.create'],
    },
  ],
  policies: [
    {
      key: 'governance.policies.create',
      label_key: 'governance.action.create_policy',
      kind: 'create',
      resource_type: 'policy',
      required_permissions: ['governance.policies.create'],
    },
  ],
  'audit-log': [
    {
      key: 'governance.audit_log.inspect',
      label_key: 'governance.action.inspect_audit_log',
      kind: 'inspect',
      resource_type: 'audit_log',
      required_permissions: ['governance.audit_log.read'],
    },
    {
      key: 'governance.audit_log.export',
      label_key: 'governance.action.export_audit_log',
      kind: 'export',
      resource_type: 'audit_log',
      required_permissions: ['governance.audit_log.export'],
    },
  ],
  'data-portability': [
    {
      key: 'governance.data_portability.export',
      label_key: 'governance.action.export_data',
      kind: 'export',
      resource_type: 'tenant_export_job',
      required_permissions: ['governance.data_portability.export'],
    },
    {
      key: 'governance.data_portability.import',
      label_key: 'governance.action.import_data',
      kind: 'import',
      resource_type: 'tenant_import_job',
      required_permissions: ['governance.data_portability.import'],
    },
  ],
  compliance: [
    {
      key: 'governance.compliance.create',
      label_key: 'governance.action.create_compliance_rule',
      kind: 'create',
      resource_type: 'compliance_rule',
      required_permissions: ['governance.compliance.create'],
    },
  ],
};

function tourAction(slug) {
  return {
    key: `governance.${slug}.tour`,
    label_key: 'onboarding.take_the_tour',
    kind: 'tour',
    resource_type: `governance.${slug}`,
    required_permissions: ['governance.read'],
  };
}

function governanceRouteActions(slug) {
  return [...(governanceActions[slug] || []), tourAction(slug)];
}

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
    pageTitle_key: governanceLabelKeys[slug] || '',
    entitySingular,
    entitySingular_key: governanceEntityKeys[slug]?.[0] || '',
    entityPlural,
    entityPlural_key: governanceEntityKeys[slug]?.[1] || '',
    readonly_reason_key: governanceReadonlyReasons[slug] || '',
    actions: governanceRouteActions(slug),
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
