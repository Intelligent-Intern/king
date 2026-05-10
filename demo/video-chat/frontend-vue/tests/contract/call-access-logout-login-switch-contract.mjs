import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  sessionStorageKey,
  storedSessionForSeedUser,
} from '../../tests/e2e/helpers/callAccessSeedMatrix.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function createBrowserStorage(initialSession = null) {
  const rows = new Map();
  if (initialSession) {
    rows.set(sessionStorageKey, JSON.stringify(initialSession));
  }
  return {
    getItem(key) {
      return rows.get(key) ?? null;
    },
    setItem(key, value) {
      rows.set(key, String(value));
    },
    removeItem(key) {
      rows.delete(key);
    },
    dump() {
      return JSON.stringify(Object.fromEntries(rows.entries()));
    },
  };
}

function storedSession(storage) {
  const raw = storage.getItem(sessionStorageKey);
  return raw ? JSON.parse(raw) : null;
}

function persistSession(storage, session) {
  if (!session) {
    storage.removeItem(sessionStorageKey);
    return;
  }
  storage.setItem(sessionStorageKey, JSON.stringify(session));
}

function sessionRequestFor(storage, verifiedContext) {
  const current = storedSession(storage);
  return {
    authorization: current?.sessionToken ? `Bearer ${current.sessionToken}` : '',
    body: verifiedContext
      ? {
          verified_user_id: verifiedContext.userId,
          verified_session_id: verifiedContext.sessionId,
        }
      : {},
  };
}

function routeGuardDecision(request, currentViewer) {
  if (!request.authorization) {
    return { ok: false, status: 409, code: 'call_access_conflict', adoptedSession: null };
  }
  if (
    request.body.verified_user_id !== currentViewer.userId
    || request.body.verified_session_id !== currentViewer.sessionId
  ) {
    return { ok: false, status: 409, code: 'call_access_conflict', adoptedSession: null };
  }
  return {
    ok: true,
    status: 200,
    code: '',
    adoptedSession: {
      sessionId: `call_access_${currentViewer.sessionId}`,
      sessionToken: `call_access_${currentViewer.sessionToken}`,
    },
  };
}

const authSession = readText('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const callAccessSession = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const verifiedContextContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs');
const accountIsolationContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-account-isolation-contract.mjs');
const routeGuardContract = readText('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');

const previousViewer = storedSessionForSeedUser('alpha_call_owner', 'alpha_active');
const nextViewer = storedSessionForSeedUser('alpha_normal_user', 'alpha_active');
assert.notEqual(previousViewer.userId, nextViewer.userId, 'proof must switch between distinct viewer accounts');
assert.notEqual(previousViewer.sessionToken, nextViewer.sessionToken, 'proof must switch between distinct session tokens');

const browserA = createBrowserStorage(previousViewer);
const browserB = createBrowserStorage();
const verifiedBeforeLogout = {
  userId: previousViewer.userId,
  sessionId: previousViewer.sessionId,
  sessionToken: previousViewer.sessionToken,
};

persistSession(browserA, null);
assert.equal(storedSession(browserA), null, 'logout must clear browser A storage before a different viewer logs in');
persistSession(browserB, nextViewer);
assert.equal(storedSession(browserB).sessionToken, nextViewer.sessionToken, 'browser B must hold only the newly logged-in viewer token');
assert.doesNotMatch(browserB.dump(), new RegExp(previousViewer.sessionToken), 'browser B storage must not contain previous viewer token');

const switchedRequest = sessionRequestFor(browserB, verifiedBeforeLogout);
assert.equal(switchedRequest.authorization, `Bearer ${nextViewer.sessionToken}`, 'session POST must use the current browser viewer token');
assert.deepEqual(
  switchedRequest.body,
  {
    verified_user_id: previousViewer.userId,
    verified_session_id: previousViewer.sessionId,
  },
  'session POST must preserve the originally verified viewer snapshot',
);
const switchedDecision = routeGuardDecision(switchedRequest, nextViewer);
assert.deepEqual(
  switchedDecision,
  { ok: false, status: 409, code: 'call_access_conflict', adoptedSession: null },
  'login switch must fail closed instead of reusing the previous viewer call-access session',
);

