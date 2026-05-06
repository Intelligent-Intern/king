import assert from 'node:assert/strict';
import { readdir } from 'node:fs/promises';
import path from 'node:path';
import { workspaceModuleRegistry } from '../../src/modules/index.js';
import { GOVERNANCE_CRUD_DESCRIPTORS } from '../../src/modules/governance/crudDescriptors.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

const expectedModules = [
  'administration',
  'calendar',
  'calls',
  'governance',
  'infrastructure',
  'localization',
  'marketplace',
  'onboarding',
  'theme_editor',
  'users',
  'workspace_settings',
];

const modules = workspaceModuleRegistry.list();
assert.deepEqual(
  modules.map((descriptor) => descriptor.module_key).sort(),
  expectedModules,
  'module registry must expose every workspace module descriptor',
);

const moduleDirectories = (await readdir(path.join(root, 'src/modules'), { withFileTypes: true }))
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();
assert.deepEqual(
  modules.map((descriptor) => descriptor.module_key).sort(),
  moduleDirectories,
  'every src/modules directory must have a catalog descriptor',
);

const routes = workspaceModuleRegistry.routes();
for (const route of routes) {
  assert.ok(route.module_key, 'route must carry owning module key');
  assert.ok(route.path.startsWith('/admin/') || route.path === '/calendar', `unexpected route path ${route.path}`);
  assert.ok(!String(route.source_path || '').startsWith('domain/calls/'), 'module route must not touch call domain');
  assert.ok(!String(route.source_path || '').startsWith('domain/realtime/'), 'module route must not touch realtime domain');
}

for (const descriptor of modules) {
  assert.ok(descriptor.permissions.length > 0, `${descriptor.module_key} must expose manifest permissions`);
  assert.ok(Array.isArray(descriptor.access.grant_targets), `${descriptor.module_key} access grant targets missing`);
  assert.ok(descriptor.access.grant_targets.includes('group'), `${descriptor.module_key} must be group-assignable`);
  assert.ok(descriptor.access.grant_targets.includes('organization'), `${descriptor.module_key} must be organization-assignable`);
  assert.equal(
    descriptor.access.supports_time_limited_grants,
    true,
    `${descriptor.module_key} must expose the time-limited grant metadata slot`,
  );
}

const manifestPermissions = new Set(modules.flatMap((descriptor) => descriptor.permissions));
assert.ok(manifestPermissions.has('calls.join'), 'calls module must expose join permission');
assert.ok(manifestPermissions.has('calendar.share'), 'calendar module must expose share permission');
assert.ok(manifestPermissions.has('governance.groups.update'), 'governance module must expose group update permission');
assert.ok(manifestPermissions.has('infrastructure.read'), 'infrastructure module must expose read permission');
assert.ok(manifestPermissions.has('workspace_settings.update'), 'workspace settings module must expose update permission');

const referencedPermissions = new Set();
for (const descriptor of modules) {
  for (const entry of [...descriptor.routes, ...descriptor.navigation, ...descriptor.settings_panels]) {
    collectRequiredPermissions(entry, referencedPermissions);
    for (const action of Array.isArray(entry.actions) ? entry.actions : []) {
      collectRequiredPermissions(action, referencedPermissions);
    }
  }
}
for (const descriptor of Object.values(GOVERNANCE_CRUD_DESCRIPTORS)) {
  for (const action of descriptor.row_actions || []) {
    collectRequiredPermissions(action, referencedPermissions);
  }
}

const missingPermissions = [...referencedPermissions].filter((permission) => !manifestPermissions.has(permission));
assert.deepEqual(missingPermissions, [], 'every permission referenced by routes/actions must be exposed by a module manifest');

assert.ok(
  routes.some((route) => route.path === '/admin/administration/marketplace' && route.module_key === 'marketplace'),
  'marketplace route must be descriptor-owned',
);
assert.ok(
  routes.some((route) => route.path === '/admin/governance/modules' && route.module_key === 'governance'),
  'governance module route must be descriptor-owned',
);

const navigationGroups = new Set(workspaceModuleRegistry.navigation().map((item) => item.group).filter(Boolean));
assert.ok(navigationGroups.has('administration'), 'administration navigation group missing');
assert.ok(navigationGroups.has('governance'), 'governance navigation group missing');

const settingsKeys = new Set(workspaceModuleRegistry.settingsPanels().map((panel) => panel.key));
assert.ok(settingsKeys.has('personal.about'), 'about settings panel missing');
assert.ok(settingsKeys.has('personal.credentials'), 'credentials settings panel missing');
assert.ok(settingsKeys.has('personal.theme'), 'theme settings panel missing');
assert.ok(settingsKeys.has('personal.localization'), 'localization settings panel missing');
assert.ok(!settingsKeys.has('personal.regional'), 'regional time settings panel must be merged into localization');

const namespaces = workspaceModuleRegistry.i18nNamespaces();
for (const moduleKey of expectedModules) {
  assert.ok(namespaces.includes(moduleKey), `missing i18n namespace ${moduleKey}`);
}

console.log('[module-registry-contract] PASS');

function collectRequiredPermissions(entry, output) {
  if (!entry || typeof entry !== 'object' || !Array.isArray(entry.required_permissions)) return;
  for (const permission of entry.required_permissions) {
    const key = String(permission || '').trim();
    if (key !== '') output.add(key);
  }
}
