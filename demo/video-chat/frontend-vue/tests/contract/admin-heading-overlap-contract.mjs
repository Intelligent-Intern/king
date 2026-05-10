import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const pageHeader = await source('src/components/AppPageHeader.vue');
const pageFrame = await source('src/components/admin/AdminPageFrame.vue');
const tableFrame = await source('src/components/admin/AdminTableFrame.vue');
const governanceView = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const usersView = await source('src/modules/users/pages/admin/UsersView.vue');
const marketplaceView = await source('src/modules/marketplace/pages/AdminMarketplaceView.vue');
const localizationView = await source('src/modules/localization/pages/AdministrationLocalizationView.vue');
const appConfigurationView = await source('src/modules/administration/pages/AppConfigurationView.vue');
const themeEditorView = await source('src/modules/theme_editor/pages/ThemeEditorView.vue');
const governanceStyles = await source('src/modules/governance/pages/GovernanceCrudView.css');
const usersStyles = await source('src/modules/users/pages/admin/UsersView.css');
const marketplaceStyles = await source('src/modules/marketplace/pages/AdminMarketplaceView.css');
const workspaceStyles = await source('src/styles/workspace-shared.css');

assert.match(
  pageHeader,
  /<h1>\{\{\s*title\s*\}\}<\/h1>/,
  'shared admin page headers must render the route title as h1',
);
assert.match(
  pageHeader,
  /\.app-page-header h1\s*\{[\s\S]*?font-size:\s*14px;[\s\S]*?line-height:\s*1\.2;[\s\S]*?overflow-wrap:\s*anywhere;/,
  'shared admin h1 titles must keep the standard 14px size with wrapping that prevents overlap',
);
assert.match(
  pageHeader,
  /\.app-page-header-title\s*\{[\s\S]*?min-width:\s*0;[\s\S]*?max-width:\s*100%;/,
  'admin page title container must be allowed to shrink inside the action header',
);
assert.match(
  pageHeader,
  /\.app-page-header\s*\{[\s\S]*?display:\s*flex;[\s\S]*?flex-wrap:\s*wrap;/,
  'admin page header must wrap actions instead of overlapping the title',
);
assert.match(
  pageFrame,
  /\.admin-page-frame\s*\{[\s\S]*?height:\s*100%;[\s\S]*?min-height:\s*0;[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;[\s\S]*?overflow:\s*hidden;/,
  'admin page frame must constrain document scroll while delegating overflow to child scrollers',
);
assert.match(
  tableFrame,
  /\.admin-table-frame\s*\{[\s\S]*?flex:\s*1 1 auto;[\s\S]*?min-height:\s*0;/,
  'admin table frame must remain the flexible content region',
);
assert.match(
  workspaceStyles,
  /\.table-wrap\s*\{[\s\S]*?min-height:\s*0;[\s\S]*?overflow:\s*auto;/,
  'shared table wrapper must be reachable through its own scroll owner',
);

for (const [name, file] of [
  ['GovernanceCrudView', governanceView],
  ['UsersView', usersView],
  ['AdminMarketplaceView', marketplaceView],
  ['AdministrationLocalizationView', localizationView],
  ['AppConfigurationView', appConfigurationView],
  ['ThemeEditorView', themeEditorView],
]) {
  assert.match(file, /AdminPageFrame/, `${name} must stay on the shared admin page frame`);
  assert.doesNotMatch(file, /<h1\b|import AppPageHeader/, `${name} must not bypass the shared h1 header`);
}

assert.match(
  governanceStyles,
  /\.governance-organizations-view\s*\{[\s\S]*?min-height:\s*0;[\s\S]*?flex:\s*1 1 auto;/,
  'governance organization cards must keep a reachable flex content region',
);
assert.match(
  usersStyles,
  /\.users-table-wrap\s*\{[\s\S]*?flex:\s*1 1 auto;[\s\S]*?min-height:\s*0;/,
  'user management table must keep a reachable scroll region',
);
assert.doesNotMatch(
  marketplaceStyles,
  /\.marketplace-view\s+\.app-page-header h1|\.marketplace-view\s+h1/,
  'marketplace must not override shared admin h1 sizing',
);

console.log('[admin-heading-overlap-contract] PASS');
