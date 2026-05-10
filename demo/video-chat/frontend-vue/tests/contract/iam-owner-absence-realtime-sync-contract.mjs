import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const videoChatRoot = path.resolve(__dirname, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(videoChatRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const ownerAbsence = read('backend-king-php/domain/realtime/realtime_owner_absence.php');
const presenceDb = read('backend-king-php/domain/realtime/realtime_call_presence_db.php');
const roomSnapshot = read('backend-king-php/domain/realtime/realtime_room_snapshot.php');
const lobbySync = read('backend-king-php/domain/realtime/realtime_lobby_sync.php');
const lobbySecurity = read('backend-king-php/http/module_realtime_lobby_security.php');
const backendContract = read('backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.php');
const backendContractSh = read('backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.sh');
const sqliteProof = read('backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = readJson('frontend-vue/package.json');

requireIncludes(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_TIMER_MS = 15 * 60 * 1000;', '15-minute owner absence timer');
requireIncludes(ownerAbsence, 'const VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS = 5 * 60 * 1000;', '5-minute owner absence countdown');
requireIncludes(ownerAbsence, 'function videochat_realtime_owner_absence_snapshot(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'server-clock owner absence snapshot');
requireIncludes(ownerAbsence, 'function videochat_realtime_apply_owner_absence_timeout(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array', 'server-clock owner absence timeout transition');
requireIncludes(ownerAbsence, 'function videochat_realtime_owner_absence_stale_owner_left_at_ms(', 'stale owner heartbeat cutoff');
requireIncludes(ownerAbsence, 'videochat_realtime_owner_absence_mark_stale_owner_left(', 'stale owner left_at materialization');
requireIncludes(ownerAbsence, "'status' => 'owner_present'", 'owner return status');
requireIncludes(ownerAbsence, "'status' => 'no_participants'", 'no participant status');
requireIncludes(ownerAbsence, "$status = $countdownStarted ? 'countdown' : 'monitoring';", 'monitoring/countdown split');
requireIncludes(ownerAbsence, "$status = 'ended';", 'ended state');
requireIncludes(ownerAbsence, "$payload['ended_reason'] = 'owner_absent_timeout';", 'owner absence timeout reason');
requireIncludes(ownerAbsence, 'videochat_realtime_owner_absence_disable_call_access_links(', 'timeout link invalidation');
requireIncludes(ownerAbsence, 'videochat_realtime_owner_absence_revoke_call_access_sessions(', 'timeout session revocation');
requireIncludes(ownerAbsence, 'videochat_realtime_owner_absence_downgrade_absent_owner_connection(', 'absent owner connection downgrade');
requireMatch(ownerAbsence, /'call_role'\] = 'participant'[\s\S]*'can_moderate_call'\] = false[\s\S]*'can_manage_call_owner'\] = false/, 'absent owner downgrade must remove active privileges');

requireIncludes(presenceDb, 'function videochat_realtime_presence_db_retention_ms(): int', 'presence retention helper');
requireIncludes(presenceDb, '20 * 60 * 1000', 'presence retention long enough for owner absence');
requireIncludes(presenceDb, 'max(', 'presence pruning must keep stale heartbeat rows beyond the short TTL');

requireIncludes(roomSnapshot, "require_once __DIR__ . '/realtime_owner_absence.php';", 'room snapshot owner absence import');
requireIncludes(roomSnapshot, '?int $nowMs = null', 'room snapshot testable server clock');
requireIncludes(roomSnapshot, 'videochat_realtime_apply_owner_absence_timeout($openDatabase(), $callId, $roomId, $nowMs)', 'room snapshot applies owner absence timeout');
requireIncludes(roomSnapshot, "'call_lifecycle' => [", 'room snapshot lifecycle payload');
requireIncludes(roomSnapshot, "'owner_absence' => $ownerAbsence", 'room snapshot owner absence payload');
requireIncludes(roomSnapshot, "'call_lifecycle' => $payload['call_lifecycle'] ?? [],", 'room snapshot signature includes lifecycle');
requireIncludes(roomSnapshot, '$absentOwnerUserId = (int) ($ownerAbsence[\'owner_user_id\'] ?? 0);', 'room snapshot must derive absent owner from server owner absence state');
requireIncludes(roomSnapshot, '!== $absentOwnerUserId', 'room snapshot must drop stale local owner participants when DB presence is absent');
requireIncludes(roomSnapshot, 'videochat_realtime_owner_absence_downgrade_absent_owner_connection(', 'room snapshot viewer must use absent owner downgrade');

requireIncludes(lobbySync, "require_once __DIR__ . '/realtime_owner_absence.php';", 'lobby sync owner absence import');
requireIncludes(lobbySync, 'videochat_realtime_connection_with_call_context($connection, $openDatabase)', 'lobby sync revalidates connection context');
requireIncludes(lobbySync, 'videochat_realtime_owner_absence_downgrade_absent_owner_connection(', 'lobby sync downgrades absent owner before filtering snapshot');
requireIncludes(lobbySecurity, 'stale_lobby_authority', 'lobby command denial for stale scoped authority');
requireIncludes(lobbySecurity, 'videochat_realtime_presence_db_has_room_membership($pdo, $normalizedRoomId, $callId, $userId)', 'lobby command must fail closed when scoped owner/moderator has no active DB presence');

for (const proofNeedle of [
  'owner_stale_heartbeat',
  'stale_lobby_authority',
  'owner_absent_lobby_sync',
  'owner_return',
  'countdown_a',
  'participant_refresh',
  'owner_absence_timeout',
  'revoked timeout call-access session must fail closed',
  'timeout denial must redact call payload',
  'stale owner snapshot after timeout must remove owner controls',
]) {
  requireIncludes(backendContract, proofNeedle, `backend proof must cover ${proofNeedle}`);
}
requireIncludes(backendContractSh, 'call-access-owner-absence-realtime-sync-contract.php', 'shell wrapper must execute backend proof');
requireIncludes(sqliteProof, 'call-access-owner-absence-realtime-sync-contract.sh', 'SQLite IAM proof must include owner absence realtime sync contract');

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
requireIncludes(iamContractScript, 'node tests/contract/iam-owner-absence-realtime-sync-contract.mjs', 'IAM static gate must include owner absence realtime sync contract');
requireIncludes(iamContractScript, '../backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.sh', 'IAM static gate must include backend owner absence wrapper');

process.stdout.write('[iam-owner-absence-realtime-sync-contract] PASS\n');
