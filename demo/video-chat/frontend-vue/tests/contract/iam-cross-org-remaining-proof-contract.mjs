import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const iamCallAccessPackageScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const crossOrgBackend = read('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const staleRoleBackend = read('demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php');

function scenario(key) {
  const found = (seedMatrix.scenarios || []).find((row) => row?.key === key);
  assert.ok(found, `seed matrix must include scenario ${key}`);
  return found;
}

function accessLink(key) {
  const found = (seedMatrix.access_links || []).find((row) => row?.key === key);
  assert.ok(found, `seed matrix must include access link ${key}`);
  return found;
}

assert.match(
  String(packageJson.scripts?.['test:contract:iam9-09-cross-org-remaining'] || ''),
  /iam-cross-org-remaining-proof-contract\.mjs/,
  'IAM9-09 package script must run the remaining cross-org proof',
);
assert.match(
  ciGate,
  /iam-cross-org-remaining-proof-contract\.mjs/,
  'IAM CI static gate must run the IAM9-09 remaining cross-org proof',
);
assert.match(
  iamCallAccessPackageScript,
  /call-access-cross-org-contract\.sh/,
  'IAM call-access package gate must keep running the backend cross-org contract',
);
assert.match(
  iamCallAccessPackageScript,
  /call-access-stale-organization-role-contract\.sh/,
  'IAM call-access package gate must keep running the stale organization-role backend contract',
);

for (const deniedScenarioKey of [
  'direct_join_org_admin_foreign_organization_denied',
  'direct_join_active_org_switch_does_not_grant_foreign_call',
  'direct_join_owner_rights_not_cross_org',
  'direct_join_guest_list_not_cross_org',
]) {
  const item = scenario(deniedScenarioKey);
  assert.equal(item.expected?.direct_join_allowed, false, `${deniedScenarioKey} must stay denied`);
  assert.equal(item.expected?.expected_resolve_state, 'forbidden', `${deniedScenarioKey} must resolve as forbidden`);
  assert.equal(item.expected?.expected_call_status, 403, `${deniedScenarioKey} must reject call fetches`);
  assert.equal(item.expected?.expected_call_error_code, 'calls_forbidden', `${deniedScenarioKey} must fail closed`);
  assert.equal(item.expected?.tenant_admin, false, `${deniedScenarioKey} must not grant tenant admin`);
  assert.equal(item.expected?.platform_admin, false, `${deniedScenarioKey} must not grant platform admin`);
  assert.match(
    seedMatrixSpec,
    new RegExp(`['"]${deniedScenarioKey}['"]`),
    `Playwright seed matrix must execute ${deniedScenarioKey}`,
  );
}

assert.equal(
  scenario('direct_join_org_admin_foreign_organization_denied').journey_key,
  'e2e_journey_013_org_admin_foreign_org_denied',
  'foreign org-admin direct-join denial must be mapped to the named main journey',
);
assert.equal(
  scenario('anonymous_open_org_admin_foreign_org_no_direct_join').journey_key,
  'e2e_anon_logged_in_006_org_admin_cannot_join_foreign_org_call',
  'foreign anonymous org-admin denial must be mapped to the named anonymous-link journey',
);
assert.equal(accessLink('beta_open').link_kind, 'open', 'beta foreign anonymous link must be an open link');
assert.equal(accessLink('beta_open').call_key, 'beta_active', 'beta foreign anonymous link must target the foreign call');
assert.equal(
  scenario('anonymous_open_org_admin_foreign_org_no_direct_join').expected?.can_manage_lobby,
  false,
  'foreign anonymous org-admin open-link session must not get lobby management rights',
);

assert.match(
  crossOrgBackend,
  /organization A admin rights must not cross into organization B calls/s,
  'backend cross-org contract must prove org-admin rights do not cross organizations',
);
assert.match(
  crossOrgBackend,
  /guest-list leakage should fail as forbidden inside organization B context/s,
  'backend cross-org contract must prove guest-list rights do not leak across organizations',
);
assert.match(
  crossOrgBackend,
  /multi-tenant active switch must not grant organization B call permission[\s\S]*multi-tenant active switch denial must not claim an access source/s,
  'backend cross-org contract must prove active organization switching cannot grant foreign call access',
);
assert.match(
  crossOrgBackend,
  /organization B open link must not grant organization A admin access to another B invite-only call[\s\S]*open-link guest must not receive organization B admin rights/s,
  'backend cross-org contract must prove foreign anonymous links remain call-scoped',
);

assert.match(
  staleRoleBackend,
  /organization_role=admin&tenant_admin=1&role=admin[\s\S]*X-Organization-Role[\s\S]*stale client role cache must not resolve hidden invite-only call/s,
  'stale organization-role contract must prove forged stale client role data is ignored',
);
assert.match(
  staleRoleBackend,
  /same session must re-read downgraded tenant role[\s\S]*locally cached session fallback must re-read downgraded tenant role/s,
  'stale organization-role contract must prove live and cached sessions re-read downgraded backend roles',
);
assert.match(
  staleRoleBackend,
  /staleDecodedSessionContext[\s\S]*call access must revalidate stale decoded role context against backend state/s,
  'stale organization-role contract must prove stale decoded session role context is revalidated',
);

console.log('[iam-cross-org-remaining-proof-contract] PASS');
