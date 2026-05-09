import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  callAccessE2eSpecs,
  callAccessE2eSuiteText,
  iamCallAccessContractCommands,
  iamCallAccessContractSuiteText,
} from './helpers/iamCallAccessSuiteCoverage.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const lobbyStateCleanupSpec = readText('demo/video-chat/frontend-vue/tests/e2e/iam-lobby-state-cleanup.spec.js');
const backendContract = readText('demo/video-chat/backend-king-php/tests/realtime-lobby-state-cleanup-contract.php');
const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

const scripts = packageJson.scripts || {};
const callAccessScript = callAccessE2eSuiteText;
const focusedScript = String(scripts['test:e2e:lobby-state-cleanup'] || '');
const contractScript = iamCallAccessContractSuiteText;
const focusedMatrixCommand = matrix.commands?.['frontend:e2e:lobby-state-cleanup'] || {};
const callAccessPaths = new Set(matrix.commands?.['frontend:e2e:call-access']?.paths || []);
const focusedPaths = new Set(focusedMatrixCommand.paths || []);

assert.match(
  String(scripts['test:e2e:call-access'] || ''),
  /call-access-e2e-suite\.mjs/,
  'package script must expose the Call Access E2E suite helper',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/iam-lobby-state-cleanup\.spec\.js/,
  'Call Access E2E script must include focused lobby state cleanup coverage',
);
assert.ok(
  callAccessE2eSpecs.includes('tests/e2e/iam-lobby-state-cleanup.spec.js'),
  'Call Access E2E suite must list the lobby state cleanup spec',
);
assert.match(
  focusedScript,
  /^playwright test tests\/e2e\/iam-lobby-state-cleanup\.spec\.js --workers=1$/,
  'package script must expose a serialized lobby-state-cleanup Playwright command',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /iam-call-access-contract-suite\.mjs/,
  'package script must expose the IAM Call Access contract suite helper',
);
assert.match(
  contractScript,
  /node tests\/contract\/iam-lobby-state-cleanup-proof-contract\.mjs/,
  'IAM Call Access contract gate must include this focused lobby cleanup proof contract',
);
assert.ok(
  iamCallAccessContractCommands.includes('node tests/contract/iam-lobby-state-cleanup-proof-contract.mjs'),
  'IAM Call Access contract suite must list this focused lobby cleanup proof contract',
);
assert.match(
  contractScript,
  /\.\.\/backend-king-php\/tests\/realtime-lobby-state-cleanup-contract\.sh/,
  'IAM Call Access contract gate must include the backend lobby state cleanup proof',
);
assert.ok(
  iamCallAccessContractCommands.includes('../backend-king-php/tests/realtime-lobby-state-cleanup-contract.sh'),
  'IAM Call Access contract suite must list the backend lobby state cleanup proof',
);

assert.equal(focusedMatrixCommand.script, 'test:e2e:lobby-state-cleanup');
assert.equal(focusedMatrixCommand.command, 'npm run test:e2e:lobby-state-cleanup');
assert.ok(
  focusedPaths.has('frontend-vue/tests/e2e/iam-lobby-state-cleanup.spec.js'),
  'UI parity matrix must expose the focused lobby state cleanup command',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/iam-lobby-state-cleanup.spec.js'),
  'focused Call Access matrix command must list the lobby state cleanup E2E spec',
);

assert.match(
  ciGate,
  /STATIC_CONTRACTS=\([\s\S]*tests\/contract\/iam-lobby-state-cleanup-proof-contract\.mjs/s,
  'IAM CI static gate must include this focused lobby cleanup proof contract',
);
assert.match(
  ciGate,
  /FULL_STATIC_CONTRACTS=\([\s\S]*tests\/contract\/iam-lobby-state-cleanup-proof-contract\.mjs/s,
  'IAM CI full static gate must include this focused lobby cleanup proof contract',
);
assert.match(
  ciGate,
  /HOST_BACKEND_CONTRACTS=\([\s\S]*tests\/realtime-lobby-state-cleanup-contract\.sh/s,
  'IAM CI gate must include the host-safe lobby state cleanup backend proof',
);
assert.match(
  ciGate,
  /available\)[\s\S]*run_static_gate 0[\s\S]*run_host_backend_gate[\s\S]*run_sqlite_gate 0/s,
  'available IAM CI gate must run the host backend lobby cleanup proof',
);
assert.match(
  ciGate,
  /full\)[\s\S]*run_static_gate 1[\s\S]*run_host_backend_gate[\s\S]*run_sqlite_gate 1/s,
  'full IAM CI gate must run the host backend lobby cleanup proof before SQLite-only proofs',
);

assert.match(
  lobbyStateCleanupSpec,
  /e2e_lobby_012_lobby_state_updates_correctly[\s\S]*lobby\/allow[\s\S]*iam_state_cleanup_admitted/s,
  'lobby state cleanup E2E must prove host-side status updates and removal after admission',
);
assert.match(
  lobbyStateCleanupSpec,
  /iam_state_cleanup_abort_cancelled[\s\S]*lobby\/queue\/cancel/s,
  'lobby state cleanup E2E must prove abort sends queue cancel and removes the host lobby row',
);
assert.match(
  lobbyStateCleanupSpec,
  /e2e_lobby_008_rejected_participant_cannot_enter[\s\S]*lobby_rejected[\s\S]*workspace-call-view[\s\S]*toHaveCount\(0\)/s,
  'lobby state cleanup E2E must prove rejected participants do not enter the workspace',
);
assert.match(
  lobbyStateCleanupSpec,
  /call_access_lobby_admitted[\s\S]*admitted participant enters call[\s\S]*waitForWorkspace/s,
  'lobby state cleanup E2E must prove admitted participants enter the call workspace',
);

assert.match(
  backendContract,
  /lobby\/queue\/cancel[\s\S]*aborted join should leave no queued row/s,
  'backend lobby cleanup contract must prove queue cancellation removes aborted join attempts',
);
assert.match(
  backendContract,
  /lobby\/allow[\s\S]*admitted participant should leave lobby queue[\s\S]*already_admitted/s,
  'backend lobby cleanup contract must prove admitted participants leave queue and cannot be requeued',
);
assert.match(
  backendContract,
  /lobby\/reject[\s\S]*rejected participant must not be admitted/s,
  'backend lobby cleanup contract must prove rejected participants are removed without admission',
);
