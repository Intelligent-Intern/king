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
const searchToolbar = await source('src/components/admin/AdminSearchToolbar.vue');
const listController = await source('src/components/admin/useAdminListController.js');
const sidePanelSubmitFooter = await source('src/components/admin/AdminSidePanelSubmitFooter.vue');
const sidePanelForm = await source('src/components/admin/useAdminSidePanelForm.js');
const governance = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const localization = await source('src/modules/localization/pages/AdministrationLocalizationView.vue');
const users = await source('src/modules/users/pages/admin/UsersView.vue');
const userEditorModal = await source('src/modules/users/pages/components/UserEditorModal.vue');
const usersTable = await source('src/modules/users/pages/components/UsersTable.vue');
const usersStyles = await source('src/modules/users/pages/admin/UsersView.css');
const appConfiguration = await source('src/modules/administration/pages/AppConfigurationView.vue');
const backgroundUploadModal = await source('src/modules/administration/components/BackgroundImageUploadModal.vue');
const marketplace = await source('src/modules/marketplace/pages/AdminMarketplaceView.vue');
const marketplaceTable = await source('src/modules/marketplace/pages/AdminMarketplaceTable.vue');
const marketplaceStyles = await source('src/modules/marketplace/pages/AdminMarketplaceView.css');
const themeEditor = await source('src/modules/theme_editor/pages/ThemeEditorView.vue');
const governanceModal = await source('src/modules/governance/pages/GovernanceCrudModal.vue');
const relationStack = await source('src/modules/governance/components/CrudRelationStack.vue');
const themeSettings = await source('src/layouts/settings/WorkspaceThemeSettings.vue');
const themeEditorSidebar = await source('src/layouts/settings/WorkspaceThemeEditorSidebar.vue');
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
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.search-field-main\)[\s\S]*?margin-inline-start:\s*0;/, 'admin toolbar search controls must rely on the shared 20px flex gap instead of auto spacing');
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.search-field\)[\s\S]*?flex:\s*0 1 360px;/, 'admin toolbar search fields must keep a stable desktop width');
assert.match(pageFrame, /\.admin-page-frame-toolbar :deep\(\.ii-select\)[\s\S]*?flex:\s*0 0 180px;/, 'admin toolbar selects must align beside the search submit control');
assert.match(tableFrame, /admin-table-frame/, 'shared admin table frame must own table wrapper layout');
assert.match(searchToolbar, /AppIconButton[\s\S]*icons\/send\.png/, 'shared admin search toolbar must own the standard submit icon');
assert.match(searchToolbar, /defineEmits\(\['update:modelValue', 'submit'\]\)/, 'shared admin search toolbar must expose v-model and submit events');
assert.match(searchToolbar, /\.search-field\s*\{[\s\S]*?flex:\s*0 1 360px;[\s\S]*?margin-inline-start:\s*0;/, 'shared admin search toolbar must own stable search field sizing');
assert.match(listController, /export function useAdminListController\(options\)/, 'shared admin list controller must expose the list composable');
assert.match(listController, /const queryDraft = ref\(''\);[\s\S]*const queryApplied = ref\(''\);[\s\S]*const pagination = reactive/, 'shared admin list controller must own query and pagination state');
assert.match(listController, /let loadToken = 0;[\s\S]*if \(token !== loadToken\) return;/, 'shared admin list controller must guard stale loads');
assert.match(listController, /watch\(queryDraft[\s\S]*globalThis\.setTimeout[\s\S]*debounceMs/, 'shared admin list controller must debounce search changes');
assert.match(sidePanelSubmitFooter, /class="btn btn-cyan admin-side-panel-submit"/, 'shared side panel submit footer must own the standard cyan submit action');
assert.match(sidePanelSubmitFooter, /margin-inline-start:\s*auto;/, 'shared side panel submit action must stay pinned to the footer right edge');
assert.doesNotMatch(sidePanelSubmitFooter, /common\.cancel|>\s*Cancel\s*</, 'shared side panel submit footer must not reintroduce cancel actions');
assert.match(sidePanelForm, /export function useAdminSidePanelForm\(\)/, 'shared side panel form composable must expose panel form state');
assert.match(sidePanelForm, /const open = ref\(false\);[\s\S]*const saving = ref\(false\);[\s\S]*const error = ref\(''\);/, 'shared side panel form composable must own open, saving, and error state');
assert.match(sidePanelForm, /async function runSubmit\(action[\s\S]*saving\.value = true;[\s\S]*error\.value = '';[\s\S]*finally[\s\S]*saving\.value = false;/, 'shared side panel form composable must wrap save state and error handling');
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
assert.doesNotMatch(governanceModal, /\bmaximizable\b/, 'governance CRUD side panel must not expose maximize or resize controls');
assert.doesNotMatch(governanceModal, /Maximize modal/, 'feature panel must not hardcode maximize controls');
assert.match(users, /AdminUserEditorModal/, 'user management must keep the extracted user editor component');
assert.match(marketplace, /AppSidePanelShell/, 'marketplace CRUD form must use the shared right side panel shell');
assert.match(marketplace, /<AdminSearchToolbar[\s\S]*v-model="queryDraft"[\s\S]*@submit="applySearchNow"/, 'marketplace must use the shared admin search toolbar');
assert.match(marketplace, /useAdminListController\(\{[\s\S]*\/api\/admin\/marketplace\/apps/, 'marketplace must use the shared admin list controller');
assert.match(marketplace, /useAdminSidePanelForm\(\)/, 'marketplace must use the shared side panel form composable');
assert.doesNotMatch(marketplace, /const dialogOpen = ref\(false\)|const formSaving = ref\(false\)|const formError = ref\(''\)/, 'marketplace must not keep duplicated side panel form refs');
assert.doesNotMatch(marketplace, /let loadToken|let searchTimer|watch\(queryDraft/, 'marketplace must not keep duplicated list controller state');
assert.doesNotMatch(marketplace, /marketplace-modal/, 'marketplace CRUD form must not keep the old centered modal markup');
for (const [name, file] of [
  ['GovernanceCrudModal', governanceModal],
  ['AdminMarketplaceView', marketplace],
  ['UserEditorModal', userEditorModal],
]) {
  assert.match(file, /AdminSidePanelSubmitFooter/, `${name} must use the shared side panel submit footer`);
  assert.doesNotMatch(file, /<button[\s\S]*class="btn btn-cyan"[\s\S]*form="(?:governanceCrudForm|userEditorForm)"/, `${name} must not keep a duplicated side panel submit button`);
}
for (const [name, file] of [
  ['GovernanceCrudModal', governanceModal],
  ['CrudRelationStack', relationStack],
  ['AdminMarketplaceView', marketplace],
  ['UserEditorModal', userEditorModal],
  ['WorkspaceThemeSettings', themeSettings],
  ['WorkspaceThemeEditorSidebar', themeEditorSidebar],
]) {
  assert.doesNotMatch(file, /common\.cancel/, `${name} must not render generic Cancel buttons in admin workflows`);
  assert.doesNotMatch(file, />\s*Cancel\s*</, `${name} must not render visible Cancel text in admin workflows`);
}
assert.doesNotMatch(relationStack, /@click="\$emit\('close'\)"/, 'relation picker must not render a redundant footer close button');
assert.doesNotMatch(relationStack, /governance\.relation_picker\.close['"`)]/, 'relation picker must not render a redundant footer close label');
assert.match(relationStack, /AppIconButton[\s\S]*icons\/send\.png/, 'relation picker sidebar search must always expose the shared submit icon');
assert.match(relationStack, /\.crud-relation-toolbar\s*\{[\s\S]*?justify-content:\s*flex-end;[\s\S]*?gap:\s*20px;/, 'relation picker sidebar search must stay right-aligned with 20px spacing');
assert.match(relationStack, /\.crud-relation-search-field \.input\s*\{[\s\S]*?background-color:\s*var\(--bg-input\);/, 'relation picker sidebar search input must use the standard input background');
assert.match(relationStack, /\.crud-relation-footer\s*\{[\s\S]*?justify-content:\s*flex-end;[\s\S]*?padding:\s*0 4px 4px 0;/, 'relation picker footer must pin the select action to the bottom right with 20px panel spacing');
assert.match(relationStack, /\.crud-relation-footer \.btn-cyan\s*\{[\s\S]*?margin-inline-start:\s*auto;/, 'relation picker select action must stay rightmost when back is visible');
assert.match(themeEditorSidebar, /theme_settings\.close_editor/, 'theme editor must use a neutral close editor label instead of generic cancel');
assert.match(modalShell, /\.app-modal-dialog\.is-maximized[\s\S]*width:\s*100vw/, 'maximized shared modals must use fullscreen width');
assert.match(modalShell, /\.app-modal-dialog\.is-maximized[\s\S]*height:\s*100vh/, 'maximized shared modals must use fullscreen height');
assert.match(backgroundUploadModal, /AppModalShell/, 'background image upload cropper must use the shared modal shell');
assert.match(backgroundUploadModal, /\bmaximizable\b/, 'background image upload cropper must expose standard modal maximization');
assert.match(backgroundUploadModal, /AppPagination/, 'bulk background upload cropper must provide pagination between selected images');
assert.doesNotMatch(backgroundUploadModal, /common\.cancel|>\s*Cancel\s*</, 'background upload cropper must rely on the standard modal close control instead of a footer cancel button');
assert.match(sidePanelShell, /\.app-side-panel[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*auto;/, 'CRUD side panels must be anchored to the right edge');
assert.match(sidePanelShell, /\.app-side-panel-dialog\s*\{[\s\S]*?border:\s*0;[\s\S]*?border-left:\s*1px solid var\(--border-subtle\);[\s\S]*?border-radius:\s*0;/, 'right side panels must only render the left divider with no rounded corners');
assert.doesNotMatch(sidePanelShell, /\.app-side-panel-dialog\s*\{[\s\S]*?border:\s*1px solid/, 'right side panels must not render top, right, and bottom borders');
assert.doesNotMatch(sidePanelShell, /border-radius:\s*10px 0 0 10px/, 'right side panels must not keep the old rounded drawer corners');
assert.doesNotMatch(sidePanelShell, /toggleMaximized|maximizeIcon|restoreIcon|update:maximized/, 'right side panels must not expose resize or maximize controls');
assert.match(sidePanelShell, /\.app-side-panel-footer\s*\{[\s\S]*?display:\s*flex;[\s\S]*?justify-content:\s*flex-end;/, 'right side panel footer must remain a bottom-right action rail');
assert.match(usersStyles, /\.search-field\s*\{[\s\S]*?flex:\s*0 1 360px;[\s\S]*?margin-inline-start:\s*0;/, 'users search field must not add extra auto spacing inside the standard toolbar');
assert.doesNotMatch(marketplaceStyles, /\.search-field|marketplace-toolbar-search-btn/, 'marketplace must not duplicate shared search toolbar CSS');

console.log('[shared-admin-components-contract] PASS');
