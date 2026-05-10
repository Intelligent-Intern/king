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

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const matrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');

const users = byKey(matrix.users, 'user');
const calls = byKey(matrix.calls, 'call');
const scenarios = byKey(matrix.scenarios, 'scenario');

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `seed matrix must include ${label}: ${key}`);
  return value;
}

function membershipForTenant(user, tenantKey) {
  return (Array.isArray(user.memberships) ? user.memberships : [])
    .find((membership) => String(membership?.tenant_key || '') === String(tenantKey || '')) || null;
}

function isPlatformAdmin(user) {
  return user.system_admin === true || String(user.role || '').trim().toLowerCase() === 'admin';
}

function isTenantAdminForCall(user, call) {
  if (typeof call.tenant_key !== 'string' || call.tenant_key === '') return false;
  const membership = membershipForTenant(user, call.tenant_key);
  const role = String(membership?.role || '').trim().toLowerCase();
  return role === 'admin' || role === 'owner';
}

function isCallOwner(user, call) {
  return String(call.owner_user_key || '') === String(user.key || '');
}

function isGuestListUser(user, call) {
  return (Array.isArray(call.guest_list_user_keys) ? call.guest_list_user_keys : [])
    .includes(String(user.key || ''));
}

function canDirectJoinFromSeedMatrix(user, call) {
  return isPlatformAdmin(user)
    || isTenantAdminForCall(user, call)
    || isCallOwner(user, call)
    || isGuestListUser(user, call);
}

const requiredCases = [
  {
    key: 'direct_join_system_admin_alpha_active_allowed',
    principal: 'system_admin',
    call: 'alpha_active',
    allow: true,
    grants: { platform_admin: true, tenant_admin: true, owner: false, guest_list_entry: false },
  },
  {
    key: 'direct_join_system_admin_beta_active_allowed',
    principal: 'system_admin',
    call: 'beta_active',
    allow: true,
    grants: { platform_admin: true, tenant_admin: true, owner: false, guest_list_entry: false },
  },
  {
    key: 'direct_join_system_admin_tenantless_active_allowed',
    principal: 'system_admin',
    call: 'tenantless_active',
    allow: true,
    grants: { platform_admin: true, tenant_admin: true, owner: true, guest_list_entry: false },
  },
  {
    key: 'direct_join_alpha_org_admin_alpha_active_allowed',
    principal: 'alpha_org_admin',
    call: 'alpha_active',
    allow: true,
    grants: { platform_admin: false, tenant_admin: true, owner: false, guest_list_entry: false },
  },
  {
    key: 'direct_join_alpha_call_owner_alpha_active_allowed',
    principal: 'alpha_call_owner',
    call: 'alpha_active',
    allow: true,
    grants: { platform_admin: false, tenant_admin: false, owner: true, guest_list_entry: false },
  },
  {
    key: 'direct_join_registered_guest_alpha_active_allowed',
    principal: 'registered_guest',
    call: 'alpha_active',
    allow: true,
    grants: { platform_admin: false, tenant_admin: false, owner: false, guest_list_entry: true },
  },
  {
    key: 'direct_join_alpha_normal_user_alpha_active_denied',
    principal: 'alpha_normal_user',
    call: 'alpha_active',
    allow: false,
    grants: { platform_admin: false, tenant_admin: false, owner: false, guest_list_entry: false },
    denial: {
      expected_resolve_status: 200,
      expected_resolve_state: 'forbidden',
      expected_resolve_reason: 'calls_forbidden',
      expected_call_status: 403,
      expected_call_error_code: 'calls_forbidden',
    },
  },
  {
    key: 'direct_join_user_without_organization_denied',
    principal: 'alpha_tenant_member_without_organization',
    call: 'alpha_active',
    allow: false,
    grants: { platform_admin: false, tenant_admin: false, owner: false, guest_list_entry: false },
    denial: {
      expected_resolve_status: 200,
      expected_resolve_state: 'forbidden',
      expected_resolve_reason: 'calls_forbidden',
      expected_call_status: 403,
      expected_call_error_code: 'calls_forbidden',
    },
  },
  {
    key: 'direct_join_alpha_org_admin_beta_active_denied',
    principal: 'alpha_org_admin',
    call: 'beta_active',
    allow: false,
    grants: { platform_admin: false, tenant_admin: false, owner: false, guest_list_entry: false },
    denial: {
      expected_resolve_status: 200,
      expected_resolve_state: 'forbidden',
      expected_resolve_reason: 'calls_forbidden',
      expected_call_status: 403,
      expected_call_error_code: 'calls_forbidden',
    },
  },
];

assert.equal(matrix.matrix_name, 'king-video-chat-iam-call-access-seeding', 'contract must use the IAM call-access seed matrix');
assert.equal(
  matrix.test_bindings?.seed_matrix_playwright_spec,
  'frontend-vue/tests/e2e/call-access-seed-matrix.spec.js',
  'seed matrix must stay bound to the direct-join Playwright seed spec',
);

