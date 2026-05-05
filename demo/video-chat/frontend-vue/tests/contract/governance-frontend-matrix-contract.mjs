import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { workspaceModuleRegistry, workspaceModuleRouteRecords } from '../../src/modules/index.js';
import { buildGovernanceCatalogRows } from '../../src/modules/governanceCatalog.js';
import { firstRouteActionByKind, routeActionsForContext } from '../../src/modules/routeActions.js';
import { moduleAccessContextFromSession, sessionPermissionKeys } from '../../src/http/routeAccess.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

function routeByName(name) {
  const record = workspaceModuleRouteRecords.find((route) => route.name === name);
  assert.ok(record, `${name} route missing`);
  return record;
}

const tenantAdminPermissions = sessionPermissionKeys({ tenant_admin: true });
for (const permission of [
  'governance.read',
  'governance.groups.create',
  'governance.organizations.create',
  'governance.grants.create',
  'governance.data_portability.export',
  'governance.data_portability.import',
]) {
  assert.ok(tenantAdminPermissions.includes(permission), `tenant_admin must imply ${permission}`);
}

const readOnlyContext = { role: 'admin', permissions: ['governance.read'] };
assert.equal(
  firstRouteActionByKind(routeActionsForContext(routeByName('admin-governance-groups'), readOnlyContext), 'create'),
  null,
  'read-only governance access must not expose create',
);

const groupManagerContext = moduleAccessContextFromSession({
  role: 'admin',
  tenantPermissions: { manage_groups: true },
});
assert.ok(
  firstRouteActionByKind(routeActionsForContext(routeByName('admin-governance-groups'), groupManagerContext), 'create'),
  'manage_groups must expose group creation',
);
assert.equal(
  firstRouteActionByKind(routeActionsForContext(routeByName('admin-governance-organizations'), groupManagerContext), 'create'),
  null,
  'manage_groups must not expose organization creation',
);

for (const routeName of ['admin-governance-modules', 'admin-governance-permissions']) {
  const route = routeByName(routeName);
  assert.equal(
    firstRouteActionByKind(routeActionsForContext(route, { role: 'admin', allPermissions: true }), 'create'),
    null,
    `${routeName} must remain readonly even for platform admin`,
  );
  assert.ok(route.meta.readonly_reason_key, `${routeName} must expose a readonly reason key`);
}

const moduleRows = buildGovernanceCatalogRows(workspaceModuleRegistry, 'admin-governance-modules');
assert.ok(moduleRows.length > 0, 'module catalog rows must exist');
assert.ok(moduleRows.every((row) => row.readonly === true), 'module catalog rows must be readonly');
assert.ok(moduleRows.every((row) => row.description_key === 'governance.catalog.module_description'), 'module rows must use keyed descriptions');
assert.ok(moduleRows.every((row) => row.description_params && typeof row.description_params === 'object'), 'module rows must expose description params');

const permissionRows = buildGovernanceCatalogRows(workspaceModuleRegistry, 'admin-governance-permissions');
assert.ok(permissionRows.length > 0, 'permission catalog rows must exist');
assert.ok(permissionRows.every((row) => row.readonly === true), 'permission catalog rows must be readonly');
assert.ok(permissionRows.every((row) => row.description_key === 'governance.catalog.permission_description'), 'permission rows must use keyed descriptions');

const governanceCrudSource = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const governanceCrudStyles = await source('src/modules/governance/pages/GovernanceCrudView.css');
const governanceToolbarSource = await source('src/modules/governance/components/GovernanceCrudToolbar.vue');
const governanceEmptyStateSource = await source('src/modules/governance/components/GovernanceEmptyState.vue');
assert.match(governanceCrudSource, /rowsByScope/, 'governance CRUD must keep route-scoped local rows isolated');
assert.match(governanceCrudSource, /routeActionsForContext/, 'governance CRUD must use permission-filtered route actions');
assert.match(governanceCrudSource, /GovernanceCrudToolbar/, 'governance CRUD must use the standard toolbar component');
assert.match(governanceCrudSource, /GovernanceEmptyState/, 'governance CRUD must render the standard empty state');
assert.match(governanceCrudSource, /class="table-empty-row"/, 'governance empty placeholders must opt out of table hover styling');
assert.match(governanceCrudStyles, /grid-template-columns:[\s\S]*minmax\(0,\s*1fr\)[\s\S]*48px;/, 'governance toolbar must reserve a flexible spacer before the send button');
assert.match(governanceCrudStyles, /\.governance-toolbar-submit-btn[\s\S]*grid-column:\s*5;/, 'governance toolbar send button must sit in the far-right column');
assert.match(governanceToolbarSource, /icons\/send\.png/, 'governance toolbar submit must use the shared send icon');
assert.match(governanceToolbarSource, /governance\.filter\.all_status/, 'governance toolbar must expose status filtering');
assert.match(governanceToolbarSource, /governance\.filter\.all_scope/, 'governance toolbar must expose scope filtering');
assert.match(governanceEmptyStateSource, /@click="\$emit\('create'\)"/, 'governance empty state must route create to the parent action');
assert.doesNotMatch(governanceCrudSource, /v-for="row in pagedRows"[\s\S]{0,500}fetch\(/, 'row rendering must not trigger per-row fetch calls');
assert.doesNotMatch(governanceCrudSource, /<select[\s\S]*group/i, 'governance CRUD must not introduce raw relation selects');

const modalSource = await source('src/modules/governance/pages/GovernanceCrudModal.vue');
assert.match(modalSource, /AppSidePanelShell/, 'governance CRUD form must use the shared maximizable side panel shell');
assert.doesNotMatch(modalSource, /AppModalShell/, 'governance CRUD form must not open as a centered modal');
assert.match(governanceCrudSource, /governance\.modal\.create/, 'governance modal create title must be keyed by the parent view');
assert.match(governanceCrudSource, /governance\.modal\.edit/, 'governance modal edit title must be keyed by the parent view');
assert.doesNotMatch(modalSource, />Create new</, 'governance modal must not hardcode generic create text');

console.log('[governance-frontend-matrix-contract] PASS');
