import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function assertNoNeedles(value, needles, label) {
  const serialized = typeof value === 'string' ? value : JSON.stringify(value);
  for (const needle of needles) {
    const text = String(needle || '').trim();
    if (text === '') continue;
    assert.doesNotMatch(serialized, new RegExp(escapeRegExp(text), 'i'), `${label} must not contain ${text}`);
  }
}

function localizedJoinErrorFromCode(code) {
  const messages = {
    call_access_conflict: 'This call link cannot be used for the current call state.',
    call_access_forbidden: 'This call link is not available for your session.',
  };
  return messages[code] || 'Could not start call access session.';
}

function uiStateAfterDeniedSession({ accessId, currentSession, payload }) {
  const code = String(payload?.error?.code || '').trim();
  return {
    route: `/join/${accessId}`,
    joinError: localizedJoinErrorFromCode(code),
    waitingForAdmission: false,
    enteredWorkspace: false,
    storedSession: { ...currentSession },
  };
}

const e2eSpec = read('tests/e2e/call-access-join.spec.js');
const joinView = read('src/domain/calls/access/JoinView.vue');
const callAccessSessionClient = read('src/domain/calls/access/callAccessSession.ts');
const apiErrors = read('src/modules/localization/apiErrorMessages.js');
const englishMessages = read('src/modules/localization/englishMessages.js');
const callAccessRoutes = read('../backend-king-php/http/module_calls_access.php');
const callAccessSessionBackend = read('../backend-king-php/domain/calls/call_access_session.php');

const foreignNeedles = [
  'Foreign Wrong Account',
  'foreign-wrong-account@example.invalid',
  'Private Mismatch Host',
  'private-mismatch-host@example.invalid',
  'Private Mismatch Call',
  'foreign-call-room',
  'foreign-call-id',
  'Private Calendar Booking',
  'foreign-calendar-public-id',
  'private-calendar-owner@example.invalid',
  'Foreign Organization',
  'foreign-org-public-id',
  'sess_foreign_denied_should_not_bind',
];

const currentSession = {
  sessionId: 'sess_current_verified_user',
  sessionToken: 'sess_current_verified_user',
  userId: 7,
};

const hostileConflictPayload = {
  status: 'error',
  error: {
    code: 'call_access_conflict',
    message: 'Foreign Wrong Account cannot use Private Mismatch Call',
  },
  result: {
    session: {
      id: 'sess_foreign_denied_should_not_bind',
      token: 'sess_foreign_denied_should_not_bind',
    },
    user: {
      id: 99,
      display_name: 'Foreign Wrong Account',
      email: 'foreign-wrong-account@example.invalid',
    },
    call: {
      id: 'foreign-call-id',
      room_id: 'foreign-call-room',
      title: 'Private Mismatch Call',
      owner: {
        display_name: 'Private Mismatch Host',
        email: 'private-mismatch-host@example.invalid',
      },
    },
    calendar: {
      title: 'Private Calendar Booking',
      public_id: 'foreign-calendar-public-id',
      owner_email: 'private-calendar-owner@example.invalid',
    },
    tenant: {
      label: 'Foreign Organization',
      public_id: 'foreign-org-public-id',
    },
  },
};

const hostileForbiddenPayload = {
  status: 'error',
  error: {
    code: 'call_access_forbidden',
    message: 'Foreign Wrong Account is not bound to Private Mismatch Host',
    details: {
      mismatch: 'strong_personalized_link',
      fields: {
        host_name: 'wrong_host_name',
      },
    },
  },
  result: hostileConflictPayload.result,
};

assert.match(JSON.stringify(hostileConflictPayload), /Foreign Wrong Account/, 'hostile conflict fixture must contain foreign data before UI sanitization');
assert.match(JSON.stringify(hostileForbiddenPayload), /Private Calendar Booking/, 'hostile forbidden fixture must contain calendar data before UI sanitization');

const conflictUiState = uiStateAfterDeniedSession({
  accessId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  currentSession,
  payload: hostileConflictPayload,
});
assert.equal(conflictUiState.joinError, 'This call link cannot be used for the current call state.');
assert.equal(conflictUiState.waitingForAdmission, false);
assert.equal(conflictUiState.enteredWorkspace, false);
assert.equal(conflictUiState.storedSession.sessionToken, currentSession.sessionToken);
assertNoNeedles(conflictUiState, foreignNeedles, 'conflict UI denial state');

const forbiddenUiState = uiStateAfterDeniedSession({
  accessId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  currentSession,
  payload: hostileForbiddenPayload,
});
assert.equal(forbiddenUiState.joinError, 'This call link is not available for your session.');
assert.equal(forbiddenUiState.waitingForAdmission, false);
assert.equal(forbiddenUiState.enteredWorkspace, false);
assert.equal(forbiddenUiState.storedSession.sessionToken, currentSession.sessionToken);
assertNoNeedles(forbiddenUiState, foreignNeedles, 'forbidden UI denial state');

