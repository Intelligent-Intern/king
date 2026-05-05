import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const pageFrame = await source('src/components/admin/AdminPageFrame.vue');
const tableFrame = await source('src/components/admin/AdminTableFrame.vue');
const governance = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const localization = await source('src/domain/administration/AdministrationLocalizationView.vue');
const governanceModal = await source('src/modules/governance/pages/GovernanceCrudModal.vue');

assert.match(pageFrame, /AppPageHeader/, 'shared admin page frame must own the page header');
assert.match(pageFrame, /admin-page-frame-toolbar/, 'shared admin page frame must own toolbar layout');
assert.match(tableFrame, /admin-table-frame/, 'shared admin table frame must own table wrapper layout');

for (const [name, file] of [
  ['GovernanceCrudView', governance],
  ['AdministrationLocalizationView', localization],
]) {
  assert.match(file, /AdminPageFrame/, `${name} must use the shared admin page frame`);
  assert.match(file, /AdminTableFrame/, `${name} must use the shared admin table frame`);
  assert.doesNotMatch(file, /import AppPageHeader/, `${name} must not import page header directly`);
}

assert.match(governanceModal, /AppModalShell/, 'governance CRUD modal must use the shared modal shell');
assert.match(governanceModal, /\bmaximizable\b/, 'governance CRUD modal must use centralized maximizable modal behavior');
assert.doesNotMatch(governanceModal, /Maximize modal/, 'feature modal must not hardcode maximize controls');

console.log('[shared-admin-components-contract] PASS');
