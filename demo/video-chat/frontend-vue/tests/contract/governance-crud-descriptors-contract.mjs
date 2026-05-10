import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  GOVERNANCE_CRUD_DESCRIPTORS,
  descriptorAllowsAction,
  governanceCrudDescriptorForRoute,
} from '../../src/modules/governance/crudDescriptors.js';
import {
  actionBarLabel,
  descriptorActionsForContext,
  descriptorPageActions,
  descriptorSubmitActionForMode,
} from '../../src/modules/actionBars.js';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';
import { workspaceModuleRouteRecords } from '../../src/modules/index.js';
import { routeActionsForContext } from '../../src/modules/routeActions.js';
import { sessionPermissionKeys } from '../../src/http/routeAccess.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const expectedEntities = [
  'users',
  'groups',
  'organizations',
  'modules',
  'permissions',
  'roles',
  'grants',
  'policies',
  'audit-log',
  'data-portability',
  'compliance',
];

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

function collectLabelKeys(value, output = new Set()) {
  if (Array.isArray(value)) {
    for (const item of value) collectLabelKeys(item, output);
    return output;
  }
  if (!value || typeof value !== 'object') return output;
  for (const [key, child] of Object.entries(value)) {
    if (key === 'label_key') output.add(String(child || '').trim());
    collectLabelKeys(child, output);
  }
  return output;
}

function routeByName(name) {
  const record = workspaceModuleRouteRecords.find((candidate) => candidate.name === name);
  assert.ok(record, `${name} route missing`);
  return record;
}

function translate(key, params = {}) {
  let value = ENGLISH_MESSAGES[key] || key;
  for (const [paramKey, paramValue] of Object.entries(params)) {
    value = value.replaceAll(`{${paramKey}}`, String(paramValue));
  }
  return value;
}

for (const entity of expectedEntities) {
  const descriptor = GOVERNANCE_CRUD_DESCRIPTORS[entity];
  assert.ok(descriptor, `${entity} descriptor missing`);
  assert.equal(descriptor.entity_key, entity, `${entity} descriptor must carry entity_key`);
  assert.ok(descriptor.resource_type, `${entity} descriptor must carry resource_type`);
  assert.ok(descriptor.endpoint.startsWith('/api/'), `${entity} endpoint must be API-backed`);
  assert.ok(Array.isArray(descriptor.fields), `${entity} fields must be an array`);
  assert.ok(Array.isArray(descriptor.relationships), `${entity} relationships must be an array`);
  assert.ok(Array.isArray(descriptor.table_columns), `${entity} table_columns must be an array`);
  assert.ok(Array.isArray(descriptor.allowed_actions), `${entity} allowed_actions must be an array`);
  assert.ok(descriptor.table_columns.length > 0, `${entity} must expose table columns`);
  assert.ok(Array.isArray(descriptor.form_actions), `${entity} form_actions must be an array`);
  assert.ok(
    descriptor.table_columns.every((column) => column.key && column.label_key),
    `${entity} table columns must be keyed and localized`,
  );
  assert.ok(
    descriptor.relationships.every((relation) => (
      relation.key && relation.target_entity && relation.label_key && relation.picker === 'recursive'
    )),
    `${entity} relationships must be recursive picker descriptors`,
  );
}

for (const entity of ['modules', 'permissions', 'audit-log']) {
  assert.equal(GOVERNANCE_CRUD_DESCRIPTORS[entity].readonly, true, `${entity} must be readonly`);
  assert.equal(descriptorAllowsAction(GOVERNANCE_CRUD_DESCRIPTORS[entity], 'create'), false, `${entity} must not allow create`);
  assert.equal(GOVERNANCE_CRUD_DESCRIPTORS[entity].row_actions.length, 0, `${entity} must not expose edit/delete row actions`);
  assert.equal(GOVERNANCE_CRUD_DESCRIPTORS[entity].form_actions.length, 0, `${entity} must not expose mutable save actions`);
}

assert.ok(
  GOVERNANCE_CRUD_DESCRIPTORS.modules.relationships.some((relation) => (
    relation.key === 'permissions' && relation.target_entity === 'permissions'
  )),
  'module assignment must expose nested module permissions',
);
assert.equal(
  GOVERNANCE_CRUD_DESCRIPTORS.groups.relationships.some((relation) => relation.key === 'permissions'),
  false,
  'groups must receive permissions through their selected modules instead of a detached permission relation',
);
assert.ok(
  GOVERNANCE_CRUD_DESCRIPTORS.groups.fields.some((field) => field.key === 'key'),
  'group create-in-place must expose the backend group key field for recursive relation drafts',
);

for (const entity of ['groups', 'organizations', 'roles', 'grants', 'policies', 'compliance']) {
  const descriptor = GOVERNANCE_CRUD_DESCRIPTORS[entity];
  assert.equal(descriptorAllowsAction(descriptor, 'create'), true, `${entity} must allow create`);
  assert.deepEqual(
    descriptor.row_actions.map((action) => action.kind).sort(),
    ['delete', 'edit'],
    `${entity} must expose icon row edit/delete actions`,
  );
  assert.ok(
    descriptor.row_actions.every((action) => action.label_key && action.icon && action.required_permissions.length > 0),
    `${entity} row actions must be localized, icon-based, and permission-bound`,
  );
  assert.deepEqual(
    descriptor.form_actions.map((action) => action.kind),
    ['save'],
    `${entity} must expose a descriptor-backed save form action`,
  );
  assert.ok(
    descriptor.form_actions.every((action) => (
      action.label_key === 'governance.save_entity'
      && action.icon
      && action.required_permissions.some((permission) => permission.endsWith('.update'))
    )),
    `${entity} save actions must be localized, icon-based, and update-permission-bound`,
  );
}