const loggedOutRequest = sessionRequestFor(browserA, verifiedBeforeLogout);
assert.equal(loggedOutRequest.authorization, '', 'logged-out browser must not send a stale bearer token');
const loggedOutDecision = routeGuardDecision(loggedOutRequest, previousViewer);
assert.equal(loggedOutDecision.ok, false, 'logged-out browser must not issue or adopt a call-access session');
assert.equal(loggedOutDecision.adoptedSession, null, 'logged-out browser must not adopt a foreign call-access session');

assert.match(
  authSession,
  /export async function logoutSession\(\)[\s\S]*finally \{[\s\S]*clearSessionState\(\);[\s\S]*setRecoveryState\('idle'\);[\s\S]*\}/,
  'logoutSession must always clear local viewer state',
);
assert.match(
  authSession,
  /export function clearSessionState\(\)[\s\S]*sessionState\.sessionId = '';[\s\S]*sessionState\.sessionToken = '';[\s\S]*persist\(\);/,
  'clearSessionState must persist the blanked session id and token',
);
assert.match(
  callAccessSession,
  /const verifiedContext = callAccessVerifiedContextFromSession\(options\?\.verifiedContext\);[\s\S]*verifiedContext && String\(sessionState\.sessionToken \|\| ''\)\.trim\(\) === ''[\s\S]*call_access_conflict/,
  'call-access session issuance must reject a verified context after logout before making a session request',
);
assert.match(
  callAccessSession,
  /body\.verified_user_id = verifiedContext\.userId[\s\S]*body\.verified_session_id = verifiedContext\.sessionId/,
  'call-access session request must send the verified viewer identity separately from the current bearer token',
);
assert.match(
  callAccessSession,
  /headers\.authorization = `Bearer \$\{token\}`/,
  'call-access session request must authorize with the current browser viewer token',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)[\s\S]*loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext/,
  'join flow must freeze the verified viewer context before issuing a call-access session',
);
assert.match(
  routeGuardContract,
  /session switch should conflict[\s\S]*call_access_conflict[\s\S]*session_context_changed[\s\S]*session switch route must not persist a session/s,
  'backend route guard must reject changed verified session context without persisting a call-access session',
);
assert.match(
  routeGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*call_access_forbidden[\s\S]*wrong account route must not persist a session/s,
  'backend route guard must reject a different logged-in account without persisting a call-access session',
);
assert.match(
  verifiedContextContract,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*logout during verified call-access link context fails closed without leaking or joining/s,
  'verified-context contract must keep login-switch and logout browser proofs anchored',
);
assert.match(
  accountIsolationContract,
  /logout must remove the previous stored session before the next login[\s\S]*login switch must not keep the logged-out account token/s,
  'account-isolation contract must prove storage replacement across viewer switch',
);
assert.match(
  callAccessJoinSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)[\s\S]*sessionRequestBody\)\.toEqual\(\{\s*verified_user_id:\s*2,\s*verified_session_id:\s*verifiedSession\.sessionId,\s*\}\)/s,
  'browser proof must show current bearer token plus previous verified viewer snapshot on login switch',
);
assert.match(
  callAccessJoinSpec,
  /logout during verified call-access link context fails closed without leaking or joining[\s\S]*await logoutSession\(\)[\s\S]*expect\(sessionPostCount\)\.toBe\(0\)/s,
  'browser proof must show logout clears state and prevents session POST reuse',
);
assert.match(
  callAccessJoinSpec,
  /same personalized link in parallel contexts keeps account sessions isolated[\s\S]*requests\.a\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountA\.sessionToken\}`\)[\s\S]*requests\.b\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountB\.sessionToken\}`\)/s,
  'parallel browser proof must keep each viewer bearer token isolated',
);

process.stdout.write('[call-access-logout-login-switch-contract] PASS\n');
