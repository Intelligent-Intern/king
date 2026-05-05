const STATUS_OPTIONS = Object.freeze([
  { value: 'active', label_key: 'governance.status_active' },
  { value: 'archived', label_key: 'governance.status_archived' },
  { value: 'draft', label_key: 'governance.status_draft' },
  { value: 'disabled', label_key: 'governance.status_disabled' },
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

const SEVERITY_OPTIONS = Object.freeze([
  { value: 'low', label_key: 'governance.option.low' },
  { value: 'medium', label_key: 'governance.option.medium' },
  { value: 'high', label_key: 'governance.option.high' },
]);

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

function mutableRowActions(permissionRoot, resourceType) {
  return Object.freeze([
    {
      key: `${permissionRoot}.update`,
      kind: 'edit',
      label_key: 'governance.edit_entity',
      icon: '/assets/orgas/kingrt/icons/gear.png',
      resource_type: resourceType,
      required_permissions: [`${permissionRoot}.update`],
    },
    {
      key: `${permissionRoot}.delete`,
      kind: 'delete',
      label_key: 'governance.delete_entity',
      icon: '/assets/orgas/kingrt/icons/remove_user.png',
      resource_type: resourceType,
      required_permissions: [`${permissionRoot}.delete`],
      danger: true,
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
    row_actions: Object.freeze(config.row_actions || []),
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
    search_fields: Object.freeze(['display_name', 'email', 'status']),
  }),
  groups: descriptor('groups', {
    resource_type: 'group',
    relationships: Object.freeze([
      relation('organization', 'organizations', 'governance.relation.organization'),
      relation('members', 'users', 'governance.relation.members', { selection_mode: 'multiple' }),
      relation('roles', 'roles', 'governance.relation.roles', { selection_mode: 'multiple' }),
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.groups', 'group'),
  }),
  organizations: descriptor('organizations', {
    resource_type: 'organization',
    relationships: Object.freeze([
      relation('parent_organization', 'organizations', 'governance.relation.parent_organization'),
      relation('groups', 'groups', 'governance.relation.groups', { selection_mode: 'multiple' }),
      relation('users', 'users', 'governance.relation.users', { selection_mode: 'multiple' }),
      relation('roles', 'roles', 'governance.relation.roles', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.organizations', 'organization'),
  }),
  modules: descriptor('modules', {
    resource_type: 'module',
    readonly: true,
    endpoint: '/api/governance/module-catalog',
    allowed_actions: Object.freeze(['inspect']),
    selection_mode: 'multiple',
  }),
  permissions: descriptor('permissions', {
    resource_type: 'permission',
    readonly: true,
    endpoint: '/api/governance/permission-catalog',
    allowed_actions: Object.freeze(['inspect']),
    selection_mode: 'multiple',
    relationships: Object.freeze([
      relation('module', 'modules', 'governance.relation.module'),
    ]),
  }),
  roles: descriptor('roles', {
    resource_type: 'role',
    relationships: Object.freeze([
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.roles', 'role'),
  }),
  grants: descriptor('grants', {
    resource_type: 'permission_grant',
    fields: Object.freeze([
      textField('name', 'governance.name', { required: true }),
      enumField('subject_type', 'governance.field.subject_type', SUBJECT_OPTIONS, { default: 'user' }),
      enumField('status', 'governance.status', STATUS_OPTIONS, { default: 'active' }),
      textField('description', 'governance.description', { type: 'textarea', wide: true }),
      textField('valid_from', 'governance.field.valid_from', { input_type: 'datetime-local' }),
      textField('valid_until', 'governance.field.valid_until', { input_type: 'datetime-local' }),
    ]),
    relationships: Object.freeze([
      relation('subject', 'subjects', 'governance.relation.subject'),
      relation('permission', 'permissions', 'governance.relation.permission'),
      relation('resource', 'resources', 'governance.relation.resource'),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.name', { cell: 'primary', width: '22%' }),
      column('subject_type', 'governance.column.subject', { width: '14%' }),
      column('status', 'governance.status', { cell: 'status', width: '12%' }),
      column('description', 'governance.description', { cell: 'description', width: '25%' }),
      column('valid_until', 'governance.field.valid_until', { cell: 'datetime', width: '18%' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.grants', 'permission_grant'),
    search_fields: Object.freeze(['name', 'subject_type', 'description', 'status']),
  }),
  policies: descriptor('policies', {
    resource_type: 'policy',
    relationships: Object.freeze([
      relation('organizations', 'organizations', 'governance.relation.organizations', { selection_mode: 'multiple' }),
      relation('groups', 'groups', 'governance.relation.groups', { selection_mode: 'multiple' }),
      relation('permissions', 'permissions', 'governance.relation.permissions', { selection_mode: 'multiple' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.policies', 'policy'),
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
    search_fields: Object.freeze(['event', 'actor', 'resource', 'description']),
  }),
  'data-portability': descriptor('data-portability', {
    resource_type: 'tenant_export_import_job',
    endpoint: '/api/governance/data-portability-jobs',
    fields: Object.freeze([
      enumField('job_type', 'governance.field.job_type', JOB_TYPE_OPTIONS, { required: true, default: 'export' }),
      enumField('status', 'governance.status', STATUS_OPTIONS, { default: 'draft' }),
      textField('description', 'governance.description', { type: 'textarea', wide: true }),
    ]),
    relationships: Object.freeze([
      relation('user', 'users', 'governance.relation.user'),
      relation('organization', 'organizations', 'governance.relation.organization'),
    ]),
    table_columns: Object.freeze([
      column('job_type', 'governance.field.job_type', { cell: 'primary', width: '22%' }),
      column('status', 'governance.status', { cell: 'status', width: '12%' }),
      column('description', 'governance.description', { cell: 'description', width: '38%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '18%' }),
    ]),
    allowed_actions: Object.freeze(['export', 'import']),
    search_fields: Object.freeze(['job_type', 'description', 'status']),
  }),
  compliance: descriptor('compliance', {
    resource_type: 'compliance_rule',
    fields: Object.freeze([
      textField('name', 'governance.name', { required: true }),
      textField('key', 'governance.key'),
      enumField('severity', 'governance.field.severity', SEVERITY_OPTIONS, { default: 'medium' }),
      enumField('status', 'governance.status', STATUS_OPTIONS, { default: 'active' }),
      textField('description', 'governance.description', { type: 'textarea', wide: true }),
    ]),
    relationships: Object.freeze([
      relation('modules', 'modules', 'governance.relation.modules', { selection_mode: 'multiple' }),
      relation('policies', 'policies', 'governance.relation.policies', { selection_mode: 'multiple' }),
    ]),
    table_columns: Object.freeze([
      column('name', 'governance.name', { cell: 'primary', width: '22%' }),
      column('severity', 'governance.field.severity', { width: '13%' }),
      column('status', 'governance.status', { cell: 'status', width: '12%' }),
      column('description', 'governance.description', { cell: 'description', width: '31%' }),
      column('updatedAt', 'governance.updated', { cell: 'datetime', width: '14%' }),
    ]),
    allowed_actions: Object.freeze(['create', 'edit', 'delete']),
    row_actions: mutableRowActions('governance.compliance', 'compliance_rule'),
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
