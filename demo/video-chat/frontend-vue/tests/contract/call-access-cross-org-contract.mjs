import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  getSeedCall,
  getSeedScenario,
  getSeedUser,
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

function membershipsFor(user, tenantKey) {
  return (Array.isArray(user.memberships) ? user.memberships : [])
    .filter((membership) => String(membership?.tenant_key || '') === tenantKey);
}

function serialized(value) {
  return JSON.stringify(value);
}

const seedMatrix = JSON.parse(readText('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json'));
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const backendCrossOrgContract = readText('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');

const alphaAdmin = getSeedUser('alpha_org_admin');
const alphaAdminBetaMember = getSeedUser('alpha_admin_beta_member');
const betaAdmin = getSeedUser('beta_org_admin');
const betaCall = getSeedCall('beta_active');
const crossOrgDenied = getSeedScenario('direct_join_alpha_org_admin_beta_active_denied');
const activeSwitchDenied = getSeedScenario('direct_join_alpha_admin_beta_member_active_switch_denied');

assert.equal(betaCall.tenant_key, 'beta', 'beta_active call must remain bound to the beta organization');
assert.equal(crossOrgDenied.principal_user_key, 'alpha_org_admin', 'cross-org denied scenario must use the alpha org admin');
assert.equal(crossOrgDenied.call_key, 'beta_active', 'cross-org denied scenario must target a beta organization call');
assert.equal(crossOrgDenied.expected.direct_join_allowed, false, 'alpha org admin must not directly join beta organization calls');
assert.equal(crossOrgDenied.expected.expected_resolve_status, 200, 'cross-org resolve denial must keep the public HTTP 200 envelope');
assert.equal(crossOrgDenied.expected.expected_resolve_state, 'forbidden', 'cross-org resolve denial must be forbidden');
assert.equal(crossOrgDenied.expected.expected_resolve_reason, 'calls_forbidden', 'cross-org resolve denial reason must be calls_forbidden');
assert.equal(crossOrgDenied.expected.expected_call_status, 403, 'cross-org call fetch denial must be HTTP 403');
assert.equal(crossOrgDenied.expected.expected_call_error_code, 'calls_forbidden', 'cross-org call fetch denial code must be calls_forbidden');
assert.equal(crossOrgDenied.expected.tenant_admin, false, 'cross-org denial must not inherit tenant-admin rights');
assert.equal(crossOrgDenied.expected.platform_admin, false, 'cross-org denial must not inherit platform-admin rights');

assert.equal(membershipsFor(alphaAdmin, 'alpha').length, 1, 'alpha org admin must have exactly one alpha membership');
assert.equal(membershipsFor(alphaAdmin, 'beta').length, 0, 'alpha org admin must not have beta membership in the seed matrix');
assert.equal(membershipsFor(alphaAdminBetaMember, 'alpha').length, 1, 'active-switch alpha admin must have exactly one alpha membership');
assert.equal(membershipsFor(alphaAdminBetaMember, 'beta').length, 1, 'active-switch alpha admin must have exactly one beta member membership');
assert.equal(membershipsFor(alphaAdminBetaMember, 'alpha')[0].role, 'admin', 'active-switch alpha membership must be admin');
assert.equal(membershipsFor(alphaAdminBetaMember, 'beta')[0].role, 'member', 'active-switch beta membership must stay member');
assert.equal(membershipsFor(betaAdmin, 'beta').length, 1, 'beta org admin must have exactly one beta membership');
assert.equal(membershipsFor(betaAdmin, 'alpha').length, 0, 'beta org admin must not have alpha membership in the seed matrix');

const alphaActiveSession = storedSessionForSeedUser('alpha_org_admin', 'alpha_active');
assert.equal(alphaActiveSession.tenant.slug, 'iam-alpha', 'alpha org admin default active org must resolve to alpha');
assert.equal(alphaActiveSession.tenant.membership_id > 0, true, 'alpha active-org session must carry a real alpha membership id');
assert.equal(alphaActiveSession.tenant.permissions.tenant_admin, true, 'alpha active-org session must carry alpha tenant-admin rights');

const betaSwitchedSession = storedSessionForSeedUser('alpha_org_admin', 'beta_active');
assert.equal(betaSwitchedSession.tenant.slug, 'iam-beta', 'active-org switch proof must resolve the beta org snapshot');
assert.equal(betaSwitchedSession.tenant.membership_id, 0, 'active-org switch must not mint a beta membership id');
assert.equal(betaSwitchedSession.tenant.role, 'member', 'active-org switch fallback must stay least-privilege member');
assert.equal(betaSwitchedSession.tenant.permissions.tenant_admin, false, 'active-org switch must not mint beta tenant-admin rights');
assert.equal(betaSwitchedSession.tenant.permissions.platform_admin, false, 'active-org switch must not mint platform-admin rights');

const multiTenantAlphaSession = storedSessionForSeedUser('alpha_admin_beta_member', 'alpha_active');
assert.equal(multiTenantAlphaSession.tenant.slug, 'iam-alpha', 'multi-tenant active-org switch proof must start in alpha');
assert.equal(multiTenantAlphaSession.tenant.permissions.tenant_admin, true, 'multi-tenant proof must start with alpha admin rights');

