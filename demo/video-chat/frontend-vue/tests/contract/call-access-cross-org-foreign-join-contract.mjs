import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  callAccessE2eSuiteText,
  iamCallAccessContractSuiteText,
} from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const sprint = read('SPRINT.md');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-cross-org-foreign-join.spec.js');

assert.match(
  iamCallAccessContractSuiteText,
  /call-access-cross-org-foreign-join-contract\.mjs/,
  'IAM call-access contract script must include the cross-org foreign join static proof',
);
assert.match(
  callAccessE2eSuiteText,
  /call-access-cross-org-foreign-join\.spec\.js/,
  'focused call-access Playwright suite must include the cross-org foreign join E2E proof',
);
assert.match(
  ciGate,
  /call-access-cross-org-foreign-join-contract\.mjs/,
  'IAM CI static gate must run the cross-org foreign join static proof',
);
assert.match(
  ciGate,
  /call-access-cross-org-contract\.sh/,
  'IAM CI SQLite gate must run the backend cross-org contract',
);
assert.ok(
  new Set(matrix.commands?.['frontend:e2e:call-access']?.paths || []).has('frontend-vue/tests/e2e/call-access-cross-org-foreign-join.spec.js'),
  'call-access matrix must list the cross-org foreign join Playwright proof',
);

for (const sprintItem of [
  'User from organization A opens personalized link for organization A call',
  'User from organization A opens personalized link for organization B call',
  'User from organization A opens anonymous link for organization B call',
  'User with accounts in multiple organizations is checked in correct call context',
  'Temporary account from organization A invitation receives no rights in organization B',
]) {
  assert.match(sprint, new RegExp(`- \\[[ x]\\] ${sprintItem.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`), `SPRINT must keep cross-org item: ${sprintItem}`);
}

assert.match(
  backendContract,
  /organization A user should open own-organization personalized link[\s\S]*sess_cross_org_a_user_a_personal/,
  'backend contract must prove an organization A personalized link for an organization A call',
);
assert.match(
  backendContract,
  /organization A user should open explicit organization B personalized link as call-scoped participant[\s\S]*organization B personalized link must not create organization B tenant membership/,
  'backend contract must prove explicit foreign personalized link join remains call-scoped',
);
assert.match(
  backendContract,
  /organization A user must not consume organization B personalized link issued for another account[\s\S]*foreign mismatch denial should not persist a session/,
  'backend contract must prove mismatched foreign personalized links fail closed',
);
assert.match(
  backendContract,
  /organization A user should open organization B anonymous link as call-scoped participant[\s\S]*foreign anonymous link must not create organization B tenant membership/,
  'backend contract must prove foreign anonymous links do not mint organization membership',
);
assert.match(
  backendContract,
  /multi-organization account must use personalized-link call tenant[\s\S]*multi-organization account should be checked against organization A call context/,
  'backend contract must prove multi-org accounts are checked in the linked call context',
);
assert.match(
  backendContract,
  /organization A temporary invite should issue call-scoped session[\s\S]*videochat_call_access_cross_org_assert_no_call_rights\(\$pdo, 'organization A temporary invite account'/,
  'backend contract must prove organization A temporary invite accounts receive no organization B rights',
);

assert.match(
  e2eSpec,
  /cross-org personalized link uses the linked call tenant instead of the browser active organization[\s\S]*verified_user_id[\s\S]*verified_session_id/,
  'Playwright proof must cover personalized link context binding',
);
assert.match(
  e2eSpec,
  /foreign anonymous link keeps the logged-in org A account call-scoped in org B[\s\S]*guest_name[\s\S]*Org A User Via Anonymous Link/,
  'Playwright proof must cover foreign anonymous link account scoping',
);
assert.match(
  e2eSpec,
  /foreign personalized link mismatch does not replace the org A session or expose foreign data[\s\S]*foreignNeedles[\s\S]*sessionToken\)\.toBe\(orgAAccount\.sessionToken\)/,
  'Playwright proof must cover foreign personalized mismatch safety',
);

console.log('[call-access-cross-org-foreign-join-contract] PASS');
