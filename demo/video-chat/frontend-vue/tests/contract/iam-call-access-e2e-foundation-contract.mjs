import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  callAccessE2eSuiteText,
  iamCallAccessContractSuiteText,
} from './helpers/iamCallAccessSuiteCoverage.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const callAccessSeedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const e2eSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const tempGuestListDirectSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-temp-guest-list-direct-join.spec.js');
const coreOrgSessionSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-core-org-session-journey.spec.js');
const mainJourneySmokeSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-main-journey-smoke.spec.js');
const ownerTransferMainJourneysSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-owner-transfer-main-journeys.spec.js');
const terminalMainJourneysSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-invite-reschedule-delete-end-main-journeys.spec.js');
const anonymousDisabledBrowserSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-anonymous-disabled-link.spec.js');
const ownerAbsenceBrowserSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-owner-absence-browser.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const seedRuntimeHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedRuntime.js');
const liveFixtureHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js');
const backendContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const coreOrgSessionBackendContract = readText('demo/video-chat/backend-king-php/tests/iam-core-org-session-journey-contract.php');
const sqliteProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const smoke = readText('demo/video-chat/scripts/smoke.sh');
const auth = readText('demo/video-chat/backend-king-php/support/auth.php');
const authCache = readText('demo/video-chat/backend-king-php/support/auth_session_cache.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');

const scripts = packageJson.scripts || {};
const callAccessScript = callAccessE2eSuiteText;
const iamContractScript = iamCallAccessContractSuiteText;
const matrixScript = String(scripts['test:e2e:matrix'] || '');
const seedScenarios = Array.isArray(callAccessSeedMatrix.scenarios) ? callAccessSeedMatrix.scenarios : [];
const directJoinScenarioKeys = seedScenarios
  .filter((scenario) => (
    typeof scenario?.call_key === 'string'
    && typeof scenario?.principal_user_key === 'string'
    && typeof scenario?.expected?.direct_join_allowed === 'boolean'
  ))
  .map((scenario) => String(scenario.key || '').trim())
  .filter(Boolean);
const deniedDirectJoinScenarioKeys = seedScenarios
  .filter((scenario) => (
    directJoinScenarioKeys.includes(String(scenario?.key || '').trim())
    && scenario?.expected?.direct_join_allowed === false
    && scenario?.expected?.expected_resolve_reason === 'calls_forbidden'
    && scenario?.expected?.expected_call_error_code === 'calls_forbidden'
  ))
  .map((scenario) => String(scenario.key || '').trim());
const terminalDirectJoinScenarioKeys = seedScenarios
  .filter((scenario) => (
    directJoinScenarioKeys.includes(String(scenario?.key || '').trim())
    && scenario?.expected?.direct_join_allowed === false
    && typeof scenario?.expected?.expected_call_status_value === 'string'
  ))
  .map((scenario) => String(scenario.key || '').trim());
const directJoinCasesMatch = seedMatrixSpec.match(/const\s+directJoinPermissionCases\s*=\s*\[([\s\S]*?)\];/);
const directJoinCasesInSpec = directJoinCasesMatch
  ? [...directJoinCasesMatch[1].matchAll(/['"]([^'"]+)['"]/g)].map((match) => match[1])
  : [];

assert.match(
  String(scripts['test:e2e:call-access'] || ''),
  /call-access-e2e-suite\.mjs/,
  'package script must expose the live backend Call Access Playwright suite helper',
);

