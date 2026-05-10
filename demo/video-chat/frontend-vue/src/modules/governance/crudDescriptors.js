const STATUS_OPTIONS = Object.freeze([
  { value: 'active', label_key: 'governance.status_active' },
  { value: 'archived', label_key: 'governance.status_archived' },
  { value: 'draft', label_key: 'governance.status_draft' },
  { value: 'disabled', label_key: 'governance.status_disabled' },
]);

const ACTIVE_ARCHIVED_STATUS_OPTIONS = Object.freeze(STATUS_OPTIONS.filter((option) => (
  ['active', 'archived'].includes(option.value)
)));

const GRANT_STATUS_OPTIONS = Object.freeze(STATUS_OPTIONS);

const COMPLIANCE_STATUS_OPTIONS = Object.freeze(STATUS_OPTIONS);

const PORTABILITY_STATUS_OPTIONS = Object.freeze([
  { value: 'queued', label_key: 'governance.status_queued' },
  { value: 'running', label_key: 'governance.status_running' },
  { value: 'completed', label_key: 'governance.status_completed' },
  { value: 'failed', label_key: 'governance.status_failed' },
]);

const SUBJECT_OPTIONS = Object.freeze([
  { value: 'user', label_key: 'governance.option.user' },
  { value: 'group', label_key: 'governance.option.group' },
  { value: 'organization', label_key: 'governance.option.organization' },
]);

const JOB_TYPE_OPTIONS = Object.freeze([
  { value: 'export', label_key: 'governance.option.export' },
  { value: 'import', label_key: 'governance.option.import' },
]);

const PORTABILITY_SCOPE_OPTIONS = Object.freeze([
  { value: 'organization', label_key: 'governance.option.organization' },
  { value: 'user', label_key: 'governance.option.user' },
]);

const SEVERITY_OPTIONS = Object.freeze([
  { value: 'low', label_key: 'governance.option.low' },
  { value: 'medium', label_key: 'governance.option.medium' },
  { value: 'high', label_key: 'governance.option.high' },
]);

const GOVERNANCE_KEY_VALIDATION = Object.freeze({
  max_length: 120,
  pattern: '^[A-Za-z0-9._:-]+$',
  pattern_error_key: 'governance.validation.key_format',
});

const LONG_TEXT_VALIDATION = Object.freeze({
  max_length: 2000,
});

function textField(key, labelKey, options = {}) {
  return { key, label_key: labelKey, type: 'text', required: false, ...options };
}

function enumField(key, labelKey, options, extra = {}) {
  return { key, label_key: labelKey, type: 'enum', options, required: false, ...extra };
}

function column(key, labelKey, options = {}) {
  return { key, label_key: labelKey, cell: 'text', width: '', ...options };
}

function relation(key, targetEntity, labelKey, options = {}) {
  return {
    key,
    target_entity: targetEntity,
    label_key: labelKey,
    selection_mode: 'single',
    picker: 'recursive',
    ...options,
  };
}

function mutableRowActions(permissionRoot, resourceType, labels = {}) {
  return Object.freeze([
    {
      key: `${permissionRoot}.update`,
      kind: 'edit',
      label_key: labels.edit || 'governance.edit_entity',
      icon: '/assets/orgas/kingrt/icons/gear.png',
      resource_type: resourceType,
      required_permissions: [`${permissionRoot}.update`],
    },
    {
      key: `${permissionRoot}.delete`,
      kind: 'delete',
      label_key: labels.delete || 'governance.delete_entity',
      icon: '/assets/orgas/kingrt/icons/remove_user.png',
      resource_type: resourceType,
      required_permissions: [`${permissionRoot}.delete`],
      danger: true,
    },
  ]);
}

function mutableFormActions(permissionRoot, resourceType) {
  return Object.freeze([
    {
      key: `${permissionRoot}.save`,
      kind: 'save',
      label_key: 'governance.save_entity',
      icon: '/assets/orgas/kingrt/icons/send.png',
      resource_type: resourceType,
      required_permissions: [`${permissionRoot}.update`],
    },
  ]);
}

