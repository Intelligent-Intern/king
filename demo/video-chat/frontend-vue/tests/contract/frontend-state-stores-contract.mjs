import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
  createParticipantDirectoryStore,
} from '../../src/domain/calls/callViewState.js';

function fail(message) {
  throw new Error(`[frontend-state-stores-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

function readSource(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

try {
  const adminCalls = readSource('src/domain/calls/AdminCallsView.vue');
  const userCalls = readSource('src/domain/calls/UserDashboardView.vue');

  const listStore = createCallListStore({ defaultScope: 'all', pageSize: 25 });
  assert.equal(listStore.scopeFilter.value, 'all', 'call list store must own the default scope');
  assert.equal(listStore.pagination.pageSize, 25, 'call list store must own pagination page size');
  listStore.applyPagination({ page: 3, page_size: 20, total: 47, page_count: 3, has_prev: true, has_next: false }, 0);
  assert.equal(listStore.pagination.page, 3, 'call list store must apply backend page');
  assert.equal(listStore.pagination.total, 47, 'call list store must apply backend total');
  assert.equal(listStore.pagination.hasPrev, true, 'call list store must apply previous-page state');
  listStore.resetPagination();
  assert.equal(listStore.pagination.total, 0, 'call list store must reset totals without destroying current page');
  assert.equal(listStore.pagination.page, 3, 'call list store reset must preserve current page by default');

  const noticeStore = createNoticeStore();
  noticeStore.setNotice('ok', ' Saved ');
  assert.equal(noticeStore.noticeMessage.value, 'Saved', 'notice store must trim notice messages');
  assert.deepEqual(noticeStore.noticeKindClass.value, { ok: true, error: false }, 'notice store must expose banner classes');
  noticeStore.clearNotice();
  assert.equal(noticeStore.noticeMessage.value, '', 'notice store must clear messages');

  const chatStore = createChatArchiveStore();
  chatStore.openChatArchive({ id: 'call-1', title: 'Daily' });
  assert.deepEqual(chatStore.state, { open: true, callId: 'call-1', callTitle: 'Daily' }, 'chat archive store must open from a call row');
  chatStore.closeChatArchive();
  assert.deepEqual(chatStore.state, { open: false, callId: '', callTitle: '' }, 'chat archive store must reset archive state');

  const participantStore = createParticipantDirectoryStore({ pageSize: 5 });
  participantStore.applyRows([{ id: 2 }], { page: 2, page_size: 5, total: 9, page_count: 2, has_prev: true });
  assert.equal(participantStore.state.rows.length, 1, 'participant store must own directory rows');
  assert.equal(participantStore.state.page, 2, 'participant store must own directory pagination');
  participantStore.fail('No directory');
  assert.equal(participantStore.state.error, 'No directory', 'participant store must own directory errors');
  assert.equal(participantStore.state.rows.length, 0, 'participant store failure must clear stale rows');
  participantStore.reset();
  assert.equal(participantStore.state.page, 1, 'participant store reset must restore first page');

  for (const [name, source] of [
    ['AdminCallsView', adminCalls],
    ['UserDashboardView', userCalls],
  ]) {
    assert.match(source, /createCallListStore/, `${name} must use the call list store`);
    assert.match(source, /createNoticeStore/, `${name} must use the notice store`);
    assert.match(source, /createChatArchiveStore/, `${name} must use the chat archive store`);
    assert.match(source, /createParticipantDirectoryStore/, `${name} must use the participant directory store`);
    assert.doesNotMatch(source, /const pagination = reactive\(/, `${name} must not own ad-hoc call pagination state`);
    assert.doesNotMatch(source, /const chatArchiveState = reactive\(/, `${name} must not own ad-hoc chat archive state`);
    assert.doesNotMatch(source, /const composeParticipants = reactive\(/, `${name} must not own ad-hoc participant directory state`);
  }

  process.stdout.write('[frontend-state-stores-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
