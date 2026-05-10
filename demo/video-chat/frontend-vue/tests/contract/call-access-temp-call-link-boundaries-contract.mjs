import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function byKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    assert.notEqual(key, '', `${label} row must have a stable key`);
    assert.equal(index.has(key), false, `${label} row key must be unique: ${key}`);
    index.set(key, row);
  }
  return index;
}

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `${label} must exist: ${key}`);
  return value;
}

function validateTempBinding({ binding, link, call, user, nowUnix }) {
  if (binding.user_id !== user.id) return { ok: false, reason: 'call_access_session_user_mismatch' };
  if (binding.access_id !== link.id) return { ok: false, reason: 'call_access_link_invalidated' };
  if (binding.call_id !== link.call_id || binding.call_id !== call.id) {
    return { ok: false, reason: 'call_access_binding_mismatch' };
  }
  if (binding.room_id !== call.room_id) return { ok: false, reason: 'call_access_binding_mismatch' };
  if (binding.tenant_id !== link.tenant_id || binding.tenant_id !== call.tenant_id) {
    return { ok: false, reason: 'tenant_scope_mismatch' };
  }
  if (!['scheduled', 'active'].includes(call.status)) {
    return { ok: false, reason: 'call_access_call_not_joinable' };
  }
  if (Date.parse(binding.expires_at) / 1000 <= nowUnix) {
    return { ok: false, reason: 'call_access_session_expired' };
  }
  if (Date.parse(link.expires_at) / 1000 <= nowUnix) {
    return { ok: false, reason: 'call_access_link_expired' };
  }
  if (user.account_type !== 'guest' || user.temporary !== true || user.system_admin === true) {
    return { ok: false, reason: 'temporary_identity_required' };
  }
  return { ok: true, reason: 'ok' };
}

function canEnterCallRoom({ binding, targetCall, admitted }) {
  if (binding.call_id !== targetCall.id || binding.room_id !== targetCall.room_id) {
    return { ok: false, reason: 'call_access_binding_mismatch' };
  }
  if (!admitted) return { ok: false, reason: 'waiting_for_host_admission' };
  return { ok: true, reason: 'admitted' };
}

