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

const evidence = readText('documentation/iam-sprint-05-duplicate-cleanup-family.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const scripts = packageJson.scripts || {};

assert.match(
  evidence,
  /No source worktree was\s+reset, checked out, cleaned, rebased, merged, conflict-resolved, or deleted\./,
  'IAM5-03 evidence must prove the source dirty worktrees were preserved',
);
for (const branch of [
  'codex/iam-duplicate-cleanup',
  'codex/iam-duplicate-cleanup-reaudit-20260509',
  'codex/iam-duplicate-cleanup-current-reaudit-20260509',
  'codex/iam-duplicate-cleanup-latest-reaudit-20260509',
]) {
  assert.ok(evidence.includes(branch), `IAM5-03 evidence must classify ${branch}`);
}

assert.match(
  evidence,
  /UU demo\/video-chat\/frontend-vue\/package\.json/,
  'IAM5-03 evidence must retain the unresolved package conflict state',
);
assert.match(
  evidence,
  /100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo\/video-chat\/frontend-vue\/package\.json[\s\S]*100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo\/video-chat\/frontend-vue\/package\.json[\s\S]*100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo\/video-chat\/frontend-vue\/package\.json/,
  'IAM5-03 evidence must preserve package conflict stage object ids',
);

assert.match(
  evidence,
  /test:contract:iam-call-access = node tests\/contract\/iam-call-access-contract-suite\.mjs/,
  'IAM5-03 evidence must extract the current contract suite package value',
);
assert.match(
  evidence,
  /test:e2e:call-access = node tests\/e2e\/call-access-e2e-suite\.mjs/,
  'IAM5-03 evidence must extract the current E2E suite package value',
);
assert.match(
  evidence,
  /The current Sprint 05 base package scripts do not already carry the\s+duplicate-cleanup suite refactor\./,
  'IAM5-03 evidence must identify current Sprint 05 package-script state',
);
assert.match(
  evidence,
  /Any future implementation of the suite runner should be\s+rebuilt in a focused lane against the current Sprint 05 proof inventory/,
  'IAM5-03 evidence must avoid whole-branch extraction from the stale family',
);

const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
assert.notEqual(iamContractScript, '', 'current package.json must keep the IAM contract script present');
assert.notEqual(callAccessE2eScript, '', 'current package.json must keep the call-access E2E script present');
assert.equal(
  iamContractScript,
  [
    'node tests/contract/iam-call-access-ci-wire-contract.mjs',
    'node tests/contract/iam-sprint-03-inventory-contract.mjs',
    'node tests/contract/iam-sprint-04-focused-wire-contract.mjs',
    'node tests/contract/call-access-ci-artifacts-contract.mjs',
    'node tests/contract/call-access-forged-identifiers-contract.mjs',
    'node tests/contract/call-access-tampered-verified-context-contract.mjs',
    'node tests/contract/call-access-duplicate-device-browser-contract.mjs',
    'node tests/contract/call-access-logout-login-switch-contract.mjs',
    'node tests/contract/call-access-logout-switch-extract-contract.mjs',
    'node tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
    'node tests/contract/call-access-anonymous-guest-manipulation-contract.mjs',
    'node tests/contract/call-access-temp-call-link-boundaries-contract.mjs',
    'node tests/contract/call-access-disabled-links-fail-closed-contract.mjs',
    'node tests/contract/call-access-kicked-rejoin-denial-contract.mjs',
    'node tests/contract/call-access-permission-change-active-call-contract.mjs',
    'node tests/contract/call-access-calendar-invite-join-contract.mjs',
    'node tests/contract/call-access-registered-logged-out-handoff-contract.mjs',
    'node tests/contract/call-access-registered-logged-in-invitee-contract.mjs',
    'node tests/contract/call-access-registered-invitee-extract-contract.mjs',
    'node tests/contract/call-access-personalized-temp-reuse-contract.mjs',
    'node tests/contract/call-access-invite-invalidation-terminal-contract.mjs',
    'node tests/contract/call-access-duplicate-invite-replay-contract.mjs',
    'node tests/contract/call-access-owner-transfer-main-contract.mjs',
    'node tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs',
    'node tests/contract/owner-transfer-lifecycle-contract.mjs',
    'node tests/contract/call-access-removed-members-contract.mjs',
    'node tests/contract/call-access-terminal-browser-flows-contract.mjs',
    'node tests/contract/call-access-stale-role-org-switch-contract.mjs',
    'node tests/contract/call-access-audit-event-compatibility-contract.mjs',
    'node tests/contract/call-access-verified-context-ui-contract.mjs',
    'node tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
    'node tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
    'node tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs',
    'node tests/contract/iam-guest-list-revocation-extraction-contract.mjs',
    'node tests/contract/call-access-link-privacy-contract.mjs',
    'node tests/contract/iam-call-access-e2e-foundation-contract.mjs',
    'node tests/contract/call-access-direct-join-rights-contract.mjs',
    'node tests/contract/call-access-cross-org-contract.mjs',
    'node tests/contract/call-access-terminal-states-contract.mjs',
    'node tests/contract/call-access-admission-boundaries-contract.mjs',
    'node tests/contract/call-access-lobby-concurrency-contract.mjs',
    'node tests/contract/call-access-duplicate-abuse-contract.mjs',
    'node tests/contract/call-access-account-isolation-contract.mjs',
    'node tests/contract/call-access-audit-redaction-contract.mjs',
    'node tests/contract/call-access-callapp-revocation-contract.mjs',
    'node tests/contract/call-access-route-guard-ui-contract.mjs',
    'node tests/contract/call-access-realtime-scope-contract.mjs',
    'php ../backend-king-php/tests/call-access-anonymous-temp-rights-contract.php',
    '../backend-king-php/tests/call-access-terminal-join-contract.sh',
    '../backend-king-php/tests/call-guest-list-direct-join-contract.sh',
    '../backend-king-php/tests/call-access-cross-org-contract.sh',
    '../backend-king-php/tests/realtime-lobby-concurrency-contract.sh',
    '../backend-king-php/tests/call-access-membership-removal-contract.sh',
    '../backend-king-php/tests/call-access-stale-organization-role-contract.sh',
    '../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh',
    '../backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh',
  ].join(' && '),
  'IAM5-03 must not rewrite package.json to the unresolved suite runner',
);
assert.equal(
  callAccessE2eScript,
  'PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests/e2e/call-access-join.spec.js tests/e2e/call-access-seed-matrix.spec.js tests/e2e/call-access-calendar-unregistered-invite.spec.js tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1',
  'IAM5-03 must keep the current focused call-access E2E package script untouched',
);

process.stdout.write('[iam-duplicate-cleanup-family-contract] PASS\n');
