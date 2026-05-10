import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function matrixRow(rows, key, label) {
  const row = rows.find((candidate) => String(candidate?.key || '') === key);
  assert.ok(row, `${label} ${key} must exist in IAM seed matrix`);
  return row;
}

const router = readFrontend('src/http/router.ts');
const loginView = readFrontend('src/domain/auth/LoginView.vue');
const admissionGate = readFrontend('src/domain/calls/access/admissionGate.ts');
const joinView = readFrontend('src/domain/calls/access/JoinView.vue');
const callAccessSession = readFrontend('src/domain/calls/access/callAccessSession.ts');
const routeGuardContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const seedMatrixSpec = readFrontend('tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrix = JSON.parse(readRepo('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json'));

const registeredGuest = matrixRow(seedMatrix.users, 'registered_guest', 'user');
assert.equal(registeredGuest.account_type, 'account', 'registered invitee must be a real account in the IAM seed matrix');
assert.equal(registeredGuest.is_guest, false, 'registered invitee must not be modeled as a guest');
assert.equal(registeredGuest.temporary, false, 'registered invitee must not be temporary');

const alphaCall = matrixRow(seedMatrix.calls, 'alpha_active', 'call');
assert.ok(
  Array.isArray(alphaCall.guest_list_user_keys) && alphaCall.guest_list_user_keys.includes('registered_guest'),
  'registered invitee must be attached to the intended alpha active call guest list',
);

const registeredDirectJoin = matrixRow(seedMatrix.scenarios, 'direct_join_registered_guest_alpha_active_allowed', 'scenario');
assert.equal(registeredDirectJoin.principal_user_key, 'registered_guest');
assert.equal(registeredDirectJoin.call_key, 'alpha_active');
assert.equal(registeredDirectJoin.expected?.direct_join_allowed, true);
assert.equal(registeredDirectJoin.expected?.guest_list_entry, true);
assert.equal(registeredDirectJoin.expected?.tenant_admin, false);
assert.equal(registeredDirectJoin.expected?.platform_admin, false);

assert.match(
  router,
  /path:\s*'workspace\/call\/:callRef\?'[\s\S]*name:\s*'call-workspace'[\s\S]*meta:\s*\{\s*requiresAuth:\s*true,\s*roles:\s*\['admin',\s*'user'\]\s*\}/,
  'workspace call route must stay authenticated for logged-out registered invitees',
);
assert.match(
  router,
  /if \(requiresAuth && !loggedIn\)[\s\S]*path:\s*'\/login'[\s\S]*query:\s*to\.fullPath !== '\/' \? \{ redirect:\s*to\.fullPath \}/,
  'logged-out call route access must hand off to login while preserving the intended call redirect',
);
assert.match(
  router,
  /export function resolveAuthorizedRedirect\(target,\s*role,\s*routerInstance = router\)[\s\S]*!value\.startsWith\('\/'\) \|\| value\.startsWith\('\/\/'\)[\s\S]*routeAllowsSessionAccess\(resolved,\s*\{ \.\.\.sessionState,\s*role \}\)/,
  'login redirects must reject unsafe paths and preserve only authorized route targets',
);

assert.match(
  loginView,
  /function getRedirectTarget\(role\)[\s\S]*route\.query\.redirect[\s\S]*resolveAuthorizedRedirect\(redirect,\s*role,\s*router\)/,
  'login must start from the router-vetted redirect target',
);
assert.match(
  loginView,
  /function extractWorkspaceCallRef\(redirectTarget\)[\s\S]*router\.resolve\(String\(redirectTarget \|\| ''\)\.trim\(\)\)[\s\S]*resolved\?\.name[\s\S]*'call-workspace'/,
  'login handoff must only normalize call workspace redirects',
);
assert.match(
  loginView,
  /const entryMode = String\(resolved\.query\?\.entry \|\| ''\)[\s\S]*if \(entryMode === 'invite'\)[\s\S]*return ''/,
  'login handoff must not rewrap an already admitted invite workspace entry',
);
assert.match(
  loginView,
  /const callRef = String\(resolved\.params\?\.callRef \|\| ''\)[\s\S]*if \(!CALL_UUID_PATTERN\.test\(callRef\)\)[\s\S]*return ''/,
  'login handoff must only treat UUID call refs as registered invite handoff candidates',
);
assert.match(
  loginView,
  /const sessionToken = String\(sessionState\.sessionToken \|\| ''\)\.trim\(\)[\s\S]*if \(sessionToken === ''\)[\s\S]*return redirectTarget/,
  'login handoff must wait until password login has established a verified current session',
);
assert.match(
  loginView,
  /fetchBackend\(`\/api\/calls\/resolve\/\$\{encodeURIComponent\(callRef\)\}`[\s\S]*method:\s*'GET'[\s\S]*authorization:\s*`Bearer \$\{sessionToken\}`/,
  'login handoff must resolve the original call with the registered invitee bearer session',
);
assert.match(
  loginView,
  /String\(result\.state \|\| ''\)[\s\S]*!== 'resolved'[\s\S]*return redirectTarget/,
  'login handoff must not invent a join link when the authenticated invite resolution is not resolved',
);
assert.match(
  loginView,
  /const accessId = String\(result\?\.access_link\?\.id \|\| ''\)[\s\S]*if \(CALL_UUID_PATTERN\.test\(accessId\)\)[\s\S]*return `\/join\/\$\{encodeURIComponent\(accessId\)\}`/,
  'login handoff must rebind to the backend-returned access link for the intended invite',
);
assert.match(
  loginView,
  /callRequiresJoinModalForViewer\(call,\s*\{[\s\S]*userId:\s*sessionState\.userId[\s\S]*role:\s*sessionState\.role[\s\S]*email:\s*sessionState\.email[\s\S]*\}\)[\s\S]*createSelfJoinPathForCall\(callId,\s*sessionToken\)/,
  'login handoff may mint a self-join path only for the authenticated viewer who still requires the join modal',
);
assert.match(
  loginView,
  /const redirectTarget = getRedirectTarget\(result\.role\)[\s\S]*const normalizedTarget = await normalizePostLoginRedirectTarget\(redirectTarget\)[\s\S]*router\.replace\(normalizedTarget \|\| defaultRouteForRole\(result\.role\)\)/,
  'password login must navigate to the normalized registered-invite join handoff',
);

assert.match(
  admissionGate,
  /export function callRequiresJoinModalForViewer\(callPayload,\s*viewerPayload = \{\}\)[\s\S]*viewerUserId[\s\S]*viewerEmail[\s\S]*internalParticipants[\s\S]*viewerParticipant[\s\S]*inviteState === 'invited' \|\| inviteState === 'pending' \|\| inviteState === 'accepted'/,
  'join-modal decision must be based on the authenticated registered invitee identity and invite state',
);
assert.match(
  admissionGate,
  /export function callAccessVerifiedContextFromSession\(sessionPayload\)[\s\S]*userId[\s\S]*sessionId[\s\S]*sessionToken[\s\S]*return null[\s\S]*return \{\s*userId,\s*sessionId,\s*sessionToken,\s*\}/,
  'verified context helper must snapshot the registered invitee user, session id, and token',
);

assert.match(
  joinView,
  /fetchBackend\(`\/api\/call-access\/\$\{encodeURIComponent\(accessId\)\}\/join`[\s\S]*method:\s*'GET'[\s\S]*accept:\s*'application\/json'/,
  'join handoff must resolve the selected access link before session issuance',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)/,
  'join handoff must freeze the registered login context after resolving the intended invite',
);
assert.match(
  joinView,
  /loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext[\s\S]*\}\)/,
  'join handoff must send the frozen registered context into call-access session issuance',
);