assert.match(
  callAccessScript,
  /playwright test tests\/e2e\/call-access-join\.spec\.js/,
  'package script must keep the live backend Call Access Playwright spec',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-seed-matrix\.spec\.js/,
  'package script must include additive deterministic Call Access seed-matrix coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-temp-guest-list-direct-join\.spec\.js/,
  'package script must include temporary personalized guest-list direct-join E2E coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-core-org-session-journey\.spec\.js/,
  'package script must include the core organization/account/session journey proof',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-duplicate-review-email\.spec\.js/,
  'package script must include duplicate-review and account-confirmation E2E coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-owner-absence-browser\.spec\.js/,
  'package script must include browser-near owner absence countdown and auto-end coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-main-journey-smoke\.spec\.js/,
  'package script must include deterministic main-journey Call Access smoke coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-owner-transfer-main-journeys\.spec\.js/,
  'package script must include owner-transfer main-journey E2E coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-invite-reschedule-delete-end-main-journeys\.spec\.js/,
  'package script must include terminal invitation/reschedule/delete/end main-journey coverage',
);
assert.match(
  callAccessScript,
  /tests\/e2e\/call-access-anonymous-disabled-link\.spec\.js/,
  'package script must include disabled anonymous link E2E coverage',
);
assert.match(
  callAccessScript,
  /--workers=1/,
  'call-access E2E script must run serially to avoid live backend access-link contention',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /iam-call-access-contract-suite\.mjs/,
  'package script must expose the IAM Call Access contract suite helper',
);
assert.match(
  iamContractScript,
  /iam-call-access-e2e-foundation-contract\.mjs/,
  'package script must expose the IAM Call Access contract gate',
);
assert.match(
  iamContractScript,
  /call-access-duplicate-review-email-contract\.mjs/,
  'IAM Call Access contract gate must include duplicate-review/account-confirmation static proof',
);
assert.match(
  iamContractScript,
  /iam-king-participants-owner-timeout-contract\.mjs/,
  'IAM Call Access contract gate must include the owner-absence runtime/browser proof contract',
);
assert.match(
  iamContractScript,
  /\.\.\/backend-king-php\/tests\/call-access-membership-removal-contract\.sh/,
  'IAM Call Access contract gate must include the backend membership-removal proof',
);
assert.match(
  iamContractScript,
  /\.\.\/backend-king-php\/tests\/iam-core-org-session-journey-contract\.sh/,
  'IAM Call Access contract gate must include the backend core organization/account/session proof',
);
assert.match(
  iamContractScript,
  /call-access-email-confirmation-contract\.sh/,
  'IAM Call Access contract gate must include the backend email confirmation proof',
);
assert.match(
  iamContractScript,
  /call-access-anonymous-disabled-link-contract\.sh/,
  'IAM Call Access contract gate must include the disabled anonymous link backend proof',
);
assert.match(
  iamContractScript,
  /call-access-anonymous-logged-in-rights-contract\.sh/,
  'IAM Call Access contract gate must include logged-in anonymous-link org-admin and guest-list rights proof',
);
assert.match(
  iamContractScript,
  /call-access-anonymous-temp-rights-contract\.sh/,
  'IAM Call Access contract gate must include anonymous temporary rights backend proof',
);
assert.match(
  iamContractScript,
  /\.\.\/backend-king-php\/tests\/call-access-invited-user-org-removal-contract\.sh/,
  'IAM Call Access contract gate must include the invited-user organization-removal proof',
);
assert.match(
  iamContractScript,
  /\.\.\/backend-king-php\/tests\/call-access-membership-stale-invite-rights-contract\.sh/,
  'IAM Call Access contract gate must include the stale-invite membership-rights proof',
);
assert.doesNotMatch(
  matrixScript,
  /tests\/e2e\/call-access-join\.spec\.js/,
  'broader compose E2E matrix must not execute the live Call Access join spec with host-style backend origin',
);

const uiParityPaths = new Set(matrix.commands?.['frontend:e2e:ui-parity']?.paths || []);
const matrixPaths = new Set(matrix.commands?.['frontend:e2e:matrix']?.paths || []);
const callAccessPaths = new Set(matrix.commands?.['frontend:e2e:call-access']?.paths || []);
const requiredSpecs = new Set(matrix.release_gate?.required_ui_parity_specs || []);
const removedInvitedMember = callAccessSeedMatrix.users.find((user) => user?.key === 'removed_invited_member');
assertUniqueField(callAccessSeedMatrix.tenants, 'id', 'tenant seed row');
assertUniqueField(callAccessSeedMatrix.organizations, 'id', 'organization seed row');
assertUniqueField(callAccessSeedMatrix.users, 'id', 'user seed row');
assertUniqueField(callAccessSeedMatrix.calls, 'id', 'call seed row');
assertUniqueField(callAccessSeedMatrix.access_links, 'id', 'access-link seed row');
assert.ok(removedInvitedMember, 'call-access seed matrix must include the removed invited member principal');
assert.deepEqual(removedInvitedMember.memberships || [], [], 'removed invited member must not keep active tenant membership in the deterministic seed');
assert.deepEqual(removedInvitedMember.organization_memberships || [], [], 'removed invited member must not keep active organization membership in the deterministic seed');
assert.ok(
  (removedInvitedMember.removed_organization_memberships || []).some((membership) => (
    membership?.organization_key === 'alpha_org' && membership?.role === 'admin'
  )),
  'removed invited member seed must retain former org-admin metadata for stale-role regression coverage',
);
assert.ok(
  uiParityPaths.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'UI parity matrix must list the Call Access join spec',
);
assert.ok(
  !matrixPaths.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'chat/layout compose matrix must not list the live Call Access join spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'focused Call Access command must list the live backend Call Access join spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-seed-matrix.spec.js'),
  'focused Call Access command must list the deterministic seed-matrix spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-core-org-session-journey.spec.js'),
  'focused Call Access command must list the core organization/account/session journey spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js'),
  'focused Call Access command must list duplicate-review/account-confirmation E2E coverage',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-owner-absence-browser.spec.js'),
  'focused Call Access command must list the owner absence browser proof spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-main-journey-smoke.spec.js'),
  'focused Call Access command must list the deterministic main-journey smoke spec',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-invite-reschedule-delete-end-main-journeys.spec.js'),
  'focused Call Access command must list terminal invitation/reschedule/delete/end main-journey coverage',
);
assert.ok(
  callAccessPaths.has('frontend-vue/tests/e2e/call-access-anonymous-disabled-link.spec.js'),
  'focused Call Access command must list the disabled anonymous link E2E spec',
);
assert.ok(
  requiredSpecs.has('frontend-vue/tests/e2e/call-access-join.spec.js'),
  'release gate must pin the Call Access join spec as required coverage',
);

