import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { createPinia, setActivePinia } from 'pinia';
import {
  buildModuleRouteRecords,
  buildSettingsPanels,
  buildWorkspaceNavigation,
} from '../../src/modules/navigationBuilder.js';
import { workspaceModuleRegistry, workspaceModuleRouteRecords } from '../../src/modules/index.js';
import { useWorkspaceModuleStore } from '../../src/stores/workspaceModuleStore.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const routes = buildModuleRouteRecords(workspaceModuleRegistry);
assert.equal(routes.length, workspaceModuleRegistry.routes().length, 'every descriptor route must become a router record');
assert.deepEqual(
  workspaceModuleRouteRecords.map((route) => route.name).sort(),
  routes.map((route) => route.name).sort(),
  'index export must expose the generated module router records',
);

for (const route of routes) {
  assert.ok(!route.path.startsWith('/'), `${route.name} must be a workspace-shell child route`);
  assert.equal(route.meta.requiresAuth, true, `${route.name} must require auth`);
  assert.ok(route.meta.module_key, `${route.name} must carry module key metadata`);
  assert.ok(Array.isArray(route.meta.required_permissions), `${route.name} must carry permission metadata`);
  assert.equal(typeof route.component, 'function', `${route.name} must keep its descriptor loader`);
  assert.ok(!String(route.meta.source_path || '').startsWith('domain/calls/'), `${route.name} must not route calls`);
}

const adminNavigation = buildWorkspaceNavigation(workspaceModuleRegistry, { role: 'admin' });
const adminFlat = adminNavigation.flatMap((item) => item.children || [item]);
assert.ok(adminFlat.some((item) => item.to === '/admin/overview'), 'admin overview must be visible');
assert.ok(adminNavigation.some((item) => item.key === 'administration'), 'administration group must be built');
assert.ok(adminNavigation.some((item) => item.key === 'governance'), 'governance group must be built');

const userNavigation = buildWorkspaceNavigation(workspaceModuleRegistry, { role: 'user' });
assert.deepEqual(userNavigation, [], 'descriptor admin navigation must be hidden for normal users');

const restrictedNavigation = buildWorkspaceNavigation(workspaceModuleRegistry, {
  role: 'admin',
  permissions: ['users.read', 'governance.read'],
});
const restrictedFlat = restrictedNavigation.flatMap((item) => item.children || [item]);
assert.ok(restrictedFlat.some((item) => item.to === '/admin/overview'), 'explicit users.read permission must allow users nav');
assert.ok(
  restrictedFlat.some((item) => item.to === '/admin/governance/users'),
  'explicit governance.read permission must allow governance nav',
);
assert.ok(
  !restrictedFlat.some((item) => item.to === '/admin/administration/marketplace'),
  'missing marketplace.admin permission must hide marketplace nav',
);

const moduleFilteredNavigation = buildWorkspaceNavigation(workspaceModuleRegistry, {
  role: 'admin',
  moduleKeys: ['users'],
});
assert.deepEqual(
  moduleFilteredNavigation.flatMap((item) => item.children || [item]).map((item) => item.module_key),
  ['users'],
  'runtime module allowlist must filter navigation entries',
);

const settingsPanels = buildSettingsPanels(workspaceModuleRegistry, { role: 'user' });
assert.ok(settingsPanels.some((panel) => panel.key === 'personal.theme'), 'user settings panels must be descriptor built');
assert.ok(settingsPanels.every((panel) => Array.isArray(panel.required_permissions)), 'settings panels must carry permissions');

setActivePinia(createPinia());
const store = useWorkspaceModuleStore();
assert.ok(store.moduleKeys.includes('governance'), 'Pinia module store must expose descriptor keys');
assert.equal(store.routes().length, routes.length, 'Pinia module store must expose generated routes');
assert.equal(
  store.navigationFor({ role: 'admin' }).flatMap((item) => item.children || [item]).length,
  adminFlat.length,
  'Pinia module store must expose generated navigation',
);

const routerSource = await readFile(path.join(root, 'src/http/router.js'), 'utf8');
assert.match(routerSource, /workspaceModuleRouteRecords/, 'router must consume generated module route records');
assert.doesNotMatch(routerSource, /name:\s*['"]admin-governance-groups['"]/, 'router must not hardcode governance CRUD routes');
assert.doesNotMatch(routerSource, /name:\s*['"]admin-administration-marketplace['"]/, 'router must not hardcode administration module routes');

const navigationSource = await readFile(path.join(root, 'src/layouts/WorkspaceNavigation.vue'), 'utf8');
assert.match(navigationSource, /useWorkspaceModuleStore/, 'workspace navigation must consume the Pinia module store');
assert.doesNotMatch(navigationSource, /label:\s*['"]Marketplace['"]/, 'workspace navigation must not hardcode module nav labels');
assert.match(navigationSource, /callNavigationItems/, 'workspace navigation may keep call navigation outside module descriptors');

console.log('[module-navigation-builder-contract] PASS');
