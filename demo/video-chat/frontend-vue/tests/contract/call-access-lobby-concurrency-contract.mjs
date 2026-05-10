import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function normalizeEntry(entry) {
  const userId = Number(entry?.user_id || 0);
  return {
    user_id: Number.isInteger(userId) && userId > 0 ? userId : 0,
    display_name: String(entry?.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: String(entry?.role || 'user').trim() || 'user',
    requested_unix_ms: Number(entry?.requested_unix_ms || 0),
    admitted_unix_ms: Number(entry?.admitted_unix_ms || 0),
    feedback: String(entry?.feedback || '').trim(),
  };
}

function uniqueByUser(entries) {
  const rows = new Map();
  for (const entry of Array.isArray(entries) ? entries : []) {
    const normalized = normalizeEntry(entry);
    if (normalized.user_id <= 0) continue;
    rows.set(normalized.user_id, normalized);
  }
  return Array.from(rows.values());
}

function applyLobbySnapshot(state, payload) {
  const admitted = uniqueByUser(payload?.admitted);
  const admittedIds = new Set(admitted.map((row) => row.user_id));
  const queue = uniqueByUser(payload?.queue).filter((row) => !admittedIds.has(row.user_id));
  state.queue = queue;
  state.admitted = admitted;
  for (const key of Object.keys(state.actionState)) {
    if (key.startsWith('allow:') || key.startsWith('remove:')) {
      delete state.actionState[key];
    }
  }
}

function sortedLobbyRows(queue) {
  return queue
    .map((row) => ({ ...row, status: 'queued', sortTs: Number(row.requested_unix_ms || 0) }))
    .sort((left, right) => {
      if (left.sortTs !== right.sortTs) return left.sortTs - right.sortTs;
      return String(left.display_name || '').localeCompare(String(right.display_name || ''));
    });
}

function filteredLobbyRows(rows, query) {
  const normalized = String(query || '').trim().toLowerCase();
  if (normalized === '') return rows;
  return rows.filter((row) => (
    String(row.display_name || '').toLowerCase().includes(normalized)
    || String(row.status || '').toLowerCase().includes(normalized)
    || String(row.user_id || '').includes(normalized)
    || String(row.feedback || '').toLowerCase().includes(normalized)
  ));
}

function paginateRows(rows, page, pageSize) {
  const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
  const clampedPage = Math.min(Math.max(1, Number(page) || 1), pageCount);
  const offset = (clampedPage - 1) * pageSize;
  return {
    page: clampedPage,
    pageCount,
    rows: rows.slice(offset, offset + pageSize),
  };
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const roomState = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const participantUi = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const orchestration = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/orchestration.ts');
const rightRosterPanel = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');
const lobbyConcurrencyE2e = read('demo/video-chat/frontend-vue/tests/e2e/lobby-concurrency-ui.spec.js');
const backendConcurrency = read('demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php');

assert.match(
  String(packageJson.scripts?.['test:contract:iam-call-access'] || ''),
  /call-access-lobby-concurrency-contract\.mjs/,
  'IAM call-access contract gate must include lobby queue concurrency proof',
);
assert.match(
  roomState,
  /function uniqueLobbyEntriesByUser\(entries\)[\s\S]*const rows = new Map\(\)[\s\S]*rows\.set\(userId, normalized\)/,
  'room state must dedupe lobby queue/admitted snapshots by user id',
);
assert.match(
  roomState,
  /const admittedUserIds = new Set\(admittedRows\.map[\s\S]*filter\(\(entry\) => !admittedUserIds\.has/,
  'admitted snapshot rows must win over stale queued rows for the same user',
);
assert.match(
  roomState,
  /for \(const key of Object\.keys\(lobbyActionState\)\)[\s\S]*key\.startsWith\('allow:'\)[\s\S]*key\.startsWith\('remove:'\)[\s\S]*delete lobbyActionState\[key\]/,
  'fresh lobby snapshots must clear stale per-row allow/remove pending states',
);
assert.match(
  participantUi,
  /const lobbyRows = computed\(\(\) =>[\s\S]*lobbyQueue\.value\.map[\s\S]*status: 'queued'[\s\S]*sortTs[\s\S]*compareLocalizedStrings/,
  'lobby rows must be sorted deterministically by queue timestamp and display name',
);
assert.match(
  participantUi,
  /const filteredLobbyRows = computed\(\(\) =>[\s\S]*display_name[\s\S]*status[\s\S]*user_id[\s\S]*feedback/,
  'lobby search must cover name, status, user id, and action feedback',
);
assert.match(
  participantUi,
  /const lobbyPageRows = computed\(\(\) =>[\s\S]*const offset = \(lobbyPage\.value - 1\) \* LOBBY_PAGE_SIZE[\s\S]*filteredLobbyRows\.value\.slice/,
  'lobby pagination must be derived from the filtered row set',
);
assert.match(
  orchestration,
  /watch\(filteredLobbyRows \|\| lobbyRows[\s\S]*lobbyPage\.value > lobbyPageCount\.value[\s\S]*lobbyPage\.value = lobbyPageCount\.value[\s\S]*lobbyPage\.value < 1/s,
  'lobby page must clamp when snapshots or search reduce page count',
);
assert.match(
  rightRosterPanel,
  /v-if="showLobbySearch"[\s\S]*:value="lobbySearch"[\s\S]*@input="\$emit\('update:lobbySearch'/,
  'right roster must expose stable lobby search input',
);
assert.match(
  rightRosterPanel,
  /v-if="lobbyPageCount > 1"[\s\S]*lobby_page_info[\s\S]*go-to-lobby-page/s,
  'right roster must expose lobby pagination controls only when needed',
);
assert.match(
  rightRosterPanel,
  /:disabled="!canModerate \|\| row\.status !== 'queued' \|\| lobbyActionPending\(row\.user_id\)"/,
  'allow action must be disabled while a row action is pending or no longer queued',
);
assert.match(
  rightRosterPanel,
  /:disabled="!canModerate \|\| lobbyActionPending\(row\.user_id\)"/,
  'remove action must be disabled while a row action is pending',
);
assert.match(
  lobbyConcurrencyE2e,
  /concurrent_duplicate_queue[\s\S]*toHaveCount\(1\)[\s\S]*concurrent_admitted_wins_over_stale_queue[\s\S]*reject_final_empty/s,
  'focused UI E2E must prove duplicate queue rows and stale controls disappear after concurrent snapshots',
);
assert.match(
  backendConcurrency,
  /concurrent allow should create one admitted handoff[\s\S]*late duplicate allow should be idempotent[\s\S]*admit-then-reject should leave no queued entry[\s\S]*reject-then-stale-admit should leave no admitted handoff/s,
  'backend contract must keep authoritative concurrent allow/reject behavior pinned',
);

const state = {
  queue: [],
  admitted: [],
  actionState: {
    'allow:20': { pending: true },
    'remove:30': { pending: true },
    'mute:20': { pending: true },
  },
};

applyLobbySnapshot(state, {
  queue: [
    { user_id: 20, display_name: 'Waiting User', requested_unix_ms: 3000 },
    { user_id: 20, display_name: 'Waiting User', requested_unix_ms: 1000, feedback: 'duplicate should collapse' },
    { user_id: 21, display_name: 'Alice Searchable', requested_unix_ms: 2000 },
    { user_id: 22, display_name: 'Charlie', requested_unix_ms: 4000 },
  ],
  admitted: [
    { user_id: 22, display_name: 'Charlie', admitted_unix_ms: 5000 },
    { user_id: 22, display_name: 'Charlie Duplicate', admitted_unix_ms: 6000 },
  ],
});

assert.deepEqual(
  state.queue.map((row) => row.user_id).sort((left, right) => left - right),
  [20, 21],
  'queue snapshot must dedupe repeated users and remove admitted users',
);
assert.deepEqual(
  state.admitted.map((row) => row.user_id),
  [22],
  'admitted snapshot must dedupe repeated admitted handoffs',
);
assert.deepEqual(
  Object.keys(state.actionState).sort(),
  ['mute:20'],
  'lobby snapshot must clear stale allow/remove pending controls without clearing unrelated moderation state',
);

const stableRows = sortedLobbyRows([
  { user_id: 4, display_name: 'Delta', requested_unix_ms: 4000 },
  { user_id: 1, display_name: 'Alpha', requested_unix_ms: 1000 },
  { user_id: 3, display_name: 'Gamma', requested_unix_ms: 3000 },
  { user_id: 2, display_name: 'Beta', requested_unix_ms: 2000 },
  { user_id: 5, display_name: 'Echo', requested_unix_ms: 5000 },
  { user_id: 6, display_name: 'Foxtrot', requested_unix_ms: 6000 },
  { user_id: 7, display_name: 'Golf', requested_unix_ms: 7000 },
]);
assert.deepEqual(
  paginateRows(stableRows, 2, 3).rows.map((row) => row.user_id),
  [4, 5, 6],
  'lobby pagination must stay stable after deterministic timestamp sorting',
);
assert.deepEqual(
  paginateRows(filteredLobbyRows(stableRows, 'queued'), 3, 3).page,
  3,
  'status search must preserve page when result count still has that page',
);
assert.deepEqual(
  paginateRows(filteredLobbyRows(stableRows, 'alpha'), 3, 3),
  { page: 1, pageCount: 1, rows: [stableRows[0]] },
  'search narrowing must clamp out-of-range lobby pages to the last available page',
);
assert.deepEqual(
  filteredLobbyRows([{ user_id: 91, display_name: 'No Match', status: 'queued', feedback: 'Owner is deciding' }], 'deciding')
    .map((row) => row.user_id),
  [91],
  'lobby search must remain stable for action feedback text while admit/deny is pending',
);

applyLobbySnapshot(state, {
  queue: [
    { user_id: 20, display_name: 'Waiting User', requested_unix_ms: 7000 },
  ],
  admitted: [
    { user_id: 20, display_name: 'Waiting User', admitted_unix_ms: 7100 },
  ],
});
assert.deepEqual(state.queue, [], 'concurrent admit must beat a stale queued copy for the same user');
assert.deepEqual(state.admitted.map((row) => row.user_id), [20], 'concurrent admit must leave one admitted row');

applyLobbySnapshot(state, { queue: [], admitted: [] });
assert.deepEqual(state.queue, [], 'later deny/remove snapshot must not resurrect a previously admitted queue row');
assert.deepEqual(state.admitted, [], 'later empty snapshot may clear admitted handoff after server convergence');

console.log('[call-access-lobby-concurrency-contract] PASS');
