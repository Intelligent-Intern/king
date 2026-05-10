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

function indexByKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    assert.notEqual(key, '', `${label} rows must have stable keys`);
    assert.equal(index.has(key), false, `${label} key must be unique: ${key}`);
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
const users = indexByKey(matrix.users, 'user');
const calls = indexByKey(matrix.calls, 'call');
const scenarios = indexByKey(matrix.scenarios, 'scenario');

const router = readRepo('demo/video-chat/frontend-vue/src/http/router.ts');
const session = readRepo('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const seedSpec = readRepo('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedHelper = readRepo('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const foundationContract = readRepo('demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs');
const terminalContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const inviteInvalidationContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs');
const authSupport = readRepo('demo/video-chat/backend-king-php/support/auth.php');
const authCacheSupport = readRepo('demo/video-chat/backend-king-php/support/auth_session_cache.php');
const accessContract = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const accessPublic = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const adminUserMutationContract = readRepo('demo/video-chat/backend-king-php/tests/admin-user-mutation-contract.php');
const callAccessPrivacyContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php');

const terminalCases = [
  {
    scenarioKey: 'direct_join_system_admin_alpha_ended_denied',
    callKey: 'alpha_ended',
    status: 'ended',
    resolveStatus: 200,
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callCode: 'calls_forbidden',
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_disabled_denied',
    callKey: 'alpha_disabled',
    status: 'disabled',
    resolveStatus: 200,
    resolveReason: 'call_not_joinable_from_status',
    callStatus: 403,
    callCode: 'calls_forbidden',
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_deleted_hidden',
    callKey: 'alpha_deleted',
    status: 'deleted',
    resolveStatus: 404,
    resolveErrorCode: 'calls_not_found',
    callStatus: 404,
    callCode: 'calls_not_found',
  },
];

for (const terminalCase of terminalCases) {
  const scenario = row(scenarios, terminalCase.scenarioKey, 'terminal browser-flow scenario');
  const call = row(calls, terminalCase.callKey, 'terminal browser-flow call');
  const expected = scenario.expected || {};

  assert.equal(call.status, terminalCase.status, `${terminalCase.callKey} must stay terminal`);
  assert.equal(expected.direct_join_allowed, false, `${terminalCase.scenarioKey} must stay closed`);
  assert.equal(expected.private_call_payload_forbidden, true, `${terminalCase.scenarioKey} must redact private call payloads`);
  assert.equal(expected.expected_resolve_status, terminalCase.resolveStatus, `${terminalCase.scenarioKey} resolve status mismatch`);
  assert.equal(expected.expected_call_status, terminalCase.callStatus, `${terminalCase.scenarioKey} call fetch status mismatch`);
  assert.equal(expected.expected_call_error_code, terminalCase.callCode, `${terminalCase.scenarioKey} call fetch error code mismatch`);
  assert.equal(expected.expected_call_status_value, terminalCase.status, `${terminalCase.scenarioKey} terminal status mismatch`);
  if (terminalCase.resolveStatus === 200) {
    assert.equal(expected.expected_resolve_state, 'forbidden', `${terminalCase.scenarioKey} resolve state mismatch`);
    assert.equal(expected.expected_resolve_reason, terminalCase.resolveReason, `${terminalCase.scenarioKey} resolve reason mismatch`);
  } else {
    assert.equal(expected.expected_resolve_error_code, terminalCase.resolveErrorCode, `${terminalCase.scenarioKey} resolve error mismatch`);
  }
}

const disabledOrDeletedUserFixtures = [
  row(users, 'removed_invited_member', 'deleted/removed user fixture'),
  row(users, 'temporary_personalized_guest', 'disabled temporary user fixture'),
  row(users, 'temporary_anonymous_guest', 'disabled anonymous user fixture'),
];
for (const userFixture of disabledOrDeletedUserFixtures) {
  assert.equal(userFixture.role, 'user', `${userFixture.key} must not be elevated`);
  assert.equal(userFixture.system_admin, false, `${userFixture.key} must not carry system admin rights`);
}

assert.match(
  router,
  /router\.beforeEach\(async \(to\) => \{[\s\S]*if \(sessionState\.sessionToken\) \{[\s\S]*await ensureSessionRecovery\(\)[\s\S]*const loggedIn = isAuthenticated\(\)[\s\S]*if \(requiresAuth && !loggedIn\)[\s\S]*path:\s*'\/login'/,
  'browser route guard must recover stored sessions before allowing authenticated call routes',
);
assert.match(
  session,
  /fetchBackend\('\/api\/auth\/session-state'[\s\S]*if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\)[\s\S]*normalizeAuthErrorState\('invalid_session',\s*message,\s*true\)/,
  'browser session recovery must clear local state on disabled, deleted, or otherwise invalid users',
);
assert.match(
  session,
  /sessionStateResult !== 'authenticated'[\s\S]*const reason = String\(payload\?\.result\?\.reason \|\| 'invalid_session'\)[\s\S]*normalizeAuthErrorState\(reason,\s*message,\s*true\)/,
  'browser session recovery must also clear non-authenticated terminal session-state results',
);
assert.match(
  session,
  /function normalizeAuthErrorState\(reason,\s*message,\s*clearState = false\)[\s\S]*if \(clearState\) \{[\s\S]*clearSessionState\(\)[\s\S]*export function clearSessionState\(\)[\s\S]*sessionState\.sessionToken = ''[\s\S]*persist\(\)/,
  'browser invalid-session handling must remove stale disabled or deleted user credentials',
);

assert.match(
  authSupport,
  /INNER JOIN users ON users\.id = sessions\.user_id[\s\S]*if \(!is_array\(\$row\)\)[\s\S]*'reason' => 'invalid_session'/,
  'deleted users must invalidate existing backend sessions through the auth join',
);
assert.match(
  authSupport,
  /\$userStatus = is_string\(\$row\['user_status'\][\s\S]*if \(\$userStatus !== 'active'\)[\s\S]*'reason' => 'user_inactive'/,
  'disabled users must invalidate existing backend sessions',
);
assert.match(
  authCacheSupport,
  /WHERE users\.id = :user_id[\s\S]*if \(!is_array\(\$row\)\)[\s\S]*'reason' => 'invalid_session'[\s\S]*if \(\$userStatus !== 'active'\)[\s\S]*'reason' => 'user_inactive'/,
  'locally issued call-access sessions must close for deleted or disabled users',
);
assert.match(
  accessContract,
  /function videochat_fetch_active_user_for_call_access\([\s\S]*AND users\.status = 'active'/,
  'personalized call-access target lookup must exclude disabled users',
);
assert.match(
  accessPublic,
  /if \(\$linkKind === 'personal' && !is_array\(\$targetUser\) && \$participantEmail === ''\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/,
  'deleted or disabled personalized target users must produce a safe public not-found state',
);
assert.match(
  callAccessPrivacyContract,
  /UPDATE users SET status = 'disabled'[\s\S]*domain public resolution should fail closed for broken personalized link[\s\S]*domain public resolution must not return target user[\s\S]*domain public resolution must not return participant hint/,
  'backend privacy contract must prove disabled personalized users do not leak through public resolution',
);
assert.match(
  adminUserMutationContract,
  /videochat_admin_delete_user\(\$pdo,\s*\$createdUserId\)[\s\S]*deleted user should no longer exist[\s\S]*owned calls should be deleted with user[\s\S]*issued invite codes should be deleted/,
  'backend admin mutation contract must prove deleted users remove dependent calls and invites',
);

assert.match(
  seedHelper,
  /if \(url\.pathname === '\/api\/auth\/session-state' \|\| url\.pathname === '\/api\/auth\/session'\)[\s\S]*if \(!record\)[\s\S]*status:\s*'error'[\s\S]*code:\s*'auth_failed'/,
  'browser seed harness must keep missing, deleted, or disabled browser sessions closed',
);
assert.match(
  seedHelper,
  /function callDirectAccessFailure\(call\)[\s\S]*status === 'deleted'[\s\S]*hidden:\s*true[\s\S]*errorCode:\s*'calls_not_found'[\s\S]*!\['scheduled',\s*'active'\]\.includes\(status\)[\s\S]*reason:\s*'call_not_joinable_from_status'/,
  'browser seed harness must close deleted calls as hidden and ended or disabled calls as not joinable',
);
assert.match(
  seedHelper,
  /if \(callFailure\?\.hidden\)[\s\S]*status:\s*'error'[\s\S]*error:\s*\{ code:\s*callFailure\.errorCode[\s\S]*if \(callFailure\) \{[\s\S]*state:\s*'forbidden'[\s\S]*access_link:\s*null[\s\S]*call:\s*null/,
  'browser seed resolve route must not return call payloads for deleted, ended, or disabled calls',
);
assert.match(
  seedHelper,
  /if \(callFailure\) \{[\s\S]*status:\s*'error'[\s\S]*code:\s*callFailure\.errorCode[\s\S]*details:\s*\{ reason:\s*callFailure\.reason \}/,
  'browser seed call fetch route must return terminal errors instead of a call payload',
);
assert.match(
  seedSpec,
  /directJoinPermissionCases[\s\S]*direct_join_system_admin_alpha_ended_denied[\s\S]*direct_join_alpha_owner_alpha_disabled_denied[\s\S]*direct_join_alpha_owner_alpha_deleted_hidden/,
  'browser seed E2E must enumerate ended, disabled, and deleted call cases',
);
assert.match(
  seedSpec,
  /if \(expected\.private_call_payload_forbidden === true\)[\s\S]*responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)[\s\S]*responses\.call\.payload\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)/,
  'browser seed E2E must assert terminal calls do not expose private call payloads',
);
assert.match(
  foundationContract,
  /for \(const scenarioKey of terminalDirectJoinScenarioKeys\)[\s\S]*expected_call_status_value[\s\S]*\/\^\(ended\|disabled\|deleted\)\$\/[\s\S]*must not masquerade as a normal permission denial/,
  'foundation contract must require terminal browser flows to remain distinct from normal permission denials',
);
assert.match(
  terminalContract,
  /direct_join_system_admin_alpha_ended_denied[\s\S]*direct_join_alpha_owner_alpha_disabled_denied[\s\S]*direct_join_alpha_owner_alpha_deleted_hidden[\s\S]*private_call_payload_forbidden/,
  'terminal-state contract must pin closed browser-flow coverage for ended, disabled, and deleted calls',
);
assert.match(
  inviteInvalidationContract,
  /deleted calls must make personalized links resolve as safe not-found states[\s\S]*ended or disabled calls must produce terminal conflict states with no call payload/,
  'invite invalidation contract must keep terminal call-access links closed in browser join flows',
);

console.log('[call-access-terminal-browser-flows-contract] PASS');
