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

const evidence = readText('documentation/iam-sprint-04-duplicate-cleanup-classification.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const ciWire = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');

assert.match(
  evidence,
  /Recommendation: `manual`/,
  'duplicate-cleanup source worktree must remain manually classified',
);
assert.match(
  evidence,
  /was not reset, rebased, checked out, cleaned, or deleted/,
  'classification evidence must document non-destructive handling',
);
assert.match(
  evidence,
  /UU demo\/video-chat\/frontend-vue\/package\.json/,
  'classification evidence must capture the unresolved package conflict',
);
assert.match(
  evidence,
  /100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo\/video-chat\/frontend-vue\/package\.json[\s\S]*100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo\/video-chat\/frontend-vue\/package\.json[\s\S]*100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo\/video-chat\/frontend-vue\/package\.json/,
  'classification evidence must preserve the conflict-stage ids',
);
assert.match(
  evidence,
  /suite helper and runner concept is the only current unique value/,
  'classification evidence must identify the unique suite-runner value',
);
assert.match(
  evidence,
  /cannot be safely extracted as-is/,
  'classification evidence must explain why the suite runner is not extracted',
);

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const callAccessE2eScript = String(packageJson.scripts?.['test:e2e:call-access'] || '');
assert.notEqual(iamContractScript, '', 'IAM call-access contract gate must remain present');
assert.notEqual(callAccessE2eScript, '', 'IAM call-access E2E gate must remain present');
assert.equal(
  iamContractScript.includes('node tests/contract/iam-call-access-contract-suite.mjs'),
  false,
  'stable IAM contract gate must not adopt the unresolved duplicate-cleanup suite runner',
);
assert.equal(
  callAccessE2eScript.includes('node tests/e2e/call-access-e2e-suite.mjs'),
  false,
  'stable IAM E2E gate must not adopt the unresolved duplicate-cleanup E2E suite runner',
);

for (const absentLegacyTarget of [
  'call-access-identity-mismatch-review-flow-contract.mjs',
  'call-access-privacy-foreign-data-contract.mjs',
  'call-access-safe-screen-final-contract.mjs',
  'call-access-multi-session-device-safety-contract.mjs',
  'call-access-link-invalidation-durability-contract.mjs',
  'call-access-security-manipulation-contract.mjs',
  'call-access-parallel-account-tabs-contract.mjs',
  'call-access-cross-org-foreign-join-contract.mjs',
  'iam-king-container-ci-contract.mjs',
  'iam-lobby-management-moderator-rights-contract.mjs',
  'call-access-strong-mismatch-host-verification.spec.js',
  'call-access-duplicate-race.spec.js',
  'call-access-rejoin-kick-membership.spec.js',
]) {
  assert.equal(
    iamContractScript.includes(absentLegacyTarget) || callAccessE2eScript.includes(absentLegacyTarget),
    false,
    `stable IAM gates must not reference unresolved duplicate-cleanup target ${absentLegacyTarget}`,
  );
}

assert.match(
  ciWire,
  /requiredIamContractPaths[\s\S]*iam-backend-docker-runtime-proof-wrapper\.sh/,
  'current CI-wire contract must keep explicit stable IAM proof path coverage',
);
const iamMatrixPaths = matrix.commands?.['frontend:contract:iam-call-access']?.paths || [];
assert.ok(
  Array.isArray(iamMatrixPaths) && iamMatrixPaths.includes('backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh'),
  'release-gate metadata must keep current Sprint 03 IAM runtime wrapper path',
);

process.stdout.write('[iam-duplicate-cleanup-classification-contract] PASS\n');
