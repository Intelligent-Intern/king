import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

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

const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const ownerModeration = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
const lobbySecurity = readText('demo/video-chat/backend-king-php/tests/realtime-lobby-security-contract.php');
const orgAdmin = readText('demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php');
const systemAdmin = readText('demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php');
const lobbySecurityModule = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const realtimeCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeCallRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');

const users = byKey(seedMatrix.users, 'user');
const calls = byKey(seedMatrix.calls, 'call');
const scenarios = byKey(seedMatrix.scenarios, 'scenario');

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `seed matrix must include ${label}: ${key}`);
  return value;
}

const systemAdminScenario = row(scenarios, 'system_admin_join_any_organization_call_without_guest_list', 'system-admin admission scenario');
for (const requiredCallKey of ['alpha_active', 'beta_active', 'tenantless_active']) {
  assert.ok(
    systemAdminScenario.call_keys?.includes(requiredCallKey),
    `system admin scenario must cover ${requiredCallKey}`,
  );
}
assert.equal(systemAdminScenario.expected?.can_manage_lobby, true, 'system admin must manage lobby admission');
assert.equal(systemAdminScenario.expected?.can_admit, true, 'system admin must admit participants');
assert.equal(systemAdminScenario.expected?.can_reject, true, 'system admin must reject participants');
assert.equal(systemAdminScenario.expected?.can_kick, true, 'system admin must kick participants');
assert.equal(systemAdminScenario.expected?.tenant_admin, true, 'system admin must carry tenant-admin admission authority');
assert.equal(systemAdminScenario.expected?.platform_admin, true, 'system admin must carry platform-admin authority');
assert.match(
  seedMatrixSpec,
  /system_admin_join_any_organization_call_without_guest_list[\s\S]*can_manage_lobby[\s\S]*platform_admin/,
  'frontend seed matrix spec must assert system-admin lobby authority',
);

const systemAdminUser = row(users, 'system_admin', 'system admin user');
assert.equal(systemAdminUser.system_admin, true, 'seed system admin must be explicitly marked system_admin');
assert.equal(systemAdminUser.role, 'admin', 'seed system admin must use the backend admin role');
for (const callKey of systemAdminScenario.call_keys) {
  assert.ok(row(calls, callKey, 'system-admin reachable call'), `system admin call coverage missing ${callKey}`);
}

const alphaAdmin = row(users, 'alpha_org_admin', 'alpha org admin');
assert.deepEqual(
  alphaAdmin.memberships,
  [{ tenant_key: 'alpha', role: 'admin' }],
  'alpha org admin must have only alpha admin membership in the seed matrix',
);
assert.equal(row(scenarios, 'direct_join_alpha_org_admin_alpha_active_allowed', 'alpha org admin own tenant').expected?.direct_join_allowed, true);
assert.equal(row(scenarios, 'direct_join_alpha_org_admin_beta_active_denied', 'alpha org admin foreign tenant').expected?.direct_join_allowed, false);
assert.equal(
  row(scenarios, 'direct_join_alpha_org_admin_beta_active_denied', 'alpha org admin foreign tenant').expected?.expected_call_error_code,
  'calls_forbidden',
  'foreign-tenant org-admin denial must remain calls_forbidden',
);

assert.match(
  seedMatrixHelper,
  /function permissionsFor\(user,\s*membershipRole\)[\s\S]*const isTenantAdmin = normalizedRole === 'owner' \|\| normalizedRole === 'admin'[\s\S]*manage_lobby:\s*elevated[\s\S]*admit_participants:\s*elevated[\s\S]*reject_participants:\s*elevated[\s\S]*kick_participants:\s*elevated/,
  'frontend seed helper must derive admission controls only from platform or tenant admin elevation',
);
assert.match(
  seedMatrixHelper,
  /function canDirectlyResolveCall\(user,\s*call\)[\s\S]*isPlatformAdminUser\(user\)[\s\S]*isTenantAdminForCall\(user,\s*call\)[\s\S]*isCallOwner\(user,\s*call\)[\s\S]*isGuestListUserForCall\(user,\s*call\)/,
  'direct join helper must not add normal-member admission authority',
);

