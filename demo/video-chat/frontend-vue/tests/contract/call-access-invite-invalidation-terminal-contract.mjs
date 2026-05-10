import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readRepo(relativePath));
}

function byKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    assert.notEqual(key, '', `${label} rows must have stable keys`);
    assert.equal(index.has(key), false, `${label} row key must be unique: ${key}`);
    index.set(key, row);
  }
  return index;
}

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `${label} ${key} must exist`);
  return value;
}

const matrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const calls = byKey(matrix.calls, 'call');
const scenarios = byKey(matrix.scenarios, 'scenario');

const publicAccess = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const accessContract = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const accessSession = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const accessRoutes = readRepo('demo/video-chat/backend-king-php/http/module_calls_access.php');
const cancelDomain = readRepo('demo/video-chat/backend-king-php/domain/calls/call_management_cancel.php');
const guestListDomain = readRepo('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const backendInvalidationContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-invalidation-contract.php');
const terminalContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const seedSpec = readRepo('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedHelper = readRepo('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const joinView = readRepo('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const callAccessSession = readRepo('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');

const terminalCases = [
  {
    label: 'explicit end',
    scenarioKey: 'direct_join_system_admin_alpha_ended_denied',
    callKey: 'alpha_ended',
    status: 'ended',
    resolveStatus: 200,
    resolveState: 'forbidden',
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callCode: 'calls_forbidden',
  },
  {
    label: 'disable',
    scenarioKey: 'direct_join_alpha_owner_alpha_disabled_denied',
    callKey: 'alpha_disabled',
    status: 'disabled',
    resolveStatus: 200,
    resolveState: 'forbidden',
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callCode: 'calls_forbidden',
  },
  {
    label: 'delete',
    scenarioKey: 'direct_join_alpha_owner_alpha_deleted_hidden',
    callKey: 'alpha_deleted',
    status: 'deleted',
    resolveStatus: 404,
    resolveCode: 'calls_not_found',
    callStatus: 404,
    callCode: 'calls_not_found',
  },
];

for (const terminalCase of terminalCases) {
  const scenario = row(scenarios, terminalCase.scenarioKey, `${terminalCase.label} scenario`);
  const call = row(calls, terminalCase.callKey, `${terminalCase.label} call`);
  const expected = scenario.expected || {};

  assert.equal(scenario.call_key, terminalCase.callKey, `${terminalCase.label} scenario must point at the terminal call`);
  assert.equal(call.status, terminalCase.status, `${terminalCase.label} call status mismatch`);
  assert.equal(expected.direct_join_allowed, false, `${terminalCase.label} must deny direct entry`);
  assert.equal(expected.private_call_payload_forbidden, true, `${terminalCase.label} must require safe payload redaction`);
  assert.equal(expected.expected_resolve_status, terminalCase.resolveStatus, `${terminalCase.label} resolve status mismatch`);
  assert.equal(expected.expected_call_status, terminalCase.callStatus, `${terminalCase.label} call fetch status mismatch`);
  assert.equal(expected.expected_call_error_code, terminalCase.callCode, `${terminalCase.label} call fetch error mismatch`);
  assert.equal(expected.expected_call_status_value, terminalCase.status, `${terminalCase.label} expected status value mismatch`);

  if (terminalCase.resolveStatus === 200) {
    assert.equal(expected.expected_resolve_state, terminalCase.resolveState, `${terminalCase.label} resolve state mismatch`);
    assert.equal(expected.expected_resolve_reason, terminalCase.resolveReason, `${terminalCase.label} resolve reason mismatch`);
  } else {
    assert.equal(expected.expected_resolve_error_code, terminalCase.resolveCode, `${terminalCase.label} hidden resolve code mismatch`);
  }
}

assert.match(
  publicAccess,
  /videochat_call_access_link_is_invalidated\(\$pdo,\s*\$accessLink\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null/,
  'explicit revoke must make public join resolution look like a safe missing link with no invite data',
);
assert.match(
  publicAccess,
  /\$expiresAtUnix <= time\(\)[\s\S]*'reason' => 'expired'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null/,
  'rescheduled stale links must expire safely without exposing old invite metadata',
);
assert.match(
  publicAccess,
  /if \(!is_array\(\$call\)\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/,
  'deleted calls must make personalized links resolve as safe not-found states',
);
assert.match(
  publicAccess,
  /!\s*videochat_is_call_joinable_status\(\$callStatus\)[\s\S]*'reason' => 'conflict'[\s\S]*'call_id' => 'call_not_joinable_from_status'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/,
  'ended or disabled calls must produce terminal conflict states with no call payload',
);

assert.match(
  accessContract,
  /function videochat_call_access_link_is_invalidated\(PDO \$pdo,\s*array \$accessLink\): bool[\s\S]*\['cancelled',\s*'declined'\]/,
  'explicit revoke must include cancelled and declined invite states',
);
assert.match(
  accessContract,
  /function videochat_is_call_joinable_status\(string \$status\): bool[\s\S]*return in_array\(\$normalized,\s*\['scheduled',\s*'active'\],\s*true\)/,
  'only scheduled and active calls may remain joinable after invite handoff',
);
assert.match(
  accessContract,
  /!\s*videochat_is_call_joinable_status\(\(string\) \(\$row\['resolved_call_status'\] \?\? ''\)\)[\s\S]*return \$fail\('call_access_call_not_joinable'\)/,
  'existing call-access sessions must fail after explicit end or disable',
);
assert.match(
  accessContract,
  /\$linkExpiresAtUnix <= \$currentUnix[\s\S]*return \$fail\('call_access_link_expired'\)/,
  'existing call-access sessions must fail after rescheduled stale link expiry',
);

assert.match(
  accessSession,
  /if \(!\(bool\) \(\$resolve\['ok'\] \?\? false\)\)[\s\S]*'session' => null[\s\S]*'user' => null[\s\S]*'access_link' => null[\s\S]*'call' => null/,
  'fresh session issuance must not allocate identities or leak data for invalidated links',
);
assert.match(
  callAccessSession,
  /if \(!response\.ok\)[\s\S]*ok:\s*false[\s\S]*errorCode:\s*errorCodeFromPayload\(payload\)/,
  'frontend call-access session helper must surface terminal backend errors instead of adopting a session',
);

assert.match(
  accessRoutes,
  /if \(\$reason === 'not_found'\)[\s\S]*return \$errorResponse\(404,\s*'call_access_not_found'[\s\S]*'access_id' => strtolower\(trim\(\$accessId\)\)/,
  'public join route must map revoked or deleted invite links to a terminal not-found state',
);
assert.match(
  accessRoutes,
  /if \(\$reason === 'expired'\)[\s\S]*return \$errorResponse\(410,\s*'call_access_expired'/,
  'public join and session routes must keep rescheduled stale links as expired terminal states',
);
assert.match(
  accessRoutes,
  /if \(\$reason === 'conflict'\)[\s\S]*return \$errorResponse\(409,\s*'call_access_conflict'[\s\S]*'fields' => is_array\(\$resolveResult\['errors'\]/,
  'public join route must map ended or disabled calls to a terminal conflict state',
);
assert.match(
  accessRoutes,
  /if \(\$reason === 'conflict'\)[\s\S]*return \$errorResponse\(409,\s*'call_access_conflict'[\s\S]*'fields' => is_array\(\$issueResult\['errors'\]/,
  'public session route must map ended or disabled calls to a terminal conflict state',
);

assert.match(
  cancelDomain,
  /UPDATE call_participants[\s\S]*SET invite_state = 'cancelled'[\s\S]*WHERE call_id = :call_id/,
  'call cancellation must explicitly revoke participant invite states',
);
assert.match(
  guestListDomain,
  /in_array\(\$inviteState,\s*\['declined',\s*'cancelled'\],\s*true\)[\s\S]*'reason' => 'guest_list_entry_inactive'/,
  'explicitly revoked guest-list entries must not remain active direct-join grants',
);

assert.match(
  backendInvalidationContract,
  /SET invite_state = 'cancelled'[\s\S]*videochat_call_access_link_is_invalidated[\s\S]*invalidated link must not resolve[\s\S]*invalidated personalized link must not create a fresh session/,
  'backend invalidation contract must prove explicit revoke denies fresh join and session issuance',
);
assert.match(
  backendInvalidationContract,
  /invalidated join should return safe not-found status[\s\S]*call_access_not_found[\s\S]*invalidated HTTP session should return safe not-found status[\s\S]*call_access_not_found/,
  'backend invalidation contract must prove both public join and session endpoints return safe terminal codes',
);
assert.match(
  backendInvalidationContract,
  /must not leak invited email[\s\S]*must not leak invited display name[\s\S]*must not leak call title[\s\S]*must not leak call id/,
  'backend invalidation contract must prove terminal responses do not leak invite data',
);

assert.match(
  terminalContract,
  /direct_join_system_admin_alpha_ended_denied[\s\S]*direct_join_alpha_owner_alpha_disabled_denied[\s\S]*direct_join_alpha_owner_alpha_deleted_hidden/,
  'frontend terminal-state contract must include end, disable, and delete terminal cases',
);
assert.match(
  terminalContract,
  /private_call_payload_forbidden[\s\S]*resolvePayload\.result\?\.call \?\? null[\s\S]*callFetchPayload\.call \?\? null/,
  'frontend terminal-state contract must prove terminal payloads redact private call objects',
);
assert.match(
  seedSpec,
  /if \(expected\.private_call_payload_forbidden === true\)[\s\S]*responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)[\s\S]*responses\.call\.payload\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)/,
  'seed matrix E2E must enforce safe terminal payloads for deleted, disabled, and ended calls',
);
assert.match(
  seedHelper,
  /function callDirectAccessFailure\(call\)[\s\S]*status === 'deleted'[\s\S]*hidden:\s*true[\s\S]*errorCode:\s*'calls_not_found'[\s\S]*!\['scheduled',\s*'active'\]\.includes\(status\)[\s\S]*reason:\s*'call_not_joinable_from_status'/,
  'seed helper must model deleted as hidden and all other terminal statuses as not joinable',
);

assert.match(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\)[\s\S]*payload = \{ error:\s*\{ code:\s*'call_access_validation_failed' \} \}[\s\S]*state\.contextError = localizedApiErrorMessage/,
  'public join UI must collapse terminal invite failures into safe localized copy before rendering entry controls',
);
assert.match(
  joinView,
  /v-else-if="state\.contextError"[\s\S]*call-access-join-status error[\s\S]*state\.contextError[\s\S]*<template v-else>/,
  'public join UI must render terminal safe state instead of the join controls',
);
assert.match(
  joinView,
  /startSessionAndJoin\(\)[\s\S]*if \(state\.joining \|\| state\.waitingForAdmission \|\| state\.loadingContext \|\| state\.contextError\) return/,
  'public join UI must not post a session after an invite has reached a terminal safe state',
);

console.log('[call-access-invite-invalidation-terminal-contract] PASS');