assert.match(
  callAccessSession,
  /body\.verified_user_id\s*=\s*verifiedContext\.userId[\s\S]*body\.verified_session_id\s*=\s*verifiedContext\.sessionId/,
  'call-access session body must rebind the issued session to the intended verified registered invitee',
);
assert.match(
  callAccessSession,
  /headers\.authorization\s*=\s*`Bearer \$\{token\}`/,
  'call-access session request must carry the current registered invitee bearer token',
);
assert.match(
  callAccessSession,
  /verifiedContext[\s\S]*sessionState\.sessionToken[\s\S]*status:\s*409[\s\S]*errorCode:\s*'call_access_conflict'/,
  'call-access session request must fail closed if the registered login context disappears before issuing',
);

assert.match(
  routeGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*call_access_forbidden[\s\S]*not_bound_to_current_user[\s\S]*must not persist a session/,
  'backend route guard must reject a personalized invite rebound to the wrong registered account',
);
assert.match(
  routeGuardContract,
  /session switch should conflict[\s\S]*call_access_conflict[\s\S]*session_context_changed[\s\S]*must not persist a session/,
  'backend route guard must reject a changed verified registered session context',
);
assert.match(
  routeGuardContract,
  /matching logged-in user should issue[\s\S]*matching logged-in route should bind the linked user/,
  'backend route guard must still issue for the intended registered invitee account',
);

assert.match(
  seedMatrixSpec,
  /direct_join_registered_guest_alpha_active_allowed/,
  'seed matrix E2E proof must include the registered invitee direct access row',
);
assert.match(
  seedMatrixSpec,
  /expect\.soft\(responses\.resolve\.payload\?\.result\?\.call\?\.id[\s\S]*\.toBe\(call\.id\)/,
  'seed matrix E2E proof must verify the registered invitee resolves only the intended call',
);

console.log('[call-access-registered-logged-out-handoff-contract] PASS');
