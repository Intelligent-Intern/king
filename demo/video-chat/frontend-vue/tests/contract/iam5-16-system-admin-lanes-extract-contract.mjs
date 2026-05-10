import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

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
  assert.ok(value, `${label} ${key} must exist`);
  return value;
}

const evidence = readText('documentation/iam-sprint-05-system-admin-lanes-extraction.md');
const matrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const adminJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js');
const admissionContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs');
const directJoinContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs');
const terminalContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const terminalBrowserContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs');
const ownerTransferMain = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs');
const adminOwnerRights = readText('demo/video-chat/frontend-vue/tests/contract/admin-owner-rights-contract.mjs');
const systemAdminContract = readText('demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php');
const orgAdminContract = readText('demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php');
const callCreationOwnerRights = readText('demo/video-chat/backend-king-php/tests/call-creation-owner-rights-contract.php');
const callOwnerModeration = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
const sessionNormalizers = readText('demo/video-chat/frontend-vue/src/domain/auth/sessionNormalizers.js');
const sessionStore = readText('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const organizationMemberships = readText('demo/video-chat/backend-king-php/domain/tenancy/governance_organization_memberships.php');

for (const [branch, head] of [
  ['local/iam-e2e-system-admin-edge-cases', '434a3ec334b0'],
  ['local/iam-e2e-system-admin-deleted-ended-proof-3', '4cabdf6b06b3'],
  ['codex/iam-e2e-anon-system-admin-proof-20260509', '0d3e9e04103e'],
  ['codex/iam-lane-54-organization-role-bootstrap-proof', '528fb034e816'],
  ['codex/iam-lane-58-owner-transfer-rights-audit-proof', '0092f3768eae'],
  ['codex/iam-lane-59-admin-join-boundaries-proof', 'f9ad4bf14b15'],
  ['local/iam-e2e-call-owner-creation-rights', 'd3b3b18efecf'],
  ['local/iam-e2e-core-org-session-journey', '9800a2f3ae42'],
]) {
  assert.ok(evidence.includes(branch), `evidence must list source branch ${branch}`);
  assert.ok(evidence.includes(head), `evidence must record source head ${head}`);
}

assert.match(
  evidence,
  /Base checked: local `prod-kingrt-do-not-push-to-github` at\s+`17c851ace650903f17b8b02776028d0d01a9b783`/,
  'evidence must record the inspected integration base',
);
assert.match(
  evidence,
  /Background,\s+Gossip,\s+SFU,\s+MediaSecurity,\s+BTGF,\s+deploy scripts,\s+and `SPRINT\.md` were not\s+touched/,
  'evidence must preserve protected-area boundaries',
);
assert.match(
  evidence,
  /No product code, package scripts, shared CI wiring, `SPRINT\.md`, `BACKLOG\.md`,\s+or protected Background\/Gossip\/SFU\/MediaSecurity\/BTGF\/deploy files were edited/,
  'evidence must stay doc/static-contract only',
);
assert.match(
  evidence,
  /organization-role bootstrap route proof[\s\S]*unified admin-join backend\s+proof[\s\S]*system-admin review flags[\s\S]*tenantless backend runtime proof[\s\S]*owner-transfer rights audit/,
  'evidence must keep source-only backend gaps as follow-up evidence',
);
assert.match(
  evidence,
  /logged-in open-link\s+behavior[\s\S]*overlaps with later temp-access extraction decisions[\s\S]*explicit product work/,
  'evidence must not silently weaken the current open-link/temp-access policy',
);

const users = byKey(matrix.users, 'seed user');
const calls = byKey(matrix.calls, 'seed call');
const scenarios = byKey(matrix.scenarios, 'seed scenario');

const systemAdmin = row(users, 'system_admin', 'system-admin seed user');
assert.equal(systemAdmin.role, 'admin', 'system-admin seed must use backend admin role');
assert.equal(systemAdmin.account_type, 'account', 'system-admin seed must be a registered account');
assert.equal(systemAdmin.is_guest, false, 'system-admin seed must not be a guest');
assert.equal(systemAdmin.system_admin, true, 'system-admin seed must be explicit');

const alphaOrgAdmin = row(users, 'alpha_org_admin', 'alpha org-admin seed user');
assert.equal(alphaOrgAdmin.role, 'user', 'organization admins must keep account role user');
assert.equal(alphaOrgAdmin.system_admin, false, 'organization admins must not become system admins');
assert.deepEqual(
  alphaOrgAdmin.memberships,
  [{ tenant_key: 'alpha', role: 'admin' }],
  'alpha org-admin must only carry alpha admin membership',
);

for (const tempUserKey of ['temporary_personalized_guest', 'temporary_anonymous_guest']) {
  const tempUser = row(users, tempUserKey, 'temporary guest');
  assert.equal(tempUser.account_type, 'guest', `${tempUserKey} must stay a guest account`);
  assert.equal(tempUser.system_admin, false, `${tempUserKey} must not carry system admin`);
  assert.equal(Array.isArray(tempUser.memberships) && tempUser.memberships.length, 0, `${tempUserKey} must not carry tenant membership`);
}

const systemAdminAnyOrg = row(scenarios, 'system_admin_join_any_organization_call_without_guest_list', 'system-admin any-org scenario');
assert.deepEqual(
  systemAdminAnyOrg.call_keys,
  ['alpha_active', 'beta_active', 'beta_cross_org_private', 'tenantless_active'],
  'system-admin any-org scenario must include active, cross-org, and tenantless calls',
);
assert.equal(systemAdminAnyOrg.expected?.guest_list_required, false, 'system admin must not require guest-list membership');
assert.equal(systemAdminAnyOrg.expected?.can_manage_lobby, true, 'system admin must manage lobby');
assert.equal(systemAdminAnyOrg.expected?.platform_admin, true, 'system admin must keep platform admin authority');

const tenantless = row(calls, 'tenantless_active', 'tenantless call');
assert.equal(tenantless.tenant_key, null, 'tenantless seed call must keep null tenant scope');
const tenantlessSystemAdmin = row(scenarios, 'direct_join_system_admin_tenantless_active_allowed', 'tenantless direct-join scenario');
assert.equal(tenantlessSystemAdmin.expected?.direct_join_allowed, true, 'system admin must direct join tenantless seed call');
assert.equal(tenantlessSystemAdmin.expected?.guest_list_required, false, 'tenantless system admin must not need guest-list access');

const foreignOrgDenied = row(scenarios, 'direct_join_alpha_org_admin_beta_cross_org_private_denied', 'foreign org-admin denial');
assert.equal(foreignOrgDenied.expected?.direct_join_allowed, false, 'foreign org-admin direct join must stay denied');
assert.equal(foreignOrgDenied.expected?.expected_call_error_code, 'calls_forbidden', 'foreign org-admin denial must stay forbidden');
assert.equal(foreignOrgDenied.expected?.cross_org, true, 'foreign org-admin denial must stay marked cross-org');

const terminalSystemAdmin = row(scenarios, 'direct_join_system_admin_alpha_ended_denied', 'system-admin terminal denial');
assert.equal(terminalSystemAdmin.expected?.direct_join_allowed, false, 'system admin must not join ended calls');
assert.equal(terminalSystemAdmin.expected?.expected_resolve_reason, 'call_not_joinable_from_status', 'ended system-admin denial reason mismatch');
assert.equal(terminalSystemAdmin.expected?.private_call_payload_forbidden, true, 'ended system-admin denial must redact private call payloads');

assert.match(seedSpec, /system_admin_join_any_organization_call_without_guest_list/, 'seed spec must cover system-admin any-org scenario');
assert.match(seedSpec, /direct_join_system_admin_tenantless_active_allowed/, 'seed spec must cover tenantless system-admin direct join');
assert.match(seedSpec, /direct_join_system_admin_alpha_ended_denied/, 'seed spec must cover ended system-admin denial');

for (const proofLabel of [
  'system admin joins an active organization call without guest-list membership',
  'org admin joins only their own organization call',
  'foreign org admin cannot cross organization boundaries',
  'call-scoped moderator joins assigned call without organization admin rights',
  'call owner joins their own call without organization admin rights',
  'plain organization member cannot bypass invitation boundaries',
]) {
  assert.ok(adminJoinSpec.includes(proofLabel), `admin-join browser proof must include: ${proofLabel}`);
}
assert.match(
  adminJoinSpec,
  /if \(isPlatformAdmin\(user\)\) \{[\s\S]*source:\s*'system_admin'[\s\S]*membershipRole === 'admin' \|\| membershipRole === 'owner'[\s\S]*source:\s*'organization_admin'[\s\S]*callRoleFor\(user,\s*call\) === 'owner'[\s\S]*callRoleFor\(user,\s*call\) === 'moderator'/,
  'admin-join browser proof must keep source order system-admin, org-admin, owner, moderator',
);

assert.match(
  admissionContract,
  /system admin must manage lobby admission[\s\S]*system admin must admit participants[\s\S]*system admin must reject participants[\s\S]*system admin must kick participants/s,
  'admission contract must pin system-admin lobby authority',
);
assert.match(
  admissionContract,
  /org admin must be able to moderate own organization lobby[\s\S]*org admin moderation must not imply owner-transfer rights[\s\S]*org admin access should not require guest-list insertion/s,
  'admission contract must separate org-admin moderation from owner management',
);
assert.match(
  admissionContract,
  /regular users must not forge system-admin authority[\s\S]*temporary accounts must not gain system-admin admission authority/s,
  'admission contract must block forged and temporary system-admin elevation',
);

assert.match(
  directJoinContract,
  /direct_join_system_admin_alpha_active_allowed[\s\S]*direct_join_system_admin_beta_active_allowed[\s\S]*direct_join_system_admin_tenantless_active_allowed/s,
  'direct-join contract must keep system-admin tenant and tenantless rows',
);
assert.match(
  directJoinContract,
  /direct_join_alpha_org_admin_alpha_active_allowed[\s\S]*direct_join_alpha_org_admin_beta_active_denied/s,
  'direct-join contract must keep same-org allow and foreign-org denial',
);

assert.match(terminalContract, /direct_join_system_admin_alpha_ended_denied/, 'terminal contract must include system-admin ended denial');
assert.match(terminalContract, /private_call_payload_forbidden/, 'terminal contract must keep private payload redaction');
assert.match(terminalBrowserContract, /disabled users must invalidate existing backend sessions/, 'terminal browser contract must keep disabled-user session invalidation');
assert.match(terminalBrowserContract, /deleted users must invalidate existing backend sessions/, 'terminal browser contract must keep deleted-user session invalidation');

assert.match(systemAdminContract, /system admin should not need foreign tenant membership/, 'system-admin runtime must avoid foreign tenant membership requirement');
assert.match(systemAdminContract, /system admin should not need guest-list participant row/, 'system-admin runtime must avoid guest-list dependency');
assert.match(systemAdminContract, /system admin should manage foreign-tenant call participants/, 'system-admin runtime must manage foreign participants');
assert.match(systemAdminContract, /system admin should transfer owner on foreign-tenant call/, 'system-admin runtime must preserve owner transfer');
assert.match(systemAdminContract, /regular user must not simulate system admin through role string/, 'system-admin runtime must reject forged role strings');
assert.match(systemAdminContract, /temporary account must not receive system-admin call rights even with admin role data/, 'system-admin runtime must reject temporary admin-shaped accounts');

assert.match(orgAdminContract, /org admin helper should allow own organization call/, 'org-admin runtime must allow own organization calls');
assert.match(orgAdminContract, /org admin helper should reject foreign organization call/, 'org-admin runtime must deny foreign organization calls');
assert.match(orgAdminContract, /org admin should not receive owner-transfer rights/, 'org-admin runtime must deny owner-transfer rights');
assert.match(orgAdminContract, /org admin access should not require guest-list insertion/, 'org-admin runtime must avoid guest-list dependency');

assert.match(callCreationOwnerRights, /creator participant should have owner role/, 'call creation runtime must make creator owner');
assert.match(callCreationOwnerRights, /creator invite state should be allowed/, 'call creation runtime must allow creator participant');
assert.match(callCreationOwnerRights, /owner should have owner-management rights in own call/, 'call creation runtime must grant owner-management context');
assert.match(callOwnerModeration, /normal participant must not admit lobby users/, 'owner moderation runtime must deny normal participant admission');
assert.match(callOwnerModeration, /owner should admit lobby users/, 'owner moderation runtime must allow owner admission');
assert.match(callOwnerModeration, /current owner should transfer ownership/, 'owner moderation runtime must cover owner transfer');
assert.match(callOwnerModeration, /global admin should keep moderation controls after owner transfer/, 'owner moderation runtime must preserve global admin moderation');

assert.match(ownerTransferMain, /owner transfer must require the current owner or a system admin/, 'owner transfer static contract must keep authority boundary');
assert.match(ownerTransferMain, /owner transfer must update the canonical calls\.owner_user_id field/, 'owner transfer static contract must require canonical owner update');
assert.match(adminOwnerRights, /owner-management gate must honor explicit owner-equivalent permission/, 'admin owner contract must keep owner-management separate from moderation');

assert.match(sessionNormalizers, /const AUTH_ROLES = new Set\(\['admin', 'user'\]\)/, 'frontend must keep account roles limited');
assert.match(sessionNormalizers, /const ACCOUNT_TYPES = new Set\(\['account', 'guest'\]\)/, 'frontend must keep account types limited');
assert.match(sessionNormalizers, /permissions: source\.permissions && typeof source\.permissions === 'object' \? \{ \.\.\.source\.permissions \} : \{\}/, 'frontend must default missing tenant permissions closed');
assert.match(sessionStore, /fetchBackend\('\/api\/auth\/login'/, 'frontend login must use backend login route');
assert.match(sessionStore, /fetchBackend\('\/api\/auth\/logout'/, 'frontend logout must use backend logout route');
assert.match(sessionStore, /finally\s*{\s*clearSessionState\(\);\s*setRecoveryState\('idle'\);/s, 'frontend logout must clear local session state');
assert.match(sessionStore, /sessionStateResult !== 'authenticated'[\s\S]*normalizeAuthErrorState\(reason, message, true\)/, 'frontend recovery must clear unauthenticated sessions');

assert.match(
  organizationMemberships,
  /INSERT INTO organization_memberships\(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at\)\s+VALUES\(:tenant_id, :organization_id, :user_id, 'member', 'active', :created_at, :updated_at\)/,
  'current organization relationship sync still inserts generic member roles, so lane-54 role bootstrap remains source-only',
);
assert.match(
  evidence,
  /Current\s+`governance_organization_memberships\.php`\s+still inserts organization users as\s+`member`/,
  'evidence must document the organization-role bootstrap runtime gap honestly',
);

process.stdout.write('[iam5-16-system-admin-lanes-extract-contract] PASS\n');
