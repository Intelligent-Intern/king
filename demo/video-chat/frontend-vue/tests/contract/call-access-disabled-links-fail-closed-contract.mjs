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

function serialize(value) {
  return JSON.stringify(value);
}

function assertNoPrivateNeedles(payload, needles, label) {
  const serialized = serialize(payload);
  for (const needle of needles) {
    assert.equal(
      serialized.includes(needle),
      false,
      `${label} must not leak private value ${needle}`,
    );
  }
}

function disabledResolveResult() {
  return {
    ok: false,
    reason: 'conflict',
    errors: { call_id: 'call_not_joinable_from_status' },
    access_link: null,
    call: null,
    target_user: null,
    target_hint: { participant_email: null },
  };
}

function disabledSessionResult(resolveResult) {
  assert.equal(resolveResult.ok, false, 'disabled links must be denied before session issue continues');
  return {
    ok: false,
    reason: resolveResult.reason,
    errors: resolveResult.errors,
    session: null,
    user: null,
    access_link: null,
    call: null,
    sideEffects: {
      sessionPostSucceeded: false,
      sessionCreated: false,
      callAccessSessionCreated: false,
      guestOrTempUserCreated: false,
      participantOrLobbyRowInserted: false,
    },
  };
}

function disabledJoinUiState(resolveResponse) {
  assert.equal(resolveResponse.ok, false, 'disabled join context must be a failed response');
  return {
    contextError: 'call_access_validation_failed',
    callId: '',
    roomId: '',
    callTitle: '',
    linkKind: '',
    requiresGuestName: false,
    joinControlsRendered: false,
    sessionPostAttempted: false,
    lobbyFrames: [],
    backendPayloadRendered: false,
  };
}

const disabledCases = [
  {
    label: 'anonymous open link disabled',
    link: {
      id: 'open-disabled-link-id',
      kind: 'open',
      participant_user_id: 0,
      participant_email: '',
      tenant_id: 4101,
    },
    call: {
      id: 'disabled-open-call-id',
      room_id: 'private-open-room',
      title: 'Private Disabled Open Call',
      status: 'disabled',
      organization: 'Alpha Private Org',
      owner_email: 'alpha-owner-disabled@example.test',
    },
    privateNeedles: [
      'open-disabled-link-id',
      'disabled-open-call-id',
      'private-open-room',
      'Private Disabled Open Call',
      'Alpha Private Org',
      'alpha-owner-disabled@example.test',
      'anonymous-guest@example.test',
      'anonymous disabled guest',
    ],
  },
  {
    label: 'personalized call-access link disabled',
    link: {
      id: 'personal-disabled-link-id',
      kind: 'personal',
      participant_user_id: 9017,
      participant_email: 'invitee-disabled@example.test',
      tenant_id: 4202,
    },
    call: {
      id: 'disabled-personal-call-id',
      room_id: 'private-personal-room',
      title: 'Private Disabled Personal Call',
      status: 'disabled',
      organization: 'Beta Private Org',
      owner_email: 'beta-owner-disabled@example.test',
    },
    privateNeedles: [
      'personal-disabled-link-id',
      'disabled-personal-call-id',
      'private-personal-room',
      'Private Disabled Personal Call',
      'Beta Private Org',
      'beta-owner-disabled@example.test',
      'invitee-disabled@example.test',
      'Disabled Invitee',
    ],
  },
];

