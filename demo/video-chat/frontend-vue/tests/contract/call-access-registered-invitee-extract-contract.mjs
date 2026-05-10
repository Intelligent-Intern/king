import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '..', '..');

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function assertMatches(source, pattern, message) {
  assert.match(source, pattern, message);
}

const seedMatrix = JSON.parse(readRepo('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json'));
const loggedInInviteeContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-registered-logged-in-invitee-contract.mjs');
const loggedOutHandoffContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-registered-logged-out-handoff-contract.mjs');
const routeGuardContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const callAccessSession = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const realtimeCallContext = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const evidence = readRepo('documentation/iam-sprint-04-registered-invitee-extract-evidence.md');

const sourceBranches = [
  'local/iam-e2e-registered-invitee-logged-in-proof-3',
  'local/iam-e2e-invite-registered-logged-out-proof-3',
  'local/iam-e2e-registered-invitee-final-proof-3',
];

for (const branch of sourceBranches) {
  assert.ok(evidence.includes(branch), `evidence must classify ${branch}`);
}

const registeredGuest = (seedMatrix.users || []).find((user) => user?.key === 'registered_guest');
assert.ok(registeredGuest, 'seed matrix must keep the registered invitee user');
assert.equal(registeredGuest.account_type, 'account', 'registered invitee remains a registered account');
assert.equal(registeredGuest.is_guest, false, 'registered invitee must not be modeled as a guest');
assert.equal(registeredGuest.temporary, false, 'registered invitee must not be temporary');

const registeredScenario = (seedMatrix.scenarios || []).find((scenario) => scenario?.key === 'direct_join_registered_guest_alpha_active_allowed');
assert.ok(registeredScenario, 'seed matrix must keep the registered invitee direct-join scenario');
assert.equal(registeredScenario.principal_user_key, 'registered_guest');
assert.equal(registeredScenario.expected?.direct_join_allowed, true);
assert.equal(registeredScenario.expected?.guest_list_entry, true);
assert.equal(registeredScenario.expected?.tenant_admin, false);
assert.equal(registeredScenario.expected?.platform_admin, false);

assertMatches(
  routeGuardContract,
  /anonymous personal link should still issue[\s\S]*anonymous personal link should bind the linked user/s,
  'logged-out personalized links must issue only the server-bound registered invitee identity',
);
assertMatches(
  routeGuardContract,
  /matching logged-in user should issue[\s\S]*matching logged-in route should bind the linked user/s,
  'logged-in registered invitee must issue as the linked account',
);
assertMatches(
  routeGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*not_bound_to_current_user[\s\S]*wrong account route must not persist a session/s,
  'wrong registered account must fail closed without persisting a call-access session',
);
assertMatches(
  routeGuardContract,
  /session switch should conflict[\s\S]*session_context_changed[\s\S]*session switch route must not persist a session/s,
  'verified-context switching must fail closed before session persistence',
);
assertMatches(
  routeGuardContract,
  /open link should issue an isolated guest user/s,
  'guest-session issuance must stay limited to open links, not personalized registered invitees',
);

assertMatches(
  callAccessSession,
  /linkKind === 'personal'[\s\S]*authenticatedUserId > 0[\s\S]*authenticatedUserId !== \$userId[\s\S]*'reason' => 'forbidden'/s,
  'personalized session issuance must reject authenticated users other than the linked registered invitee',
);
assertMatches(
  callAccessSession,
  /videochat_decide_call_access_for_user\([\s\S]*\$userId[\s\S]*\$tenantId/s,
  'registered invitee session issuance must re-check current call and tenant access before binding',
);
assertMatches(
  callAccessSession,
  /INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at/s,
  'registered invitee sessions must persist call-scoped access bindings',
);

assertMatches(
  loggedOutHandoffContract,
  /workspace call route must stay authenticated for logged-out registered invitees[\s\S]*login redirects must reject unsafe paths and preserve only authorized route targets/s,
  'logged-out registered invitees must use a safe login handoff instead of anonymous rebinding',
);
assertMatches(
  loggedOutHandoffContract,
  /login handoff must resolve the original call with the registered invitee bearer session[\s\S]*login handoff must rebind to the backend-returned access link for the intended invite/s,
  'post-login handoff must rebind only to the intended backend-resolved invite',
);
assertMatches(
  loggedOutHandoffContract,
  /call-access session body must rebind the issued session to the intended verified registered invitee[\s\S]*call-access session request must fail closed if the registered login context disappears before issuing/s,
  'frontend session issuance must carry verified user/session context and fail closed if it changes',
);

assertMatches(
  loggedInInviteeContract,
  /session issuance must persist a call-scoped access binding[\s\S]*registered invitee call-access session must not be reusable for a different call/s,
  'logged-in registered invitee sessions must remain bound to the intended call',
);
assertMatches(
  loggedInInviteeContract,
  /registered logged-in invitee must keep the authenticated account identity[\s\S]*call-scoped admission must not rewrite the active organization boundary/s,
  'logged-in invitee proof must preserve account identity without organization-boundary escalation',
);
assertMatches(
  realtimeCallContext,
  /videochat_fetch_call_access_session_binding\(\$pdo, \$sessionId\)[\s\S]*roomMismatch[\s\S]*callMismatch[\s\S]*userMismatch/s,
  'realtime room resolution must reject stale registered-invitee bindings for another room, call, or user',
);

assert.ok(
  evidence.includes('No product-code change is required'),
  'evidence must record that the source branches are superseded by current focused IAM coverage',
);

process.stdout.write('[call-access-registered-invitee-extract-contract] PASS\n');
