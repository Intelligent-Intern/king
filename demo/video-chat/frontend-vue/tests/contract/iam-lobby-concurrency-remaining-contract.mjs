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

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const sprint = read('SPRINT.md');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const backendContract = read('demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php');
const backendContractShell = read('demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.sh');
const lobbySpec = read('demo/video-chat/frontend-vue/tests/e2e/lobby-concurrency-ui.spec.js');
const roomState = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const participantUi = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');

const scripts = packageJson.scripts || {};
const lobbyScript = String(scripts['test:e2e:lobby-concurrency'] || '');
const contractScript = String(scripts['test:contract:iam-call-access'] || '');
const lobbyMatrixCommand = matrix.commands?.['frontend:e2e:lobby-concurrency'] || {};

for (const e2eCase of [
  'e2e_lobby_010_concurrent_admission_idempotent',
  'e2e_lobby_011_concurrent_admit_reject_deterministic',
  'e2e_lobby_012_lobby_state_updates_correctly',
]) {
  requireIncludes(lobbySpec, e2eCase, `Playwright lobby concurrency spec must name ${e2eCase}`);
  requireIncludes(sprint, `- [x] \`${e2eCase}\``, `SPRINT.md must close ${e2eCase} only with this proof`);
}

requireIncludes(
  sprint,
  '- [x] Lobby status updates correctly',
  'SPRINT.md must close the narrative lobby status item after browser snapshot proof',
);
requireIncludes(
  sprint,
  '- [x] Participant is removed from lobby after admission',
  'SPRINT.md must close the admission-removal item after browser stale snapshot proof',
);
requireIncludes(
  sprint,
  '`iam-lobby-concurrency-remaining-contract.mjs` binds `e2e_lobby_010`,',
  'SPRINT.md proof narrative must name the static proof binding the target lobby IDs',
);

requireIncludes(
  lobbyScript,
  'playwright test tests/e2e/lobby-concurrency-ui.spec.js',
  'package.json must expose the focused lobby-concurrency Playwright command',
);
assert.equal(
  lobbyMatrixCommand.script,
  'test:e2e:lobby-concurrency',
  'UI parity matrix must bind the lobby concurrency command to the package script',
);
assert.ok(
  (lobbyMatrixCommand.paths || []).includes('frontend-vue/tests/e2e/lobby-concurrency-ui.spec.js'),
  'UI parity matrix must list the lobby concurrency browser proof spec',
);
requireIncludes(
  contractScript,
  'node tests/contract/iam-lobby-concurrency-remaining-contract.mjs',
  'IAM call-access contract script must execute this lobby concurrency proof binding',
);
requireIncludes(
  ciGate,
  '"tests/contract/iam-lobby-concurrency-remaining-contract.mjs"',
  'IAM call-access CI gate must execute this lobby concurrency proof binding',
);
requireIncludes(
  ciGate,
  '"tests/realtime-lobby-concurrency-contract.sh"',
  'IAM call-access CI gate must include the backend lobby concurrency contract',
);
requireIncludes(
  backendContractShell,
  'realtime-lobby-concurrency-contract.php',
  'backend lobby concurrency shell must execute the PHP contract',
);

requireMatch(
  backendContract,
  /workerAState[\s\S]*workerBState[\s\S]*workerAAllow[\s\S]*workerBAllow[\s\S]*concurrent allow should persist one allowed state/s,
  'backend contract must simulate two stale workers admitting the same queued participant',
);
requireMatch(
  backendContract,
  /allowed canonical queue should be empty[\s\S]*concurrent allow should create one admitted handoff[\s\S]*late duplicate allow should be idempotent[\s\S]*already_allowed/s,
  'backend contract must prove e2e_lobby_010 idempotent admission and no duplicate handoff',
);
requireMatch(
  backendContract,
  /admit-then-reject race[\s\S]*reject should win after admit-then-reject race[\s\S]*admit-then-reject should leave no admitted handoff/s,
  'backend contract must prove reject wins after an admit-then-reject race',
);
requireMatch(
  backendContract,
  /reject side of reject-then-admit race[\s\S]*stale admit side should not error before DB compare-and-set[\s\S]*reject should win after reject-then-stale-admit race[\s\S]*reject-then-stale-admit should leave no admitted handoff/s,
  'backend contract must prove reject wins before a stale admit replay',
);
requireMatch(
  websocketCommands,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'allowed'[\s\S]*\['pending'\]/s,
  'successful lobby admission must persist through a pending-only compare-and-set',
);
requireMatch(
  websocketCommands,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'invited'[\s\S]*\['pending', 'allowed', 'accepted'\]/s,
  'successful lobby rejection must clear pending or admitted handoff state deterministically',
);

