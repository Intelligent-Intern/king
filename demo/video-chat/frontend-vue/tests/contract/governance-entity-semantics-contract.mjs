import assert from 'node:assert/strict';
import { GOVERNANCE_CRUD_DESCRIPTORS } from '../../src/modules/governance/crudDescriptors.js';
import { validateGovernanceCrudSubmission } from '../../src/modules/governance/governanceEntityValidation.js';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';
import { workspaceModuleRouteRecords } from '../../src/modules/index.js';

function t(key, params = {}) {
  const template = ENGLISH_MESSAGES[key] || key;
  return template.replace(/\{([^}]+)\}/g, (_, name) => String(params[name] ?? ''));
}

function fieldKeys(entity) {
  return GOVERNANCE_CRUD_DESCRIPTORS[entity].fields.map((field) => field.key);
}

function field(entity, key) {
  const found = GOVERNANCE_CRUD_DESCRIPTORS[entity].fields.find((candidate) => candidate.key === key);
  assert.ok(found, `${entity}.${key} field missing`);
  return found;
}

function relationship(entity, key) {
  const found = GOVERNANCE_CRUD_DESCRIPTORS[entity].relationships.find((candidate) => candidate.key === key);
  assert.ok(found, `${entity}.${key} relationship missing`);
  return found;
}

function labelKeysFor(entity) {
  const descriptor = GOVERNANCE_CRUD_DESCRIPTORS[entity];
  return [
    ...descriptor.fields.map((item) => item.label_key),
    ...descriptor.table_columns.map((item) => item.label_key),
    ...descriptor.relationships.map((item) => item.label_key),
    ...descriptor.row_actions.map((item) => item.label_key),
    ...Object.values(descriptor.action_label_keys),
  ].filter(Boolean);
}

function routeByName(name) {
  const route = workspaceModuleRouteRecords.find((candidate) => candidate.name === name);
  assert.ok(route, `${name} route missing`);
  return route;
}

assert.deepEqual(fieldKeys('groups'), ['name', 'key', 'status'], 'groups must expose persisted group fields only');
assert.deepEqual(fieldKeys('organizations'), ['name', 'status'], 'organizations must expose persisted organization fields only');
assert.deepEqual(fieldKeys('roles'), ['name', 'key', 'description', 'status'], 'roles must expose role-specific key and description fields');
assert.deepEqual(fieldKeys('grants'), ['name', 'subject_type', 'status', 'description', 'valid_from', 'valid_until'], 'grants must expose grant label, subject, lifecycle, and time-window fields');
assert.deepEqual(fieldKeys('policies'), ['name', 'key', 'description', 'status'], 'policies must expose policy-specific key and description fields');
assert.deepEqual(fieldKeys('data-portability'), ['job_type', 'scope_type', 'status'], 'export/import jobs must expose job status fields instead of generic CRUD fields');
assert.deepEqual(fieldKeys('compliance'), ['name', 'key', 'severity', 'status', 'description'], 'compliance must expose rule-specific severity and lifecycle fields');
assert.deepEqual(fieldKeys('modules'), [], 'module catalog must not expose mutable form fields');
assert.deepEqual(fieldKeys('permissions'), [], 'permission catalog must not expose mutable form fields');
assert.deepEqual(fieldKeys('audit-log'), [], 'audit log must not expose mutable form fields');

assert.equal(field('groups', 'name').label_key, 'governance.field.group_name');
assert.equal(field('groups', 'key').label_key, 'governance.field.group_key');
assert.equal(field('organizations', 'name').label_key, 'governance.field.organization_name');
assert.equal(field('roles', 'key').validation.pattern_error_key, 'governance.validation.key_format');
assert.equal(field('policies', 'description').validation.max_length, 2000);
assert.equal(field('compliance', 'severity').label_key, 'governance.field.severity');
assert.equal(relationship('grants', 'subject').required, true, 'grant subject relation must be required');
assert.equal(relationship('grants', 'permission').required, true, 'grant permission relation must be required');
assert.equal(relationship('grants', 'resource').required, true, 'grant resource relation must be required');

for (const entity of ['modules', 'permissions', 'audit-log']) {
  assert.equal(GOVERNANCE_CRUD_DESCRIPTORS[entity].readonly, true, `${entity} must be readonly`);
  assert.equal(GOVERNANCE_CRUD_DESCRIPTORS[entity].allowed_actions.includes('create'), false, `${entity} must not have create semantics`);
}

