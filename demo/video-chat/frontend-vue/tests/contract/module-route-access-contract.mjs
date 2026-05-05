import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  moduleAccessContextFromSession,
  routeAllowsRequiredPermissions,
  routeAllowsRole,
  routeAllowsSessionAccess,
  sessionPermissionKeys,
} from '../../src/http/routeAccess.js';
import { workspaceModuleRouteRecords } from '../../src/modules/index.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

function asResolvedRoute(record) {
  return { matched: [record] };
}

const marketplaceRecord = workspaceModuleRouteRecords.find((record) => record.name === 'admin-administration-marketplace');
const themeRecord = workspaceModuleRouteRecords.find((record) => record.name === 'admin-administration-theme-editor');
assert.ok(marketplaceRecord, 'marketplace module route missing');
assert.ok(themeRecord, 'theme editor module route missing');

assert.equal(routeAllowsRole(asResolvedRoute(marketplaceRecord), 'admin'), true, 'admin role must pass role gate');
assert.equal(routeAllowsRole(asResolvedRoute(marketplaceRecord), 'user'), false, 'user role must fail admin route gate');

assert.equal(
  routeAllowsRequiredPermissions(asResolvedRoute(marketplaceRecord), { permissions: ['marketplace.admin'] }),
  true,
  'explicit module permission must allow module route',
);
assert.equal(
  routeAllowsRequiredPermissions(asResolvedRoute(marketplaceRecord), { permissions: ['users.read'] }),
  false,
  'missing module permission must deny module route',
);
assert.equal(
  routeAllowsRequiredPermissions(asResolvedRoute(marketplaceRecord), { permissions: [], allPermissions: true }),
  true,
  'platform admin context must allow module route',
);

assert.deepEqual(
  sessionPermissionKeys({ manage_users: true, edit_themes: true }),
  ['edit_themes', 'manage_users', 'theme_editor.admin', 'users.read'],
  'tenant permission aliases must map to module permission keys',
);
assert.deepEqual(
  moduleAccessContextFromSession({ role: 'admin', tenantPermissions: {} }),
  { role: 'admin', permissions: [], allPermissions: true },
  'legacy admin sessions without tenant permissions must stay compatible',
);
assert.equal(
  routeAllowsSessionAccess(asResolvedRoute(themeRecord), {
    role: 'admin',
    tenantPermissions: { platform_admin: false, edit_themes: true },
  }),
  true,
  'edit_themes tenant permission must allow theme editor module route',
);
assert.equal(
  routeAllowsSessionAccess(asResolvedRoute(marketplaceRecord), {
    role: 'admin',
    tenantPermissions: { platform_admin: false, edit_themes: true },
  }),
  false,
  'admin sessions without matching module permission must not resolve the route',
);

const routerSource = await readFile(path.join(root, 'src/http/router.js'), 'utf8');
assert.match(routerSource, /routeAllowsSessionAccess/, 'router must enforce role and module permissions together');

const navigationSource = await readFile(path.join(root, 'src/layouts/WorkspaceNavigation.vue'), 'utf8');
assert.match(navigationSource, /moduleAccessContextFromSession/, 'workspace navigation must use session module access context');

console.log('[module-route-access-contract] PASS');