for (const contractCase of requiredCases) {
  const scenario = row(scenarios, contractCase.key, 'direct-join scenario');
  const principal = row(users, contractCase.principal, 'principal user');
  const call = row(calls, contractCase.call, 'call');
  const expected = scenario.expected || {};

  assert.equal(scenario.principal_user_key, contractCase.principal, `${contractCase.key} principal mismatch`);
  assert.equal(scenario.call_key, contractCase.call, `${contractCase.key} call mismatch`);
  assert.equal(expected.direct_join_allowed, contractCase.allow, `${contractCase.key} direct join expectation mismatch`);
  assert.equal(canDirectJoinFromSeedMatrix(principal, call), contractCase.allow, `${contractCase.key} seed-derived direct join result mismatch`);

  for (const [field, value] of Object.entries(contractCase.grants)) {
    assert.equal(expected[field], value, `${contractCase.key} expected ${field} mismatch`);
  }

  if (contractCase.allow) {
    assert.equal(expected.expected_resolve_status, 200, `${contractCase.key} allowed resolve must use HTTP 200`);
    assert.equal(expected.expected_resolve_state, 'resolved', `${contractCase.key} allowed resolve state mismatch`);
    assert.equal(expected.expected_call_status, 200, `${contractCase.key} allowed call fetch must use HTTP 200`);
    assert.equal(expected.guest_list_required, expected.guest_list_entry === true, `${contractCase.key} guest-list requirement mismatch`);
  } else {
    assert.deepEqual(
      {
        expected_resolve_status: expected.expected_resolve_status,
        expected_resolve_state: expected.expected_resolve_state,
        expected_resolve_reason: expected.expected_resolve_reason,
        expected_call_status: expected.expected_call_status,
        expected_call_error_code: expected.expected_call_error_code,
      },
      contractCase.denial,
      `${contractCase.key} denial envelope mismatch`,
    );
    assert.equal(expected.guest_list_required, true, `${contractCase.key} denied direct join must still require explicit guest-list admission`);
  }

  assert.match(
    seedMatrixSpec,
    new RegExp(escapeRegExp(contractCase.key)),
    `Playwright seed spec must exercise ${contractCase.key}`,
  );
}

const alphaNormal = row(users, 'alpha_normal_user', 'denied normal member');
assert.ok(membershipForTenant(alphaNormal, 'alpha'), 'denied normal member must remain a real alpha tenant member');
assert.equal(isTenantAdminForCall(alphaNormal, row(calls, 'alpha_active', 'alpha call')), false, 'normal tenant membership alone must not become tenant-admin direct join');
assert.equal(isGuestListUser(alphaNormal, row(calls, 'alpha_active', 'alpha call')), false, 'denied normal member must not be on the alpha call guest list');

const registeredGuest = row(users, 'registered_guest', 'registered guest');
assert.equal(Array.isArray(registeredGuest.memberships) && registeredGuest.memberships.length, 0, 'registered guest-list participant must not need tenant membership');
assert.equal(isGuestListUser(registeredGuest, row(calls, 'alpha_active', 'alpha call')), true, 'registered guest-list participant must be call-scoped');

assert.match(
  seedMatrixSpec,
  /const directJoinPermissionCases = \[[\s\S]*direct_join_system_admin_alpha_active_allowed[\s\S]*direct_join_alpha_normal_user_alpha_active_denied[\s\S]*\]/,
  'seed spec must keep a dedicated direct-join permission case list',
);
assert.match(
  seedMatrixSpec,
  /fetchDirectJoinResponses[\s\S]*\/api\/calls\/resolve\/\$\{encodeURIComponent\(targetRoomId\)\}[\s\S]*\/api\/calls\/\$\{encodeURIComponent\(targetCallId\)\}/,
  'seed spec must probe direct call-ref resolve and call fetch APIs',
);
assert.match(
  seedMatrixSpec,
  /expected\.expected_resolve_status[\s\S]*expected\.expected_resolve_state[\s\S]*expected\.expected_call_status[\s\S]*expected\.expected_call_error_code/s,
  'seed spec must assert both allowed and denied direct-join API envelopes from matrix expectations',
);
assert.match(
  seedMatrixHelper,
  /function canDirectlyResolveCall\(user,\s*call\)[\s\S]*isPlatformAdminUser\(user\)[\s\S]*isTenantAdminForCall\(user,\s*call\)[\s\S]*isCallOwner\(user,\s*call\)[\s\S]*isGuestListUserForCall\(user,\s*call\)/,
  'seed helper must keep direct-join authorization limited to platform admin, tenant admin, call owner, or guest-list participant',
);
assert.match(
  seedMatrixHelper,
  /resolveMatch[\s\S]*state:\s*'forbidden'[\s\S]*reason:\s*'calls_forbidden'[\s\S]*call:\s*null/s,
  'seed helper must model denied direct resolve as HTTP 200 forbidden without leaking call data',
);
assert.match(
  seedMatrixHelper,
  /callMatch[\s\S]*fulfillJson\(route,\s*403,[\s\S]*code:\s*'calls_forbidden'/s,
  'seed helper must model denied direct call fetch as HTTP 403 calls_forbidden',
);

process.stdout.write('[call-access-direct-join-rights-contract] PASS\n');
