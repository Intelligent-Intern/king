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

const authSession = readFrontend('src/domain/auth/session.ts');
const admissionGate = readFrontend('src/domain/calls/access/admissionGate.ts');
const joinView = readFrontend('src/domain/calls/access/JoinView.vue');
const callAccessSessionClient = readFrontend('src/domain/calls/access/callAccessSession.ts');
const callAccessJoinSpec = readFrontend('tests/e2e/call-access-join.spec.js');
const callAccessSeedHelper = readFrontend('tests/e2e/helpers/callAccessSeedMatrix.js');
const personalizedTempReuseContract = readFrontend('tests/contract/call-access-personalized-temp-reuse-contract.mjs');
const routeGuardContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const callAccessSession = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessRoutes = readRepo('demo/video-chat/backend-king-php/http/module_calls_access.php');
const authSupport = readRepo('demo/video-chat/backend-king-php/support/auth.php');
const tenantContext = readRepo('demo/video-chat/backend-king-php/support/tenant_context.php');

assert.match(
  authSession,
  /storage\.setItem\([\s\S]*JSON\.stringify\(\{[\s\S]*sessionId:\s*sessionState\.sessionId,[\s\S]*sessionToken:\s*sessionState\.sessionToken,[\s\S]*expiresAt:\s*sessionState\.expiresAt,[\s\S]*\}\)/,
  'frontend storage must persist only session id/token/expiry, not trusted user, tenant, or call identity',
);
assert.doesNotMatch(
  authSession,
  /storage\.setItem\([\s\S]*userId[\s\S]*tenantId[\s\S]*callId/s,
  'frontend storage must not persist a user/tenant/call tuple that could rebind verified context',
);
assert.match(
  authSession,
  /fetchBackend\('\/api\/auth\/session-state'[\s\S]*headers:\s*sessionHeaders\(\)[\s\S]*applySessionEnvelope\(payload\.session,\s*payload\.user,\s*payload\.tenant\)/,
  'session recovery must replace browser user and tenant state from the backend session-state response',
);
assert.match(
  authSession,
  /function sessionHeaders\(\)[\s\S]*authorization:\s*`Bearer \$\{token\}`/,
  'session recovery must authenticate with the stored bearer token rather than stored user metadata',
);
assert.match(
  authSupport,
  /videochat_validate_call_access_session_binding\([\s\S]*\$trimmedSessionId,[\s\S]*\(int\) \$row\['user_id'\]/,
  'backend auth must revalidate call-access session bindings with the stored session id and user id',
);
assert.match(
  tenantContext,
  /WHERE call_access_sessions\.session_id = :session_id[\s\S]*AND call_access_sessions\.user_id = :user_id[\s\S]*AND calls\.status IN \('scheduled', 'active'\)[\s\S]*AND tenants\.status = 'active'/,
  'backend tenant fallback must derive tenant context from the bound call-access session, user, and active call',
);

assert.match(
  admissionGate,
  /export function callAccessVerifiedContextFromSession\(sessionPayload\)[\s\S]*const userId = normalizeUserId\(session\.userId[\s\S]*const sessionId = String\(session\.sessionId[\s\S]*const sessionToken = String\(session\.sessionToken[\s\S]*return null[\s\S]*return \{\s*userId,\s*sessionId,\s*sessionToken,\s*\}/,
  'verified context snapshot must contain only normalized user id, session id, and session token',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)/,
  'public join must snapshot verified context only after session recovery has populated sessionState',
);
assert.match(
  joinView,
  /loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext[\s\S]*\}\)/,
  'public join must pass the frozen verified context into session issuance',
);
assert.match(
  callAccessSessionClient,
  /body\.verified_user_id\s*=\s*verifiedContext\.userId[\s\S]*body\.verified_session_id\s*=\s*verifiedContext\.sessionId/,
  'frontend call-access session body must send only verified user/session ids from the snapshot',
);
assert.doesNotMatch(
  callAccessSessionClient,
  /verified_(?:tenant|call|room|access)_id|tenant_id\s*=|call_id\s*=|room_id\s*=/,
  'frontend must not send tamperable tenant, call, room, or access binding fields as verified context',
);
assert.match(
  callAccessSessionClient,
  /const token = String\(sessionState\.sessionToken \|\| ''\)\.trim\(\)[\s\S]*headers\.authorization\s*=\s*`Bearer \$\{token\}`/,
  'frontend must authenticate issuance with the current recovered bearer token',
);
assert.match(
  callAccessSessionClient,
  /if \(verifiedContext && String\(sessionState\.sessionToken \|\| ''\)\.trim\(\) === ''\)[\s\S]*status:\s*409[\s\S]*errorCode:\s*'call_access_conflict'/,
  'frontend must fail closed if storage manipulation leaves a verified snapshot without a current session token',
);

assert.match(
  callAccessRoutes,
  /if \(array_key_exists\('verified_user_id', \$payload\)\)[\s\S]*\$sessionOptions\['verified_user_id'\][\s\S]*if \(array_key_exists\('verified_session_id', \$payload\)\)[\s\S]*\$sessionOptions\['verified_session_id'\]/,
  'backend route should accept only verified user/session fields from the frontend payload',
);
assert.doesNotMatch(
  callAccessRoutes,
  /verified_(?:tenant|call|room|access)_id|sessionOptions\['tenant_id'\]|sessionOptions\['call_id'\]|sessionOptions\['room_id'\]/,
  'backend route must ignore tampered tenant/call/room/access binding fields from the frontend payload',
);
assert.match(
  callAccessRoutes,
  /videochat_call_access_session_auth_context\([\s\S]*videochat_authenticate_request\(\$pdo,\s*\$request,\s*'rest'\)[\s\S]*\$sessionOptions\['authenticated_user_id'\][\s\S]*\$sessionOptions\['authenticated_session_id'\]/,
  'backend route must derive authenticated user/session from the bearer token, not the verified payload',
);

assert.match(
  callAccessSession,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId,\s*\$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'session issuance must reject tampered verified_session_id that differs from the bearer session',
);
assert.match(
  callAccessSession,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'session issuance must reject tampered verified_user_id that differs from the bearer user',
);
assert.match(
  callAccessSession,
  /\$linkKind === 'personal' && !\$createdPersonalGuest && \$verifiedUserId > 0 && \$verifiedUserId !== \$userId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'session issuance must reject a verified user that does not match the personalized link target',
);
assert.match(
  callAccessSession,
  /\$linkKind === 'personal' && !\$createdPersonalGuest && \$authenticatedUserId > 0 && \$authenticatedUserId !== \$userId[\s\S]*'reason' => 'forbidden'[\s\S]*'auth' => 'not_bound_to_current_user'/,
  'session issuance must reject a bearer user that is not bound to the personalized link target',
);
assert.match(
  callAccessSession,
  /videochat_call_access_session_id_available\(\$pdo,\s*\$sessionId\)[\s\S]*'session_id_not_available'/,
  'session issuance must reject duplicate session ids instead of rebinding an existing browser session',
);
assert.match(
  callAccessSession,
  /videochat_tenant_update_session\(\$pdo,\s*\$sessionId,\s*\$tenantId\)[\s\S]*INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at\{\$bindTenantColumn\}\)[\s\S]*':session_id' => \$sessionId,[\s\S]*':access_id' => \(string\) \(\$accessLink\['id'\] \?\? ''\),[\s\S]*':call_id' => \$callId,[\s\S]*':room_id' => \$roomId,[\s\S]*':user_id' => \$userId,[\s\S]*\$bindParams\[':tenant_id'\] = \$tenantId/,
  'issued sessions must persist tenant/call/access/room/user bindings from server-side link and call rows',
);
assert.match(
  callAccessSession,
  /videochat_get_call_for_user\([\s\S]*\(string\) \(\$call\['id'\] \?\? ''\),[\s\S]*\$userId,[\s\S]*\$userRole,[\s\S]*\$tenantId/,
  'session response call payload must be fetched for the server-selected user and tenant',
);

assert.match(
  routeGuardContract,
  /'verified_user_id' => \$standardUserId,[\s\S]*'verified_session_id' => 'sess_route_guard_standard'/,
  'backend route guard contract must submit a tampered verified user/session pair',
);
assert.match(
  routeGuardContract,
  /session switch should conflict[\s\S]*call_access_conflict[\s\S]*session_context_changed[\s\S]*session switch route must not persist a session/,
  'backend route guard contract must prove tampered verified user/session cannot persist a session',
);
assert.match(
  routeGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*call_access_forbidden[\s\S]*not_bound_to_current_user[\s\S]*wrong account route must not persist a session/,
  'backend route guard contract must prove current bearer user cannot rebind to another personalized link target',
);
assert.match(
  routeGuardContract,
  /invalid presented session should fail before public issuance[\s\S]*invalid presented session must not persist a session/,
  'backend route guard contract must reject manipulated bearer sessions before session issuance',
);

assert.match(
  personalizedTempReuseContract,
  /session issuance must fail when another browser or tab changes the verified session context[\s\S]*session issuance must fail when another account changes the verified user context[\s\S]*issued personalized temp sessions must persist the session\/access\/call\/room\/user binding/,
  'existing call-access contract must pin verified context and binding invariants for session/user/call/tenant',
);
assert.match(
  callAccessJoinSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)[\s\S]*verified_user_id:\s*2[\s\S]*verified_session_id:\s*verifiedSession\.sessionId[\s\S]*call_access_conflict/,
  'browser E2E must prove storage/login-switch tampering cannot rebind a verified user/session',
);
assert.match(
  callAccessJoinSpec,
  /same personalized link in parallel contexts keeps account sessions isolated[\s\S]*requests\.a\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountA\.sessionToken\}`\)[\s\S]*requests\.b\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountB\.sessionToken\}`\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)/,
  'browser E2E must prove parallel storage contexts cannot cross-bind sessions',
);
assert.match(
  callAccessJoinSpec,
  /strong personalized-link mismatch wrong host denial gives no access and leaks no foreign person data[\s\S]*call_access_forbidden[\s\S]*sessionRequestAuthorization\)\.toBe\(`Bearer \$\{wrongLoggedInSession\.sessionToken\}`\)[\s\S]*verified_user_id:\s*wrongLoggedInUserId[\s\S]*verified_session_id:\s*wrongLoggedInSession\.sessionId[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(deniedSessionToken\)/,
  'browser E2E must prove wrong stored account context cannot bind a foreign personalized call',
);
assert.match(
  callAccessSeedHelper,
  /function sessionStatePayload\(record\) \{[\s\S]*const tenant = record\.tenant \|\| tenantSnapshotFor\(record\.user, record\.call\);[\s\S]*user:\s*userPayload\(record\.user,\s*tenant\),[\s\S]*tenant,/,
  'seed harness must derive session state user and tenant from server-side session records',
);
assert.match(
  callAccessSeedHelper,
  /issuedSessions\.set\(session\.token,\s*\{ session,\s*user:\s*targetUser,\s*call,\s*tenant,\s*link \}\)/,
  'seed harness must model session state from issued server-side records rather than local storage metadata',
);

console.log('[call-access-tampered-verified-context-contract] PASS');
