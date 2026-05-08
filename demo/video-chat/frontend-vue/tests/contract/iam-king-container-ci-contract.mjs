import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[iam-king-container-ci-contract] missing ${label}`);
}

const helper = read('demo/video-chat/backend-king-php/tests/call-access-king-container-helper.php');
const contract = read('demo/video-chat/backend-king-php/tests/call-access-king-container-contract.php');
const contractShell = read('demo/video-chat/backend-king-php/tests/call-access-king-container-contract.sh');
const proofScript = read('demo/video-chat/scripts/king-participant-container-proof.sh');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const smoke = read('demo/video-chat/scripts/smoke.sh');
const packageJson = read('demo/video-chat/frontend-vue/package.json');

const testIds = [
  'e2e_king_001_king_can_join_as_owner',
  'e2e_king_002_king_can_join_as_registered_user',
  'e2e_king_003_king_can_join_as_personalized_guest',
  'e2e_king_004_king_can_join_as_anonymous_guest',
  'e2e_king_005_king_streams_deterministic_dummy_media',
  'e2e_king_006_king_disconnects_gracefully',
  'e2e_king_007_king_simulates_abrupt_disconnect',
  'e2e_king_008_king_simulates_network_loss',
  'e2e_king_009_king_reconnects_same_identity',
  'e2e_king_010_king_exposes_call_state',
  'e2e_king_011_king_exposes_countdown_state',
  'e2e_king_012_king_logs_are_collected_on_failure',
  'e2e_king_013_multiple_king_containers_join_same_call',
  'e2e_king_014_king_containers_terminate_cleanly',
];

for (const testId of testIds) {
  requireContains(contract, testId, `${testId} proof mapping`);
}

requireContains(helper, 'function videochat_iam_king_container_create(', 'king container descriptor factory');
requireContains(helper, "'container_name' => videochat_iam_king_container_name", 'king-* container naming');
requireContains(helper, "'mode' => 'deterministic_dummy_media'", 'deterministic dummy media mode');
requireContains(helper, 'function videochat_iam_king_container_stream_dummy_media(', 'dummy media stream proof helper');
requireContains(helper, 'function videochat_iam_king_container_graceful_disconnect(', 'graceful disconnect helper');
requireContains(helper, 'function videochat_iam_king_container_abrupt_disconnect(', 'abrupt disconnect helper');
requireContains(helper, 'function videochat_iam_king_container_network_loss(', 'network loss helper');
requireContains(helper, 'function videochat_iam_king_container_reconnect_same_identity(', 'same-identity reconnect helper');
requireContains(helper, 'function videochat_iam_king_container_call_state(', 'call/countdown state helper');
requireContains(helper, 'function videochat_iam_king_container_collect_logs(', 'failure artifact log collector');
requireContains(helper, 'function videochat_iam_king_container_terminate(', 'clean termination helper');
requireContains(helper, 'videochat_realtime_remove_call_presence', 'termination removes realtime presence');
requireContains(helper, 'videochat_iam_king_container_safe_log_fields', 'sanitized log fields');

requireContains(contract, "videochat_iam_king_container_create('owner', 'owner')", 'owner king container');
requireContains(contract, "videochat_iam_king_container_create('registered', 'registered_user')", 'registered king container');
requireContains(contract, "videochat_iam_king_container_create('personalized', 'personalized_guest')", 'personalized guest king container');
requireContains(contract, "videochat_iam_king_container_create('anonymous', 'anonymous_guest')", 'anonymous guest king container');
requireContains(contract, 'videochat_iam_king_container_stream_dummy_media($owner, 3)', 'deterministic dummy media assertion');
requireContains(contract, 'videochat_iam_king_container_collect_logs([$owner, $registered, $personalized, $anonymous]', 'multi-container log collection');
requireContains(contract, 'videochat_iam_king_container_presence_count($pdo, $primaryCallId) === 0', 'clean termination presence assertion');
requireContains(contractShell, 'pdo_sqlite is not available', 'host-safe SQLite skip');
requireContains(proofScript, 'VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR', 'artifact directory override');
requireContains(proofScript, 'call-access-king-container-contract.sh', 'proof script executes backend contract');
requireContains(ciGate, 'tests/contract/iam-king-container-ci-contract.mjs', 'static CI gate includes king container contract');
requireContains(ciGate, 'tests/call-access-king-container-contract.sh', 'SQLite CI gate includes king container backend proof');
requireContains(smoke, 'king-participant-container-proof.sh', 'compose smoke wires king container proof');
requireContains(packageJson, 'tests/contract/iam-king-container-ci-contract.mjs', 'package IAM contract includes static king container proof');
requireContains(packageJson, '../scripts/king-participant-container-proof.sh', 'package IAM contract includes backend king container proof');

process.stdout.write('[iam-king-container-ci-contract] PASS\n');
