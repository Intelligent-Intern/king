import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const evidence = readText('documentation/iam-sprint-05-temp-access-extraction.md');
const tempBoundaries = readText('demo/video-chat/frontend-vue/tests/contract/call-access-temp-call-link-boundaries-contract.mjs');
const personalizedTemp = readText('demo/video-chat/frontend-vue/tests/contract/call-access-personalized-temp-reuse-contract.mjs');
const directJoin = readText('demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs');
const guestListDirectJoin = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const anonymousTempRights = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-temp-rights-contract.php');
const adminPrevention = readText('demo/video-chat/backend-king-php/tests/call-access-admin-prevention-contract.php');
const sessionContract = readText('demo/video-chat/backend-king-php/tests/call-access-session-contract.php');
const kickedRejoin = readText('demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs');
const tempModerator = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const realtimeLobby = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php');
const websocketCommands = readText('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const lobbySecurity = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');

for (const [branch, head] of [
  ['agent/iam-e2e-direct-join-roles', '02a2bdfe'],
  ['agent/iam-e2e-rejoin-kick-membership', 'bbe9a8f7'],
  ['local/iam-e2e-temp-guest-list-direct-join', 'c2f84b45'],
  ['local/iam-e2e-temp-moderator-remaining', 'f36b2ccc'],
  ['local/iam-e2e-temp-user-kick-rejoin', '453ee854'],
  ['local/iam-e2e-anonymous-temp-rights-proof-2', 'f6748e36'],
  ['local/iam-e2e-anonymous-link-org-admin-rights', '03223058'],
  ['local/iam-e2e-rejoin-refresh-session-safety', 'fe6fd427'],
  ['codex/iam-lane-61-temporary-call-link-account-proof', '21684060'],
]) {
  assert.ok(evidence.includes(branch), `evidence must list source branch ${branch}`);
  assert.ok(evidence.includes(head), `evidence must record source head ${head}`);
}

assert.match(
  evidence,
  /Base checked: local `prod-kingrt-do-not-push-to-github` at\s+`5988e6b7de705f2cfbad56ca14ae9f7efde36411`/,
  'evidence must record the inspected prod base',
);
assert.match(
  evidence,
  /Background, Gossip, SFU, MediaSecurity, and BTGF areas were not\s+touched/,
  'evidence must preserve protected-area boundary',
);
assert.match(
  evidence,
  /No product code, package scripts, shared CI wiring, sprint planning files/,
  'evidence must stay documentation/static-contract only',
);
assert.match(
  evidence,
  /same-link\/same-temporary-account reuse behavior is a\s+backend product follow-up outside this doc\/contract-only write scope/,
  'source-only lane 61 reuse behavior must be recorded instead of silently dropped',
);
assert.match(
  evidence,
  /Current prod is stricter[\s\S]*`lobby\/kick` normalizes to\s+`lobby\/remove`[\s\S]*writes `cancelled`/,
  'evidence must record the current stricter kicked temporary-user policy',
);

assert.match(
  tempBoundaries,
  /temporary_personalized_guest[\s\S]*temporary_anonymous_guest[\s\S]*must not persist tenant memberships/,
  'temporary call-link boundaries must keep temporary users out of tenant/admin membership grants',
);
assert.match(
  tempBoundaries,
  /temporary account must not gain direct call access[\s\S]*temporary account must not gain guest-list direct join[\s\S]*anonymous session issuance must not add guest-list rights/s,
  'anonymous temporary proof must deny direct access, direct join, and guest-list mutation',
);
assert.match(
  personalizedTemp,
  /email-only personal link without an existing user must create a temporary guest[\s\S]*a personalized temporary session cannot be reused by another account[\s\S]*a personalized temporary session cannot be replayed into another call binding[\s\S]*a personalized temporary session cannot be replayed into another room binding/s,
  'personalized temporary contract must cover account, call, and room binding',
);
assert.match(
  callAccessPublic,
  /videochat_fetch_active_user_for_call_access\([\s\S]*\$participantEmail === '' \? null : \$participantEmail,[\s\S]*\$tenantId,[\s\S]*false[\s\S]*\)/,
  'public resolution must not auto-resolve email-only temporary links to registered same-email accounts',
);
assert.match(
  callAccessSession,
  /\$requiresGuestName[\s\S]*videochat_create_guest_user_for_call_access\(\$pdo, \$guestName, \$tenantId\)[\s\S]*videochat_call_access_session_id_available\(\$pdo, \$sessionId\)[\s\S]*session_id_not_available/s,
  'session issuance must create temporary guests and reject duplicate temporary session ids',
);

