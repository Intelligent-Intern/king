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
const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const reviewDomain = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const callAccessRoutes = read('demo/video-chat/backend-king-php/http/module_calls_access.php');
const systemAdminContract = read('demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php');
const anonymousLobbyContract = read('demo/video-chat/backend-king-php/tests/call-access-anonymous-lobby-contract.php');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

const systemAdminTenantless = seedMatrix.scenarios.find(
  (scenario) => scenario.key === 'direct_join_system_admin_tenantless_without_organization',
);
assert.ok(systemAdminTenantless, 'seed matrix must include the tenantless system-admin direct-join scenario');
assert.equal(systemAdminTenantless.call_key, 'tenantless_active', 'tenantless system-admin scenario must target the tenantless call');
assert.equal(systemAdminTenantless.expected?.decision_source, 'system_admin', 'tenantless system-admin scenario must keep system_admin as source');
assert.equal(systemAdminTenantless.expected?.tenant_required, false, 'tenantless system-admin scenario must not invent tenant scope');

assert.match(
  seedSpec,
  /direct_join_system_admin_tenantless_without_organization/,
  'Playwright seed-matrix spec must execute the tenantless system-admin direct join',
);
assert.match(
  seedSpec,
  /tenantSnapshotForSeedUser\(tenantlessSystemAdminScenario\.principal_user_key,\s*tenantlessSystemAdminScenario\.call_key\)\)\.toBeNull\(\)/,
  'Playwright seed-matrix spec must prove the tenantless path remains tenantless',
);

assert.match(
  reviewDomain,
  /function videochat_call_access_list_review_flags_for_user/,
  'backend review domain must expose a system-admin review flag list contract',
);
assert.match(
  reviewDomain,
  /function videochat_call_access_handle_review_flag_for_user/,
  'backend review domain must expose a system-admin review flag handling contract',
);
assert.match(
  reviewDomain,
  /videochat_user_has_system_admin_call_rights\(\$pdo,\s*\$authUserId,\s*\$authRole\)/,
  'review flag list and handling must revalidate real system-admin rights',
);
assert.match(
  reviewDomain,
  /'raw_link_identifier_logged' => false[\s\S]*'access_fingerprint_logged' => false[\s\S]*'account_email_logged' => false/,
  'review flag handling audit payload must mark sensitive identifiers omitted',
);
assert.doesNotMatch(
  reviewDomain,
  /'access_fingerprint'\s*=>\s*\(string\)\s*\(\$row\['access_fingerprint'\]/,
  'public review flag payload must not return access_fingerprint',
);

assert.match(
  callAccessRoutes,
  /\/api\/call-access\/review-flags/,
  'HTTP call-access routes must expose review flag list and handling endpoints',
);
assert.match(
  callAccessRoutes,
  /Only system admins can view call access review flags/,
  'HTTP review flag list route must reject non-system-admin sessions',
);
assert.match(
  callAccessRoutes,
  /Only system admins can handle call access review flags/,
  'HTTP review flag handling route must reject non-system-admin sessions',
);

assert.match(
  systemAdminContract,
  /tenantless active call should be created when product data contains such calls/,
  'backend system-admin contract must create and prove an active tenantless call',
);
assert.match(
  systemAdminContract,
  /system admin should direct join tenantless active call/,
  'backend system-admin contract must prove tenantless direct join',
);
assert.match(
  systemAdminContract,
  /organization admin must not inherit rights over tenantless calls/,
  'backend system-admin contract must include tenantless organization-admin denial',
);
assert.match(
  systemAdminContract,
  /temporary account must not join tenantless call as system admin/,
  'backend system-admin contract must include tenantless temporary-account denial',
);
assert.match(
  systemAdminContract,
  /system admin should list open review flags[\s\S]*system admin should handle review flag/,
  'backend system-admin contract must prove review flag view and handling',
);
assert.match(
  systemAdminContract,
  /review list route must not expose access fingerprint[\s\S]*review handle route must not expose access fingerprint/,
  'backend route proof must keep review flag responses fingerprint-minimized',
);
assert.match(
  anonymousLobbyContract,
  /system admin should see waiting participants in lobby snapshot/,
  'anonymous-lobby contract must prove system admins can see waiting participants',
);

assert.match(
  String(packageJson.scripts?.['test:contract:iam-call-access'] || ''),
  /iam-system-admin-edge-cases-contract\.mjs/,
  'IAM contract script must include the system-admin edge-cases static contract',
);
assert.match(
  String(packageJson.scripts?.['test:contract:iam-call-access'] || ''),
  /system-admin-call-rights-contract\.php/,
  'IAM contract script must include the backend system-admin edge-cases proof',
);
assert.match(
  ciGate,
  /iam-system-admin-edge-cases-contract\.mjs/,
  'IAM CI gate must include the system-admin edge-cases static contract',
);
assert.match(
  ciGate,
  /tests\/system-admin-call-rights-contract\.php/,
  'IAM CI gate must include the backend system-admin proof',
);

console.log('[iam-system-admin-edge-cases-contract] PASS');