assert.match(e2eSpec, /\/api\/call-access\/\$\{accessId\}\/join/, 'E2E spec must observe the public join resolution request');
assert.match(e2eSpec, /\/api\/call-access\/\$\{accessId\}\/session/, 'E2E spec must observe the public call-access session request');
assert.match(e2eSpec, /nativeAudioTransferHarness\.js/, 'E2E spec must keep using the live backend harness');
assert.match(e2eSpec, /createInvitedCallViaApi[\s\S]*createPersonalAccessJoinPath/s, 'E2E spec must keep live API call and access-link creation');
assert.match(e2eSpec, /tenant_admin[\s\S]*false/, 'E2E spec must assert the session does not gain tenant-admin rights');
assert.match(
  seedMatrixSpec,
  /temporary_personalized_guest[\s\S]*temporary_anonymous_guest[\s\S]*tenant_admin[\s\S]*false/s,
  'seed-matrix spec must prove temporary guests do not receive tenant/system admin rights',
);
assert.match(
  seedMatrixSpec,
  /alpha_tenant_member_without_organization[\s\S]*organization_memberships[\s\S]*\[\][\s\S]*direct_join_user_without_organization_denied/s,
  'seed-matrix spec must prove a tenant member without organization receives no organization-based direct call rights',
);
assert.match(
  seedMatrixHelper,
  /const organizationIndex = byKey\(iamCallAccessSeedMatrix\.organizations,[\s\S]*export function getSeedOrganization/,
  'seed-matrix helper must expose deterministic organization fixtures without changing existing gate structure',
);
const seedOrganizationsByKey = new Map((callAccessSeedMatrix.organizations || []).map((organization) => [organization?.key, organization]));
assert.deepEqual(
  seedOrganizationsByKey.get('alpha_org'),
  {
    key: 'alpha_org',
    tenant_key: 'alpha',
    public_id: 'organization-alpha-e2e',
    label: 'IAM Alpha Private Organization',
    status: 'active',
  },
  'seed matrix must define the alpha organization fixture for core org/session proofs',
);
assert.deepEqual(
  (callAccessSeedMatrix.users || []).find((user) => user?.key === 'alpha_normal_user')?.organization_memberships,
  [{ organization_key: 'alpha_org', role: 'member' }],
  'seed matrix must keep normal account users as organization members, not admins',
);
assert.deepEqual(
  (callAccessSeedMatrix.users || []).find((user) => user?.key === 'alpha_org_admin')?.organization_memberships,
  [{ organization_key: 'alpha_org', role: 'admin' }],
  'seed matrix must keep organization admins on account role user with organization admin membership',
);
assert.deepEqual(
  (callAccessSeedMatrix.users || []).find((user) => user?.key === 'alpha_tenant_member_without_organization')?.organization_memberships,
  [],
  'seed matrix must include a tenant member with no organization membership',
);
assert.deepEqual(
  seedScenarios.find((scenario) => scenario?.key === 'direct_join_user_without_organization_denied')?.expected,
  {
    direct_join_allowed: false,
    expected_resolve_status: 200,
    expected_resolve_state: 'forbidden',
    expected_resolve_reason: 'calls_forbidden',
    expected_call_status: 403,
    expected_call_error_code: 'calls_forbidden',
    platform_admin: false,
    tenant_admin: false,
    guest_list_entry: false,
    owner: false,
    guest_list_required: true,
  },
  'seed matrix must pin tenant-without-organization direct join denial to existing forbidden envelopes',
);
assert.match(
  callAccessSession,
  /body\.verified_user_id = verifiedContext\.userId;[\s\S]*body\.verified_session_id = verifiedContext\.sessionId;[\s\S]*headers\.authorization = `Bearer \$\{token\}`;/,
  'frontend call-access session start must keep verified logged-in account context on the authenticated request',
);
assert.match(
  coreOrgSessionBackendContract,
  /\/api\/admin\/users[\s\S]*\/api\/governance\/organizations[\s\S]*\/api\/auth\/login[\s\S]*\/api\/auth\/logout/s,
  'backend core organization/session contract must exercise governance, user registration, login, and logout routes',
);
assert.match(
  coreOrgSessionBackendContract,
  /videochat_user_is_organization_admin_for_call[\s\S]*tenant-only user without organization should be forbidden/s,
  'backend core organization/session contract must prove org-admin rights and no-organization denial from server state',
);
assert.match(
  coreOrgSessionBackendContract,
  /verified_user_id[\s\S]*verified_session_id[\s\S]*opening a call link must not revoke the logged-in account session/s,
  'backend core organization/session contract must prove logged-in open-link session start preserves the registered account',
);
assert.match(
  sqliteProof,
  /iam-core-org-session-journey-contract\.sh/,
  'SQLite/Docker IAM runtime proof must include the backend core organization/session journey contract',
);
const seedScenarioKeys = new Set((callAccessSeedMatrix.scenarios || []).map((scenario) => scenario?.key));
const seedScenariosByKey = new Map((callAccessSeedMatrix.scenarios || []).map((scenario) => [scenario?.key, scenario]));
assert.ok(directJoinScenarioKeys.length > 0, 'seed matrix must define Direct Join Permissions scenarios');
assert.deepEqual(
  [...directJoinCasesInSpec].sort(),
  [...directJoinScenarioKeys].sort(),
  'seed-matrix spec Direct Join Permissions cases must exactly match the seed matrix',
);
for (const scenarioKey of directJoinScenarioKeys) {
  assert.ok(
    seedScenarioKeys.has(scenarioKey),
    `seed matrix must include Direct Join Permissions scenario ${scenarioKey}`,
  );
  assert.match(
    seedMatrixSpec,
    new RegExp(escapeRegExp(scenarioKey)),
    `seed-matrix spec must exercise Direct Join Permissions scenario ${scenarioKey}`,
  );
}
for (const scenarioKey of deniedDirectJoinScenarioKeys) {
  const expected = seedScenariosByKey.get(scenarioKey)?.expected || {};
  assert.equal(expected.expected_resolve_status, 200, `${scenarioKey} resolve denial must keep production HTTP 200 envelope`);
  assert.equal(expected.expected_resolve_state, 'forbidden', `${scenarioKey} resolve denial must be forbidden`);
  assert.equal(expected.expected_resolve_reason, 'calls_forbidden', `${scenarioKey} resolve denial reason must match production`);
  assert.equal(expected.expected_call_status, 403, `${scenarioKey} call GET denial must be HTTP 403`);
  assert.equal(expected.expected_call_error_code, 'calls_forbidden', `${scenarioKey} call GET denial code must match production`);
}
for (const scenarioKey of terminalDirectJoinScenarioKeys) {
  const expected = seedScenariosByKey.get(scenarioKey)?.expected || {};
  assert.match(
    String(expected.expected_call_status_value || ''),
    /^(ended|disabled|deleted)$/,
    `${scenarioKey} terminal direct-join scenario must pin a safe terminal status`,
  );
  assert.notEqual(
    expected.expected_resolve_reason,
    'calls_forbidden',
    `${scenarioKey} terminal direct-join scenario must not masquerade as a normal permission denial`,
  );
}
assert.match(
  seedMatrixSpec,
  /directJoinPermissionCases[\s\S]*createDirectJoinProbePage[\s\S]*fetchDirectJoinResponses/s,
  'seed-matrix spec must exercise Direct Join Permissions through direct call-ref API probes',
);
assert.match(
  seedMatrixSpec,
  /expected\.expected_resolve_status[\s\S]*expected\.expected_resolve_state[\s\S]*expected\.expected_resolve_reason/s,
  'seed-matrix spec must assert denied direct call resolve from matrix expectations',
);
assert.match(
  seedMatrixSpec,
  /expected\.expected_call_status[\s\S]*expected\.expected_call_error_code/s,
  'seed-matrix spec must assert denied direct call GET from matrix expectations',
);
assert.match(
  seedMatrixHelper,
  /VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON/,
  'seed-matrix helper must support compose smoke injection when contracts/v1 is outside the frontend container mount',
);
assert.match(
  seedMatrixHelper,
  /canDirectlyResolveCall[\s\S]*authenticatedSeedSessionRecord/s,
  'seed-matrix helper must model authenticated direct call-ref permission decisions',
);
assert.match(
  seedMatrixHelper,
  /resolveMatch[\s\S]*fulfillJson\(route,\s*200,[\s\S]*state:\s*'forbidden'[\s\S]*reason:\s*'calls_forbidden'[\s\S]*call:\s*null/s,
  'seed-matrix helper must model denied /api/calls/resolve/{ref} as HTTP 200 forbidden with calls_forbidden',
);
assert.match(
  seedMatrixHelper,
  /callMatch[\s\S]*fulfillJson\(route,\s*403,[\s\S]*code:\s*'calls_forbidden'/s,
  'seed-matrix helper must model denied /api/calls/{id} GET as HTTP 403 calls_forbidden',
);
assert.match(
  backendContract,
  /videochat_tenant_user_is_member\(\$pdo, \$invitedUserId, \$tenantId\)[\s\S]*membership removal/s,
  'backend contract must prove losing tenant membership remains effective',
);
assert.match(
  backendContract,
  /videochat_resolve_call_access_public\(\$pdo, \$accessId\)[\s\S]*remain resolvable/s,
  'backend contract must prove explicit call-scoped links remain resolvable',
);
assert.match(
  backendContract,
  /tenant_admin[\s\S]*false/,
  'backend contract must prove call-scoped fallback does not restore tenant admin rights',
);
assert.match(
  ciGate,
  /call-access-active-permission-change-contract\.sh/,
  'IAM Call Access CI gate must include active permission-change backend proof',
);
assert.match(
  ciGate,
  /call-access-anonymous-logged-in-rights-contract\.sh/,
  'IAM Call Access CI gate must include the logged-in anonymous-link backend proof when SQLite is available',
);
assert.match(
  anonymousLoggedInRightsBackendContract,
  /own organization admin should enter own org call room[\s\S]*guest-list user should enter through anonymous link[\s\S]*foreign org admin should start in lobby/s,
  'backend logged-in anonymous-link proof must cover own-org admin, foreign-org denial, and guest-list user paths',
);
assert.match(
  anonymousLoggedInRightsBackendContract,
  /open link must not gain participant_user_id[\s\S]*open link must not gain participant_email[\s\S]*session binding must remain open-link kind/s,
  'backend logged-in anonymous-link proof must prove no personalized binding is created',
);
assert.match(
  activePermissionContract,
  /videochat_iam_rejoin_contract_set_invite_state\([\s\S]*'cancelled'[\s\S]*videochat_realtime_resolve_connection_rooms\(\$guestAuth/s,
  'active permission contract must prove guest-list removal affects rejoin room resolution',
);
assert.match(
  activePermissionContract,
  /videochat_realtime_connection_can_bypass_admission_for_room\(\$staleGuestConnection/s,
  'active permission contract must prove stale guest connection state cannot bypass admission',
);
assert.match(
  activePermissionContract,
  /videochat_iam_active_permission_contract_set_organization_role[\s\S]*'member'[\s\S]*videochat_realtime_is_user_moderator_for_room/s,
  'active permission contract must prove org-admin role downgrade uses current permissions',
);
assert.match(
  activePermissionContract,
  /videochat_update_call_participant_role\([\s\S]*'owner'[\s\S]*videochat_realtime_connection_with_call_context/s,
  'active permission contract must prove owner transfer updates realtime permissions',
);
assert.match(
  realtimeCallContext,
  /require_once __DIR__ \. '\/realtime_call_roles\.php';/,
  'realtime call context must use the focused current-role resolver',
);
assert.match(
  realtimeCallRoles,
  /videochat_user_has_system_admin_call_rights[\s\S]*videochat_user_is_organization_admin_for_call/s,
  'realtime role resolver must derive system and organization admin rights from current backend state',
);
assert.doesNotMatch(
  realtimeCallContext,
  /connectionInviteState|connectionCallRole/,
  'admission bypass must not trust cached connection invite or call role after active permission changes',
);
assert.match(
  invitedOrgRemovalContract,
  /videochat_iam_rejoin_contract_disable_organization_membership[\s\S]*organization removal alone must not delete tenant membership/s,
  'invited organization-removal contract must remove organization membership without deleting tenant membership',
);
assert.match(
  invitedOrgRemovalContract,
  /videochat_issue_session_for_call_access[\s\S]*call-scoped invited session[\s\S]*videochat_fetch_call_access_session_binding/s,
  'invited organization-removal contract must prove the personal link issues a call-scoped session binding',
);
assert.match(
  invitedOrgRemovalContract,
  /pendingResolution[\s\S]*videochat_realtime_waiting_room_id[\s\S]*allowedResolution[\s\S]*admitted call-scoped invited guest should enter only the invited call room/s,
  'invited organization-removal contract must prove lobby-before-admission and call-room-after-admission behavior',
);
assert.match(
  invitedOrgRemovalContract,
  /bindingMismatch[\s\S]*access_session_binding[\s\S]*mismatch/s,
  'invited organization-removal contract must prove the call-scoped session cannot bind an unrelated call',
);
assert.match(
  invitedOrgRemovalContract,
  /removed invited user must not join deleted call[\s\S]*removed invited user must not join ended call[\s\S]*removed invited kicked user must not direct-rejoin the call room/s,
  'invited organization-removal contract must prove deleted, ended, and kicked states override the call-scoped invitation',
);
assert.match(
  invitedOrgRemovalContract,
  /removed invited user should receive a session while invite remains valid[\s\S]*removed invited user must not rejoin after invite invalidation/s,
  'invited organization-removal contract must prove rejoin is allowed only while the invitation remains valid',
);
assert.match(
  membershipStaleInviteRightsContract,
  /moved organization member should lose old-organization resource grants[\s\S]*moved member should not use old organization membership[\s\S]*sess_iam_moved_member_call_scoped/s,
  'membership stale-invite contract must prove moved org members join only through call-scoped invitation',
);
assert.match(
  membershipStaleInviteRightsContract,
  /org admin should have same-organization call rights before downgrade[\s\S]*downgraded admin should lose org-admin rights for unrelated calls[\s\S]*sess_iam_downgraded_admin_call_scoped/s,
  'membership stale-invite contract must prove downgraded admins keep explicit invite access without org-admin rights',
);
assert.match(
  membershipStaleInviteRightsContract,
  /promoted user should receive current org-admin call source[\s\S]*promoted org admin should direct-enter from current organization rights/s,
  'membership stale-invite contract must prove promoted users receive current org-admin rights while still members',
);
assert.match(
  membershipStaleInviteRightsContract,
  /forged stale admin role should not restore call administration[\s\S]*IAM Removed Admin Stale Invite Call[\s\S]*sess_iam_removed_stale_admin_call_scoped/s,
  'membership stale-invite contract must prove stale invite payloads cannot restore removed org-admin rights',
);
assert.match(
  membershipStaleInviteRightsContract,
  /removed lobby user should keep call-scoped pending room binding[\s\S]*removed lobby user should remain queued through call-scoped invitation/s,
  'membership stale-invite contract must prove removed lobby users lose org rights but keep call-scoped lobby state',
);
assert.match(
  activeRemovalContract,
  /active removed user should remain connected when explicit call-scoped access exists[\s\S]*active removed org admin must lose realtime moderator controls immediately/s,
  'active membership-removal contract must prove active call-scoped participants stay connected while org-admin controls are revoked',
);
assert.match(
  activeRemovalContract,
  /stale org role connection must lose active call binding after removal[\s\S]*active removed user should keep room admission only through allowed call-scoped access/s,
  'active membership-removal contract must prove removed org users remain in calls only through explicit call-scoped permission',
);
assert.match(
  ciGate,
  /call-access-invited-user-org-removal-contract\.sh/,
  'IAM Call Access CI gate must run the invited-user organization-removal backend contract when SQLite is available',
);
assert.match(
  ciGate,
  /call-access-membership-stale-invite-rights-contract\.sh/,
  'IAM Call Access CI gate must run the stale-invite membership-rights backend contract when SQLite is available',
);
assert.match(
  smoke,
  /call-access-membership-removal-contract\.sh/,
  'smoke gate must include the backend call-access membership-removal contract',
);
assert.match(
  smoke,
  /VITE_VIDEOCHAT_BACKEND_ORIGIN='http:\/\/videochat-backend-v1:18080'[\s\S]*npm run test:e2e:call-access/s,
  'compose smoke must run the focused Call Access E2E command against the backend service DNS origin',
);
assert.match(
  smoke,
  /npm run test:e2e:call-access -- --reporter=list --workers=1/,
  'compose smoke must serialize the live Call Access E2E command to avoid fresh-compose SQLite write contention',
);
assert.match(
  smoke,
  /VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON=\$\{call_access_seed_matrix_json\}/,
  'compose smoke must inject the deterministic Call Access seed matrix into the frontend container',
);
assert.match(
  smoke,
  /VITE_VIDEOCHAT_WS_ORIGIN='http:\/\/videochat-backend-ws-v1:18080'[\s\S]*VITE_VIDEOCHAT_ALLOW_INSECURE_WS='1'[\s\S]*npm run test:e2e:call-access/s,
  'compose smoke must provide service-DNS websocket origin for the live Call Access lobby path',
);
assert.match(
  smoke,
  /VITE_VIDEOCHAT_BACKEND_ORIGIN='http:\/\/127\.0\.0\.1:\$\{compose_backend_port\}'[\s\S]*npm run test:e2e:matrix/s,
  'compose smoke must keep the broader chat/layout matrix on the host-style backend origin',
);
assert.match(
  auth + authCache,
  /videochat_tenant_context_for_call_access_session/,
  'auth paths must fall back to call-scoped tenant context for access sessions',
);
assert.match(
  tenantContext,
  /membership_id,[\s\S]*0 AS membership_id,[\s\S]*'member' AS membership_role/s,
  'call-scoped tenant fallback must be least-privilege and must not invent membership ids',
);
assert.match(
  callAccessPublic,
  /videochat_fetch_active_user_for_call_access\([\s\S]*false[\s\S]*\);/,
  'public call-access resolution must allow explicit invitation lookup without active tenant membership',
);
assert.match(
  tempGuestListDirectSpec,
  /requiresAdmission:\s*false[\s\S]*e2e_personalized_logged_out_003_temp_guest_on_guest_list_direct_join[\s\S]*lobby\/queue\/join[\s\S]*toBe\(false\)/,
  'temporary guest-list direct-join E2E must prove logged-out personalized links enter without lobby admission',
);
assert.match(
  tempGuestListDirectSpec,
  /participant_user_id=.*call_id=[\s\S]*expect\(sessionAuthorization\)\.toBe\(''\)[\s\S]*expect\(sessionBody\)\.toBeNull\(\)/,
  'temporary guest-list direct-join E2E must prove logged-out URL identity parameters are not sent as session authority',
);
assert.match(
  tempGuestListDirectSpec,
  /e2e_personalized_logged_out_007_manipulated_link_rejected[\s\S]*joinResponse\.status\(\)\)\.toBe\(404\)[\s\S]*sessionRequests\)\.toBe\(0\)/,
  'temporary guest-list manipulated-link E2E must reject changed link ids without session issuance',
);
assert.match(
  guestListDirectJoinContract,
  /sess_direct_join_temp_manipulated_body[\s\S]*body fields must not change the temporary link identity[\s\S]*temporary link must not assume another participant identity/,
  'backend guest-list direct-join contract must prove temporary personalized links ignore forged body identity fields',
);
assert.match(
  guestListDirectJoinContract,
  /mutated temporary personalized link should be rejected[\s\S]*temporary guest-list session should remain bound after leaving[\s\S]*reopened temporary link should recognize the same temporary account/,
  'backend guest-list direct-join contract must prove mutated-link rejection and same temporary-account recognition after leaving',
);
assert.match(
  ciGate,
  /call-guest-list-direct-join-contract\.sh/,
  'IAM CI SQLite gate must include the guest-list direct-join backend proof',
);

process.stdout.write('[iam-call-access-e2e-foundation-contract] PASS\n');
