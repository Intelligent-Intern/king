import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const sprint = read('SPRINT.md');
const iamCiGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const guestListContract = read('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const guestListDomain = read('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const auditDomain = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');

for (const matrixId of [
  'e2e_guest_list_001_add_registered_user_to_guest_list',
  'e2e_guest_list_002_add_temp_account_to_guest_list',
  'e2e_guest_list_003_remove_guest_list_entry',
  'e2e_guest_list_004_guest_list_user_direct_join',
  'e2e_guest_list_005_non_guest_user_no_direct_join',
  'e2e_guest_list_006_temp_guest_list_user_direct_join',
  'e2e_guest_list_007_guest_list_call_scoped',
  'e2e_guest_list_008_guest_list_cross_org_not_valid',
  'e2e_guest_list_009_duplicate_guest_entries_handled',
  'e2e_guest_list_010_guest_list_changes_audit_logged',
]) {
  assert.ok(sprint.includes(`- [x] \`${matrixId}\``), `SPRINT matrix must mark ${matrixId} as proven`);
}

for (const proofNeedle of [
  'owner should add registered user to guest list',
  'temporary guest account should be created through call-access helper',
  'owner should add temporary account to guest list',
  'owner should remove registered guest-list entry',
  'registered user should direct join after guest-list add',
  'non-guest-list user should not direct join',
  'temporary guest should direct join after guest-list add',
  'registered guest list must remain call-scoped',
  'guest list from one call must not grant direct join to another call',
  'duplicate registered add must not create extra entries',
  'duplicate temporary add must not create extra entries',
  'guest-list add audit events should exist',
  'guest-list duplicate merge audit events should exist',
  'guest-list remove audit event should exist',
  'guest-list restore audit event should exist',
]) {
  assert.ok(guestListContract.includes(proofNeedle), `backend guest-list contract must prove: ${proofNeedle}`);
}

assert.match(
  guestListContract,
  /videochat_create_guest_user_for_call_access\(\$pdo, 'Direct Join Temporary Guest', \$tenantId, false\)/,
  'temporary guest-list proof must use the real call-access temporary account helper without tenant membership',
);
assert.match(
  guestListContract,
  /videochat_call_guest_list_direct_join_assert_audit_event\([\s\S]*guest_list_entry_added[\s\S]*guest_list_entry_merged[\s\S]*guest_list_entry_removed[\s\S]*guest_list_entry_restored/s,
  'backend guest-list contract must assert add, merge, remove, and restore audit rows',
);
assert.match(
  guestListContract,
  /videochat_call_guest_list_direct_join_assert_audit_event[\s\S]*resource_fingerprint[\s\S]*videochat_audit_fingerprint\(\$callId \. ':' \. \$targetUserId\)[\s\S]*raw_guest_identifiers_logged/s,
  'guest-list audit proof must assert call-scoped fingerprints and sanitized raw guest identifiers',
);
assert.match(
  guestListDomain,
  /function videochat_add_call_guest_list_entry[\s\S]*videochat_audit_record_guest_list_entry_change/s,
  'guest-list add path must write audit rows through the shared audit helper',
);
assert.match(
  guestListDomain,
  /function videochat_remove_call_guest_list_entry[\s\S]*videochat_audit_record_guest_list_entry_change/s,
  'guest-list remove path must write audit rows through the shared audit helper',
);
assert.match(
  auditDomain,
  /function videochat_audit_record_guest_list_entry_change[\s\S]*guest_list_entry_[\s\S]*resource_type' => 'call_guest_list_entry'[\s\S]*call_scoped' => true[\s\S]*raw_guest_identifiers_logged' => false/s,
  'guest-list audit helper must persist scoped, sanitized guest-list event payloads',
);
assert.ok(
  iamCallAccessContractSuiteText.includes('iam-guest-list-management-audit-proof-contract.mjs'),
  'IAM contract suite must include the guest-list management audit proof contract',
);
assert.ok(
  iamCiGate.includes('"tests/contract/iam-guest-list-management-audit-proof-contract.mjs"'),
  'IAM CI static gate must include the guest-list management audit proof contract',
);

console.log('[iam-guest-list-management-audit-proof-contract] PASS');
