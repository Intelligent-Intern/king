import assert from 'node:assert/strict';
import { workspaceModuleRegistry } from '../../src/modules/index.js';

const expectedModules = [
  'administration',
  'governance',
  'localization',
  'marketplace',
  'theme_editor',
  'users',
  'workspace_settings',
];

const modules = workspaceModuleRegistry.list();
assert.deepEqual(
  modules.map((descriptor) => descriptor.module_key).sort(),
  expectedModules,
  'module registry must expose the first non-call module set',
);

const routes = workspaceModuleRegistry.routes();
for (const route of routes) {
  assert.ok(route.module_key, 'route must carry owning module key');
  assert.ok(route.path.startsWith('/admin/'), `unexpected route path ${route.path}`);
  assert.ok(!String(route.source_path || '').startsWith('domain/calls/'), 'module route must not touch call domain');
  assert.ok(!String(route.source_path || '').startsWith('domain/realtime/'), 'module route must not touch realtime domain');
}

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

const namespaces = workspaceModuleRegistry.i18nNamespaces();
for (const moduleKey of expectedModules) {
  assert.ok(namespaces.includes(moduleKey), `missing i18n namespace ${moduleKey}`);
}

console.log('[module-registry-contract] PASS');
