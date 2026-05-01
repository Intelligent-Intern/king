import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[call-room-leave-cleanup-contract] missing ${label}`);
}

const socketLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
const realtimeWebsocket = read('demo/video-chat/backend-king-php/http/module_realtime_websocket.php');
const realtimeRoomSnapshot = read('demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php');

requireContains(socketLifecycle, "if (type === 'room/left')", 'dedicated room left handler');
requireContains(socketLifecycle, 'const leftUserId = Number(payload?.participant?.user?.id || 0);', 'left participant user id extraction');
requireContains(socketLifecycle, 'removeParticipantLocallyAfterHangup(leftUserId);', 'immediate roster and media cleanup for room left');
requireContains(socketLifecycle, 'requestRoomSnapshot();', 'authoritative snapshot backfill after local leave cleanup');

requireContains(realtimeRoomSnapshot, 'function videochat_realtime_broadcast_room_snapshot', 'authoritative room snapshot broadcast helper');
requireContains(realtimeRoomSnapshot, 'videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason)', 'per-viewer room snapshot payload after leave');
requireContains(realtimeWebsocket, '$leavingRoomId = videochat_presence_normalize_room_id', 'leave path remembers previous room');
requireContains(realtimeWebsocket, "videochat_realtime_broadcast_room_snapshot(\n                        $presenceState,\n                        $leavingRoomId,\n                        $openDatabase,\n                        'participant_left',", 'leave path broadcasts post-cleanup snapshot');
requireContains(realtimeWebsocket, "videochat_realtime_broadcast_room_snapshot(\n                $presenceState,\n                $disconnectedRoomId,\n                $openDatabase,\n                'participant_disconnected',", 'disconnect path broadcasts post-cleanup snapshot');

process.stdout.write('[call-room-leave-cleanup-contract] PASS\n');