for (const contractCase of disabledCases) {
  assert.equal(contractCase.call.status, 'disabled', `${contractCase.label} fixture must model a disabled call`);

  const resolveResult = disabledResolveResult(contractCase);
  assert.equal(resolveResult.reason, 'conflict', `${contractCase.label} must deny as a terminal call-state conflict`);
  assert.equal(resolveResult.errors.call_id, 'call_not_joinable_from_status', `${contractCase.label} must pin the disabled-call field error`);
  assert.equal(resolveResult.access_link, null, `${contractCase.label} resolve must redact the access link`);
  assert.equal(resolveResult.call, null, `${contractCase.label} resolve must redact the call`);
  assert.equal(resolveResult.target_user, null, `${contractCase.label} resolve must redact the target user`);
  assert.equal(resolveResult.target_hint.participant_email, null, `${contractCase.label} resolve must redact participant email hints`);
  assertNoPrivateNeedles(resolveResult, contractCase.privateNeedles, `${contractCase.label} resolve payload`);

  const sessionResult = disabledSessionResult(resolveResult);
  assert.equal(sessionResult.session, null, `${contractCase.label} session payload must not include a session`);
  assert.equal(sessionResult.user, null, `${contractCase.label} session payload must not include a user`);
  assert.equal(sessionResult.access_link, null, `${contractCase.label} session payload must redact the access link`);
  assert.equal(sessionResult.call, null, `${contractCase.label} session payload must redact the call`);
  assert.deepEqual(sessionResult.sideEffects, {
    sessionPostSucceeded: false,
    sessionCreated: false,
    callAccessSessionCreated: false,
    guestOrTempUserCreated: false,
    participantOrLobbyRowInserted: false,
  }, `${contractCase.label} must fail closed before account/session/lobby side effects`);
  assertNoPrivateNeedles(sessionResult, contractCase.privateNeedles, `${contractCase.label} session denial payload`);

  const uiState = disabledJoinUiState(resolveResult);
  assert.equal(uiState.joinControlsRendered, false, `${contractCase.label} must render terminal safe UI before join controls`);
  assert.equal(uiState.sessionPostAttempted, false, `${contractCase.label} must not attempt session POST from terminal safe UI`);
  assert.deepEqual(uiState.lobbyFrames, [], `${contractCase.label} must not send lobby admission frames`);
  assert.equal(uiState.backendPayloadRendered, false, `${contractCase.label} must not render backend denial payload details`);
  assertNoPrivateNeedles(uiState, contractCase.privateNeedles, `${contractCase.label} terminal UI state`);
}

