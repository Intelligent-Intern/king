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
const betaAdmin = getSeedUser('beta_org_admin');
const betaCall = getSeedCall('beta_active');
const crossOrgDenied = getSeedScenario('direct_join_alpha_org_admin_beta_active_denied');

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

assert.match(
  seedMatrixSpec,
  /direct_join_alpha_org_admin_beta_active_denied/,
  'seed-matrix browser spec must exercise the alpha-admin to beta-call cross-org denial',
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
assert.ok(
  (seedMatrix.scenarios || []).some((scenario) => scenario?.key === crossOrgDenied.key),
  'seed matrix must publish the cross-org denial scenario for downstream E2E use',
);

process.stdout.write('[call-access-cross-org-contract] PASS\n');