assert.match(
  lobbySecurityModule,
  /videochat_realtime_lobby_command_requires_moderation[\s\S]*\['lobby\/allow', 'lobby\/remove', 'lobby\/allow_all'\]/,
  'backend must keep allow/remove/allow_all behind moderation authorization',
);
assert.match(
  lobbySecurityModule,
  /videochat_realtime_lobby_server_role_for_user\(PDO \$pdo,\s*int \$userId\)[\s\S]*INNER JOIN roles ON roles\.id = users\.role_id/,
  'lobby moderation must reload the server role from the database',
);
assert.match(
  lobbySecurityModule,
  /videochat_realtime_authorize_lobby_moderation_command[\s\S]*videochat_realtime_call_role_context_for_room_user[\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\] \?\? false\)\)/,
  'lobby moderation authorization must bind to realtime call context and require can_moderate',
);
assert.match(
  realtimeCallContext,
  /require_once __DIR__ \. '\/realtime_call_role_context\.php'/,
  'realtime call context must load the focused role resolver extraction',
);
assert.match(
  realtimeCallRoleContext,
  /videochat_user_is_organization_admin_for_call\(\$pdo, \$organizationAdminPreferredRow, \$userId, \$tenantId\)[\s\S]*return \$contextFromRow\(\$organizationAdminPreferredRow, true\)/,
  'realtime role context must bind same-organization admins even without a guest-list row',
);
assert.match(
  realtimeCallRoleContext,
  /\$scopedRoleActive =[\s\S]*videochat_call_invite_state_allows_scoped_role\(\$inviteState\)[\s\S]*can_moderate' => \$isAdmin[\s\S]*\$isOrganizationAdmin[\s\S]*\$scopedRoleActive && in_array\(\$callRole, \['owner', 'moderator'\], true\)/,
  'realtime role context must allow same-organization admin moderation without granting owner-transfer authority',
);
assert.match(
  realtimeCallRoleContext,
  /can_manage_owner' => \$isAdmin \|\| \(\$scopedRoleActive && \$callRole === 'owner'\)/,
  'owner-transfer authority must be stricter than general moderation authority',
);

assert.match(ownerModeration, /normal participant must not admit lobby users/, 'owner contract must reject participant admission');
assert.match(ownerModeration, /owner should admit lobby users/, 'owner contract must allow owner admission');
assert.match(ownerModeration, /normal participant must not transfer ownership/, 'owner contract must deny participant owner transfer');
assert.match(ownerModeration, /current owner should transfer ownership/, 'owner contract must allow current owner transfer');
assert.match(ownerModeration, /old owner should lose call moderation controls/, 'owner transfer must revoke old owner admission controls');
assert.match(ownerModeration, /new owner should gain call moderation controls/, 'owner transfer must grant new owner admission controls');
assert.match(ownerModeration, /global admin should keep moderation controls after owner transfer/, 'global admin must retain admission controls after owner transfer');

assert.match(lobbySecurity, /DB owner should be authorized even if connection role is stale/, 'lobby security must trust DB owner role over stale connection role');
assert.match(lobbySecurity, /DB moderator should be authorized even if connection call_role is stale/, 'lobby security must authorize DB moderator role');
assert.match(lobbySecurity, /DB admin should be authorized even if connection role is stale/, 'lobby security must authorize DB admin role');
assert.match(lobbySecurity, /forged role\/call_role must not authorize lobby moderation/, 'lobby security must reject forged global or call roles');
assert.match(lobbySecurity, /owner of another call must not moderate this room lobby/, 'lobby security must reject owner authority from another call');
assert.match(lobbySecurity, /forged call id must be rebound to target room context/, 'lobby security must rebind authorization to the target room call');

assert.match(orgAdmin, /org admin helper should allow own organization call/, 'org admin must be recognized for own organization call');
assert.match(orgAdmin, /org admin helper should reject foreign organization call/, 'org admin must not cross organization boundaries');
assert.match(orgAdmin, /own realtime context should elevate org admin to moderator/, 'org admin must receive admission moderation only in own organization context');
assert.match(orgAdmin, /own realtime context should allow lobby moderation/, 'org admin must be able to moderate own organization lobby');
assert.match(orgAdmin, /org admin should not receive owner-transfer rights/, 'org admin moderation must not imply owner-transfer rights');
assert.match(orgAdmin, /foreign realtime context should not allow moderation/, 'org admin must not moderate foreign organization calls');
assert.match(orgAdmin, /org admin access should not require guest-list insertion/, 'org admin admission authority must not rely on guest-list mutation');

assert.match(systemAdmin, /system admin should not need foreign tenant membership/, 'system admin must not require foreign tenant membership');
assert.match(systemAdmin, /system admin should not need guest-list participant row/, 'system admin must not require guest-list participation');
assert.match(systemAdmin, /system admin should manage foreign-tenant call participants/, 'system admin must manage foreign-tenant participants');
assert.match(systemAdmin, /system admin should transfer owner on foreign-tenant call/, 'system admin must be able to transfer owner on foreign-tenant calls');
assert.match(systemAdmin, /system admin rights should remain after owner transfer/, 'system admin rights must survive owner transfer');
assert.match(systemAdmin, /regular user must not simulate system admin through role string/, 'regular users must not forge system-admin authority');
assert.match(systemAdmin, /temporary account must not receive system-admin call rights even with admin role data/, 'temporary accounts must not gain system-admin admission authority');

process.stdout.write('[call-access-admission-boundaries-contract] PASS\n');
