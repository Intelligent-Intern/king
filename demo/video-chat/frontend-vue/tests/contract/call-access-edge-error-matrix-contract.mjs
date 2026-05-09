import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const sprint = read('SPRINT.md');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-edge-error-matrix-contract.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/call-access-edge-error-matrix-contract.sh');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const callAccessPublic = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessDecision = read('demo/video-chat/backend-king-php/domain/calls/call_access_decision.php');
const callAccessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const callManagementQuery = read('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
const tenantContext = read('demo/video-chat/backend-king-php/support/tenant_context.php');

requireIncludes(
  iamCallAccessContractSuiteText,
  'node tests/contract/call-access-edge-error-matrix-contract.mjs',
  'IAM contract script must run the edge/error matrix static proof',
);
requireIncludes(
  iamCallAccessContractSuiteText,
  '../backend-king-php/tests/call-access-edge-error-matrix-contract.sh',
  'IAM contract script must run the edge/error matrix backend proof',
);
requireIncludes(
  ciGate,
  '"tests/contract/call-access-edge-error-matrix-contract.mjs"',
  'IAM CI static gate must include the edge/error matrix static proof',
);
requireIncludes(
  ciGate,
  '"tests/call-access-edge-error-matrix-contract.sh"',
  'IAM CI SQLite gate must include the edge/error matrix backend proof',
);
requireIncludes(
  backendShell,
  'call-access-edge-error-matrix-contract.php',
  'backend shell must execute the edge/error matrix PHP contract',
);

for (const sprintLine of [
  '- [x] Call does not exist',
  '- [x] Organization is disabled',
  '- [x] Host is disabled',
  '- [x] Invited temporary account was deleted',
]) {
  requireIncludes(sprint, sprintLine, `SPRINT.md must close ${sprintLine} only with this proof`);
}
requireMatch(
  sprint,
  /`call-access-edge-error-matrix-contract` closes call-not-found, disabled\s+organization\/workspace, disabled host, and deleted invited temporary-account\s+paths/,
  'SPRINT.md proof narrative must name the focused edge/error matrix contract',
);

for (const e2eCase of [
  'e2e_edge_001_call_does_not_exist',
  'e2e_edge_002_disabled_organization',
  'e2e_edge_003_host_disabled',
  'e2e_edge_004_invited_temporary_account_deleted',
]) {
  requireIncludes(backendContract, e2eCase, `backend contract must name ${e2eCase}`);
}
requireMatch(
  backendContract,
  /missing call should resolve as not_found[\s\S]*missing access session issuer must not be called/s,
  'backend proof must fail closed for missing calls/access links without issuing sessions',
);
requireMatch(
  backendContract,
  /tenants SET status = 'archived'[\s\S]*tenant_inactive[\s\S]*disabled organization session issuer must not be called/s,
  'backend proof must deny archived organization/workspace access before session issuance',
);
requireMatch(
  backendContract,
  /users SET status = 'disabled'[\s\S]*call_host_inactive[\s\S]*disabled host session issuer must not be called/s,
  'backend proof must deny disabled-host access before session issuance',
);
requireMatch(
  backendContract,
  /videochat_create_guest_user_for_call_access[\s\S]*DELETE FROM users[\s\S]*deleted temporary guest session issuer must not be called/s,
  'backend proof must deny deleted invited temporary accounts before session issuance',
);

requireMatch(
  tenantContext,
  /function videochat_tenant_is_active[\s\S]*tenants[\s\S]*status = 'active'/s,
  'tenant context must expose active tenant/workspace validation',
);
requireMatch(
  callManagementQuery,
  /owners\.status AS owner_status[\s\S]*function videochat_call_owner_is_active[\s\S]*owner_status/s,
  'call query must carry owner status for call-access decisions',
);
requireMatch(
  callManagementQuery,
  /function videochat_get_call_for_user[\s\S]*videochat_call_tenant_is_active[\s\S]*videochat_call_owner_is_active[\s\S]*'not_found'/s,
  'direct call resolution must hide inactive tenant or host call data',
);
requireMatch(
  callAccessDecision,
  /videochat_call_tenant_is_active[\s\S]*tenant_inactive[\s\S]*videochat_call_owner_is_active[\s\S]*call_host_inactive/s,
  'call access decision must fail closed for inactive tenant/workspace and host',
);
requireMatch(
  callAccessPublic,
  /videochat_call_tenant_is_active[\s\S]*videochat_call_owner_is_active[\s\S]*'not_found'/s,
  'public call-access resolution must hide inactive tenant or host call data',
);
requireMatch(
  callAccessContract,
  /resolved_call_tenant_id[\s\S]*videochat_tenant_is_active[\s\S]*call_access_tenant_inactive[\s\S]*resolved_call_owner_status[\s\S]*call_access_call_host_inactive/s,
  'call-access session binding validation must revoke inactive tenant or host sessions',
);

for (const label of [
  'disabled organization',
  'disabled host',
  'deleted temporary account',
]) {
  requireIncludes(e2eSpec, `label: '${label}'`, `Playwright safe-screen spec must include ${label}`);
}
requireMatch(
  e2eSpec,
  /Archived Organization Private Call[\s\S]*Disabled Host Private Call[\s\S]*Deleted Temporary Guest Private Call[\s\S]*sessionPostCount[\s\S]*toBe\(0\)/s,
  'Playwright proof must keep edge/error safe screens from starting sessions',
);

process.stdout.write('[call-access-edge-error-matrix-contract] PASS\n');
