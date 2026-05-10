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

function assertNoNeedles(value, needles, message) {
  const serialized = JSON.stringify(value).toLowerCase();
  for (const needle of needles) {
    assert.equal(serialized.includes(String(needle).toLowerCase()), false, `${message}: leaked ${needle}`);
  }
}

const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const frontendCrossOrgContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-contract.mjs');
const staleOrgRoleBackendContract = readText('demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php');
const backendCrossOrgContract = readText('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const authSessionModule = readText('demo/video-chat/backend-king-php/http/module_auth_session.php');

assert.match(
  callAccessJoinSpec,
  /test\('login switch after verified call-access link fails without rebinding or leaking foreign data'[\s\S]*await page\.route\('\*\*\/api\/auth\/session-state'[\s\S]*permissions: \{ tenant_admin: false \}[\s\S]*verified_user_id: 2,[\s\S]*verified_session_id: verifiedSession\.sessionId/s,
  'browser verified-context flow must re-read current session state and submit the verified user/session snapshot without stale admin permissions',
);
assert.match(
  callAccessJoinSpec,
  /storedSession\.sessionId\)\.toBe\(switchedSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(switchedSession\.sessionToken\)[\s\S]*not\.toBe\(rejectedCallAccessToken\)/,
  'browser verified-context denial must preserve the current session instead of adopting a stale call-access token',
);
assert.match(
  callAccessJoinSpec,
  /not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'browser stale-role denial must keep foreign call/person/token data hidden',
);

assert.match(
  seedMatrixSpec,
  /direct_join_alpha_org_admin_beta_active_denied[\s\S]*direct_join_alpha_org_admin_beta_cross_org_private_denied/s,
  'browser seed-matrix flow must keep alpha-admin to beta-call active-organization switch denial cases',
);
assert.match(
  seedMatrixSpec,
  /responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)/,
  'browser denied cross-org resolve must assert private call data stays null',
);
assert.match(
  seedMatrixSpec,
  /expect\(tenant\?\.permissions\?\.platform_admin \?\? false\)\.toBe\(false\);[\s\S]*expect\(tenant\?\.permissions\?\.tenant_admin \?\? false\)\.toBe\(false\);/,
  'browser seed-matrix snapshots must keep temporary/call-scoped tenants non-admin',
);
assert.match(
  seedMatrixHelper,
  /membership_id: membership \? Number\(user\.id\) \* 100 \+ Number\(tenant\.id\) : 0,[\s\S]*permissions: permissionsFor\(user, role\)/,
  'browser seed helper must expose missing active-organization membership as membership_id 0 before permissions are evaluated',
);
assert.match(
  seedMatrixHelper,
  /state:\s*'forbidden'[\s\S]*reason:\s*'calls_forbidden'[\s\S]*access_link:\s*null[\s\S]*call:\s*null/s,
  'browser seed helper must model cross-org denial without access-link or call payload data',
);
assert.doesNotMatch(
  seedMatrixHelper,
  /details:\s*\{[^}]*call_id/s,
  'browser denied call fetch payload must not echo private call ids in error details',
);

assert.match(
  frontendCrossOrgContract,
  /active-org switch proof must resolve the beta org snapshot[\s\S]*active-org switch must not mint a beta membership id[\s\S]*active-org switch must not mint beta tenant-admin rights[\s\S]*active-org switch must not mint platform-admin rights/s,
  'frontend cross-org contract must pin active-organization switching to least privilege',
);

assert.match(
  staleOrgRoleBackendContract,
  /same session must re-read downgraded tenant role[\s\S]*same session must not keep stale tenant admin permission[\s\S]*tenant-admin checks must reject the revalidated downgraded session/s,
  'backend stale-role proof must re-read downgraded roles for live sessions and clear tenant-admin powers',
);
assert.match(
  staleOrgRoleBackendContract,
  /locally cached session fallback must re-read downgraded tenant role[\s\S]*locally cached session fallback must not retain stale tenant admin permission/s,
  'backend stale-role proof must revalidate locally cached fallback sessions after downgrade',
);
assert.match(
  staleOrgRoleBackendContract,
  /downgraded organization member must not retain organization-admin call rights[\s\S]*downgraded organization member must not access invite-only call by stale role[\s\S]*forged auth role must not restore call access after downgrade/s,
  'backend stale-role proof must reject stale and forged admin call access after downgrade',
);
assert.match(
  staleOrgRoleBackendContract,
  /downgraded organization member must not retain moderation context[\s\S]*downgraded nonparticipant must not resolve call context/s,
  'backend stale-role proof must clear stale moderator context after downgrade',
);
assert.match(
  staleOrgRoleBackendContract,
  /organization_role=admin&tenant_admin=1&role=admin[\s\S]*stale client role cache must not resolve hidden invite-only call/s,
  'backend stale-role proof must reject stale admin role hints from browser query/cache state',
);
assert.match(
  staleOrgRoleBackendContract,
  /'role' => 'admin'[\s\S]*'tenant_admin' => true[\s\S]*call access must revalidate stale decoded role context against backend state/s,
  'backend stale-role proof must revalidate stale decoded admin context against current backend state',
);

assert.match(
  backendCrossOrgContract,
  /organization A admin must not have organization B context[\s\S]*active organization A context must not fetch organization B call[\s\S]*organization B call must be hidden from organization A context/s,
  'backend cross-org proof must hide another organization call from a stale active organization context',
);
assert.match(
  backendCrossOrgContract,
  /active organization switch must not mint organization B membership[\s\S]*tenant_membership_inactive/s,
  'backend cross-org proof must reject active-organization switch replay when membership is inactive',
);
assert.match(
  backendCrossOrgContract,
  /stale personalized organization B link should resolve public metadata[\s\S]*stale personalized organization B link alone must not grant organization A admin call access[\s\S]*stale personalized link denial should come from call permission/s,
  'backend cross-org proof must not let stale personalized links preserve organization A admin powers in organization B',
);
assert.match(
  backendCrossOrgContract,
  /legacy admin fallback should be least-privilege member[\s\S]*legacy admin fallback must not become organization B admin[\s\S]*legacy admin fallback must not preserve platform admin through call access/s,
  'backend cross-org proof must downgrade legacy admin fallback to least privilege for call-access context',
);

assert.match(
  authSessionModule,
  /tenant_switch_forbidden[\s\S]*The requested tenant is not available for this session\.[\s\S]*tenant_membership_inactive/s,
  'session-state tenant switch route must reject unavailable active organizations with tenant_membership_inactive',
);
assert.match(
  authSessionModule,
  /videochat_tenant_context_for_public_id\([\s\S]*videochat_tenant_context_for_user\([\s\S]*videochat_tenant_auth_payload\(\$tenantContext\)/s,
  'session-state tenant switch route must re-read tenant context before returning browser-visible permissions',
);

const staleAdminBrowserSnapshot = {
  user_id: 3001,
  active_tenant_id: 10,
  tenant: {
    id: 10,
    role: 'admin',
    membership_id: 30110,
    permissions: { tenant_admin: true, platform_admin: false },
  },
};
const backendRevalidatedSnapshot = {
  user_id: 3001,
  requested_tenant_id: 20,
  tenant: {
    id: 20,
    role: 'member',
    membership_id: 0,
    permissions: { tenant_admin: false, platform_admin: false },
  },
};
assert.notEqual(
  staleAdminBrowserSnapshot.tenant.id,
  backendRevalidatedSnapshot.tenant.id,
  'fixture must represent a browser active-organization switch',
);
assert.equal(
  backendRevalidatedSnapshot.tenant.membership_id,
  0,
  'revalidated switched organization snapshot must not mint membership from stale browser state',
);
assert.equal(
  backendRevalidatedSnapshot.tenant.permissions.tenant_admin,
  false,
  'revalidated switched organization snapshot must clear stale tenant-admin powers',
);
assert.equal(
  backendRevalidatedSnapshot.tenant.permissions.platform_admin,
  false,
  'revalidated switched organization snapshot must clear stale platform-admin powers',
);

assertNoNeedles(
  {
    status: 'error',
    error: {
      code: 'tenant_switch_forbidden',
      details: { reason: 'tenant_membership_inactive' },
    },
  },
  [
    'Organization B Invite Only',
    'org-b-owner@example.test',
    'sess_cross_org_stale_personal',
    'tenant_admin":true',
    'platform_admin":true',
  ],
  'active-organization switch denial payload',
);

process.stdout.write('[call-access-stale-role-org-switch-contract] PASS\n');
