import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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

function byKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    assert.notEqual(key, '', `${label} row must have a key`);
    assert.equal(index.has(key), false, `${label} row key must be unique: ${key}`);
    index.set(key, row);
  }
  return index;
}

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `${label} must exist: ${key}`);
  return value;
}

function assertContains(source, needle, message) {
  assert.ok(String(source || '').includes(needle), message);
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const phpProof = readText('demo/video-chat/backend-king-php/tests/call-access-deleted-ended-disabled-join-contract.php');
const shellProof = readText('demo/video-chat/backend-king-php/tests/call-access-deleted-ended-disabled-join-contract.sh');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

const calls = byKey(matrix.calls, 'call');
const scenarios = byKey(matrix.scenarios, 'scenario');

const terminalDenials = [
  {
    scenarioKey: 'direct_join_system_admin_alpha_ended_denied',
    callKey: 'alpha_ended',
    status: 'ended',
    resolveStatus: 200,
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callErrorCode: 'calls_forbidden',
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_disabled_denied',
    callKey: 'alpha_disabled',
    status: 'disabled',
    resolveStatus: 200,
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callErrorCode: 'calls_forbidden',
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_deleted_hidden',
    callKey: 'alpha_deleted',
    status: 'deleted',
    resolveStatus: 404,
    resolveErrorCode: 'calls_not_found',
    callStatus: 404,
    callErrorCode: 'calls_not_found',
  },
];

for (const denial of terminalDenials) {
  const call = row(calls, denial.callKey, 'IAM9-11 terminal call');
  const scenario = row(scenarios, denial.scenarioKey, 'IAM9-11 terminal denial scenario');
  const expected = scenario.expected || {};

  assert.equal(call.status, denial.status, `${denial.callKey} must keep terminal status`);
  assert.equal(scenario.call_key, denial.callKey, `${denial.scenarioKey} must target the terminal call`);
  assert.equal(expected.direct_join_allowed, false, `${denial.scenarioKey} must deny direct join`);
  assert.equal(expected.private_call_payload_forbidden, true, `${denial.scenarioKey} must redact private call payloads`);
  assert.equal(expected.expected_call_status_value, denial.status, `${denial.scenarioKey} must record denied status value`);
  assert.equal(expected.expected_resolve_status, denial.resolveStatus, `${denial.scenarioKey} resolve status mismatch`);
  assert.equal(expected.expected_call_status, denial.callStatus, `${denial.scenarioKey} call-fetch status mismatch`);
  assert.equal(expected.expected_call_error_code, denial.callErrorCode, `${denial.scenarioKey} call-fetch error mismatch`);

  if (denial.resolveStatus === 200) {
    assert.equal(expected.expected_resolve_state, 'forbidden', `${denial.scenarioKey} must resolve as forbidden`);
    assert.equal(expected.expected_resolve_reason, denial.resolveReason, `${denial.scenarioKey} resolve reason mismatch`);
  } else {
    assert.equal(expected.expected_resolve_error_code, denial.resolveErrorCode, `${denial.scenarioKey} resolve error mismatch`);
  }

  assertContains(
    seedMatrixSpec,
    denial.scenarioKey,
    `Playwright seed matrix must exercise ${denial.scenarioKey}`,
  );
}

assert.match(
  phpProof,
  /foreach \(\['ended', 'cancelled', 'deleted'\] as \$state\)[\s\S]*videochat_iam7_11_assert_terminal_case/,
  'PHP runtime proof must cover ended and deleted terminal call transitions without relying on matrix-only checks',
);
assert.match(
  phpProof,
  /UPDATE users SET status = 'disabled'[\s\S]*call_access_user_inactive[\s\S]*disabled user binding fetch should be quarantined/,
  'PHP runtime proof must cover disabled-user stale join denial and binding quarantine',
);
assert.match(
  phpProof,
  /videochat_user_can_direct_join_call[\s\S]*guest-list direct join should fail[\s\S]*videochat_realtime_connection_can_join_call_scoped_room[\s\S]*cached owner connection must not rejoin terminal room/,
  'PHP runtime proof must deny direct API joins and stale realtime room rejoins for terminal calls',
);
assert.match(
  phpProof,
  /videochat_iam7_11_assert_body_omits[\s\S]*leaked/,
  'PHP runtime proof must assert denied responses omit private call/access/session details',
);
assert.match(
  shellProof,
  /"\$\{PHP_BIN\}" "\$\{SCRIPT_DIR\}\/call-access-deleted-ended-disabled-join-contract\.php"/,
  'shell wrapper must execute the extracted deleted/ended/disabled runtime proof',
);

assert.equal(
  packageJson.scripts?.['test:contract:iam9-11-terminal-join-denials'],
  'node tests/contract/iam9-11-terminal-join-denials-contract.mjs && ../backend-king-php/tests/call-access-deleted-ended-disabled-join-contract.sh',
  'package.json must expose the focused IAM9-11 static plus PHP runtime proof command',
);
assert.match(
  ciGate,
  /STATIC_CONTRACTS=\([\s\S]*iam9-11-terminal-join-denials-contract\.mjs/,
  'IAM static CI gate must include the focused IAM9-11 terminal denial contract',
);
assert.doesNotMatch(
  packageJson.scripts?.['test:contract:iam9-11-terminal-join-denials'] || '',
  /deleted-ended-disabled-followup-proof-3|iam9-10-terminal-followup-denials|adjacent terminal join denials/i,
  'IAM9-11 command must not take ownership of IAM9-10 adjacent/follow-up denial work',
);

process.stdout.write('[iam9-11-terminal-join-denials-contract] PASS\n');
