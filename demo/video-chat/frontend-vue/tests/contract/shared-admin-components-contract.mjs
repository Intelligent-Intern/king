import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const pageFrame = await source('src/components/admin/AdminPageFrame.vue');
const pageHeader = await source('src/components/AppPageHeader.vue');
const tableFrame = await source('src/components/admin/AdminTableFrame.vue');
const governance = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const localization = await source('src/modules/localization/pages/AdministrationLocalizationView.vue');
const users = await source('src/modules/users/pages/admin/UsersView.vue');
const usersTable = await source('src/modules/users/pages/components/UsersTable.vue');
const usersStyles = await source('src/modules/users/pages/admin/UsersView.css');
const appConfiguration = await source('src/modules/administration/pages/AppConfigurationView.vue');
const marketplace = await source('src/modules/marketplace/pages/AdminMarketplaceView.vue');
const marketplaceTable = await source('src/modules/marketplace/pages/AdminMarketplaceTable.vue');
const marketplaceStyles = await source('src/modules/marketplace/pages/AdminMarketplaceView.css');
const themeEditor = await source('src/modules/theme_editor/pages/ThemeEditorView.vue');
const governanceModal = await source('src/modules/governance/pages/GovernanceCrudModal.vue');
const relationStack = await source('src/modules/governance/components/CrudRelationStack.vue');
const themeSettings = await source('src/layouts/settings/WorkspaceThemeSettings.vue');
const modalShell = await source('src/components/AppModalShell.vue');
const sidePanelShell = await source('src/components/AppSidePanelShell.vue');
const responsiveStyles = await source('src/styles/responsive.css');
const workspaceStyles = await source('src/styles/workspace-shared.css');

