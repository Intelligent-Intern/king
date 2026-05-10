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

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

const doc = read('documentation/iam-sprint-05-lobby-state-cleanup-extraction.md');
const lobby = read('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php');
const lobbyState = read('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_state.php');
const lobbySync = read('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_sync.php');
const websocket = read('demo/video-chat/backend-king-php/http/module_realtime_websocket.php');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const roomState = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const socketLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const workspaceView = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const template = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html');
const rosterPanel = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');
const messages = read('demo/video-chat/frontend-vue/src/modules/localization/callWorkspaceMessages.js');

for (const branch of [
  'local/iam-e2e-lobby-state-cleanup-proof',
  'codex/iam-e2e-lobby-state-cleanup-proof-20260509',
  'codex/iam-e2e-lobby-state-cleanup-script-gate-audit-20260509',
  'codex/iam-lobby-timeout-consistency-followup-20260509',
  'codex/iam-lobby-audit-cleanup-followup-20260509',
]) {
  requireIncludes(doc, branch, `extraction doc must record inspected branch ${branch}`);
}

for (const phrase of [
  'Stale queue rows must be removed',
  'Accepted users must leave `queue` and appear in `admitted`',
  'Rejected or removed users must leave both `queue` and `admitted`',
  'Recovery must be websocket-driven',
  'No manual refresh or reload control',
]) {
  requireIncludes(doc, phrase, `extraction doc must preserve proof value: ${phrase}`);
}

requireMatch(
  lobby,
  /if \(\$action === 'lobby\/queue\/join'\)[\s\S]*isset\(\$admittedByUser\[\$userId\]\)[\s\S]*'state' => 'already_admitted'/,
  'already admitted queue retries must not recreate stale queue rows',
);
requireMatch(
  lobby,
  /if \(\$action === 'lobby\/queue\/cancel'\)[\s\S]*unset\(\$queuedByUser\[\$userId\]\)[\s\S]*unset\(\$admittedByUser\[\$userId\]\)[\s\S]*'state' => 'cancelled'/,
  'queue cancel must remove queued and admitted lobby state',
);
requireMatch(
  lobby,
  /if \(\$action === 'lobby\/allow'\)[\s\S]*unset\(\$queuedByUser\[\$targetUserId\]\)[\s\S]*\$admittedByUser\[\$targetUserId\] = \[/,
  'allow must atomically move a user from queued to admitted state',
);
requireMatch(
  lobby,
  /if \(\$action === 'lobby\/remove'\)[\s\S]*unset\(\$queuedByUser\[\$targetUserId\]\)[\s\S]*unset\(\$admittedByUser\[\$targetUserId\]\)[\s\S]*videochat_lobby_prune_empty_room_state/,
  'remove/reject must clear all lobby state for the target user',
);
requireMatch(
  lobby,
  /\$currentRoomId !== 'waiting-room' && isset\(\$admittedByUser\[\$userId\]\)/,
  'waiting-room disconnect cleanup must preserve admitted handoff for call join recovery',
);
requireMatch(
  lobbyState,
  /function videochat_lobby_remove_user_from_room[\s\S]*unset\(\$queuedByUser\[\$userId\]\)[\s\S]*unset\(\$admittedByUser\[\$userId\]\)/,
  'admission handoff consumption must remove queued and admitted state',
);

requireMatch(
  lobbySync,
  /cp\.invite_state IN \('pending', 'allowed', 'accepted'\)[\s\S]*if \(\$inviteState === 'pending'\)[\s\S]*\$queuedByUser\[\$userId\]/,
  'DB lobby sync must recover pending participants as queued lobby entries',
);
requireMatch(
  lobbySync,
  /if \(\$joinedAt !== '' \|\| \$callRole === 'owner'\)[\s\S]*continue;[\s\S]*\$admittedByUser\[\$userId\]/,
  'DB lobby sync must recover unjoined allowed participants as admitted handoffs',
);
requireMatch(
  lobbySync,
  /function videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed[\s\S]*videochat_realtime_lobby_snapshot_signature[\s\S]*\$signature === \$lastSignature/,
  'lobby websocket recovery must use signature-gated snapshot backfill',
);

requireMatch(
  websocket,
  /videochat_realtime_send_synced_lobby_snapshot_to_connection\([\s\S]*'joined_room'/,
  'websocket attach must send an initial synced lobby snapshot',
);
requireMatch(
  websocket,
  /videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed\([\s\S]*'db_sync'/,
  'websocket loop must recover lobby state through changed snapshot backfill',
);
requireMatch(
  websocket,
  /if \(\$commandType === 'room\/snapshot\/request'\)[\s\S]*videochat_realtime_send_room_snapshot\([\s\S]*videochat_realtime_send_synced_lobby_snapshot_to_connection/,
  'snapshot requests must return both room and lobby websocket snapshots',
);
requireMatch(
  websocket,
  /videochat_lobby_remove_user_from_room\([\s\S]*'admission_consumed'/,
  'joining the admitted room must consume and broadcast lobby handoff cleanup',
);
requireMatch(
  websocketCommands,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'cancelled'[\s\S]*\['pending', 'allowed', 'accepted'\]/,
  'reject/remove persistence must clear accepted and pending lobby admission state',
);
requireMatch(
  websocketCommands,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'allowed'[\s\S]*\['pending'\][\s\S]*videochat_realtime_send_lobby_snapshot_to_users/,
  'accepted admission must persist allowed state before targeted admitted snapshots',
);

requireMatch(
  roomState,
  /function applyLobbySnapshot\(payload\)[\s\S]*const admittedRows = uniqueLobbyEntriesByUser\(payload\?\.admitted\)[\s\S]*filter\(\(entry\) => !admittedUserIds\.has/,
  'frontend lobby snapshots must let admitted entries win over stale queued rows',
);
requireMatch(
  roomState,
  /pendingAdmissionJoinRoomId\.value = roomId;[\s\S]*refs\.sendRoomJoin\(roomId\)/,
  'frontend admitted snapshot recovery must join the pending room over websocket state',
);
requireMatch(
  roomState,
  /for \(const key of Object\.keys\(lobbyActionState\)\)[\s\S]*key\.startsWith\('allow:'\)[\s\S]*key\.startsWith\('remove:'\)[\s\S]*delete lobbyActionState\[key\]/,
  'fresh lobby snapshots must clear stale row-level action pending state',
);
requireMatch(
  socketLifecycle,
  /if \(type === 'lobby\/snapshot'\) \{[\s\S]*applyLobbySnapshot\(payload\);[\s\S]*return;/,
  'frontend must apply lobby state from websocket snapshots',
);
requireIncludes(
  workspaceView,
  "sendSocketFrame({ type: 'room/snapshot/request' })",
  'frontend recovery must request websocket snapshot backfill',
);

assert.doesNotMatch(
  `${template}\n${rosterPanel}\n${messages}`,
  /refresh[_\s-]*lobby|reload[_\s-]*lobby|manual[_\s-]*refresh/i,
  'lobby state cleanup extraction must not add manual refresh/reload UI',
);

process.stdout.write('[call-access-lobby-state-cleanup-extract-contract] PASS\n');
