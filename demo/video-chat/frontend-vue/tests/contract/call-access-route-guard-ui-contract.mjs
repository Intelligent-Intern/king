import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(root, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const router = readFrontend('src/http/router.ts');
const routeAccess = readFrontend('src/http/routeAccess.js');
const loginView = readFrontend('src/domain/auth/LoginView.vue');
const joinView = readFrontend('src/domain/calls/access/JoinView.vue');
const admissionGate = readFrontend('src/domain/calls/access/admissionGate.ts');
const callAccessSession = readFrontend('src/domain/calls/access/callAccessSession.ts');
const routeResolution = readFrontend('src/domain/realtime/workspace/callWorkspace/routeResolution.ts');
const socketLifecycle = readFrontend('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const orchestration = readFrontend('src/domain/realtime/workspace/callWorkspace/orchestration.ts');
const callAccessJoinSpec = readFrontend('tests/e2e/call-access-join.spec.js');
const backendRouteGuardContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');

assert.match(
  router,
  /path:\s*'\/join\/:accessId'[\s\S]*name:\s*'call-access-join'[\s\S]*JoinView\.vue[\s\S]*meta:\s*\{\s*public:\s*true,\s*i18nNamespaces:\s*\['public'\]\s*\}/,
  'call-access join route must stay public and render the verified join UI, not the authenticated workspace directly',
);
assert.match(
  router,
  /path:\s*'\/call-goodbye'[\s\S]*name:\s*'call-goodbye'[\s\S]*GoodbyeView\.vue[\s\S]*meta:\s*\{\s*requiresAuth:\s*true,\s*roles:\s*\['user'\]\s*\}/,
  'call goodbye route must stay authenticated and user-scoped for guest call-access exits',
);
assert.match(
  router,
  /path:\s*'workspace\/call\/:callRef\?'[\s\S]*name:\s*'call-workspace'[\s\S]*CallWorkspaceView\.vue[\s\S]*meta:\s*\{\s*requiresAuth:\s*true,\s*roles:\s*\['admin',\s*'user'\]\s*\}/,
  'workspace call route must require an authenticated admin or user session',
);
assert.match(
  router,
  /if \(requiresAuth && !loggedIn\)[\s\S]*path:\s*'\/login'[\s\S]*query:\s*to\.fullPath !== '\/' \? \{ redirect:\s*to\.fullPath \}/,
  'router guard must send unauthenticated workspace access through login with the original target preserved',
);
assert.match(
  router,
  /if \(to\.name === 'call-goodbye' && loggedIn && !isGuestSession\(\)\)[\s\S]*return callListRouteForRole\(sessionState\.role\)/,
  'router guard must keep non-guest users out of the guest goodbye surface',
);
assert.match(
  router,
  /if \(loggedIn && !routeAllowsSessionAccess\(to,\s*sessionState\)\)[\s\S]*return defaultRouteForRole\(sessionState\.role\)/,
  'router guard must continue enforcing route role and permission access after login',
);

assert.match(
  routeAccess,
  /export function routeAllowsSessionAccess\(route,\s*session = \{\}\) \{[\s\S]*routeAllowsRole\(route,\s*accessContext\.role\)[\s\S]*routeAllowsRequiredPermissions\(route,\s*accessContext\)/,
  'route access helper must enforce both role and required-permission boundaries',
);
assert.match(
  routeAccess,
  /tenantPermissions\.platform_admin === true[\s\S]*role === 'admin' && permissionKeys\.length === 0/,
  'route access helper must preserve platform-admin all-permission semantics without weakening tenant IAM',
);

assert.match(
  loginView,
  /function extractWorkspaceCallRef\(redirectTarget\)[\s\S]*resolved\?\.name[\s\S]*'call-workspace'[\s\S]*entryMode === 'invite'[\s\S]*return ''[\s\S]*CALL_UUID_PATTERN\.test\(callRef\)/,
  'login redirect normalization must only intercept authenticated workspace call UUID redirects and must not re-wrap invite entries',
);
assert.match(
  loginView,
  /fetchBackend\(`\/api\/calls\/resolve\/\$\{encodeURIComponent\(callRef\)\}`[\s\S]*authorization:\s*`Bearer \$\{sessionToken\}`/,
  'post-login call redirect normalization must resolve calls with the verified current bearer session',
);
assert.match(
  loginView,
  /accessId[\s\S]*CALL_UUID_PATTERN\.test\(accessId\)[\s\S]*return `\/join\/\$\{encodeURIComponent\(accessId\)\}`/,
  'post-login call redirect normalization must prefer an existing access link and return the join modal route',
);
assert.match(
  loginView,
  /callRequiresJoinModalForViewer\(call,\s*\{[\s\S]*userId:\s*sessionState\.userId[\s\S]*role:\s*sessionState\.role[\s\S]*email:\s*sessionState\.email[\s\S]*\}\)[\s\S]*createSelfJoinPathForCall\(callId,\s*sessionToken\)/,
  'post-login call redirect normalization must only mint a self-join path when the viewer still requires the join modal',
);
assert.match(
  loginView,
  /const normalizedTarget = await normalizePostLoginRedirectTarget\(redirectTarget\)[\s\S]*router\.replace\(normalizedTarget \|\| defaultRouteForRole\(result\.role\)\)/,
  'login submit flow must navigate to the normalized join-modal target instead of bypassing admission UI',
);

assert.match(
  admissionGate,
  /export function callRequiresJoinModalForViewer\(callPayload,\s*viewerPayload = \{\}\)[\s\S]*viewerRole === 'admin'[\s\S]*return false[\s\S]*ownerUserId > 0 && ownerUserId === viewerUserId[\s\S]*return false[\s\S]*callRole === 'owner' \|\| callRole === 'moderator'[\s\S]*return false[\s\S]*inviteState === 'invited' \|\| inviteState === 'pending' \|\| inviteState === 'accepted'/,
  'admission gate must preserve owner, moderator, and admin bypasses while keeping invited users on the join modal',
);
assert.match(
  admissionGate,
  /export function callAccessVerifiedContextFromSession\(sessionPayload\)[\s\S]*userId[\s\S]*sessionId[\s\S]*sessionToken[\s\S]*return null[\s\S]*return \{\s*userId,\s*sessionId,\s*sessionToken,\s*\}/,
  'admission gate must expose a complete verified user/session/token snapshot helper',
);

assert.match(
  routeResolution,
  /async function redirectInvitedRouteToJoinModal\(callResolution\)[\s\S]*route\.name[\s\S]*'call-workspace'[\s\S]*currentWorkspaceEntryMode\(\) === 'invite'[\s\S]*return false/,
  'workspace route resolution must only redirect normal workspace entry, never the already admitted invite entry',
);
assert.match(
  routeResolution,
  /callRequiresJoinModalForViewer\(call,\s*\{[\s\S]*userId:\s*refs\.currentUserId\.value[\s\S]*role:\s*refs\.sessionState\.role[\s\S]*email:\s*refs\.sessionState\.email[\s\S]*\}\)[\s\S]*return false/,
  'workspace route resolution must reuse the same viewer-aware join-modal decision as login',
);
assert.match(
  routeResolution,
  /directAccessId[\s\S]*refs\.callUuidPattern\.test\(directAccessId\) \? `\/join\/\$\{encodeURIComponent\(directAccessId\)\}`[\s\S]*createSelfJoinPathForCall\(callResolution\?\.callId \|\| call\?\.id \|\| ''\)[\s\S]*refs\.router\.replace\(joinPath\)/,
  'workspace route resolution must redirect invited viewers to a join path instead of entering the room directly',
);
assert.match(
  routeResolution,
  /if \(callResolution\.state === 'resolved'\)[\s\S]*if \(await redirectInvitedRouteToJoinModal\(callResolution\)\)[\s\S]*redirecting:\s*true[\s\S]*return false/,
  'workspace route resolution must stop workspace entry after it redirects to the join modal',
);

assert.match(
  socketLifecycle,
  /requiresAdmission && pendingRoomId !== ''[\s\S]*!tryDirectJoinWithModeratorBypass\(pendingRoomId\)[\s\S]*setAdmissionGate\(pendingRoomId\)[\s\S]*redirectInvitedRouteToJoinModal\(/,
  'workspace socket welcome must redirect non-moderator admission-required users back to the join modal',
);
assert.match(
  socketLifecycle,
  /code === 'room_join_requires_admission' \|\| code === 'room_join_not_allowed'[\s\S]*!tryDirectJoinWithModeratorBypass\(pendingRoomId\)[\s\S]*setAdmissionGate\(pendingRoomId\)[\s\S]*redirectInvitedRouteToJoinModal\(/,
  'workspace socket error handling must redirect room-join denials to the join modal instead of silently joining',
);
assert.match(
  orchestration,
  /callEntryMode === 'invite' && refs\.isGuestSession\(\)[\s\S]*router\.push\(\{ name:\s*'call-goodbye' \}\)/,
  'call leave orchestration must send guest invite sessions to the call-goodbye route',
);

assert.match(
  joinView,
  /if \(!CALL_UUID_PATTERN\.test\(accessId\)\)[\s\S]*localizedApiErrorMessage\(\{ error:\s*\{ code:\s*'call_access_validation_failed' \} \}/,
  'public join UI must fail invalid access IDs closed with the safe validation copy',
);
assert.match(
  joinView,
  /fetchBackend\(`\/api\/call-access\/\$\{encodeURIComponent\(accessId\)\}\/join`[\s\S]*method:\s*'GET'[\s\S]*accept:\s*'application\/json'/,
  'public join UI must resolve link context through the backend join endpoint before presenting entry controls',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)/,
  'public join UI must freeze the verified user/session context after resolving the link',
);
assert.match(
  joinView,
  /loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext[\s\S]*\}\)/,
  'public join UI must pass the frozen verified context into session issuance',
);
assert.match(
  joinView,
  /state\.admissionMessage[\s\S]*role="status"[\s\S]*aria-live="polite"/,
  'public join UI must render admission wait and reconnect state through an accessible live status',
);
assert.match(
  joinView,
  /startAdmissionWait\(accessId\)/,
  'public join UI must enter the admission lobby after call-access session issuance rather than bypassing it',
);

assert.match(
  callAccessSession,
  /body\.verified_user_id\s*=\s*verifiedContext\.userId[\s\S]*body\.verified_session_id\s*=\s*verifiedContext\.sessionId/,
  'call-access session request body must include the verified user and session IDs',
);
assert.match(
  callAccessSession,
  /headers\.authorization\s*=\s*`Bearer \$\{token\}`/,
  'call-access session request must carry the current bearer token when a verified session is present',
);
assert.match(
  callAccessSession,
  /verifiedContext[\s\S]*sessionState\.sessionToken[\s\S]*status:\s*409[\s\S]*errorCode:\s*'call_access_conflict'/,
  'call-access session request must fail closed if verified context exists after local logout',
);

assert.match(
  backendRouteGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*call_access_forbidden[\s\S]*not_bound_to_current_user/,
  'backend route guard contract must still reject a personalized link used by the wrong logged-in account',
);
assert.match(
  backendRouteGuardContract,
  /session switch should conflict[\s\S]*call_access_conflict[\s\S]*session_context_changed/,
  'backend route guard contract must still reject verified-context session switching',
);
assert.match(
  backendRouteGuardContract,
  /invalid presented session should fail before public issuance[\s\S]*must not persist a session/,
  'backend route guard contract must still fail invalid bearer sessions before issuing call-access sessions',
);

assert.match(
  callAccessJoinSpec,
  /invalid call-access link renders safe state without foreign call data/,
  'E2E coverage must prove invalid join links render a safe state',
);
assert.match(
  callAccessJoinSpec,
  /expect\(page\.url\(\)\)\.toContain\(`\/join\/\$\{accessId\}`\)[\s\S]*expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'E2E coverage must prove denied or pending join flows stay on the join route instead of entering the workspace',
);
assert.match(
  callAccessJoinSpec,
  /await expect\(page\)\.toHaveURL\(new RegExp\(`\/workspace\/call\/\$\{call\.id\.replaceAll\('-', '\\\\-'\)\}\\\\\?entry=invite`\)/,
  'E2E coverage must prove admitted call-access sessions enter the workspace only with invite entry context',
);
assert.match(
  callAccessJoinSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*expect\(sessionPayload\?\.error\?\.code\)\.toBe\('call_access_conflict'\)/,
  'E2E coverage must prove verified-context login switching fails safely',
);
assert.match(
  callAccessJoinSpec,
  /logout during verified call-access link context fails closed without leaking or joining[\s\S]*expect\(sessionPostCount\)\.toBe\(0\)[\s\S]*expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'E2E coverage must prove logout after verified context does not issue or join a call-access session',
);

console.log('[call-access-route-guard-ui-contract] PASS');