const BASE_FIELDS = Object.freeze([
  textField('name', 'governance.name', { required: true }),
  textField('key', 'governance.key'),
  textField('description', 'governance.description', { type: 'textarea', wide: true }),
  enumField('status', 'governance.status', STATUS_OPTIONS, { default: 'active' }),
]);

const BASE_COLUMNS = Object.freeze([
  column('name', 'governance.name', { cell: 'primary', width: '22%' }),
  column('key', 'governance.key', { width: '17%' }),
  column('status', 'governance.status', { cell: 'status', width: '12%' }),
  column('description', 'governance.description', { cell: 'description', width: '29%' }),
  column('updatedAt', 'governance.updated', { cell: 'datetime', width: '14%' }),
]);

function descriptor(entityKey, config) {
  return Object.freeze({
    entity_key: entityKey,
    route_name: `admin-governance-${entityKey}`,
    resource_type: config.resource_type || entityKey,
    endpoint: config.endpoint || `/api/governance/${entityKey}`,
    readonly: config.readonly === true,
    selection_mode: config.selection_mode || 'single',
    fields: Object.freeze(config.fields || BASE_FIELDS),
    relationships: Object.freeze(config.relationships || []),
    table_columns: Object.freeze(config.table_columns || BASE_COLUMNS),
    allowed_actions: Object.freeze(config.allowed_actions || []),
    action_label_keys: Object.freeze(config.action_label_keys || {}),
    row_actions: Object.freeze(config.row_actions || []),
    form_actions: Object.freeze(config.form_actions || []),
    search_fields: Object.freeze(config.search_fields || ['name', 'key', 'description', 'status']),
  });
}

