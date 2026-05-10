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

function exists(relativePath) {
  return fs.existsSync(path.join(repoRoot, relativePath));
}

const evidence = readText('documentation/iam-sprint-05-guest-list-extraction.md');
const guestListDomain = readText('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const auditDomain = readText('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const directJoinContract = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const directJoinRights = readText('demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs');
const removedMembers = readText('demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs');
const sprint04RevocationEvidence = readText('documentation/iam-sprint-04-guest-list-revocation-extraction.md');
const dockerMembership = readText('demo/video-chat/frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs');
const ownerModeration = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
const ownerTransferMain = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs');
const tempModerator = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs');
const admissionBoundaries = readText('demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs');
const activePermission = readText('demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs');
const guestLifecycle = readText('demo/video-chat/backend-king-php/tests/call-guest-lifecycle-contract.php');
const guestCleanupWrapper = readText('demo/video-chat/backend-king-php/tests/call-guest-cleanup-sqlite-proof.sh');
const callUpdate = readText('demo/video-chat/backend-king-php/tests/call-update-contract.php');

for (const branch of [
  'local/iam-e2e-guest-list-management-proof',
  'local/iam-e2e-guest-list-management-audit-proof-2',
  'codex/iam-lane-57-guest-list-owner-management-proof',
  'local/iam-e2e-guest-list-harness-followup-3',
  'local/iam-e2e-guest-list-revocation-proof-3',
  'local/iam-e2e-guest-owner-transfer-revocation',
  'local/iam-e2e-guest-cleanup',
  'local/iam-e2e-guest-cleanup-remaining',
  'local/iam-e2e-guest-lifecycle-temp-cleanup-remaining',
]) {
  assert.match(evidence, new RegExp(branch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `evidence must list source branch ${branch}`);
}

for (const sourceHead of [
  'f40b2ce945bf',
  '1cc2e65c9e97',
  '1243ac3892f4',
  '61b2cc8a7b10',
  'cc020a0d0267',
  '276c8e995194',
  '1f44ef1cb0de',
  '10d8a706cc01',
  '5703fb393acf',
]) {
  assert.match(evidence, new RegExp(sourceHead), `evidence must pin source head ${sourceHead}`);
}

assert.match(
  evidence,
  /Source-Only Value Not Ported[\s\S]*videochat_add_call_guest_list_entry[\s\S]*videochat_remove_call_guest_list_entry[\s\S]*videochat_audit_record_guest_list_entry_change[\s\S]*call-guest-list-owner-management-contract\.php/s,
  'evidence must preserve the unported guest-list management and audit helper value',
);
assert.match(
  evidence,
  /Copying the source static audit contract without the missing runtime\s+helpers would create a stale assertion/,
  'evidence must explain why source-only static audit assertions were not copied',
);
assert.equal(
  exists('demo/video-chat/backend-king-php/tests/call-guest-list-owner-management-contract.php'),
  false,
  'IAM5-11 must not silently import the lane-57 backend owner-management contract outside write scope',
);
assert.equal(
  exists('demo/video-chat/frontend-vue/tests/contract/iam-guest-list-management-audit-proof-contract.mjs'),
  false,
  'IAM5-11 must not silently import the source audit contract without backend helper support',
);
assert.equal(
  exists('demo/video-chat/backend-king-php/tests/call-guest-cleanup-lifecycle-remaining-contract.php'),
  false,
  'IAM5-11 must not silently import granular cleanup runtime contracts outside write scope',
);
assert.doesNotMatch(
  guestListDomain,
  /function videochat_add_call_guest_list_entry|function videochat_remove_call_guest_list_entry/,
  'current base should not claim the source-only guest-list mutation helpers exist',
);
assert.doesNotMatch(
  auditDomain,
  /videochat_audit_record_guest_list_entry_change/,
  'current base should not claim the source-only guest-list audit helper exists',
);

assert.match(
  directJoinContract,
  /require_once __DIR__ \. '\/\.\.\/support\/auth_rbac\.php';/,
  'current direct-join harness must retain the harness follow-up auth_rbac include',
);
assert.match(
  directJoinContract,
  /user on guest list should be allowed to direct join[\s\S]*user not on guest list should not direct join[\s\S]*guest list from one call must not grant direct join to another call[\s\S]*declined guest-list entry must not direct join[\s\S]*guest list must not cross tenant call lookup/s,
  'current backend direct-join proof must cover allow, deny, call scope, inactive entry, and tenant scope',
);
assert.match(
  directJoinRights,
  /direct_join_system_admin_alpha_active_allowed[\s\S]*direct_join_alpha_org_admin_alpha_active_allowed[\s\S]*direct_join_alpha_call_owner_alpha_active_allowed[\s\S]*direct_join_registered_guest_alpha_active_allowed[\s\S]*direct_join_alpha_org_admin_beta_active_denied/s,
  'current direct-join rights contract must cover admin, org-admin, owner, guest-list, and cross-org denial policy',
);
assert.match(
  removedMembers,
  /removed invited user must not be on the alpha direct guest list[\s\S]*removed invited user must not directly see the org call[\s\S]*cancelled invited user must lose direct guest-list call access[\s\S]*declined invited user must lose direct guest-list call access/s,
  'current removed-member proof must cover guest-list revocation after removal, cancellation, and decline',
);
assert.match(
  sprint04RevocationEvidence,
  /direct guest-list call visibility[\s\S]*stale personalized call-access links[\s\S]*stale call-scoped sessions[\s\S]*lobby\/realtime rejoin paths/,
  'Sprint 04 revocation extraction must keep the guest-list revocation invariant visible',
);
assert.match(
  dockerMembership,
  /call-guest-list-direct-join-contract\.sh[\s\S]*call-access-membership-removal-contract\.sh[\s\S]*Host PHP lacks pdo_sqlite; using container fallback/s,
  'Docker membership proof must keep the guest-list plus stale-membership runtime fallback',
);

assert.match(
  callUpdate,
  /owner update should succeed[\s\S]*owner update internal participant total mismatch[\s\S]*admin owner transfer should update calls\.owner_user_id/s,
  'current update proof must retain maintained participant reconciliation and owner-transfer coverage',
);
assert.match(
  ownerModeration,
  /owner should admit lobby users[\s\S]*normal participant must not transfer ownership[\s\S]*current owner should transfer ownership[\s\S]*old owner should lose call moderation controls[\s\S]*new owner should moderate after transfer/s,
  'current owner proof must cover owner management and old-owner revocation',
);
assert.match(
  ownerTransferMain,
  /owner transfer must require the current owner or a system admin[\s\S]*lobby moderation must authorize through fresh DB-backed call context instead of stale connection roles/s,
  'current owner-transfer static proof must pin canonical owner authority and DB-backed lobby authority',
);
assert.match(
  tempModerator,
  /temporary moderator should gain only assigned-call moderation[\s\S]*temporary moderator must not transfer ownership[\s\S]*server-side role update must reject forged client moderator state[\s\S]*revoked temporary moderator must fail the next lobby command/s,
  'current temp-moderator proof must keep owner-management separate from moderation',
);
assert.match(
  admissionBoundaries,
  /owner contract must allow owner admission[\s\S]*org admin admission authority must not rely on guest-list mutation[\s\S]*system admin must not require guest-list participation/s,
  'current admission boundaries must prove owner/admin management without guest-list mutation dependence',
);
assert.match(
  activePermission,
  /backend realtime role context must recompute active-call owner\/moderator\/admin authority from current DB rows[\s\S]*owner transfer action must be gated by refreshed owner-management authority[\s\S]*runtime owner-transfer contract must prove permission downgrade revokes stale owner moderation actions/s,
  'current active-permission proof must cover stale owner/moderator action revocation',
);

assert.match(
  guestLifecycle,
  /personal cleanup should revoke stale guest session[\s\S]*stale personalized link must not revive invalidated guest[\s\S]*guest cleanup must not disable registered user[\s\S]*open link may create a fresh guest after cleanup/s,
  'current guest lifecycle source must retain core cleanup and stale-link revocation assertions',
);
assert.match(
  guestCleanupWrapper,
  /call-guest-lifecycle-contract\.sh[\s\S]*Host PHP lacks pdo_sqlite; using container fallback[\s\S]*PASS/s,
  'guest cleanup wrapper must keep deterministic host-PHP or Docker execution',
);

assert.match(
  evidence,
  /Docker fallback reached[\s\S]*call-guest-lifecycle-contract\.php[\s\S]*failed on[\s\S]*personal cleanup audit must not expose session-keyed counters[\s\S]*adjacent follow-up/s,
  'evidence must record the current cleanup Docker failure instead of overclaiming cleanup closure',
);
assert.match(
  evidence,
  /No product code, package scripts, shared CI wiring, `SPRINT\.md`, or\s+`BACKLOG\.md` were edited/,
  'evidence must preserve the IAM5-11 write-scope boundary',
);

process.stdout.write('[call-access-guest-list-remaining-extract-contract] PASS\n');
