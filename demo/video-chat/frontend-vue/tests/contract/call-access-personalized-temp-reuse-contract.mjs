import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const callAccessContract = read('../backend-king-php/domain/calls/call_access_contract.php');
const callAccessPublic = read('../backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = read('../backend-king-php/domain/calls/call_access_session.php');
const databaseMigrations = read('../backend-king-php/support/database_migrations.php');
const auth = read('../backend-king-php/support/auth.php');
const tenantContext = read('../backend-king-php/support/tenant_context.php');
const joinView = read('src/domain/calls/access/JoinView.vue');
const callAccessSessionClient = read('src/domain/calls/access/callAccessSession.ts');
const admissionGate = read('src/domain/calls/access/admissionGate.ts');

function normalizeEmail(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized) ? normalized : '';
}

function linkKind(accessLink) {
  const linkedUserId = Number.isInteger(accessLink?.participant_user_id) ? accessLink.participant_user_id : 0;
  const participantEmail = normalizeEmail(accessLink?.participant_email);
  return linkedUserId <= 0 && participantEmail === '' ? 'open' : 'personal';
}

function requiresGuestName(accessLink, targetUser = null) {
  if (!accessLink || typeof accessLink !== 'object') return false;
  if (linkKind(accessLink) === 'open') return true;
  const linkedUserId = Number.isInteger(accessLink.participant_user_id) ? accessLink.participant_user_id : 0;
  return linkedUserId <= 0 && normalizeEmail(accessLink.participant_email) !== '' && targetUser === null;
}

function validateBinding({ binding, row, suppliedUserId, nowUnix = 1_800_000_000 }) {
  const fail = (reason) => ({ ok: false, reason });
  if (suppliedUserId > 0 && binding.user_id !== suppliedUserId) {
    return fail('call_access_session_user_mismatch');
  }
  if (!binding.session_id || !binding.access_id || !binding.call_id || !binding.room_id || binding.user_id <= 0) {
    return fail('call_access_binding_mismatch');
  }
  if (!row.link_id) return fail('call_access_link_invalidated');
  if (row.link_call_id !== binding.call_id || row.resolved_call_id !== binding.call_id || row.resolved_room_id !== binding.room_id) {
    return fail('call_access_binding_mismatch');
  }
  if (!['scheduled', 'active'].includes(row.resolved_call_status)) {
    return fail('call_access_call_not_joinable');
  }
  if (linkKind({ participant_user_id: row.link_participant_user_id, participant_email: row.link_participant_email }) !== binding.link_kind) {
    return fail('call_access_binding_mismatch');
  }
  if (Date.parse(binding.expires_at) / 1000 <= nowUnix) {
    return fail('call_access_session_expired');
  }
  const participantEmail = normalizeEmail(row.link_participant_email);
  const userEmail = normalizeEmail(row.resolved_user_email);
  if (binding.link_kind === 'personal') {
    if (row.link_participant_user_id > 0 && row.link_participant_user_id !== binding.user_id) {
      return fail('call_access_binding_mismatch');
    }
    if (participantEmail !== '' && participantEmail !== userEmail && row.resolved_user_account_type !== 'guest') {
      return fail('call_access_binding_mismatch');
    }
  }
  return { ok: true, reason: 'ok' };
}

const personalizedTempLink = {
  id: '11111111-1111-4111-8111-111111111111',
  tenant_id: 701,
  call_id: 'call-alpha',
  participant_user_id: null,
  participant_email: 'External.Invitee@example.test',
};

const issuedTempGuest = {
  id: 9001,
  email: 'guest+22222222-2222-4222-8222-222222222222@videochat.local',
  account_type: 'guest',
  is_guest: true,
};

const issuedBinding = {
  session_id: 'sess-personalized-temp-alpha',
  access_id: personalizedTempLink.id,
  call_id: 'call-alpha',
  room_id: 'room-alpha',
  user_id: issuedTempGuest.id,
  link_kind: 'personal',
  expires_at: '2028-01-01T00:00:00Z',
  tenant_id: personalizedTempLink.tenant_id,
};

const validBindingRow = {
  link_id: personalizedTempLink.id,
  link_call_id: 'call-alpha',
  link_participant_user_id: null,
  link_participant_email: personalizedTempLink.participant_email,
  resolved_call_id: 'call-alpha',
  resolved_room_id: 'room-alpha',
  resolved_call_status: 'active',
  resolved_user_email: issuedTempGuest.email,
  resolved_user_account_type: issuedTempGuest.account_type,
};