const groupsRoute = routeByName('admin-governance-groups');
assert.deepEqual(
  descriptorPageActions(
    GOVERNANCE_CRUD_DESCRIPTORS.groups,
    routeActionsForContext(groupsRoute, { role: 'admin', permissions: ['governance.read'] }),
  ),
  [],
  'descriptor page actions must hide group creation without create permission',
);
const groupPageActions = descriptorPageActions(
  GOVERNANCE_CRUD_DESCRIPTORS.groups,
  routeActionsForContext(groupsRoute, {
    role: 'admin',
    permissions: ['governance.read', 'governance.groups.create'],
  }),
);
assert.deepEqual(groupPageActions.map((action) => action.kind), ['create'], 'group page action bar must expose create from descriptors');
assert.equal(
  actionBarLabel(groupPageActions[0], translate, '', { entity: ENGLISH_MESSAGES['governance.entity.group'] }),
  'Create group',
  'group create action must keep its entity-local label',
);

const groupSaveActions = descriptorActionsForContext(
  GOVERNANCE_CRUD_DESCRIPTORS.groups.form_actions,
  { role: 'admin', permissions: ['governance.groups.update'] },
);
const groupSaveAction = descriptorSubmitActionForMode('edit', groupPageActions, groupSaveActions);
assert.equal(groupSaveAction?.kind, 'save', 'edit forms must resolve to descriptor-backed save actions');
assert.equal(
  actionBarLabel(groupSaveAction, translate, '', { entity: ENGLISH_MESSAGES['governance.entity.group'] }),
  'Save Group',
  'edit save action must use an entity-local label',
);
assert.equal(
  descriptorSubmitActionForMode('edit', groupPageActions, descriptorActionsForContext(
    GOVERNANCE_CRUD_DESCRIPTORS.groups.form_actions,
    { role: 'admin', permissions: ['governance.groups.create'] },
  )),
  null,
  'edit save action must be hidden without update permission',
);

const portabilityRoute = routeByName('admin-governance-data-portability');
const portabilityActions = descriptorPageActions(
  GOVERNANCE_CRUD_DESCRIPTORS['data-portability'],
  routeActionsForContext(portabilityRoute, {
    role: 'admin',
    permissions: ['governance.data_portability.export', 'governance.data_portability.import'],
  }),
);
assert.deepEqual(
  portabilityActions.map((action) => [action.kind, actionBarLabel(action, translate)]),
  [['export', 'Export tenant data'], ['import', 'Import tenant data']],
  'data portability page action bar must expose locally named export/import actions',
);

assert.equal(
  governanceCrudDescriptorForRoute({ name: 'admin-governance-data-portability' })?.entity_key,
  'data-portability',
  'route names must resolve to CRUD descriptors',
);
assert.deepEqual(
  GOVERNANCE_CRUD_DESCRIPTORS['data-portability'].row_actions.map((action) => action.kind),
  ['export'],
  'data portability rows must expose an explicit result download action',
);
assert.equal(
  governanceCrudDescriptorForRoute({ path: '/admin/governance/audit-log' })?.entity_key,
  'audit-log',
  'route paths must resolve to CRUD descriptors',
);

const tenantAdminPermissions = sessionPermissionKeys({ tenant_admin: true });
for (const permission of [
  'governance.groups.update',
  'governance.groups.delete',
  'governance.organizations.update',
  'governance.organizations.delete',
  'governance.roles.update',
  'governance.roles.delete',
  'governance.grants.update',
  'governance.grants.delete',
  'governance.policies.update',
  'governance.policies.delete',
  'governance.compliance.update',
  'governance.compliance.delete',
]) {
  assert.ok(tenantAdminPermissions.includes(permission), `tenant_admin must imply ${permission}`);
}

for (const labelKey of collectLabelKeys(GOVERNANCE_CRUD_DESCRIPTORS)) {
  if (labelKey === '') continue;
  assert.ok(ENGLISH_MESSAGES[labelKey], `descriptor label key missing from English messages: ${labelKey}`);
}

const viewSource = await source('src/modules/governance/pages/GovernanceCrudView.vue');
assert.match(viewSource, /governanceCrudDescriptorForRoute/, 'governance CRUD view must resolve entity descriptors');
assert.match(viewSource, /v-for="column in tableColumns"/, 'governance table must render descriptor columns');
assert.match(viewSource, /rowActions/, 'governance row actions must come from descriptors');
assert.match(viewSource, /entryAllowsAccess/, 'governance row actions must be permission-gated');
assert.doesNotMatch(viewSource, /<th>\{\{\s*t\('governance\.name'\)/, 'governance table headers must not be hardcoded');

const modalSource = await source('src/modules/governance/pages/GovernanceCrudModal.vue');
assert.match(modalSource, /v-for="field in fields"/, 'governance modal must render descriptor fields');
assert.doesNotMatch(modalSource, /<span>\{\{\s*t\('governance\.name'\)/, 'governance modal fields must not be hardcoded');

console.log('[governance-crud-descriptors-contract] PASS');
