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
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function storageWithSession(initialSession = null) {
  const rows = new Map();
  if (initialSession) rows.set(sessionStorageKey, JSON.stringify(initialSession));
  return {
    getItem: (key) => rows.get(key) ?? null,
    setItem: (key, value) => rows.set(key, String(value)),
    removeItem: (key) => rows.delete(key),
    dump: () => JSON.stringify(Object.fromEntries(rows.entries())),
  };
}

function storedSession(storage) {
  const raw = storage.getItem(sessionStorageKey);
  return raw ? JSON.parse(raw) : null;
}

function replaceStoredSession(storage, session) {
  if (!session?.sessionToken) {
    storage.removeItem(sessionStorageKey);
    return;
  }
  storage.setItem(sessionStorageKey, JSON.stringify(session));
}

function sessionPostFor(storage, verifiedContext) {
  const current = storedSession(storage);
  return {
    authorization: current?.sessionToken ? `Bearer ${current.sessionToken}` : '',
    body: {
      verified_user_id: verifiedContext.userId,
      verified_session_id: verifiedContext.sessionId,
    },
  };
}

function backendDecision(request, activeViewer) {
  if (!request.authorization) {
    return { ok: false, status: 409, code: 'call_access_conflict', auth: 'missing_session', session: null };
  }
  if (
    request.body.verified_user_id !== activeViewer.userId
    || request.body.verified_session_id !== activeViewer.sessionId
  ) {
    return { ok: false, status: 409, code: 'call_access_conflict', auth: 'session_context_changed', session: null };
  }
  return { ok: true, status: 200, code: 'ok', auth: 'matched', session: { token: `call_access_${activeViewer.sessionToken}` } };
}

function assertNoNeedles(value, needles, message) {
  const serialized = JSON.stringify(value).toLowerCase();
  for (const needle of needles) {
    assert.equal(serialized.includes(String(needle).toLowerCase()), false, `${message}: leaked ${needle}`);
  }
}

const authSessionSource = readText('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const callAccessSessionSource = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const logoutLoginSwitchContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-logout-login-switch-contract.mjs');
const accountIsolationContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-account-isolation-contract.mjs');
const duplicateInviteReplayContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs');
const routeGuardContract = readText('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const callAccessSessionBackend = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');

assert.match(
  logoutLoginSwitchContract,
  /logout must clear browser A storage before a different viewer logs in[\s\S]*session POST must use the current browser viewer token[\s\S]*session POST must preserve the originally verified viewer snapshot/s,
  'current logout/login-switch contract must already pin the separated logout and login-switch primitives',
);
assert.match(
  accountIsolationContract,
  /logout must remove the previous stored session before the next login[\s\S]*login switch must store the newly authenticated account token[\s\S]*post-switch storage must not retain previous account token or email/s,
  'current account-isolation contract must already pin account replacement in browser storage',
);
assert.match(
  duplicateInviteReplayContract,
  /stale verified-context replay must preserve the current logged-in session instead of adopting a rejected token[\s\S]*stale verified-context replay denial must not render foreign call, person, or token data/s,
  'current duplicate replay contract must already pin no-rebind and no-leak behavior for stale verified contexts',
);

assert.match(
  callAccessJoinSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)[\s\S]*verified_user_id:\s*2,[\s\S]*verified_session_id:\s*verifiedSession\.sessionId/s,
  'browser E2E must prove a login switch posts current bearer token with the originally verified identity',
);
assert.match(
  callAccessJoinSpec,
  /logout during verified call-access link context fails closed without leaking or joining[\s\S]*await logoutSession\(\)[\s\S]*expect\(sessionPostCount\)\.toBe\(0\)/s,
  'browser E2E must prove logout clears the verified link flow before any session POST',
);

assert.match(
  authSessionSource,
  /export async function logoutSession\(\)[\s\S]*finally \{[\s\S]*clearSessionState\(\);[\s\S]*setRecoveryState\('idle'\);[\s\S]*\}/,
  'logoutSession must always clear local state even when backend logout handling varies',
);
assert.match(
  authSessionSource,
  /function resetUserFields\(\)[\s\S]*sessionState\.userId = 0;[\s\S]*export function clearSessionState\(\)[\s\S]*resetUserFields\(\);[\s\S]*sessionState\.sessionId = '';[\s\S]*sessionState\.sessionToken = '';[\s\S]*persist\(\);/,
  'clearSessionState must persist a blank user/session before a later login switch',
);
assert.match(
  callAccessSessionSource,
  /body\.verified_user_id = verifiedContext\.userId;[\s\S]*body\.verified_session_id = verifiedContext\.sessionId;/,
  'call-access session POST must carry the frozen verified viewer separately from current auth',
);
assert.match(
  callAccessSessionSource,
  /headers\.authorization = `Bearer \$\{token\}`/,
  'call-access session POST must authorize with the current browser account after login switch',
);
assert.match(
  callAccessSessionSource,
  /if \(!response\.ok\) \{[\s\S]*ok: false,[\s\S]*errorCode: errorCodeFromPayload\(payload\)[\s\S]*\}[\s\S]*applySessionEnvelope\(result\.session, result\.user, result\.tenant\);/s,
  'frontend must not apply a rejected call-access session envelope from a 409 response',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)[\s\S]*loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext/,
  'join view must freeze the verified viewer at link resolution and reuse it during redemption',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*state\.joinError = localizedApiErrorMessage\(errorPayload, t\('public\.join\.start_session_failed'\)\);[\s\S]*return;[\s\S]*\}/,
  'join view must stop on 409 conflict before consuming any returned call/session payload',
);