assert.equal(linkKind(personalizedTempLink), 'personal', 'email-only personalized temp access must remain a personal link');
assert.equal(requiresGuestName(personalizedTempLink, null), true, 'email-only personal link without an existing user must create a temporary guest');
assert.deepEqual(validateBinding({ binding: issuedBinding, row: validBindingRow, suppliedUserId: issuedTempGuest.id }), {
  ok: true,
  reason: 'ok',
});

assert.deepEqual(
  validateBinding({ binding: issuedBinding, row: validBindingRow, suppliedUserId: 9002 }),
  { ok: false, reason: 'call_access_session_user_mismatch' },
  'a personalized temporary session cannot be reused by another account',
);

assert.deepEqual(
  validateBinding({
    binding: issuedBinding,
    row: { ...validBindingRow, resolved_call_id: 'call-beta' },
    suppliedUserId: issuedTempGuest.id,
  }),
  { ok: false, reason: 'call_access_binding_mismatch' },
  'a personalized temporary session cannot be replayed into another call binding',
);

assert.deepEqual(
  validateBinding({
    binding: { ...issuedBinding, room_id: 'room-beta' },
    row: validBindingRow,
    suppliedUserId: issuedTempGuest.id,
  }),
  { ok: false, reason: 'call_access_binding_mismatch' },
  'a personalized temporary session cannot be replayed into another room binding',
);

