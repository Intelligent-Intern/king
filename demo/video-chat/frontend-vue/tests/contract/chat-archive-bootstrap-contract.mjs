import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import {
  createCallWorkspaceChatHistorySync,
  createCallWorkspaceChatHistorySyncState,
} from '../../src/domain/realtime/workspace/callWorkspace/chatArchiveBootstrap.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '../..');

const appended = [];
const diagnostics = [];
const ensured = [];
const refs = {
  activeCallId: { value: 'call-reload-proof' },
  activeRoomId: { value: 'room-reload-proof' },
};
const state = createCallWorkspaceChatHistorySyncState();
let apiCallCount = 0;
const bootstrap = createCallWorkspaceChatHistorySync({
  callbacks: {
    apiRequest: async (url) => {
      apiCallCount += 1;
      assert.match(url, /^\/api\/calls\/call-reload-proof\/chat-archive\?/);
      assert.match(url, /room_id=room-reload-proof/);
      assert.match(url, /tail=1/);
      if (apiCallCount === 1) {
        assert.match(url, /limit=50/);
        assert.doesNotMatch(url, /cursor=/);
      } else {
        assert.match(url, /limit=2/);
        assert.match(url, /cursor=4/);
      }
      return {
        result: {
          archive: {
            filters: { room_id: 'room-reload-proof' },
            messages: [
              {
                seq: 4,
                id: 'chat-old',
                client_message_id: 'client-old',
                text: 'old',
                sender: { user_id: 8, display_name: 'Old Sender', role: 'user' },
                server_time: '2026-05-10T12:00:00Z',
                attachments: [],
              },
              {
                seq: 5,
                id: 'chat-new',
                client_message_id: 'client-new',
                text: 'new',
                sender: { user_id: 9, display_name: 'New Sender', role: 'moderator' },
                server_time: '2026-05-10T12:01:00Z',
                attachments: [{ id: 'file-1', download_url: '/api/files/file-1' }],
              },
            ],
            pagination: { has_next: true, next_cursor: 4 },
          },
        },
      };
    },
    appendChatMessage: (payload) => appended.push(payload),
    captureClientDiagnostic: (payload) => diagnostics.push(payload),
    ensureRoomBuckets: (roomId) => ensured.push(roomId),
  },
  refs,
  options: { olderLimit: 2, minIntervalMs: 0 },
  state,
});

assert.equal(await bootstrap.bootstrapChatArchive('contract'), true);
assert.deepEqual(ensured, ['room-reload-proof']);
assert.equal(appended.length, 2);
assert.equal(appended[0].source, 'chat_archive_bootstrap');
assert.equal(appended[0].history_backfill, true);
assert.equal(appended[0].message.client_message_id, 'client-old');
assert.equal(appended[1].message.attachments.length, 1);
assert.equal(appended[1].message.sender.role, 'moderator');
assert.equal(diagnostics.at(-1)?.eventType, 'chat_history_db_sync_loaded');
assert.equal(diagnostics.at(-1)?.payload?.message_count, 2);
assert.equal(state.hasOlder, true);
assert.equal(state.nextCursor, 4);
assert.equal(await bootstrap.loadOlderChatHistory('contract_older'), true);
assert.equal(apiCallCount, 2);
bootstrap.dispose();
assert.equal(await bootstrap.bootstrapChatArchive('after_dispose'), false);

const chatRuntimeSource = readFileSync(resolve(root, 'src/domain/realtime/workspace/callWorkspace/chatRuntime.ts'), 'utf8');
assert.match(chatRuntimeSource, /history_backfill[\s\S]*chat_archive_bootstrap/, 'chat runtime must recognize archive backfill payloads');
assert.match(chatRuntimeSource, /if \(!isHistoryBackfill\)[\s\S]*markChatUnread/, 'archive backfill must not create unread noise');
assert.match(chatRuntimeSource, /seq: Number\(message\.seq \|\| payload\?\.seq \|\| 0\)/, 'chat runtime must retain DB sequence in normalized chat state');
assert.match(chatRuntimeSource, /bucket\.sort\(/, 'chat runtime must keep DB-synced and live messages ordered');

const socketLifecycleSource = readFileSync(resolve(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts'), 'utf8');
assert.match(socketLifecycleSource, /bootstrapChatArchive = \(\) => false/, 'socket lifecycle must accept a chat archive bootstrap callback');
assert.match(socketLifecycleSource, /bootstrapChatArchive\('room_snapshot'\)/, 'room snapshots must trigger chat archive bootstrap');
assert.match(socketLifecycleSource, /bootstrapChatArchive\(isReconnectOpen \? 'websocket_reconnect' : 'websocket_open'\)/, 'websocket open/reconnect must trigger chat archive bootstrap');

const workspaceSource = readFileSync(resolve(root, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8');
assert.match(workspaceSource, /createCallWorkspaceChatHistorySync/, 'workspace must wire the chat DB sync helper');
assert.match(workspaceSource, /bootstrapChatArchive: \(\.\.\.args\) => bootstrapChatArchive\(\.\.\.args\)/, 'workspace socket callbacks must include bootstrapChatArchive');

console.log('[chat-archive-bootstrap-contract] PASS');