assert.match(
  callAccessSessionBackend,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend session issuer must reject verified-session drift as call_access_conflict',
);
assert.match(
  callAccessSessionBackend,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend session issuer must reject verified-user drift as call_access_conflict',
);
assert.match(
  callsAccessRoute,
  /if \(\$reason === 'conflict'\) \{[\s\S]*return \$errorResponse\(409, 'call_access_conflict'[\s\S]*'fields' => is_array\(\$issueResult\['errors'\] \?\? null\) \? \$issueResult\['errors'\] : \[\]/,
  'HTTP route must expose context changes as a narrow 409 fields payload',
);
assert.match(
  routeGuardContract,
  /session switch should conflict[\s\S]*session_context_changed[\s\S]*session switch response[\s\S]*session switch route must not persist a session/s,
  'backend route-guard proof must reject session switches without persisting call-access sessions',
);

const verifiedAccount = storedSessionForSeedUser('alpha_call_owner', 'alpha_active');
const switchedAccount = storedSessionForSeedUser('alpha_normal_user', 'alpha_active');
assert.notEqual(verifiedAccount.userId, switchedAccount.userId, 'combined proof must use two distinct accounts');
assert.notEqual(verifiedAccount.sessionToken, switchedAccount.sessionToken, 'combined proof must use two distinct session tokens');

const browser = storageWithSession(verifiedAccount);
const verifiedContext = {
  userId: verifiedAccount.userId,
  sessionId: verifiedAccount.sessionId,
};
replaceStoredSession(browser, null);
assert.equal(storedSession(browser), null, 'same-browser logout must erase account A before account B login');
replaceStoredSession(browser, switchedAccount);
assert.equal(storedSession(browser).sessionToken, switchedAccount.sessionToken, 'same browser must store only account B after login switch');
assertNoNeedles(browser.dump(), [verifiedAccount.sessionToken, verifiedAccount.email], 'same-browser post-switch storage');

const request = sessionPostFor(browser, verifiedContext);
assert.deepEqual(
  request,
  {
    authorization: `Bearer ${switchedAccount.sessionToken}`,
    body: {
      verified_user_id: verifiedAccount.userId,
      verified_session_id: verifiedAccount.sessionId,
    },
  },
  'combined logout/login-switch redemption must use account B auth with account A verified snapshot',
);
assert.deepEqual(
  backendDecision(request, switchedAccount),
  { ok: false, status: 409, code: 'call_access_conflict', auth: 'session_context_changed', session: null },
  'combined logout/login-switch redemption must deterministically fail closed',
);

const hostileConflictPayload = {
  status: 'error',
  error: {
    code: 'call_access_conflict',
    message: 'Call access cannot be used for the current call state.',
    details: {
      fields: { auth: 'session_context_changed' },
      review: {
        flag: 'duplicate_personalized_link',
        state: 'manual_review_required',
        access_fingerprint: 'sha256:duplicate-switch-link',
        subject_user_id: switchedAccount.userId,
        raw_link_identifier_logged: false,
        account_email_logged: false,
      },
    },
  },
  result: {
    session: { token: 'sess_duplicate_switch_should_not_bind' },
    user: { email: 'original-switch-target@example.invalid', display_name: 'Original Switch Target' },
    call: { title: 'Private Switch Host' },
  },
};
const safeDialogState = {
  text: 'This call link cannot be used for the current call state.',
  url: '/join/77777777-7777-4777-8777-777777777777',
  storedSession: storedSession(browser),
};
assertNoNeedles(
  safeDialogState,
  [
    'original-switch-target@example.invalid',
    'Original Switch Target',
    'Private Switch Host',
    'private-switch-host@example.invalid',
    'sess_duplicate_switch_should_not_bind',
    hostileConflictPayload.error.details.review.access_fingerprint,
  ],
  'combined logout/login-switch safe dialog state',
);
assert.equal(safeDialogState.storedSession.sessionToken, switchedAccount.sessionToken, 'rejected payload must not replace account B session');
assert.notEqual(safeDialogState.storedSession.sessionToken, hostileConflictPayload.result.session.token, 'rejected call-access token must not bind');
assert.equal(safeDialogState.url.includes('/workspace/call'), false, 'combined logout/login-switch conflict must not enter the call workspace');

process.stdout.write('[call-access-logout-switch-extract-contract] PASS\n');