const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessContract = readText('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const guestList = readText('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const auth = readText('demo/video-chat/backend-king-php/support/auth.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const joinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const personalizedTempContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-personalized-temp-reuse-contract.mjs');
const anonymousTempRightsContract = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-temp-rights-contract.php');
const guestLifecycleContract = readText('demo/video-chat/backend-king-php/tests/call-guest-lifecycle-contract.php');

const users = byKey(seedMatrix.users, 'user');
const calls = byKey(seedMatrix.calls, 'call');
const links = byKey(seedMatrix.access_links, 'access link');
const scenarios = byKey(seedMatrix.scenarios, 'scenario');

for (const userKey of ['temporary_personalized_guest', 'temporary_anonymous_guest']) {
  const user = row(users, userKey, 'temporary user');
  assert.equal(user.role, 'user', `${userKey} must stay a normal user role`);
  assert.equal(user.account_type, 'guest', `${userKey} must stay a guest account`);
  assert.equal(user.is_guest, true, `${userKey} must be marked is_guest`);
  assert.equal(user.system_admin, false, `${userKey} must not be a system admin`);
  assert.equal(user.temporary, true, `${userKey} must be marked temporary`);
  assert.deepEqual(user.memberships, [], `${userKey} must not persist tenant memberships in the seed matrix`);
}

const tempPersonalLink = row(links, 'temporary_personalized', 'temporary personalized link');
const tempAnonymousLink = row(links, 'alpha_open', 'temporary anonymous link');
for (const link of [tempPersonalLink, tempAnonymousLink]) {
  assert.equal(link.call_key, 'alpha_active', `${link.key} must target only alpha_active`);
  assert.equal(link.requires_admission, true, `${link.key} must require host admission`);
  assert.equal(link.direct_guest_list_entry, false, `${link.key} must not grant direct guest-list entry`);
}
assert.equal(tempPersonalLink.link_kind, 'personal', 'temporary personalized link must remain personal');
assert.equal(tempAnonymousLink.link_kind, 'open', 'anonymous temporary link must remain open');
assert.equal(tempPersonalLink.target_user_key, 'temporary_personalized_guest', 'personal temporary link must bind the temporary personalized user');
assert.equal(tempAnonymousLink.anonymous_user_key, 'temporary_anonymous_guest', 'open link must bind the anonymous temporary user fixture');

for (const [scenarioKey, linkKey, principalKey] of [
  ['temporary_personalized_guest_has_no_system_admin_rights', 'temporary_personalized', 'temporary_personalized_guest'],
  ['anonymous_temporary_guest_has_no_system_admin_rights', 'alpha_open', 'temporary_anonymous_guest'],
]) {
  const scenario = row(scenarios, scenarioKey, 'temporary rights scenario');
  assert.equal(scenario.link_key, linkKey, `${scenarioKey} link mismatch`);
  assert.equal(scenario.principal_user_key, principalKey, `${scenarioKey} principal mismatch`);
  assert.equal(scenario.expected?.temporary, true, `${scenarioKey} must assert temporary identity`);
  assert.equal(scenario.expected?.system_admin, false, `${scenarioKey} must deny system admin`);
  assert.equal(scenario.expected?.tenant_admin, false, `${scenarioKey} must deny tenant admin`);
  assert.equal(scenario.expected?.platform_admin, false, `${scenarioKey} must deny platform admin`);
}

assert.match(
  seedMatrixSpec,
  /temporary_personalized_guest[\s\S]*temporary_anonymous_guest[\s\S]*tenant\?\.permissions\?\.platform_admin[\s\S]*tenant\?\.permissions\?\.tenant_admin/s,
  'browser seed matrix proof must assert temporary users have no platform or tenant admin permissions',
);
assert.match(
  joinSpec,
  /external guest join link requires display name, creates temporary guest, and waits in lobby until admitted[\s\S]*expect\(sessionPayload\?\.result\?\.user\?\.is_guest\)\.toBe\(true\)[\s\S]*tenant_admin[\s\S]*platform_admin[\s\S]*lobby\/queue\/join/s,
  'browser guest-link E2E must prove temporary guest creation, non-admin tenant permissions, and lobby queueing',
);
assert.match(
  joinSpec,
  /expect\(sessionRequests\)\.toEqual\(\[\{ guest_name: guestName \}\]\)/,
  'browser guest-link E2E must not send persistent account credentials for anonymous temporary users',
);

assert.match(
  callAccessPublic,
  /\$expiresAtUnix = strtotime\(\$expiresAt\);[\s\S]*\$expiresAtUnix <= time\(\)[\s\S]*'reason' => 'expired'[\s\S]*'access_link' => null,[\s\S]*'call' => null/s,
  'public call-link resolution must expire links without returning private link or call data',
);
assert.match(
  callAccessPublic,
  /\$tenantId = is_numeric\(\$accessLink\['tenant_id'\][\s\S]*videochat_fetch_call_for_update\([\s\S]*\$tenantId\)/,
  'public call-link resolution must fetch the target call inside the access-link tenant',
);
assert.match(
  callAccessSession,
  /\$requiresGuestName[\s\S]*videochat_create_guest_user_for_call_access\(\$pdo, \$guestName, \$tenantId\)[\s\S]*\$createdPersonalGuest = \$linkKind === 'personal';/,
  'call-link session issuance must create temporary users in the target link tenant',
);
assert.match(
  callAccessSession,
  /videochat_tenant_update_session\(\$pdo, \$sessionId, \$tenantId\)/,
  'temporary call-link sessions must activate the issuing tenant only',
);
assert.match(
  callAccessSession,
  /INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at\{\$bindTenantColumn\}\)/,
  'temporary call-link sessions must persist session/access/call/room/user binding',
);
assert.match(
  callAccessSession,
  /\$bindTenantColumn = is_int\(\$tenantId\)[\s\S]*'call_access_sessions', 'tenant_id'[\s\S]*\? ', tenant_id'/,
  'temporary call-link sessions must persist tenant binding when available',
);

assert.match(
  callAccessContract,
  /\$bindingExpiresAtUnix = strtotime\([\s\S]*call_access_session_expired/s,
  'call-access binding validation must reject expired temporary sessions',
);
assert.match(
  callAccessContract,
  /\$linkExpiresAtUnix = strtotime\(\$linkExpiresAt\);[\s\S]*call_access_link_expired/s,
  'call-access binding validation must reject temporary sessions after link expiry',
);
assert.match(
  callAccessContract,
  /\$row\['link_call_id'\][\s\S]*\$binding\['call_id'\][\s\S]*call_access_binding_mismatch/,
  'call-access binding validation must reject carry-over into another call',
);
assert.match(
  callAccessContract,
  /\$row\['resolved_room_id'\][\s\S]*\$binding\['room_id'\][\s\S]*call_access_binding_mismatch/,
  'call-access binding validation must reject carry-over into another room',
);
assert.match(
  callAccessContract,
  /!videochat_is_call_joinable_status\([\s\S]*call_access_call_not_joinable/s,
  'call-access binding validation must reject ended, disabled, or deleted target calls',
);

assert.match(
  auth,
  /videochat_validate_call_access_session_binding\([\s\S]*\$trimmedSessionId,[\s\S]*\(int\) \$row\['user_id'\]/,
  'authentication must revalidate call-link binding for the current temporary user',
);
assert.match(
  tenantContext,
  /WHERE call_access_sessions\.session_id = :session_id[\s\S]*AND call_access_sessions\.user_id = :user_id[\s\S]*AND calls\.status IN \('scheduled', 'active'\)[\s\S]*AND tenants\.status = 'active'/,
  'temporary fallback tenant context must require same session, same user, active call, and active tenant',
);
assert.match(
  tenantContext,
  /COALESCE\(call_access_sessions\.tenant_id, calls\.tenant_id\)|calls\.tenant_id/,
  'temporary fallback tenant context must resolve tenant from the bound session or target call only',
);

assert.match(
  guestList,
  /if \(\$authUserId <= 0\)[\s\S]*invalid_user_context[\s\S]*SELECT user_id, invite_state, call_role[\s\S]*WHERE call_id = :call_id[\s\S]*AND user_id = :user_id/s,
  'direct-join helper must require an explicit target-call guest-list row for normal temporary users',
);
assert.match(
  anonymousTempRightsContract,
  /temporary account must not gain direct call access[\s\S]*temporary direct-access denial source mismatch[\s\S]*temporary account must not gain guest-list direct join[\s\S]*temporary direct-join denial reason mismatch/s,
  'anonymous temporary rights proof must deny direct call access and guest-list direct join',
);
assert.match(
  anonymousTempRightsContract,
  /anonymous session issuance must not create an invited\/allowed participant row[\s\S]*anonymous session issuance must not add guest-list rights/s,
  'anonymous temporary rights proof must prevent guest-list mutation during link session issuance',
);
assert.match(
  anonymousTempRightsContract,
  /organization admin rights must not cross tenant or organization boundaries[\s\S]*organization admin must not create links for another organization call/s,
  'anonymous temporary rights proof must preserve tenant and organization boundaries',
);

assert.match(
  personalizedTempContract,
  /a personalized temporary session cannot be replayed into another call binding[\s\S]*a personalized temporary session cannot be replayed into another room binding/s,
  'personalized temporary proof must reject cross-call and cross-room replay',
);
assert.match(
  personalizedTempContract,
  /a personalized temporary session cannot be reused by another account/,
  'personalized temporary proof must reject reuse by another browser account',
);
assert.match(
  guestLifecycleContract,
  /stale personalized guest browser session must not authenticate[\s\S]*stale personalized link must not revive invalidated guest[\s\S]*personal guest must stay disabled after stale link retry/s,
  'guest lifecycle proof must prevent temporary personalized users from persisting after invalidation',
);
assert.match(
  guestLifecycleContract,
  /stale open-link guest browser session must not authenticate[\s\S]*open link may create a fresh guest after cleanup[\s\S]*stale open link must not revive the old guest account/s,
  'guest lifecycle proof must prevent anonymous temporary users from persisting across cleanup',
);
assert.match(
  guestLifecycleContract,
  /guest cleanup must not disable registered user[\s\S]*registered call-access session must survive guest cleanup/s,
  'guest lifecycle proof must keep temporary cleanup scoped away from registered users',
);

const alphaCall = row(calls, 'alpha_active', 'alpha target call');
const betaCall = row(calls, 'beta_active', 'foreign beta call');
const tempGuest = row(users, 'temporary_anonymous_guest', 'temporary anonymous guest');
const issuedBinding = {
  session_id: 'sess-temp-alpha',
  access_id: tempAnonymousLink.id,
  call_id: alphaCall.id,
  room_id: alphaCall.room_id,
  user_id: tempGuest.id,
  link_kind: 'open',
  tenant_id: 1,
  expires_at: '2026-05-10T12:00:00Z',
};
const issuedLink = {
  id: tempAnonymousLink.id,
  call_id: alphaCall.id,
  tenant_id: 1,
  expires_at: '2026-05-10T12:00:00Z',
};
const targetCall = {
  id: alphaCall.id,
  room_id: alphaCall.room_id,
  tenant_id: 1,
  status: 'active',
};

assert.deepEqual(
  validateTempBinding({
    binding: issuedBinding,
    link: issuedLink,
    call: targetCall,
    user: tempGuest,
    nowUnix: Date.parse('2026-05-10T11:00:00Z') / 1000,
  }),
  { ok: true, reason: 'ok' },
  'fixture must allow a fresh temporary session only for its bound call, link, user, and tenant',
);
assert.deepEqual(
  canEnterCallRoom({ binding: issuedBinding, targetCall, admitted: false }),
  { ok: false, reason: 'waiting_for_host_admission' },
  'fresh temporary sessions must remain admission-only before host approval',
);
assert.deepEqual(
  canEnterCallRoom({ binding: issuedBinding, targetCall, admitted: true }),
  { ok: true, reason: 'admitted' },
  'fresh temporary sessions may enter the target room only after admission',
);
assert.deepEqual(
  validateTempBinding({
    binding: issuedBinding,
    link: issuedLink,
    call: { ...targetCall, id: betaCall.id, room_id: betaCall.room_id, tenant_id: 2 },
    user: tempGuest,
    nowUnix: Date.parse('2026-05-10T11:00:00Z') / 1000,
  }),
  { ok: false, reason: 'call_access_binding_mismatch' },
  'temporary sessions must not carry over into another call or tenant',
);
assert.deepEqual(
  validateTempBinding({
    binding: issuedBinding,
    link: issuedLink,
    call: targetCall,
    user: tempGuest,
    nowUnix: Date.parse('2026-05-10T12:00:01Z') / 1000,
  }),
  { ok: false, reason: 'call_access_session_expired' },
  'temporary sessions must stop at the expiration window',
);

process.stdout.write('[call-access-temp-call-link-boundaries-contract] PASS\n');
