import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '..', '..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function assertIncludes(source, needle, message) {
  assert.ok(String(source || '').includes(needle), message);
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const ciWire = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const proof3Inventory = readText('documentation/iam-sprint-04-proof3-inventory.md');
const deletedDisabledEvidence = readText('documentation/iam-sprint-04-deleted-disabled-extract-evidence.md');
const registeredInviteeEvidence = readText('documentation/iam-sprint-04-registered-invitee-extract-evidence.md');

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const iamCommandPaths = new Set(matrix.commands?.['frontend:contract:iam-call-access']?.paths || []);

const acceptedFocusedProofs = [
  {
    sourceEvidence: 'local/iam-e2e-abuse-logout-login-switch-proof-3',
    packagePath: 'tests/contract/call-access-logout-switch-extract-contract.mjs',
    matrixPath: 'frontend-vue/tests/contract/call-access-logout-switch-extract-contract.mjs',
  },
  {
    sourceEvidence: 'local/iam-e2e-registered-invitee-logged-in-proof-3',
    packagePath: 'tests/contract/call-access-registered-invitee-extract-contract.mjs',
    matrixPath: 'frontend-vue/tests/contract/call-access-registered-invitee-extract-contract.mjs',
  },
  {
    sourceEvidence: 'call-access-terminal-join-contract.php',
    packagePath: '../backend-king-php/tests/call-access-terminal-join-contract.sh',
    matrixPath: 'backend-king-php/tests/call-access-terminal-join-contract.sh',
  },
];

assertIncludes(
  proof3Inventory,
  'Port as focused duplicate-review/login-switch contract',
  'Sprint 04 inventory must record the accepted logout/login-switch extraction value',
);
assertIncludes(
  registeredInviteeEvidence,
  'call-access-registered-invitee-extract-contract.mjs',
  'registered invitee evidence must name the focused extract contract',
);
assertIncludes(
  deletedDisabledEvidence,
  'call-access-terminal-join-contract.php',
  'deleted/disabled evidence must name the focused terminal backend proof',
);

for (const proof of acceptedFocusedProofs) {
  assertIncludes(iamScript, proof.packagePath, `test:contract:iam-call-access must execute ${proof.packagePath}`);
  assert.ok(iamCommandPaths.has(proof.matrixPath), `release metadata must list ${proof.matrixPath}`);
  assertIncludes(ciWire, proof.matrixPath, `CI wire contract must require ${proof.matrixPath}`);
}

assertIncludes(
  iamScript,
  'tests/contract/iam-sprint-04-focused-wire-contract.mjs',
  'IAM package script must execute this Sprint 04 wiring completeness proof',
);
assert.ok(
  iamCommandPaths.has('frontend-vue/tests/contract/iam-sprint-04-focused-wire-contract.mjs'),
  'release metadata must list the Sprint 04 wiring completeness proof',
);
assertIncludes(
  ciWire,
  'frontend-vue/tests/contract/iam-sprint-04-focused-wire-contract.mjs',
  'CI wire contract must require the Sprint 04 wiring completeness proof',
);

assert.doesNotMatch(
  iamScript,
  /test:contract:(background|media)|background-|media-security|sfu-|gossip-/,
  'Sprint 04 IAM wiring must stay out of Background, Gossip, SFU, MediaSecurity, and BTGF gates',
);

process.stdout.write('[iam-sprint-04-focused-wire-contract] PASS\n');