for (const entity of ['groups', 'organizations', 'roles', 'grants', 'policies', 'compliance']) {
  const labels = GOVERNANCE_CRUD_DESCRIPTORS[entity].row_actions.map((action) => action.label_key);
  assert.ok(labels.every((key) => key.startsWith('governance.action.')), `${entity} row actions must use entity action names`);
  assert.equal(labels.includes('governance.edit_entity'), false, `${entity} must not fall back to generic edit labels`);
  assert.equal(labels.includes('governance.delete_entity'), false, `${entity} must not fall back to generic delete labels`);
}

assert.equal(GOVERNANCE_CRUD_DESCRIPTORS['data-portability'].row_actions[0].label_key, 'governance.action.download_export_import_result');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.modules.action_label_keys.inspect, 'governance.action.inspect_module_catalog');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.permissions.action_label_keys.inspect, 'governance.action.inspect_permission_catalog');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS['audit-log'].action_label_keys.export, 'governance.action.export_audit_log');

const modulesInspectAction = routeByName('admin-governance-modules').meta.actions.find((action) => action.kind === 'inspect');
assert.equal(modulesInspectAction?.label_key, 'governance.action.inspect_module_catalog', 'module route action must name the module catalog');
const portabilityActions = routeByName('admin-governance-data-portability').meta.actions.filter((action) => ['export', 'import'].includes(action.kind));
assert.deepEqual(
  portabilityActions.map((action) => [action.kind, action.label_key, action.resource_type]),
  [
    ['export', 'governance.action.export_tenant_data', 'tenant_export_import_job'],
    ['import', 'governance.action.import_tenant_data', 'tenant_export_import_job'],
  ],
  'export/import route actions must use tenant portability resource semantics',
);

let validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.groups.fields,
  form: { status: 'active' },
  translate: t,
});
assert.equal(validation.ok, false, 'group name must be required');
assert.equal(validation.message, 'Group name is required.');

validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.groups.fields,
  form: { name: 'Finance', status: 'draft' },
  translate: t,
});
assert.equal(validation.error_key, 'governance.validation.option_invalid', 'groups must reject draft status');

validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.roles.fields,
  form: { name: 'Approver', key: 'invalid key', status: 'active' },
  translate: t,
});
assert.equal(validation.error_key, 'governance.validation.key_format', 'role key must use governance key syntax');

validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.policies.fields,
  form: { name: 'Retention', key: 'retention', description: 'x'.repeat(2001), status: 'active' },
  translate: t,
});
assert.equal(validation.error_key, 'governance.validation.field_too_long', 'policy description must enforce contract length');

validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.grants.fields,
  relationships: GOVERNANCE_CRUD_DESCRIPTORS.grants.relationships,
  form: { subject_type: 'user', status: 'active' },
  relationSelections: {},
  translate: t,
});
assert.equal(validation.relationship_key, 'subject', 'grant subject relation must be required before save');

const completeGrantRelations = {
  subject: [{ id: '1', entity_key: 'users' }],
  permission: [{ id: 'permission:governance:governance.read', entity_key: 'permissions' }],
  resource: [{ id: '*', entity_key: 'organizations' }],
};
validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.grants.fields,
  relationships: GOVERNANCE_CRUD_DESCRIPTORS.grants.relationships,
  form: {
    subject_type: 'user',
    status: 'active',
    valid_from: '2026-05-10T12:00',
    valid_until: '2026-05-10T11:00',
  },
  relationSelections: completeGrantRelations,
  translate: t,
});
assert.equal(validation.error_key, 'governance.validation.valid_until_after_from', 'grant end time must be after start time');

validation = validateGovernanceCrudSubmission({
  fields: GOVERNANCE_CRUD_DESCRIPTORS.grants.fields,
  relationships: GOVERNANCE_CRUD_DESCRIPTORS.grants.relationships,
  form: {
    subject_type: 'user',
    status: 'active',
    valid_from: '2026-05-10T11:00',
    valid_until: '2026-05-10T12:00',
  },
  relationSelections: completeGrantRelations,
  translate: t,
});
assert.equal(validation.ok, true, 'complete grant semantics must validate');

for (const entity of Object.keys(GOVERNANCE_CRUD_DESCRIPTORS)) {
  for (const key of labelKeysFor(entity)) {
    assert.ok(ENGLISH_MESSAGES[key], `${entity} label key missing English copy: ${key}`);
  }
}

console.log('[governance-entity-semantics-contract] PASS');
