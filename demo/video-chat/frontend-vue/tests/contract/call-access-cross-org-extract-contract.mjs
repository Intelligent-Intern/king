import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const extractionDoc = readText('documentation/iam-sprint-05-cross-org-extraction.md');
const frontendCrossOrgContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-contract.mjs');
const frontendStaleRoleContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-stale-role-org-switch-contract.mjs');
const frontendDirectJoinContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs');
const frontendNoLeakContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs');
const frontendStrongMismatchContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs');
const backendCrossOrgContract = readText('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const backendStaleRoleContract = readText('demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php');
const backendPrivacyContract = readText('demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php');
const backendStrongMismatchContract = readText('demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php');

for (const branch of [
  'local/iam-e2e-cross-org-remaining-proof-2',
  'codex/iam-e2e-cross-org-remaining-proof-2-test-only-20260509',
  'local/iam-e2e-cross-org-active-org-switch',
  'local/iam-e2e-cross-org-foreign-join-edges',
  'local/iam-e2e-foreign-personalized-mismatch',
  'local/iam-e2e-privacy-foreign-data',
  'local/iam-e2e-membership-stale-invite-rights-proof-2',
]) {
  assert.match(extractionDoc, new RegExp(branch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `extraction doc must list ${branch}`);
}

for (const phrase of [
  'did not port backend/runtime code',
  'not a safe wholesale import',
  'must not mint membership',
  'tenant-admin',
  'platform-admin',
  'moderation',
  'owner-management rights',
]) {
  assert.ok(extractionDoc.includes(phrase), `extraction doc must preserve tenant-isolation wording: ${phrase}`);
}
assert.match(
  extractionDoc,
  /not be imported by deleting or replacing the maintained cross-org and stale-role\s+contracts/,
  'extraction doc must forbid replacing the maintained cross-org and stale-role contracts',
);

assert.match(
  extractionDoc,
  /positive foreign personalized-link joins[\s\S]*explicit call-scoped invite/,
  'extraction doc must record positive foreign personalized-link joins as source-only value',
);
assert.match(
  extractionDoc,
  /foreign anonymous links[\s\S]*without creating a temporary guest[\s\S]*organization B membership/,
  'extraction doc must record foreign anonymous logged-in joins as source-only value',
);
assert.match(
  extractionDoc,
  /moved, downgraded,\s+promoted, and removed organization members/,
  'extraction doc must record stale invite membership matrix value',
);

assert.match(
  frontendCrossOrgContract,
  /direct_join_alpha_org_admin_beta_active_denied[\s\S]*expected_resolve_state,\s*'forbidden'[\s\S]*expected_call_error_code,\s*'calls_forbidden'/,
  'current frontend cross-org contract must pin alpha admin to beta call denial',
);
assert.match(
  frontendCrossOrgContract,
  /active-org switch proof must resolve the beta org snapshot[\s\S]*active-org switch must not mint a beta membership id[\s\S]*active-org switch must not mint beta tenant-admin rights[\s\S]*active-org switch must not mint platform-admin rights/,
  'current frontend cross-org contract must pin active-org switch least privilege',
);
assert.match(
  frontendCrossOrgContract,
  /seed helper must model denied resolve as call:null and access_link:null/,
  'current frontend cross-org contract must require denied resolve payloads to omit call/link data',
);
assert.doesNotMatch(
  frontendCrossOrgContract,
  /packageJson|iam-call-access-ci-gate|SPRINT must mark/,
  'focused extraction must not depend on package, CI, or SPRINT edits',
);

assert.match(
  backendCrossOrgContract,
  /organization A admin must not have organization B context[\s\S]*active organization A context must not fetch organization B call[\s\S]*organization B call must be hidden from organization A context/,
  'current backend cross-org contract must keep foreign calls hidden from the wrong active tenant',
);
assert.match(
  backendCrossOrgContract,
  /active organization switch must not mint organization B membership[\s\S]*tenant_membership_inactive/,
  'current backend cross-org contract must reject active-org replay without membership',
);
assert.match(
  backendCrossOrgContract,
  /stale personalized organization B link should resolve public metadata[\s\S]*stale personalized organization B link alone must not grant organization A admin call access/,
  'current backend cross-org contract must keep stale personalized links from preserving admin power',
);
assert.match(
  backendCrossOrgContract,
  /legacy admin fallback should be least-privilege member[\s\S]*legacy admin fallback must not become organization B admin[\s\S]*legacy admin fallback must not preserve platform admin through call access/,
  'current backend cross-org contract must keep legacy fallback least privilege',
);

assert.match(
  frontendStaleRoleContract,
  /backend stale-role proof must re-read downgraded roles[\s\S]*backend stale-role proof must reject stale and forged admin call access after downgrade[\s\S]*backend stale-role proof must revalidate stale decoded admin context/,
  'current stale-role extraction contract must preserve revalidation coverage',
);
assert.match(
  backendStaleRoleContract,
  /same session must re-read downgraded tenant role[\s\S]*locally cached session fallback must re-read downgraded tenant role[\s\S]*stale client role cache must not resolve hidden invite-only call[\s\S]*call access must revalidate stale decoded role context against backend state/,
  'current backend stale-role contract must revalidate live, cached, hinted, and decoded role state',
);

assert.match(
  frontendDirectJoinContract,
  /direct_join_alpha_org_admin_beta_active_denied[\s\S]*expected_resolve_state:\s*'forbidden'[\s\S]*expected_call_error_code:\s*'calls_forbidden'/,
  'current direct-join contract must keep cross-org admin direct join denied',
);
assert.match(
  frontendDirectJoinContract,
  /seed helper must keep direct-join authorization limited to platform admin, tenant admin, call owner, or guest-list participant/,
  'current direct-join contract must limit direct-join grants to target-context rights',
);

assert.match(
  backendPrivacyContract,
  /guessed join response[\s\S]*broken personalized join response[\s\S]*wrong-user access response[\s\S]*domain public resolution must not return target user/,
  'current backend privacy contract must cover invalid and wrong-user no-leak responses',
);
assert.match(
  backendStrongMismatchContract,
  /wrong logged-in user should not resolve foreign personalized link[\s\S]*wrong-user join response[\s\S]*wrong host denial must not persist a session[\s\S]*unverified host denial must not persist a session/,
  'current backend strong-mismatch privacy contract must deny and avoid persisting foreign sessions',
);
assert.match(
  frontendNoLeakContract,
  /session envelopes must only be applied on successful call-access responses[\s\S]*HTTP session route must project forbidden denials to code and fields, not foreign result payloads/,
  'current frontend no-leak contract must keep denied foreign payloads out of UI session state',
);
assert.match(
  frontendStrongMismatchContract,
  /public join E2E must cover strong personalized-link mismatch wrong-host denial[\s\S]*strong-mismatch E2E must prove denied responses do not bind a foreign session/,
  'current frontend strong-mismatch contract must keep denied foreign sessions unbound',
);

process.stdout.write('[call-access-cross-org-extract-contract] PASS\n');