const multiTenantBetaSession = storedSessionForSeedUser('alpha_admin_beta_member', 'beta_active');
assert.equal(multiTenantBetaSession.tenant.slug, 'iam-beta', 'multi-tenant active-org switch proof must resolve beta');
assert.equal(multiTenantBetaSession.tenant.membership_id > 0, true, 'multi-tenant active-org switch should use the real beta membership');
assert.equal(multiTenantBetaSession.tenant.role, 'member', 'multi-tenant active-org switch must keep beta role as member');
assert.equal(multiTenantBetaSession.tenant.permissions.tenant_admin, false, 'multi-tenant active-org switch must not carry alpha admin rights into beta');
assert.equal(multiTenantBetaSession.tenant.permissions.platform_admin, false, 'multi-tenant active-org switch must not mint platform-admin rights');
assert.equal(activeSwitchDenied.principal_user_key, 'alpha_admin_beta_member', 'active-switch denial must use the multi-tenant alpha admin');
assert.equal(activeSwitchDenied.call_key, 'beta_active', 'active-switch denial must target the beta call');
assert.equal(activeSwitchDenied.expected.active_org_switch, true, 'active-switch denial must be marked as active-org switching proof');
assert.equal(activeSwitchDenied.expected.direct_join_allowed, false, 'active switch must not grant beta call access');
assert.equal(activeSwitchDenied.expected.tenant_admin, false, 'active switch denial must not expose beta tenant-admin rights');

assert.match(
  seedMatrixSpec,
  /direct_join_alpha_org_admin_beta_active_denied/,
  'seed-matrix browser spec must exercise the alpha-admin to beta-call cross-org denial',
);
assert.match(
  seedMatrixSpec,
  /direct_join_alpha_admin_beta_member_active_switch_denied/,
  'seed-matrix browser spec must exercise the multi-tenant active-org switch denial',
);
assert.match(
  seedMatrixSpec,
  /responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)/,
  'seed-matrix browser spec must assert denied resolve payloads do not expose call data',
);
assert.match(
  seedMatrixHelper,
  /state:\s*'forbidden'[\s\S]*reason:\s*'calls_forbidden'[\s\S]*access_link:\s*null[\s\S]*call:\s*null/s,
  'seed helper must model denied resolve as call:null and access_link:null',
);
assert.doesNotMatch(
  seedMatrixHelper,
  /details:\s*\{[^}]*call_id/s,
  'seed helper denied call fetch payload must not echo private call identifiers in error details',
);

const forbiddenResolvePayload = {
  status: 'ok',
  result: {
    state: crossOrgDenied.expected.expected_resolve_state,
    resolved_as: 'call_id',
    reason: crossOrgDenied.expected.expected_resolve_reason,
    access_link: null,
    call: null,
  },
};
assert.doesNotMatch(
  serialized(forbiddenResolvePayload),
  /IAM Beta Access Matrix Call|iam-beta-room|20000000-0000-4000-8000-000000000102|iam-beta-admin@example\.test/,
  'denied resolve payload fixture must not contain beta private call title, room, id, or owner email',
);

const forbiddenCallPayload = {
  status: 'error',
  error: {
    code: crossOrgDenied.expected.expected_call_error_code,
    message: 'You are not allowed to view this call.',
  },
};
assert.doesNotMatch(
  serialized(forbiddenCallPayload),
  /IAM Beta Access Matrix Call|iam-beta-room|20000000-0000-4000-8000-000000000102|iam-beta-admin@example\.test/,
  'denied call fetch payload fixture must not contain beta private call title, room, id, or owner email',
);

assert.match(
  backendCrossOrgContract,
  /active organization A context must not fetch organization B call[\s\S]*organization B call must be hidden from organization A context/s,
  'backend cross-org contract must keep the active-org mismatch behavior aligned',
);
assert.match(
  backendCrossOrgContract,
  /active organization switch must not mint organization B membership[\s\S]*tenant_membership_inactive/s,
  'backend cross-org contract must prove active-org switch does not create membership',
);
assert.match(
  backendCrossOrgContract,
  /multi-tenant active switch should expose organization B tenant context[\s\S]*multi-tenant active switch must not grant organization B tenant-admin permissions[\s\S]*multi-tenant active switch must not grant organization B call permission/s,
  'backend cross-org contract must prove multi-tenant active-org switching keeps call access least-privilege',
);
assert.match(
  backendCrossOrgContract,
  /organization A admin should access same-organization call[\s\S]*organization A admin rights must not cross into organization B calls/s,
  'backend cross-org contract must prove organization-admin rights stay inside the owning organization',
);
assert.match(
  backendCrossOrgContract,
  /stale organization admin membership must be re-read before call administration[\s\S]*stale organization admin must not keep invite-only call access/s,
  'backend cross-org contract must prove stale organization membership does not keep call administration rights',
);
assert.match(
  backendCrossOrgContract,
  /foreign verified context should conflict[\s\S]*foreign verified context response[\s\S]*foreign verified context denial must not persist a call access session/s,
  'backend cross-org contract must prove foreign verified context fails closed without persisting a session',
);
assert.match(
  backendCrossOrgContract,
  /Organization B Invite Only[\s\S]*cross-org-b-owner@example\.test[\s\S]*Org B Owner[\s\S]*foreign verified context response/s,
  'backend cross-org contract must prove foreign verified context responses do not leak private target data',
);
assert.ok(
  (seedMatrix.scenarios || []).some((scenario) => scenario?.key === crossOrgDenied.key),
  'seed matrix must publish the cross-org denial scenario for downstream E2E use',
);

process.stdout.write('[call-access-cross-org-contract] PASS\n');