assert.match(
  directJoin,
  /direct_join_system_admin_alpha_active_allowed[\s\S]*direct_join_alpha_org_admin_alpha_active_allowed[\s\S]*direct_join_alpha_call_owner_alpha_active_allowed[\s\S]*direct_join_registered_guest_alpha_active_allowed[\s\S]*direct_join_alpha_normal_user_alpha_active_denied/s,
  'direct-join static contract must retain the principal role matrix',
);
assert.match(
  guestListDirectJoin,
  /user on guest list should be allowed to direct join[\s\S]*user not on guest list should not direct join[\s\S]*guest list from one call must not grant direct join to another call[\s\S]*declined guest-list entry must not direct join[\s\S]*external participant row must not count as internal guest list[\s\S]*guest list must not cross tenant call lookup/s,
  'backend guest-list direct join proof must keep active, scoped, internal-only, tenant-bound semantics',
);

assert.match(
  anonymousTempRights,
  /temporary account must not inherit organization admin rights[\s\S]*temporary account must not administer the call[\s\S]*temporary account must not gain direct call access[\s\S]*temporary account must not gain guest-list direct join[\s\S]*anonymous session issuance must not create an invited\/allowed participant row[\s\S]*anonymous session issuance must not add guest-list rights/s,
  'anonymous temporary rights contract must keep temporary identity non-admin and non-guest-list',
);
assert.match(
  adminPrevention,
  /anonymous\/open link must not promote the logged-in normal account[\s\S]*logged-in normal account must not gain call-admin rights from anonymous\/open link/s,
  'open-link admin-prevention proof must block logged-in account promotion',
);

assert.match(
  kickedRejoin,
  /cached call-access sessions must fail once the participant row is cancelled or declined[\s\S]*host removal from lobby or admitted state must persist a revoked participant state[\s\S]*direct room joins must not revive participants removed after admission/s,
  'kicked/removed rejoin proof must prevent stale sessions or direct joins from reviving removed users',
);
assert.match(
  realtimeLobby,
  /if \(in_array\(\$action, \['lobby\/reject', 'lobby\/kick'\], true\)\) \{[\s\S]*\$action = 'lobby\/remove';/,
  'realtime lobby must normalize kicked temporary users through remove semantics',
);
assert.match(
  websocketCommands,
  /if \(\$lobbyAction === 'lobby\/remove'\)[\s\S]*videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'cancelled'[\s\S]*\['pending', 'allowed', 'accepted'\]/,
  'websocket persistence must write cancelled for removed/kicked users instead of preserving direct admission',
);

assert.match(
  tempModerator,
  /temporary moderator should gain only assigned-call moderation, not tenant or owner-management powers[\s\S]*temporary moderator must not moderate a different call or tenant[\s\S]*temporary moderator must not transfer ownership[\s\S]*revoked temporary moderator must fail the next lobby command/s,
  'temporary moderator extraction contract must keep call-scoped moderation and revocation boundaries',
);
assert.match(
  lobbySecurity,
  /videochat_realtime_lobby_server_role_for_user\(\$pdo, \$userId\)[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\]/s,
  'lobby moderation authorization must reload server-side call role context',
);
assert.match(
  sessionContract,
  /access-bound session mismatch should not enter secondary room[\s\S]*access-bound mismatch should be explicit/,
  'session contract must reject refreshed or replayed sessions against the wrong call room',
);

process.stdout.write('[call-access-temp-access-remaining-extract-contract] PASS\n');