assert.match(pageFrame, /AppPageHeader/, 'shared admin page frame must own the page header');
assert.match(pageFrame, /admin-page-frame-toolbar/, 'shared admin page frame must own toolbar layout');
assert.doesNotMatch(pageFrame, /class="section admin-page-frame-head"/, 'admin page header must not inherit the generic surface section band');
assert.match(pageFrame, /height:\s*100%;/, 'shared admin page frame must stay height-constrained');
assert.match(pageFrame, /overflow:\s*hidden;/, 'shared admin page frame must keep table overflow inside child scrollers');
assert.match(pageFrame, /\.admin-page-frame-head,[\s\S]*?\.admin-page-frame-toolbar[\s\S]*?background:\s*transparent;/, 'admin header and toolbar must sit on primary navy instead of a surface band');
assert.match(pageFrame, /\.admin-page-frame-footer[\s\S]*?background:\s*transparent;/, 'admin footer must not create a surface band around pagination');
assert.match(pageHeader, /\.app-page-header-actions[\s\S]*?margin-inline-start:\s*auto;[\s\S]*?justify-content:\s*flex-end;/, 'page header actions must stay right-aligned');
assert.match(pageHeader, /\.app-page-header[\s\S]*?width:\s*100%;[\s\S]*?display:\s*flex;/, 'page header root must own full-width flex alignment');
assert.match(pageHeader, /\.app-page-header-tour-btn[\s\S]*?width:\s*40px;[\s\S]*?height:\s*40px;/, 'tour button must match CRUD action button height');
assert.match(pageFrame, /\.admin-page-frame-toolbar\s*\{[\s\S]*?gap:\s*20px;[\s\S]*?justify-content:\s*flex-end;[\s\S]*?padding:\s*0 20px 20px;/, 'admin toolbar search controls must be right-aligned with 20px spacing');
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.search-field-main\)[\s\S]*?margin-inline-start:\s*auto;/, 'admin toolbar search field must push the filter cluster to the right');
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.search-field\)[\s\S]*?flex:\s*0 1 360px;/, 'admin toolbar search fields must keep a stable desktop width');
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.ii-select\)[\s\S]*?flex:\s*0 0 180px;/, 'admin toolbar selects must align beside the search submit control');
assert.match(tableFrame, /admin-table-frame/, 'shared admin table frame must own table wrapper layout');
assert.match(workspaceStyles, /\.table-wrap\s*\{[\s\S]*?overflow:\s*auto;/, 'shared table wrapper must stay the scroll owner for overflowing rows');
assert.match(workspaceStyles, /tbody tr:not\(\.table-empty-row\):hover/, 'table hover must not target empty placeholder rows');
assert.match(workspaceStyles, /tbody tr\.table-empty-row,[\s\S]*?tbody tr\.table-empty-row:hover[\s\S]*?background:\s*transparent;/, 'empty placeholder rows must not paint a hover surface');
assert.match(responsiveStyles, /\.shell\.tablet-mode:not\(\.call-workspace-mode\)[\s\S]*?overflow:\s*hidden;/, 'non-call tablet shell must keep page scroll out of the document');
assert.match(responsiveStyles, /\.shell\.mobile-mode:not\(\.call-workspace-mode\)[\s\S]*?overflow:\s*hidden;/, 'non-call mobile shell must keep page scroll out of the document');

for (const [name, file] of [
  ['GovernanceCrudView', governance],
  ['AdministrationLocalizationView', localization],
  ['UsersView', users],
  ['AppConfigurationView', appConfiguration],
  ['AdminMarketplaceView', marketplace],
  ['ThemeEditorView', themeEditor],
]) {
  assert.match(file, /AdminPageFrame/, `${name} must use the shared admin page frame`);
  assert.doesNotMatch(file, /import AppPageHeader/, `${name} must not import page header directly`);
}

for (const [name, file] of [
  ['GovernanceCrudView', governance],
  ['AdministrationLocalizationView', localization],
  ['UsersTable', usersTable],
  ['AdminMarketplaceTable', marketplaceTable],
]) {
  assert.match(file, /AdminTableFrame/, `${name} must use the shared admin table frame`);
}

assert.match(governanceModal, /AppSidePanelShell/, 'governance CRUD form must use the shared right side panel shell');
assert.doesNotMatch(governanceModal, /AppModalShell/, 'governance CRUD form must not open as a centered modal');
assert.match(governanceModal, /\bmaximizable\b/, 'governance CRUD side panel must use centralized maximizable behavior');
assert.doesNotMatch(governanceModal, /Maximize modal/, 'feature panel must not hardcode maximize controls');
assert.match(users, /AdminUserEditorModal/, 'user management must keep the extracted user editor component');
assert.match(marketplace, /AppSidePanelShell/, 'marketplace CRUD form must use the shared right side panel shell');
assert.doesNotMatch(marketplace, /marketplace-modal/, 'marketplace CRUD form must not keep the old centered modal markup');
for (const [name, file] of [
  ['GovernanceCrudModal', governanceModal],
  ['CrudRelationStack', relationStack],
  ['AdminMarketplaceView', marketplace],
  ['WorkspaceThemeSettings', themeSettings],
]) {
  assert.doesNotMatch(file, /common\.cancel/, `${name} must not render generic Cancel buttons in admin workflows`);
  assert.doesNotMatch(file, />\s*Cancel\s*</, `${name} must not render visible Cancel text in admin workflows`);
}
assert.match(relationStack, /governance\.relation_picker\.close/, 'relation picker must use a neutral close label instead of generic cancel');
assert.match(themeSettings, /theme_settings\.close_editor/, 'theme editor must use a neutral close editor label instead of generic cancel');
assert.match(modalShell, /\.app-modal-dialog\.is-maximized[\s\S]*width:\s*100vw/, 'maximized shared modals must use fullscreen width');
assert.match(modalShell, /\.app-modal-dialog\.is-maximized[\s\S]*height:\s*100vh/, 'maximized shared modals must use fullscreen height');
assert.match(sidePanelShell, /\.app-side-panel[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*auto;/, 'CRUD side panels must be anchored to the right edge');
assert.match(sidePanelShell, /\.app-side-panel-dialog\.is-maximized[\s\S]*width:\s*100vw/, 'maximized CRUD side panels must use fullscreen width');
assert.match(sidePanelShell, /\.app-side-panel-dialog\.is-maximized[\s\S]*height:\s*100vh/, 'maximized CRUD side panels must use fullscreen height');
assert.match(usersStyles, /\.search-field\s*\{[\s\S]*?flex:\s*0 1 360px;[\s\S]*?margin-inline-start:\s*auto;/, 'users search field must use right-aligned standard toolbar sizing');
assert.match(marketplaceStyles, /\.search-field\s*\{[\s\S]*?flex:\s*0 1 360px;[\s\S]*?margin-inline-start:\s*auto;/, 'marketplace search field must use right-aligned standard toolbar sizing');

console.log('[shared-admin-components-contract] PASS');