const descriptors = {
  users: descriptor('users', {
    resource_type: 'user',
    endpoint: '/api/admin/users',
    fields: Object.freeze([
      textField('display_name', 'users.display_name', { required: true }),
      textField('email', 'users.email', { required: true, input_type: 'email' }),
      enumField('status', 'users.status', STATUS_OPTIONS, { default: 'active' }),
    ]),
    relationships: Object.freeze([
      relation('groups', 'groups', 'governance.relation.groups', { selection_mode: 'multiple' }),
      relation('roles', 'roles', 'governance.relation.roles', { selection_mode: 'multiple' }),
      relation('theme', 'themes', 'governance.relation.theme'),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('users', 'user'),
    form_actions: mutableFormActions('users', 'user'),
    search_fields: Object.freeze(['display_name', 'email', 'status']),
  }),
  groups: descriptor('groups', {
    resource_type: 'group',
    fields: Object.freeze([
      textField('name', 'governance.field.group_name', { required: true }),
      textField('key', 'governance.field.group_key', { validation: GOVERNANCE_KEY_VALIDATION }),
      enumField('status', 'governance.field.group_status', ACTIVE_ARCHIVED_STATUS_OPTIONS, { default: 'active' }),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.field.group_name', { cell: 'primary', width: '44%' }),
      column('status', 'governance.field.group_status', { cell: 'status', width: '18%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '24%' }),
    ]),
    relationships: Object.freeze([
      relation('organization', 'organizations', 'governance.relation.organization'),
      relation('members', 'users', 'governance.relation.members', { selection_mode: 'multiple' }),
      relation('roles', 'roles', 'governance.relation.roles', { selection_mode: 'multiple' }),
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.create_group',
      edit: 'governance.action.edit_group',
      delete: 'governance.action.delete_group',
    }),
    row_actions: mutableRowActions('governance.groups', 'group', {
      edit: 'governance.action.edit_group',
      delete: 'governance.action.delete_group',
    }),
    form_actions: mutableFormActions('governance.groups', 'group'),
    search_fields: Object.freeze(['name', 'key', 'status']),
  }),
  organizations: descriptor('organizations', {
    resource_type: 'organization',
    fields: Object.freeze([
      textField('name', 'governance.field.organization_name', { required: true }),
      enumField('status', 'governance.field.organization_status', ACTIVE_ARCHIVED_STATUS_OPTIONS, { default: 'active' }),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.field.organization_name', { cell: 'primary', width: '44%' }),
      column('status', 'governance.field.organization_status', { cell: 'status', width: '18%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '24%' }),
    ]),
    relationships: Object.freeze([
      relation('parent_organization', 'organizations', 'governance.relation.parent_organization'),
      relation('groups', 'groups', 'governance.relation.groups', { selection_mode: 'multiple' }),
      relation('users', 'users', 'governance.relation.users', { selection_mode: 'multiple' }),
      relation('roles', 'roles', 'governance.relation.roles', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.create_organization',
      edit: 'governance.action.edit_organization',
      delete: 'governance.action.delete_organization',
    }),
    row_actions: mutableRowActions('governance.organizations', 'organization', {
      edit: 'governance.action.edit_organization',
      delete: 'governance.action.delete_organization',
    }),
    form_actions: mutableFormActions('governance.organizations', 'organization'),
    search_fields: Object.freeze(['name', 'status']),
  }),
  modules: descriptor('modules', {
    resource_type: 'module',
    readonly: true,
    endpoint: '/api/governance/module-catalog',
    fields: Object.freeze([]),
    table_columns: Object.freeze([
      column('preview_kind', 'governance.screenshot', { cell: 'module_preview', width: '28%' }),
      column('name', 'governance.field.module_name', { cell: 'primary', width: '26%' }),
      column('key', 'governance.field.module_key', { width: '24%' }),
      column('status', 'governance.status', { cell: 'status', width: '16%' }),
    ]),
    allowed_actions: Object.freeze(['inspect']),
    action_label_keys: Object.freeze({
      inspect: 'governance.action.inspect_module_catalog',
    }),
    selection_mode: 'multiple',
    relationships: Object.freeze([
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
    ]),
    search_fields: Object.freeze(['name', 'key', 'status']),
  }),
  permissions: descriptor('permissions', {
    resource_type: 'permission',
    readonly: true,
    endpoint: '/api/governance/permission-catalog',
    fields: Object.freeze([]),
    table_columns: Object.freeze([
      column('name', 'governance.field.permission_key', { cell: 'primary', width: '34%' }),
      column('module_name', 'governance.field.permission_module', { width: '24%' }),
      column('status', 'governance.status', { cell: 'status', width: '14%' }),
      column('description', 'governance.description', { cell: 'description', width: '22%' }),
    ]),
    allowed_actions: Object.freeze(['inspect']),
    action_label_keys: Object.freeze({
      inspect: 'governance.action.inspect_permission_catalog',
    }),
    selection_mode: 'multiple',
    relationships: Object.freeze([
      relation('module', 'modules', 'governance.relation.module'),
    ]),
    search_fields: Object.freeze(['name', 'key', 'module_key', 'module_name', 'status']),
  }),
  roles: descriptor('roles', {
    resource_type: 'role',
    fields: Object.freeze([
      textField('name', 'governance.field.role_name', { required: true }),
      textField('key', 'governance.field.role_key', { validation: GOVERNANCE_KEY_VALIDATION }),
      textField('description', 'governance.field.role_description', { type: 'textarea', wide: true, validation: LONG_TEXT_VALIDATION }),
      enumField('status', 'governance.field.role_status', ACTIVE_ARCHIVED_STATUS_OPTIONS, { default: 'active' }),
    ]),
    relationships: Object.freeze([
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.create_role',
      edit: 'governance.action.edit_role',
      delete: 'governance.action.delete_role',
    }),
    row_actions: mutableRowActions('governance.roles', 'role', {
      edit: 'governance.action.edit_role',
      delete: 'governance.action.delete_role',
    }),
    form_actions: mutableFormActions('governance.roles', 'role'),
  }),
  grants: descriptor('grants', {
    resource_type: 'permission_grant',
    fields: Object.freeze([
      textField('name', 'governance.field.grant_label'),
      enumField('subject_type', 'governance.field.subject_type', SUBJECT_OPTIONS, { required: true, default: 'user' }),
      enumField('status', 'governance.field.grant_status', GRANT_STATUS_OPTIONS, { default: 'active' }),
      textField('description', 'governance.field.grant_description', { type: 'textarea', wide: true, validation: LONG_TEXT_VALIDATION }),
      textField('valid_from', 'governance.field.valid_from', { input_type: 'datetime-local' }),
      textField('valid_until', 'governance.field.valid_until', { input_type: 'datetime-local' }),
    ]),
    relationships: Object.freeze([
      relation('subject', 'subjects', 'governance.relation.subject', { required: true }),
      relation('permission', 'permissions', 'governance.relation.permission', { required: true }),
      relation('resource', 'resources', 'governance.relation.resource', { required: true }),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.field.grant_label', { cell: 'primary', width: '22%' }),
      column('subject_type', 'governance.column.subject', { width: '14%' }),
      column('status', 'governance.field.grant_status', { cell: 'status', width: '12%' }),
      column('description', 'governance.field.grant_description', { cell: 'description', width: '25%' }),
      column('valid_until', 'governance.field.valid_until', { cell: 'datetime', width: '18%' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.add_grant',
      edit: 'governance.action.edit_grant',
      delete: 'governance.action.delete_grant',
    }),
    row_actions: mutableRowActions('governance.grants', 'permission_grant', {
      edit: 'governance.action.edit_grant',
      delete: 'governance.action.delete_grant',
    }),
    form_actions: mutableFormActions('governance.grants', 'permission_grant'),
    search_fields: Object.freeze(['name', 'subject_type', 'description', 'status']),
  }),
  policies: descriptor('policies', {
    resource_type: 'policy',
    fields: Object.freeze([
      textField('name', 'governance.field.policy_name', { required: true }),
      textField('key', 'governance.field.policy_key', { validation: GOVERNANCE_KEY_VALIDATION }),
      textField('description', 'governance.field.policy_description', { type: 'textarea', wide: true, validation: LONG_TEXT_VALIDATION }),
      enumField('status', 'governance.field.policy_status', ACTIVE_ARCHIVED_STATUS_OPTIONS, { default: 'active' }),
    ]),
    relationships: Object.freeze([
      relation('organizations', 'organizations', 'governance.relation.organizations', { selection_mode: 'multiple' }),
      relation('groups', 'groups', 'governance.relation.groups', { selection_mode: 'multiple' }),
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.create_policy',
      edit: 'governance.action.edit_policy',
      delete: 'governance.action.delete_policy',
    }),
    row_actions: mutableRowActions('governance.policies', 'policy', {
      edit: 'governance.action.edit_policy',
      delete: 'governance.action.delete_policy',
    }),
    form_actions: mutableFormActions('governance.policies', 'policy'),
  }),
  'audit-log': descriptor('audit-log', {
    resource_type: 'audit_log',
    readonly: true,
    endpoint: '/api/governance/audit-log',
    fields: Object.freeze([]),
    table_columns: Object.freeze([
      column('event', 'governance.column.event', { cell: 'primary', width: '25%' }),
      column('actor', 'governance.column.actor', { width: '18%' }),
      column('resource', 'governance.column.resource', { width: '18%' }),
      column('description', 'governance.description', { cell: 'description', width: '25%' }),
      column('createdAt', 'governance.column.created_at', { cell: 'datetime', width: '14%' }),
    ]),
    allowed_actions: Object.freeze(['inspect', 'export']),
    action_label_keys: Object.freeze({
      inspect: 'governance.action.inspect_audit_log',
      export: 'governance.action.export_audit_log',
    }),
    search_fields: Object.freeze(['event', 'actor', 'resource', 'description']),
  }),
  'data-portability': descriptor('data-portability', {
    resource_type: 'tenant_export_import_job',
    endpoint: '/api/governance/data-portability-jobs',
    fields: Object.freeze([
      enumField('job_type', 'governance.field.portability_job_type', JOB_TYPE_OPTIONS, { readonly: true, default: 'export' }),
      enumField('scope_type', 'governance.field.portability_scope', PORTABILITY_SCOPE_OPTIONS, { readonly: true, default: 'organization' }),
      enumField('status', 'governance.field.portability_status', PORTABILITY_STATUS_OPTIONS, { readonly: true, default: 'queued' }),
    ]),
    relationships: Object.freeze([
      relation('user', 'users', 'governance.relation.user'),
      relation('organization', 'organizations', 'governance.relation.organization'),
    ]),
    table_columns: Object.freeze([
      column('job_type', 'governance.field.portability_job_type', { cell: 'primary', width: '22%' }),
      column('scope_type', 'governance.field.portability_scope', { width: '14%' }),
      column('status', 'governance.field.portability_status', { cell: 'status', width: '12%' }),
      column('description', 'governance.description', { cell: 'description', width: '30%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '18%' }),
    ]),
    allowed_actions: Object.freeze(['export', 'import']),
    action_label_keys: Object.freeze({
      export: 'governance.action.export_tenant_data',
      import: 'governance.action.import_tenant_data',
      download_result: 'governance.action.download_export_import_result',
    }),
    row_actions: Object.freeze([
      {
        key: 'governance.data_portability.download_result',
        kind: 'export',
        label_key: 'governance.action.download_export_import_result',
        icon: '/assets/orgas/kingrt/icons/forward.png',
        resource_type: 'tenant_export_import_job',
        required_permissions: ['governance.data_portability.export'],
      },
    ]),
    search_fields: Object.freeze(['job_type', 'description', 'status']),
  }),
  compliance: descriptor('compliance', {
    resource_type: 'compliance_rule',
    fields: Object.freeze([
      textField('name', 'governance.field.compliance_rule_name', { required: true }),
      textField('key', 'governance.field.compliance_rule_key', { validation: GOVERNANCE_KEY_VALIDATION }),
      enumField('severity', 'governance.field.severity', SEVERITY_OPTIONS, { default: 'medium' }),
      enumField('status', 'governance.field.compliance_status', COMPLIANCE_STATUS_OPTIONS, { default: 'active' }),
      textField('description', 'governance.field.compliance_description', { type: 'textarea', wide: true, validation: LONG_TEXT_VALIDATION }),
    ]),
    relationships: Object.freeze([
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
      relation('policies', 'policies', 'governance.relation.policies', { selection_mode: 'multiple' }),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.field.compliance_rule_name', { cell: 'primary', width: '22%' }),
      column('severity', 'governance.field.severity', { width: '13%' }),
      column('status', 'governance.field.compliance_status', { cell: 'status', width: '12%' }),
      column('description', 'governance.field.compliance_description', { cell: 'description', width: '31%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '14%' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    action_label_keys: Object.freeze({
      create: 'governance.action.create_compliance_rule',
      edit: 'governance.action.edit_compliance_rule',
      delete: 'governance.action.delete_compliance_rule',
    }),
    row_actions: mutableRowActions('governance.compliance', 'compliance_rule', {
      edit: 'governance.action.edit_compliance_rule',
      delete: 'governance.action.delete_compliance_rule',
    }),
    form_actions: mutableFormActions('governance.compliance', 'compliance_rule'),
    search_fields: Object.freeze(['name', 'key', 'severity', 'description', 'status']),
  }),
};

export const GOVERNANCE_CRUD_DESCRIPTORS = Object.freeze(descriptors);

export function governanceEntityKeyFromRoute(routeOrName = '') {
  const routeName = typeof routeOrName === 'string' ? routeOrName : String(routeOrName?.name || '');
  const normalized = routeName.replace(/^admin-governance-/, '');
  if (Object.prototype.hasOwnProperty.call(GOVERNANCE_CRUD_DESCRIPTORS, normalized)) {
    return normalized;
  }
  const path = typeof routeOrName === 'object' ? String(routeOrName?.path || routeOrName?.fullPath || '') : '';
  return path.split('/').filter(Boolean).pop() || '';
}

export function governanceCrudDescriptorForRoute(routeOrName = '') {
  return GOVERNANCE_CRUD_DESCRIPTORS[governanceEntityKeyFromRoute(routeOrName)] || null;
}

export function descriptorAllowsAction(descriptor, actionKind) {
  return Array.isArray(descriptor?.allowed_actions) && descriptor.allowed_actions.includes(actionKind);
}