const publicAccess = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const sessionAccess = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const moduleCallsAccess = readRepo('demo/video-chat/backend-king-php/http/module_calls_access.php');
const joinView = readRepo('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const callAccessSession = readRepo('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const invalidationTerminalContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs');
const terminalStatesContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');

assert.match(
  publicAccess,
  /\$callStatus = \(string\) \(\$call\['status'\] \?\? 'scheduled'\);[\s\S]*!\s*videochat_is_call_joinable_status\(\$callStatus\)[\s\S]*'reason' => 'conflict'[\s\S]*'call_id' => 'call_not_joinable_from_status'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null[\s\S]*\$linkKind = videochat_call_access_link_kind\(\$accessLink\)/,
  'public resolver must fail disabled links closed with redacted payloads before open/personal link-kind handling',
);

assert.equal(
  publicAccess.includes('videochat_create_guest_user_for_call_access'),
  false,
  'public join resolution must not create guest/temp users',
);
assert.equal(
  publicAccess.includes('videochat_ensure_internal_call_participant'),
  false,
  'public join resolution must not insert call participant or lobby rows',
);

const resolveCallIndex = sessionAccess.indexOf('videochat_resolve_call_access_public($pdo, $accessId)');
const resolveFailIndex = sessionAccess.indexOf("if (!(bool) ($resolve['ok'] ?? false))");
const resolveFailReturnIndex = sessionAccess.indexOf("'session' => null", resolveFailIndex);
const createGuestIndex = sessionAccess.indexOf('videochat_create_guest_user_for_call_access');
const ensureParticipantIndex = sessionAccess.indexOf('videochat_ensure_internal_call_participant');
const insertSessionIndex = sessionAccess.indexOf('INSERT INTO sessions');
const insertCallAccessSessionIndex = sessionAccess.indexOf('INSERT INTO call_access_sessions');
assert.ok(resolveCallIndex >= 0, 'session issuer must resolve public access before issuing sessions');
assert.ok(resolveFailIndex > resolveCallIndex, 'session issuer must branch on failed public resolution');
assert.ok(resolveFailReturnIndex > resolveFailIndex, 'failed resolution branch must return null session data');
assert.ok(createGuestIndex > resolveFailReturnIndex, 'guest/temp user creation must be after the failed-resolution return path');
assert.ok(ensureParticipantIndex > resolveFailReturnIndex, 'participant/lobby insertion must be after the failed-resolution return path');
assert.ok(insertSessionIndex > resolveFailReturnIndex, 'session row insertion must be after the failed-resolution return path');
assert.ok(insertCallAccessSessionIndex > resolveFailReturnIndex, 'call-access session row insertion must be after the failed-resolution return path');

assert.match(
  sessionAccess,
  /if \(!\(bool\) \(\$resolve\['ok'\] \?\? false\)\) \{[\s\S]*'reason' => \(string\) \(\$resolve\['reason'\] \?\? 'internal_error'\)[\s\S]*'session' => null[\s\S]*'user' => null[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*\}/,
  'session issuer must redact session, user, access link, and call data on disabled-link resolution failure',
);

assert.match(
  moduleCallsAccess,
  /if \(\$reason === 'conflict'\) \{[\s\S]*return \$errorResponse\(409,\s*'call_access_conflict'[\s\S]*'fields' => is_array\(\$issueResult\['errors'\] \?\? null\) \? \$issueResult\['errors'\] : \[\][\s\S]*\);[\s\S]*\}/,
  'public session route must expose disabled-link denials as a safe conflict without session success',
);

assert.match(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\) \{[\s\S]*resetJoinContextDetails\(\);[\s\S]*payload = payload && typeof payload === 'object'[\s\S]*\? payload[\s\S]*: \{ error: \{ code: 'call_access_validation_failed' \} \};[\s\S]*state\.contextError = localizedApiErrorMessage\(payload,\s*t\('public\.join\.resolve_failed'\)\);[\s\S]*return;[\s\S]*\}/,
  'public join UI must preserve backend stable error codes while rendering a terminal safe UI error',
);
assert.match(
  joinView,
  /v-else-if="state\.contextError"[\s\S]*call-access-join-status error[\s\S]*<template v-else>/,
  'terminal safe UI must render before join controls',
);
assert.match(
  joinView,
  /startSessionAndJoin\(\)[\s\S]*if \(state\.joining \|\| state\.waitingForAdmission \|\| state\.loadingContext \|\| state\.contextError\) return/,
  'terminal safe UI must block session POST before loginWithCallAccess runs',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*return;[\s\S]*\}[\s\S]*startAdmissionWait\(accessId\)/,
  'public join UI must only enter the lobby wait path after a successful session result',
);
assert.match(
  joinView,
  /function handleAdmissionWelcome[\s\S]*sendAdmissionFrame\(\{ type: 'lobby\/queue\/join', room_id: pendingRoomId \}\)/,
  'lobby insertion request must be limited to the post-session admission socket path',
);

assert.match(
  callAccessSession,
  /if \(!response\.ok\) \{[\s\S]*return \{[\s\S]*ok: false[\s\S]*errorCode: errorCodeFromPayload\(payload\)[\s\S]*\};[\s\S]*\}[\s\S]*applySessionEnvelope\(result\.session, result\.user, result\.tenant\)/,
  'frontend session helper must return non-ok disabled-link denials before adopting a session envelope',
);

assert.match(
  invalidationTerminalContract,
  /ended or disabled calls must produce terminal conflict states with no call payload/,
  'invite invalidation terminal contract must keep disabled call-access links terminal and redacted',
);
assert.match(
  terminalStatesContract,
  /direct_join_alpha_owner_alpha_disabled_denied[\s\S]*private_call_payload_forbidden[\s\S]*resolve payload must not include call data[\s\S]*call fetch payload must not include call data/,
  'terminal states contract must pin disabled terminal link redaction',
);

process.stdout.write('[call-access-disabled-links-fail-closed-contract] PASS\n');
