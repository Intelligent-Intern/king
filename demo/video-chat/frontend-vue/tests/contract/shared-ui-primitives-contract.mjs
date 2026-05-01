import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[shared-ui-primitives-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

function readSource(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

try {
  const adminCalls = readSource('src/domain/calls/admin/CallsView.vue');
  const userCalls = readSource('src/domain/calls/dashboard/UserDashboardView.vue');
  const callTable = readSource('src/domain/calls/components/ListTable.vue');
  const chatArchive = readSource('src/domain/calls/components/ChatArchiveModal.vue');
  const adminUserEditor = readSource('src/domain/users/components/UserEditorModal.vue');
  const adminUsers = readSource('src/domain/users/admin/UsersView.vue');

  assert.match(callTable, /import AppIconButton from '..\/..\/..\/components\/AppIconButton\.vue';/, 'call table actions must use the shared icon button');
  assert.match(callTable, /<table class="calls-list-table"/, 'call table markup must live in one shared component');
  assert.match(callTable, /adminMode/, 'call table must preserve admin-only cancel/delete actions through admin mode');

  for (const [name, source] of [
    ['AdminCallsView', adminCalls],
    ['UserDashboardView', userCalls],
  ]) {
    assert.match(source, /import AppPagination from '..\/..\/..\/components\/AppPagination\.vue';/, `${name} must import shared pagination`);
    assert.match(source, /import CallsListTable from '..\/components\/ListTable\.vue';/, `${name} must import the shared call list table`);
    assert.match(source, /<CallsListTable/, `${name} must render the shared call list table`);
    assert.match(source, /<AppPagination/, `${name} must render shared pagination`);
    assert.doesNotMatch(source, /<table class="calls-list-table">/, `${name} must not duplicate call table markup`);
    assert.doesNotMatch(source, /class="pager-btn pager-icon-btn"[\s\S]{0,260}pagination\.page/, `${name} must not duplicate top-level pager controls`);
  }

  assert.match(chatArchive, /<AppModalShell/, 'chat archive modal must stay on the shared modal shell');
  assert.match(adminUserEditor, /<AppModalShell/, 'admin user editor modal must stay on the shared modal shell');
  assert.match(adminUsers, /<AppPageHeader/, 'admin user management must stay on the shared page header');

  process.stdout.write('[shared-ui-primitives-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
