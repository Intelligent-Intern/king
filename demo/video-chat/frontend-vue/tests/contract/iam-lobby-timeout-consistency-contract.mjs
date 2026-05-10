import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

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

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const sprint = read('SPRINT.md');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const lobbyPersistence = read('demo/video-chat/backend-king-php/http/module_realtime_lobby_persistence.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/realtime-lobby-timeout-consistency-contract.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/realtime-lobby-timeout-consistency-contract.sh');

const iamContractScript = iamCallAccessContractSuiteText;
requireIncludes(
  String(packageJson.scripts?.['test:contract:iam-call-access'] || ''),
  'iam-call-access-contract-suite.mjs',
  'IAM contract package script must run the suite helper',
);
requireIncludes(
  iamContractScript,
  'node tests/contract/iam-lobby-timeout-consistency-contract.mjs',
  'IAM contract script must run the lobby timeout static proof',
);
requireIncludes(
  iamContractScript,
  '../backend-king-php/tests/realtime-lobby-timeout-consistency-contract.sh',
  'IAM contract script must run the lobby timeout backend proof',
);
requireIncludes(
  ciGate,
  '"tests/contract/iam-lobby-timeout-consistency-contract.mjs"',
  'IAM CI static gate must include the lobby timeout static proof',
);
requireIncludes(
  ciGate,
  '"tests/realtime-lobby-timeout-consistency-contract.sh"',
  'IAM CI SQLite gate must include the lobby timeout backend proof',
);
requireIncludes(
  backendShell,
  'realtime-lobby-timeout-consistency-contract.php',
  'backend shell must execute the lobby timeout PHP contract',
);

for (const sprintLine of [
  '- [x] Participant is removed from lobby after aborting join attempt',
  '- [x] Timeout during lobby admission leads to consistent state',
]) {
  requireIncludes(sprint, sprintLine, `SPRINT.md must close ${sprintLine} only with this proof`);
}
requireMatch(
  sprint,
  /`realtime-lobby-timeout-consistency-contract` proves timeout during lobby\s+admission leaves the database pending[\s\S]*aborting the waiting connection clears\s+the lobby and resets the participant to invited/s,
  'SPRINT.md proof narrative must name the focused lobby timeout contract and both closed leaves',
);

requireMatch(
  websocketCommands,
  /videochat_lobby_apply_command\([\s\S]*videochat_realtime_lobby_command_sender\(\$lobbyCommand\)/s,
  'runtime websocket path must route lobby commands through the persistence-aware sender',
);
requireMatch(
  lobbyPersistence,
  /function videochat_realtime_lobby_command_sender[\s\S]*\['lobby\/allow', 'lobby\/allow_all'\][\s\S]*static fn \(mixed \$_socket, array \$_payload\): bool => false/s,
  'lobby admission snapshots must be deferred before persistence is confirmed',
);
requireMatch(
  lobbyPersistence,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'allowed'[\s\S]*\['pending'\]/s,
  'lobby admission must persist through a pending-only compare-and-set',
);
requireMatch(
  lobbyPersistence,
  /videochat_realtime_sync_lobby_room_from_database\([\s\S]*\$syncOk = \(bool\) \(\$sync\['ok'\] \?\? false\)[\s\S]*if \(!\$syncOk && \$unpersistedUserIds !== \[\]\)[\s\S]*videochat_realtime_repair_unpersisted_lobby_admissions/s,
  'failed or timed-out admission sync must repair unpersisted local handoffs',
);
requireMatch(
  lobbyPersistence,
  /\$syncOk && \$unpersistedUserIds !== \[\][\s\S]*\$canonicalAdmitted[\s\S]*\$persistedUserIds\[\] = \$unpersistedUserId/s,
  'successful DB resync must classify stale duplicate admissions as persisted admitted handoffs',
);
requireMatch(
  lobbyPersistence,
  /videochat_lobby_broadcast_room_snapshot\([\s\S]*\$reason[\s\S]*videochat_realtime_send_lobby_snapshot_to_users\([\s\S]*\$persistedUserIds[\s\S]*'admitted'/s,
  'lobby admission publication must happen after persistence and repair',
);

for (const proofText of [
  'Timeout during lobby admission leads to consistent state: database remains pending',
  'timeout should restore local queued participant',
  'timeout must leave no unpersisted admitted handoff',
  'Participant is removed from lobby after aborting join attempt: database state should reset',
  'aborted join should leave no queued lobby participant',
  'fresh DB sync after abort should keep lobby empty',
]) {
  requireIncludes(backendContract, proofText, `backend proof must assert ${proofText}`);
}
requireMatch(
  backendContract,
  /videochat_realtime_lobby_command_sender\(\$allowCommand\) !== null[\s\S]*videochat_lobby_apply_command\([\s\S]*videochat_realtime_lobby_command_sender\(\$allowCommand\)[\s\S]*videochat_realtime_apply_successful_lobby_command\([\s\S]*\$timeoutOpenDatabase/s,
  'backend proof must drive a deferred lobby admission through a simulated timeout',
);
requireMatch(
  backendContract,
  /videochat_lobby_clear_for_connection\([\s\S]*'abort_join_attempt'[\s\S]*videochat_realtime_reset_waiting_connection_invite\([\s\S]*'abort_join_attempt'/s,
  'backend proof must drive abort cleanup through the same clear/reset helpers used by websocket detach',
);

process.stdout.write('[iam-lobby-timeout-consistency-contract] PASS\n');
