import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const videoChatRoot = path.resolve(__dirname, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(videoChatRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[iam-king-participants-owner-timeout-contract] missing ${label}`);
}

const ownerAbsence = read('backend-king-php/domain/realtime/realtime_owner_absence.php');
const roomSnapshot = read('backend-king-php/domain/realtime/realtime_room_snapshot.php');
const kingParticipantsHelper = read('backend-king-php/tests/call-access-king-participants-helper.php');
const ownerTimeoutContract = read('backend-king-php/tests/call-access-owner-timeout-contract.php');

requireContains(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_TIMER_MS = 15 * 60 * 1000;', '15-minute owner absence timer');
requireContains(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS = 5 * 60 * 1000;', '5-minute owner absence countdown');
requireContains(ownerAbsence, 'function videochat_realtime_owner_absence_snapshot(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'CI-safe owner absence snapshot clock');
requireContains(ownerAbsence, 'function videochat_realtime_apply_owner_absence_timeout(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'CI-safe owner absence transition clock');
requireContains(ownerAbsence, "'status' => 'owner_present'", 'owner return cancellation status');
requireContains(ownerAbsence, "'status' => 'no_participants'", 'no-participant non-ending state');
requireContains(ownerAbsence, "$status = $countdownStarted ? 'countdown' : 'monitoring';", 'monitoring-to-countdown state split');
requireContains(ownerAbsence, "$status = 'ended';", 'implicit ended state');
requireContains(ownerAbsence, "SET status = 'ended',", 'persisted implicit call ending');
requireContains(ownerAbsence, "$payload['ended_reason'] = 'owner_absent_timeout';", 'owner absence end reason');

requireContains(roomSnapshot, "require_once __DIR__ . '/realtime_owner_absence.php';", 'room snapshot owner-absence helper import');
requireContains(roomSnapshot, 'function videochat_realtime_db_room_participants(callable $openDatabase, array $connection, ?int $nowMs = null): array', 'CI-safe room participant clock');
requireContains(roomSnapshot, 'videochat_realtime_db_room_participants($openDatabase, $connection, $nowMs)', 'room snapshot uses fake clock for DB participants');
requireContains(roomSnapshot, 'videochat_realtime_apply_owner_absence_timeout($openDatabase(), $callId, $roomId, $nowMs)', 'authoritative snapshot owner-timeout application');
requireContains(roomSnapshot, "'call_lifecycle' => [", 'room snapshot lifecycle payload');
requireContains(roomSnapshot, "'owner_absence' => $ownerAbsence", 'room snapshot owner absence payload');
requireContains(roomSnapshot, "'call_lifecycle' => $payload['call_lifecycle'] ?? [],", 'room snapshot signature includes lifecycle');

requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_client(', 'simulated King participant client helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_touch(PDO $pdo, array $connection, int $nowMs): void', 'simulated King participant heartbeat helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_leave(', 'simulated King participant leave helper');
requireContains(kingParticipantsHelper, 'function videochat_iam_king_participant_snapshot(', 'simulated King participant snapshot helper');
requireContains(kingParticipantsHelper, 'videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);', 'participant clients use fake clock presence upsert');
requireContains(kingParticipantsHelper, 'videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);', 'participant leave removes realtime presence');

requireContains(ownerTimeoutContract, 'videochat_iam_king_participant_client(', 'owner-timeout contract uses simulated clients');
requireContains(ownerTimeoutContract, 'VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - 1000', 'before-countdown boundary proof');
requireContains(ownerTimeoutContract, "($beforeCountdown['status'] ?? '') === 'monitoring'", 'monitoring assertion');
requireContains(ownerTimeoutContract, "($countdown['status'] ?? '') === 'countdown'", 'countdown assertion');
requireContains(ownerTimeoutContract, "($countdown['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS", 'five-minute countdown assertion');
requireContains(ownerTimeoutContract, "($ownerReturn['status'] ?? '') === 'owner_present'", 'owner return cancellation assertion');
requireContains(ownerTimeoutContract, "videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === ''", 'owner left marker cancellation assertion');
requireContains(ownerTimeoutContract, "($ended['status'] ?? '') === 'ended'", 'implicit end assertion');
requireContains(ownerTimeoutContract, "videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended'", 'persisted ended assertion');

process.stdout.write('[iam-king-participants-owner-timeout-contract] PASS\n');