assert.match(
  apiErrors,
  /call_access_conflict:\s*'errors\.api\.call_access_conflict'[\s\S]*call_access_forbidden:\s*'errors\.api\.call_access_forbidden'/,
  'localized API errors must map mismatch denial codes to stable translation keys',
);
assert.match(
  apiErrors,
  /export function localizedApiErrorMessage\(payload, fallback = ''\) \{[\s\S]*const key = apiErrorMessageKey\(code\);[\s\S]*if \(key !== ''\) \{[\s\S]*return t\(key\);[\s\S]*\}/,
  'localized API errors must prefer stable codes over backend message text',
);
assert.match(
  englishMessages,
  /'errors\.api\.call_access_conflict': 'This call link cannot be used for the current call state\.'/,
  'conflict denial copy must be generic and contain no person, call, calendar, or organization interpolation',
);
assert.match(
  englishMessages,
  /'errors\.api\.call_access_forbidden': 'This call link is not available for your session\.'/,
  'forbidden denial copy must be generic and contain no person, call, calendar, or organization interpolation',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*const errorPayload = result\.errorCode \? \{ error: \{ code: result\.errorCode \} \} : null;[\s\S]*state\.joinError = localizedApiErrorMessage\(errorPayload, t\('public\.join\.start_session_failed'\)\);[\s\S]*return;[\s\S]*\}/,
  'public join UI must render denied session states from error code only, not backend result/message payloads',
);
assert.match(
  joinView,
  /if \(!result\.ok\)[\s\S]*return;[\s\S]*const call = result\.call[\s\S]*startAdmissionWait\(accessId\);/,
  'public join UI must not read call data or open lobby admission after mismatch denial',
);
assert.match(
  callAccessSessionClient,
  /if \(!response\.ok\) \{[\s\S]*errorCode: errorCodeFromPayload\(payload\),[\s\S]*message: extractErrorMessage\(payload, 'Could not start call access session\.'\),[\s\S]*\}/,
  'session client may retain transport diagnostics but must expose a separate stable errorCode for safe UI rendering',
);
assert.match(
  callAccessSessionClient,
  /const result = payload\?\.result[\s\S]*applySessionEnvelope\(result\.session, result\.user, result\.tenant\);/,
  'session envelopes must only be applied on successful call-access responses',
);

assert.match(
  callAccessSessionBackend,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must classify verified-session switch as a conflict before issuing a session',
);
assert.match(
  callAccessSessionBackend,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must classify verified-account switch as a conflict before issuing a session',
);
assert.match(
  callAccessSessionBackend,
  /\$linkKind === 'personal' && !\$createdPersonalGuest && \$authenticatedUserId > 0 && \$authenticatedUserId !== \$userId[\s\S]*'reason' => 'forbidden'[\s\S]*'auth' => 'not_bound_to_current_user'[\s\S]*'host_name' => \$hostName === '' \? 'not_verified' : 'wrong_host_name'/,
  'backend must classify wrong host/account identity as a forbidden personalized-link mismatch',
);
assert.match(
  callAccessRoutes,
  /if \(\$reason === 'conflict'\) \{[\s\S]*return \$errorResponse\(409, 'call_access_conflict'[\s\S]*'fields' => is_array\(\$issueResult\['errors'\]/,
  'HTTP session route must project conflict denials to code and fields, not foreign result payloads',
);
assert.match(
  callAccessRoutes,
  /if \(\$reason === 'forbidden'\) \{[\s\S]*return \$errorResponse\(403, 'call_access_forbidden'[\s\S]*'fields' => is_array\(\$issueResult\['errors'\]/,
  'HTTP session route must project forbidden denials to code and fields, not foreign result payloads',
);

assert.match(
  e2eSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data[\s\S]*const foreignTitle = 'Foreign Switched Account Call'[\s\S]*const foreignEmail = 'foreign-switch@example\.invalid'[\s\S]*not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'login-switch E2E must prove wrong-account mismatch does not render foreign person, call, or session data',
);
assert.match(
  e2eSpec,
  /logout during verified call-access link context fails closed without leaking or joining[\s\S]*foreignNeedles\s*=\s*\[[\s\S]*foreignTitle[\s\S]*foreignInviteEmail[\s\S]*foreignHostName[\s\S]*foreignHostEmail[\s\S]*rejectedSessionToken[\s\S]*\][\s\S]*expect\(sessionPostCount\)\.toBe\(0\)[\s\S]*not\.toContain\('\/workspace\/call'\)/,
  'logout E2E must prove a missing verified account cannot post, join, or render foreign mismatch data',
);
assert.match(
  e2eSpec,
  /same personalized link in parallel contexts keeps account sessions isolated[\s\S]*Foreign Linked Call Title[\s\S]*account B conflict must not render[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'parallel-account E2E must prove rejected account state does not bind or render foreign call/session data',
);
assert.match(
  e2eSpec,
  /strong personalized-link mismatch wrong host denial gives no access and leaks no foreign person data[\s\S]*mismatch:\s*'strong_personalized_link'[\s\S]*host_name:\s*'wrong_host_name'[\s\S]*not\.toContainText\(\/Call owner has been notified\|Waiting for host\/i\)[\s\S]*not\.toContain\('\/workspace\/call'\)/,
  'strong mismatch E2E must prove wrong-host denial stays out of lobby and workspace states',
);
assert.doesNotMatch(
  e2eSpec,
  /call_access_conflict[\s\S]{0,400}(calendar|organization|tenant)[\s\S]{0,400}toContainText/i,
  'mismatch denial E2E must not assert rendering calendar or organization data for conflict states',
);

console.log('[call-access-mismatch-no-leak-states-contract] PASS');
