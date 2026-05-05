import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

const logicalInlineSurfaces = [
  'src/components/admin/AdminPageFrame.vue',
  'src/components/admin/AdminTableFrame.vue',
  'src/modules/marketplace/pages/AdminMarketplaceView.css',
  'src/modules/users/pages/admin/UsersView.css',
  'src/modules/localization/pages/AdministrationLocalizationView.vue',
];

for (const relativePath of logicalInlineSurfaces) {
  const source = await readFile(path.join(root, relativePath), 'utf8');
  assert.doesNotMatch(source, /padding-left:\s*10px|padding-right:\s*10px|padding-left:\s*18px/, `${relativePath} must use logical inline padding for RTL`);
}

const adminPageFrameSource = await readFile(path.join(root, 'src/components/admin/AdminPageFrame.vue'), 'utf8');
assert.match(adminPageFrameSource, /border-start-start-radius/, 'admin page frame must use logical start radius');
assert.match(adminPageFrameSource, /border-start-end-radius/, 'admin page frame must use logical end radius');

const runtimeSource = await readFile(path.join(root, 'src/modules/localization/i18nRuntime.js'), 'utf8');
assert.match(runtimeSource, /document\.documentElement\.dir = normalizedDirection/, 'runtime must keep document direction synchronized');

const sharedWorkspaceStyles = await readFile(path.join(root, 'src/styles/workspace-shared.css'), 'utf8');
assert.match(sharedWorkspaceStyles, /html\[dir="rtl"\]\s+\.pager-icon-img\s*\{[^}]*transform:\s*scaleX\(-1\)/s, 'pagination directional icons must mirror in RTL');
assert.doesNotMatch(sharedWorkspaceStyles, /html\[dir="rtl"\][^{]*(canvas|video)/, 'RTL rules must not mirror canvas or video content');

console.log('[rtl-layout-foundation-contract] PASS');
