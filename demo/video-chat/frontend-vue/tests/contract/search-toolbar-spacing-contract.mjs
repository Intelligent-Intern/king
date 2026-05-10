import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function blocksFor(file, selector) {
  const re = new RegExp(`${escapeRegExp(selector)}\\s*\\{([\\s\\S]*?)\\}`, 'g');
  return Array.from(file.matchAll(re), (match) => match[1]);
}

function assertGap20(file, selector, message) {
  const blocks = blocksFor(file, selector);
  assert.ok(blocks.length > 0, `${message}: selector missing`);
  assert.ok(
    blocks.some((block) => /(?:^|[;\s])gap:\s*20px;/.test(block)),
    `${message}: selector must declare gap: 20px`
  );
}

function assertAllDeclaredGaps20(file, selector, message) {
  const blocks = blocksFor(file, selector);
  assert.ok(blocks.length > 0, `${message}: selector missing`);
  const declaredGaps = blocks.flatMap((block) => Array.from(block.matchAll(/(?:^|[;\s])gap:\s*([^;]+);/g), (match) => match[1].trim()));
  assert.ok(declaredGaps.length > 0, `${message}: selector must declare at least one gap`);
  assert.deepEqual(declaredGaps, declaredGaps.map(() => '20px'), `${message}: all declared gaps must be 20px`);
}

const adminFrame = await source('src/components/admin/AdminPageFrame.vue');
const adminActionBar = await source('src/components/admin/AdminPageActionBar.vue');
const adminSearchToolbar = await source('src/components/admin/AdminSearchToolbar.vue');
const usersStyles = await source('src/modules/users/pages/admin/UsersView.css');
const usersView = await source('src/modules/users/pages/admin/UsersView.vue');
const marketplaceView = await source('src/modules/marketplace/pages/AdminMarketplaceView.vue');
const governanceRelationStack = await source('src/modules/governance/components/CrudRelationStack.vue');
const governanceToolbar = await source('src/modules/governance/components/GovernanceCrudToolbar.vue');
const calendarView = await source('src/modules/calendar/pages/CalendarView.vue');
const appConfigEmailTexts = await source('src/modules/administration/components/AppConfigurationEmailTextsTab.vue');
const callsAdminStyles = await source('src/domain/calls/admin/CallsView.css');
const callsDashboardStyles = await source('src/domain/calls/dashboard/UserDashboardView.css');
const chatArchiveModal = await source('src/domain/calls/components/ChatArchiveModal.vue');
const callSettingsStyles = await source('src/styles/call-settings.css');
const settingsStyles = await source('src/styles/settings.css');
const themePreviewApp = await source('src/layouts/settings/WorkspaceThemePreviewApp.vue');

assertGap20(adminFrame, '.admin-page-frame-toolbar', 'shared admin search toolbar');
assertAllDeclaredGaps20(adminActionBar, '.admin-page-action-bar', 'shared admin page action bar');
assertGap20(usersStyles, '.search-field', 'users search field');
assertGap20(governanceRelationStack, '.crud-relation-toolbar', 'relation picker search toolbar');
assertGap20(appConfigEmailTexts, '.app-config-toolbar', 'app configuration email text search toolbar');
assertGap20(callsAdminStyles, '.calls-toolbar', 'admin calls search toolbar shell');
assertGap20(callsAdminStyles, '.calls-toolbar-right', 'admin calls search toolbar controls');
assertGap20(callsAdminStyles, '.calls-search', 'admin calls inline search field');
assertGap20(callsDashboardStyles, '.calls-toolbar', 'dashboard calls search toolbar');
assertAllDeclaredGaps20(chatArchiveModal, '.chat-archive-toolbar', 'chat archive filter toolbar');
assertGap20(callSettingsStyles, '.call-owner-search', 'call owner participant search');
assertGap20(settingsStyles, '.settings-theme-preview-calls-toolbar', 'theme preview calls toolbar shell');
assertGap20(settingsStyles, '.settings-theme-preview-calls-view .settings-theme-preview-calls-toolbar-left', 'theme preview calls toolbar left controls');
assertGap20(settingsStyles, '.settings-theme-preview-calls-view .calls-toolbar-right', 'theme preview calls toolbar search controls');
assertGap20(themePreviewApp, '.theme-preview-toolbar', 'interactive theme preview toolbar');
assertGap20(themePreviewApp, '.theme-preview-app.compact .theme-preview-toolbar', 'compact interactive theme preview toolbar');
assert.match(
  calendarView,
  /\.calendar-header,[\s\S]*?\.calendar-directory-search,[\s\S]*?\{[\s\S]*?(?:^|[;\s])gap:\s*20px;/m,
  'calendar search toolbars must keep 20px control spacing'
);
assert.match(adminActionBar, /STANDARD_SUBMIT_ICON\s*=\s*'\/assets\/orgas\/kingrt\/icons\/send\.png'/, 'shared admin action bar must default to the standard submit icon');
assert.match(adminSearchToolbar, /AppIconButton[\s\S]*icons\/send\.png/, 'shared admin search toolbar must use the standard submit icon');
assert.match(marketplaceView, /<AdminSearchToolbar[\s\S]*@submit="applySearchNow"/, 'marketplace CRUD toolbar must inherit the shared 20px search spacing and submit icon');
assert.match(governanceToolbar, /AppIconButton[\s\S]*icons\/send\.png/, 'governance CRUD toolbar must use the standard submit icon');
assert.match(usersView, /AppIconButton[\s\S]*icons\/send\.png/, 'users CRUD toolbar must use the standard submit icon');
assert.match(appConfigEmailTexts, /AppIconButton[\s\S]*icons\/send\.png/, 'app configuration email texts toolbar must use the standard submit icon');

console.log('[search-toolbar-spacing-contract] PASS');