assert.match(
  callAccessContract,
  /return \$linkedUserId <= 0 && \$participantEmail !== '' && !is_array\(\$targetUser\);/,
  'backend must identify email-only personal links with no target user as temporary guest flows',
);
assert.match(
  callAccessContract,
  /\$guestEmail = 'guest\+' \. str_replace\('-', '', videochat_generate_call_access_uuid\(\)\) \. '@videochat\.local';/,
  'temporary personalized users must be generated with local guest identities',
);
assert.match(
  callAccessContract,
  /INSERT INTO users\([\s\S]*password_hash,[\s\S]*\) VALUES\([\s\S]*NULL,/,
  'temporary personalized users must be passwordless guest accounts',
);
assert.match(
  callAccessContract,
  /videochat_tenant_attach_user\(\$pdo, \$createdUserId, \$tenantId\)/,
  'temporary personalized users must attach only to the issuing link tenant',
);
assert.match(
  callAccessContract,
  /\$userId !== null && \$userId > 0 && \$bindingUserId !== \$userId[\s\S]*call_access_session_user_mismatch/,
  'session validation must reject reuse by another authenticated account',
);
assert.match(
  callAccessContract,
  /\$linkParticipantEmail !== ''[\s\S]*\$linkParticipantEmail !== \$userEmail[\s\S]*\$userAccountType !== 'guest'[\s\S]*call_access_binding_mismatch/,
  'personalized temporary guests may differ from invite email only while they remain guest accounts',
);
assert.match(
  callAccessContract,
  /\$row\['link_call_id'\][\s\S]*\$binding\['call_id'\][\s\S]*call_access_binding_mismatch/,
  'session validation must bind personalized temp sessions to the original access link call',
);
assert.match(
  callAccessContract,
  /\$row\['resolved_room_id'\][\s\S]*\$binding\['room_id'\][\s\S]*call_access_binding_mismatch/,
  'session validation must bind personalized temp sessions to the original room',
);

assert.match(
  callAccessPublic,
  /\$tenantId = is_numeric\(\$accessLink\['tenant_id'\][\s\S]*videochat_fetch_call_for_update\([\s\S]*\$tenantId\)/,
  'public access resolution must fetch the call in the link tenant',
);
assert.match(
  callAccessPublic,
  /videochat_fetch_active_user_for_call_access\([\s\S]*\$tenantId,[\s\S]*false[\s\S]*\)/,
  'public resolution must preserve tenant context even before a temporary guest exists',
);

assert.match(
  callAccessSession,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*session_context_changed/,
  'session issuance must fail when another browser or tab changes the verified session context',
);
assert.match(
  callAccessSession,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*session_context_changed/,
  'session issuance must fail when another account changes the verified user context',
);
assert.match(
  callAccessSession,
  /\$requiresGuestName[\s\S]*videochat_create_guest_user_for_call_access\(\$pdo, \$guestName, \$tenantId\)[\s\S]*\$createdPersonalGuest = \$linkKind === 'personal';/,
  'personalized temporary issuance must create the guest in the link tenant before binding the session',
);
assert.match(
  callAccessSession,
  /videochat_call_access_session_id_available\(\$pdo, \$sessionId\)[\s\S]*session_id_not_available/,
  'session issuance must reject duplicate session ids instead of rebinding an existing browser session',
);
assert.match(
  callAccessSession,
  /videochat_tenant_update_session\(\$pdo, \$sessionId, \$tenantId\)/,
  'issued personalized temp sessions must activate the issuing tenant',
);
assert.match(
  callAccessSession,
  /INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at\{\$bindTenantColumn\}\)/,
  'issued personalized temp sessions must persist the session/access/call/room/user binding',
);
assert.match(
  callAccessSession,
  /\$bindTenantColumn = is_int\(\$tenantId\)[\s\S]*'call_access_sessions', 'tenant_id'[\s\S]*\? ', tenant_id'/,
  'issued personalized temp sessions must persist tenant_id when the schema supports it',
);

assert.match(
  databaseMigrations,
  /CREATE TABLE IF NOT EXISTS call_access_sessions \([\s\S]*session_id TEXT PRIMARY KEY[\s\S]*access_id TEXT NOT NULL[\s\S]*call_id TEXT NOT NULL[\s\S]*room_id TEXT NOT NULL[\s\S]*user_id INTEGER NOT NULL[\s\S]*link_kind TEXT NOT NULL/,
  'call_access_sessions schema must make session_id unique and retain the access/call/room/user binding',
);

assert.match(
  auth,
  /videochat_validate_call_access_session_binding\([\s\S]*\$trimmedSessionId,[\s\S]*\(int\) \$row\['user_id'\]/,
  'auth must revalidate personalized temp call-access bindings before accepting the session token',
);
assert.match(
  auth,
  /videochat_tenant_context_for_call_access_session\([\s\S]*\(int\) \$row\['user_id'\],[\s\S]*\$trimmedSessionId/,
  'auth must derive fallback tenant context from the bound call-access session and user',
);
assert.match(
  tenantContext,
  /WHERE call_access_sessions\.session_id = :session_id[\s\S]*AND call_access_sessions\.user_id = :user_id[\s\S]*AND calls\.status IN \('scheduled', 'active'\)[\s\S]*AND tenants\.status = 'active'/,
  'tenant fallback must require the same session id and user id in an active call tenant',
);
assert.match(
  tenantContext,
  /COALESCE\(call_access_sessions\.tenant_id, calls\.tenant_id\)|calls\.tenant_id/,
  'tenant fallback must resolve the organization from the bound call-access session or call',
);

assert.match(
  admissionGate,
  /if \(userId <= 0 \|\| sessionId === '' \|\| sessionToken === ''\) \{[\s\S]*return null;/,
  'frontend verified context must require user id, session id, and session token',
);
assert.match(
  callAccessSessionClient,
  /body\.verified_user_id = verifiedContext\.userId;[\s\S]*body\.verified_session_id = verifiedContext\.sessionId;/,
  'frontend must send the verified account/session context when minting call-access sessions',
);
assert.match(
  callAccessSessionClient,
  /if \(verifiedContext && String\(sessionState\.sessionToken \|\| ''\)\.trim\(\) === ''\)[\s\S]*status: 409,[\s\S]*errorCode: 'call_access_conflict'/,
  'frontend must fail closed if another browser loses the verified session before session issuance',
);
assert.match(
  callAccessSessionClient,
  /headers\.authorization = `Bearer \$\{token\}`;/,
  'frontend must authenticate issuance with the current browser session token when present',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext = callAccessVerifiedContextFromSession\(sessionState\);/,
  'join view must capture the current browser account context before session issuance',
);
assert.match(
  joinView,
  /loginWithCallAccess\(accessId, \{[\s\S]*guestName:[\s\S]*verifiedContext: state\.verifiedAccessContext/,
  'join view must pass the captured context when a personalized temporary account is created',
);

console.log('[call-access-personalized-temp-reuse-contract] PASS');
