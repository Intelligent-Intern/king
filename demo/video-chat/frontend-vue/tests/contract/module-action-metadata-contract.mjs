import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';
import { workspaceModuleRouteRecords } from '../../src/modules/index.js';
import {
  firstRouteActionByKind,
  routeActionLabel,
  routeActionsForContext,
} from '../../src/modules/routeActions.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const ACTION_KINDS = new Set(['create', 'edit', 'delete', 'import', 'export', 'configure', 'inspect', 'tour', 'custom']);

function routeByName(name) {
  const record = workspaceModuleRouteRecords.find((candidate) => candidate.name === name);
  assert.ok(record, `${name} route missing`);
  return record;
}

function actionKeys(record) {
  return new Set(record.meta.actions.map((action) => action.key));
}

function actionKinds(record) {
  return new Set(record.meta.actions.map((action) => action.kind));
}

const adminGovernanceRoutes = workspaceModuleRouteRecords.filter((record) => (
  record.name.startsWith('admin-administration-')
  || record.name.startsWith('admin-governance-')
));

assert.ok(adminGovernanceRoutes.length > 0, 'admin/governance routes must be descriptor-built');

for (const record of adminGovernanceRoutes) {
  assert.ok(Array.isArray(record.meta.actions), `${record.name} must carry action metadata`);
  assert.ok(record.meta.actions.length > 0, `${record.name} must expose at least tour/inspect/action metadata`);

  for (const action of record.meta.actions) {
    assert.ok(action.key, `${record.name} action key missing`);
    assert.ok(action.label_key, `${record.name}:${action.key} label key missing`);
    assert.ok(ENGLISH_MESSAGES[action.label_key], `${record.name}:${action.key} label key has no English fallback`);
    assert.ok(ACTION_KINDS.has(action.kind), `${record.name}:${action.key} has unsupported kind ${action.kind}`);
    assert.ok(action.resource_type, `${record.name}:${action.key} resource type missing`);
    assert.ok(Array.isArray(action.required_permissions), `${record.name}:${action.key} permission metadata missing`);
    assert.ok(action.required_permissions.length > 0, `${record.name}:${action.key} must not be permissionless`);
    assert.notEqual(
      action.label_key,
      'governance.create_new',
      `${record.name}:${action.key} must not use generic governance create text`,
    );
  }
}

assert.ok(
  actionKeys(routeByName('admin-governance-users')).has('governance.users.create'),
  'users governance route must describe user creation explicitly',
);
assert.ok(
  actionKeys(routeByName('admin-governance-groups')).has('governance.groups.create'),
  'groups route must describe group creation explicitly',
);
assert.ok(
  actionKeys(routeByName('admin-governance-organizations')).has('governance.organizations.create'),
  'organizations route must describe organization creation explicitly',
);
assert.ok(
  actionKeys(routeByName('admin-governance-grants')).has('governance.grants.create'),
  'grants route must describe grant creation explicitly',
);

const groupsRoute = routeByName('admin-governance-groups');
assert.equal(
  firstRouteActionByKind(routeActionsForContext(groupsRoute, { role: 'admin', permissions: ['governance.read'] }), 'create'),
  null,
  'governance read permission must not expose group creation',
);
const groupCreateAction = firstRouteActionByKind(
  routeActionsForContext(groupsRoute, { role: 'admin', permissions: ['governance.read', 'governance.groups.create'] }),
  'create',
);
assert.ok(groupCreateAction, 'governance groups create permission must expose group creation');
assert.equal(
  routeActionLabel(groupCreateAction, (key) => ENGLISH_MESSAGES[key] || key, ''),
  'Create group',
  'route action labels must resolve through i18n keys',
);
assert.ok(
  firstRouteActionByKind(routeActionsForContext(groupsRoute, { role: 'admin', allPermissions: true }), 'create'),
  'platform admin context must expose group creation',
);

for (const name of ['admin-governance-modules', 'admin-governance-permissions']) {
  const record = routeByName(name);
  assert.equal(actionKinds(record).has('create'), false, `${name} must not offer create for system catalog rows`);
  assert.ok(record.meta.readonly_reason_key, `${name} must carry a readonly reason`);
  assert.ok(
    ENGLISH_MESSAGES[record.meta.readonly_reason_key],
    `${name} readonly reason must have English fallback`,
  );
  assert.ok(actionKinds(record).has('inspect'), `${name} must keep an inspect action`);
}

const auditRoute = routeByName('admin-governance-audit-log');
assert.equal(actionKinds(auditRoute).has('create'), false, 'audit log must not offer create');
assert.ok(actionKinds(auditRoute).has('inspect'), 'audit log must offer inspect');
assert.ok(actionKinds(auditRoute).has('export'), 'audit log must offer export');

const portabilityRoute = routeByName('admin-governance-data-portability');
assert.equal(actionKinds(portabilityRoute).has('create'), false, 'data portability must not offer generic create');
assert.ok(actionKinds(portabilityRoute).has('import'), 'data portability must offer import');
assert.ok(actionKinds(portabilityRoute).has('export'), 'data portability must offer export');

assert.ok(
  actionKeys(routeByName('admin-administration-app-configuration')).has('administration.app_configuration.save'),
  'app configuration route must expose save/configure action metadata',
);
assert.ok(
  actionKeys(routeByName('admin-administration-marketplace')).has('marketplace.apps.create'),
  'marketplace route must expose app creation metadata',
);
assert.ok(
  actionKeys(routeByName('admin-administration-localization')).has('localization.resources.upload_csv'),
  'localization route must expose CSV upload metadata',
);
assert.ok(
  actionKeys(routeByName('admin-administration-theme-editor')).has('theme_editor.themes.create'),
  'theme editor route must expose theme creation metadata',
);

const navigationBuilderSource = await readFile(path.join(root, 'src/modules/navigationBuilder.js'), 'utf8');
assert.match(navigationBuilderSource, /routeActionMetadata/, 'route action metadata must be normalized centrally');
assert.match(navigationBuilderSource, /meta:[\s\S]*actions,/, 'generated route records must expose actions in route meta');

const governanceCrudSource = await readFile(path.join(root, 'src/modules/governance/pages/GovernanceCrudView.vue'), 'utf8');
assert.match(governanceCrudSource, /routeActionsForContext/, 'governance CRUD must read descriptor route actions through the shared action helper');
assert.match(governanceCrudSource, /moduleAccessContextFromSession/, 'governance CRUD must filter actions through session permissions');
assert.match(governanceCrudSource, /createAction/, 'governance CRUD must derive create visibility from route actions');
assert.doesNotMatch(
  governanceCrudSource,
  /openCreateModal">\{\{\s*t\('governance\.create_new'\)\s*\}\}/,
  'governance CRUD must not render the legacy unconditional generic create button',
);

console.log('[module-action-metadata-contract] PASS');
