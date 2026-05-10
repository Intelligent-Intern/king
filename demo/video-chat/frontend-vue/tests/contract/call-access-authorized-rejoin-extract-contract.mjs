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

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing function ${name}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing body for ${name}`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(open + 1, index);
    }
  }

  throw new Error(`unterminated function ${name}`);
}

const packageJson = JSON.parse(readText('demo/video-chat/frontend-vue/package.json'));
const realtimeContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const guestList = readText('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const callAccessContract = readText('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const authorizedRejoinBackend = readText('demo/video-chat/backend-king-php/tests/call-access-authorized-rejoin-contract.php');
const sqliteRuntimeProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const directJoinRightsContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs');
const decisionContract = readText('demo/video-chat/backend-king-php/tests/call-access-decision-contract.php');
const membershipRemovalContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const kickedRejoinContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs');
const removedMembersContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs');
const staleRoleContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-stale-role-org-switch-contract.mjs');
const staleOrganizationRoleContract = readText('demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php');
const ownerTransferTempModeratorContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs');
const realtimeScopeContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-realtime-scope-contract.mjs');

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const contractPath of [
  'call-access-direct-join-rights-contract.mjs',
  'call-access-kicked-rejoin-denial-contract.mjs',
  'call-access-removed-members-contract.mjs',
  'call-access-stale-role-org-switch-contract.mjs',
  'call-access-owner-transfer-temp-moderator-extract-contract.mjs',
  'call-access-realtime-scope-contract.mjs',
  'call-access-authorized-rejoin-extract-contract.mjs',
  'call-access-membership-removal-contract',
  'call-access-stale-organization-role-contract',
]) {
  assert.match(iamGate, new RegExp(contractPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `IAM gate must keep ${contractPath}`);
}

const admissionBypassBody = functionBody(realtimeContext, 'videochat_realtime_call_context_allows_admission_bypass');
assert.match(
  admissionBypassBody,
  /if \(\(bool\) \(\$context\['can_moderate'\] \?\? false\)\) \{[\s\S]*return true;[\s\S]*\$inviteState = videochat_realtime_normalize_call_invite_state[\s\S]*in_array\(\$inviteState, \['allowed', 'accepted'\], true\)[\s\S]*return true;/,
  'current realtime admission must allow authorized moderator/admin and allowed or accepted participant rejoin paths',
);
assert.doesNotMatch(
  admissionBypassBody,
  /left_at|leftAt|joined_at|joinedAt/,
  'ordinary authorized rejoin must not be blocked by stale leave timestamps once current invite state is allowed or accepted',
);

const markJoinedBody = functionBody(realtimeContext, 'videochat_realtime_mark_call_participant_joined');
assert.match(
  markJoinedBody,
  /SET joined_at = :joined_at,\s+left_at = NULL,[\s\S]*WHEN invite_state IN \('invited', 'pending', 'accepted'\) THEN 'allowed'[\s\S]*ELSE invite_state/,
  'rejoin persistence must clear left_at and only promote non-revoked invite states to allowed',
);
assert.doesNotMatch(
  markJoinedBody,
  /WHEN invite_state IN \('invited', 'pending', 'accepted', 'declined', 'cancelled'\) THEN 'allowed'/,
  'rejoin persistence must not promote kicked or removed invite states back to allowed',
);

const queueBody = functionBody(realtimeContext, 'videochat_realtime_mark_call_participant_pending_for_queue');
assert.match(
  queueBody,
  /SET invite_state = 'pending',\s+joined_at = NULL,\s+left_at = NULL[\s\S]*AND invite_state = 'invited'/,
  'lobby queue re-entry must only move fresh invited rows to pending',
);
assert.doesNotMatch(
  queueBody,
  /invite_state IN \('invited', 'declined', 'cancelled'\)/,
  'lobby queue re-entry must not revive declined or cancelled participant rows',
);

assert.match(
  guestList,
  /videochat_user_has_system_admin_call_rights\(\$pdo, \$authUserId, \$authRole\)[\s\S]*'reason' => 'system_admin'/,
  'direct join must preserve database-validated system-admin authorization',
);
assert.match(
  guestList,
  /\(int\) \(\$call\['owner_user_id'\] \?\? 0\) === \$authUserId[\s\S]*'reason' => 'owner'/,
  'direct join must preserve current owner authorization',
);
assert.match(
  guestList,
  /videochat_user_is_organization_admin_for_call\(\$pdo, \$call, \$authUserId, \$tenantId\)[\s\S]*'reason' => 'organization_admin'/,
  'direct join must preserve same-organization admin authorization without requiring a guest-list row',
);
assert.match(
  guestList,
  /videochat_normalize_call_access_mode\(\$call\['access_mode'\] \?\? 'invite_only'\) === 'free_for_all'[\s\S]*'reason' => 'free_for_all'/,
  'direct join must preserve current free-for-all authorization',
);
assert.match(
  guestList,
  /if \(in_array\(\$inviteState, \['declined', 'cancelled'\], true\)\) \{[\s\S]*'reason' => 'guest_list_entry_inactive'[\s\S]*'reason' => 'guest_list'/,
  'direct guest-list join must allow current guest-list rows while failing closed after cancellation or decline',
);
assert.match(
  guestList,
  /if \(\$inviteState === 'pending'\) \{[\s\S]*'reason' => 'not_on_guest_list'/,
  'direct guest-list join must not treat pending lobby rows as authorized rejoin',
);

assert.match(
  callAccessContract,
  /\$participantInviteState = strtolower\(trim\(\(string\) \(\$row\['participant_invite_state'\] \?\? ''\)\)\);[\s\S]*in_array\(\$participantInviteState, \['cancelled', 'declined'\], true\)[\s\S]*call_access_participant_removed/,
  'cached call-access sessions must fail once the current participant row is kicked or removed',
);

assert.match(
  directJoinRightsContract,
  /direct_join_system_admin_alpha_active_allowed[\s\S]*direct_join_alpha_org_admin_alpha_active_allowed[\s\S]*direct_join_alpha_call_owner_alpha_active_allowed[\s\S]*direct_join_registered_guest_alpha_active_allowed/s,
  'maintained direct-join contract must cover the authorized admin, org-admin, owner, and registered guest-list sources',
);
assert.match(
  directJoinRightsContract,
  /direct_join_alpha_normal_user_alpha_active_denied[\s\S]*direct_join_alpha_org_admin_beta_active_denied/s,
  'maintained direct-join contract must keep normal and cross-org denied paths closed',
);

assert.match(
  decisionContract,
  /internal participant should be allowed[\s\S]*removed tenant member should keep call-scoped participant access[\s\S]*removed call participant should not retain invite-only access/s,
  'backend decision proof must allow current participants while denying removed participant rows',
);
assert.match(
  membershipRemovalContract,
  /call-scoped session should authenticate after membership removal[\s\S]*admitted call-scoped invited user should enter the bound call room[\s\S]*admitted call-scoped invited user should not remain in lobby/s,
  'membership-removal proof must allow admitted call-scoped rejoin without restoring tenant powers',
);

assert.match(
  kickedRejoinContract,
  /cached call-access sessions must fail once the participant row is cancelled or declined[\s\S]*direct room joins must not revive participants removed after admission[\s\S]*route-guard proof must keep stale workspace tabs behind authenticated and admitted call-access paths/s,
  'kicked-rejoin proof must keep stale sessions and direct room joins fail-closed after removal',
);
assert.match(
  removedMembersContract,
  /removed invited user must have no active alpha membership[\s\S]*removed invited user must not directly see the org call[\s\S]*denied removed-member paths must not join or observe lobby state/s,
  'removed-member proof must keep removed participants out of direct call and lobby visibility',
);
assert.match(
  staleRoleContract,
  /same session must re-read downgraded tenant role[\s\S]*downgraded organization member must not access invite-only call by stale role[\s\S]*downgraded organization member must not retain moderation context/s,
  'stale role proof must re-read downgraded roles before allowing call or moderation access',
);
assert.match(
  staleOrganizationRoleContract,
  /same session must not keep stale tenant admin permission[\s\S]*forged auth role must not restore call access after downgrade[\s\S]*stale client role cache must not resolve hidden invite-only call/s,
  'backend stale organization-role proof must keep role-invalidated rejoin fail-closed',
);
assert.match(
  ownerTransferTempModeratorContract,
  /old owner should not regain moderation on rejoin[\s\S]*revoked temporary moderator must lose moderation on fresh context[\s\S]*revoked temporary moderator must fail the next lobby command/s,
  'owner-transfer and temporary-moderator proof must keep role-invalidated rejoin from regaining moderation',
);
assert.match(
  realtimeScopeContract,
  /workspace reconnect must fail closed when an IAM session disappears[\s\S]*successful websocket reconnect must request an authoritative room snapshot backfill[\s\S]*backend room resolution must reject call-access session room\/call\/user binding mismatches/s,
  'realtime scope proof must revalidate reconnect sessions and bind them to the current call, room, and user',
);

assert.match(
  authorizedRejoinBackend,
  /registered_guest_can_rejoin_after_leaving[\s\S]*system_admin_can_rejoin_after_leaving[\s\S]*organization_admin_can_rejoin_after_leaving/s,
  'backend authorized-rejoin proof must cover registered guest-list, system-admin, and organization-admin rejoin paths',
);
assert.match(
  authorizedRejoinBackend,
  /forged_admin_role_does_not_rejoin[\s\S]*pending_lobby_user_must_not_bypass_admission/s,
  'backend authorized-rejoin proof must keep forged admin roles and pending lobby users fail-closed',
);
assert.match(
  authorizedRejoinBackend,
  /leave should persist left_at[\s\S]*rejoin should clear stale left_at/s,
  'backend authorized-rejoin proof must prove leave persistence and rejoin cleanup',
);
assert.match(
  sqliteRuntimeProof,
  /call-access-authorized-rejoin-contract\.sh/,
  'SQLite IAM runtime proof must execute the authorized-rejoin backend contract',
);

process.stdout.write('[call-access-authorized-rejoin-extract-contract] PASS\n');