requireMatch(
  lobbySpec,
  /concurrent_duplicate_queue[\s\S]*lobbyEntry\(\{ requested_unix_ms: 1_780_600_000_000 \}\)[\s\S]*lobbyEntry\(\{ requested_unix_ms: 1_780_600_000_100 \}\)[\s\S]*toHaveCount\(1\)[\s\S]*tab-notice-badge'\)\)\.toHaveText\('1'\)/s,
  'browser proof must collapse duplicate queued snapshots into one lobby row and badge count',
);
requireMatch(
  lobbySpec,
  /allowButton\.click\(\)[\s\S]*window\.__matrixSocketFrames[\s\S]*frame\?\.type === 'lobby\/allow'[\s\S]*toBe\(1\)[\s\S]*allowButton\)\.toBeDisabled\(\)/s,
  'browser proof must send exactly one allow command and disable the in-flight control',
);
requireMatch(
  lobbySpec,
  /concurrent_admitted_wins_over_stale_queue[\s\S]*queue:[\s\S]*admitted:[\s\S]*toHaveCount\(0\)[\s\S]*button\[title="Allow user"\]'\)\)\.toHaveCount\(0\)[\s\S]*user-list-empty'\)\)\.toBeVisible\(\)/s,
  'browser proof must remove stale queued rows and controls when admitted state arrives',
);
requireMatch(
  lobbySpec,
  /participantRow\(\{ connectionId: 'conn-waiting-a'[\s\S]*participantRow\(\{ connectionId: 'conn-waiting-b'[\s\S]*toHaveCount\(1\)[\s\S]*button\[title="Remove from lobby"\]'\)\)\.toBeDisabled\(\)/s,
  'browser proof must collapse duplicate room participants and hide stale lobby kick controls',
);
requireMatch(
  lobbySpec,
  /reject_final_empty[\s\S]*button\[title="Allow user"\]'\)\)\.toHaveCount\(0\)[\s\S]*user-list-empty'\)\)\.toBeVisible\(\)/s,
  'browser proof must leave an empty lobby after final reject state',
);

requireMatch(
  roomState,
  /function uniqueLobbyEntriesByUser\(entries\)[\s\S]*rows\.set\(userId, normalized\)[\s\S]*return Array\.from\(rows\.values\(\)\)/s,
  'workspace room state must dedupe lobby queue/admitted entries by user',
);
requireMatch(
  roomState,
  /admittedUserIds[\s\S]*uniqueLobbyEntriesByUser\(payload\?\.queue\)[\s\S]*filter\(\(entry\) => !admittedUserIds\.has/s,
  'workspace room state must let admitted snapshots override stale queued rows',
);
requireMatch(
  roomState,
  /delete lobbyActionState\[key\][\s\S]*refreshUsersDirectoryPresentation\(\)/s,
  'workspace room state must clear pending lobby controls and refresh participant presentation after snapshots',
);
requireMatch(
  participantUi,
  /const lobbyRows = computed\(\(\) => \{[\s\S]*lobbyQueue\.value\.map[\s\S]*status: 'queued'/s,
  'lobby panel rows must render from the authoritative queue state only',
);

process.stdout.write('[iam-lobby-concurrency-remaining-contract] PASS\n');
