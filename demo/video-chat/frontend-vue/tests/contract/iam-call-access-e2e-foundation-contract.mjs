import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

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

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const callAccessSeedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const e2eSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const tempGuestListDirectSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-temp-guest-list-direct-join.spec.js');
const coreOrgSessionSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-core-org-session-journey.spec.js');
const mainJourneySmokeSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-main-journey-smoke.spec.js');
const terminalMainJourneysSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-invite-reschedule-delete-end-main-journeys.spec.js');
const anonymousDisabledBrowserSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-anonymous-disabled-link.spec.js');
const ownerAbsenceBrowserSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-owner-absence-browser.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const seedRuntimeHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedRuntime.js');
const liveFixtureHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js');
const backendContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const anonymousDisabledBackendContract = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-disabled-link-contract.php');
const anonymousLoggedInRightsBackendContract = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-logged-in-rights-contract.php');
const coreOrgSessionBackendContract = readText('demo/video-chat/backend-king-php/tests/iam-core-org-session-journey-contract.php');
const activePermissionContract = readText('demo/video-chat/backend-king-php/tests/call-access-active-permission-change-contract.php');
const activeRemovalContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-active-removal-contract.php');
const invitedOrgRemovalContract = readText('demo/video-chat/backend-king-php/tests/call-access-invited-user-org-removal-contract.php');
const membershipStaleInviteRightsContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-stale-invite-rights-contract.php');
const guestListDirectJoinContract = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const smoke = readText('demo/video-chat/scripts/smoke.sh');
const auth = readText('demo/video-chat/backend-king-php/support/auth.php');
const authCache = readText('demo/video-chat/backend-king-php/support/auth_session_cache.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const realtimeCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeCallRoles = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_roles.php');

const scripts = packageJson.scripts || {};
const callAccessScript = String(scripts['test:e2e:call-access'] || '');
const matrixScript = String(scripts['test:e2e:matrix'] || '');

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
  /iam-call-access-e2e-foundation-contract\.mjs/,
  'package script must expose the IAM Call Access contract gate',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /call-access-duplicate-review-email-contract\.mjs/,
  'IAM Call Access contract gate must include duplicate-review/account-confirmation static proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /iam-king-participants-owner-timeout-contract\.mjs/,
  'IAM Call Access contract gate must include the owner-absence runtime/browser proof contract',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /\.\.\/backend-king-php\/tests\/call-access-membership-removal-contract\.sh/,
  'IAM Call Access contract gate must include the backend membership-removal proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /\.\.\/backend-king-php\/tests\/iam-core-org-session-journey-contract\.sh/,
  'IAM Call Access contract gate must include the backend core organization/account/session proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /call-access-email-confirmation-contract\.sh/,
  'IAM Call Access contract gate must include the backend email confirmation proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /call-access-anonymous-disabled-link-contract\.sh/,
  'IAM Call Access contract gate must include the disabled anonymous link backend proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /call-access-anonymous-logged-in-rights-contract\.sh/,
  'IAM Call Access contract gate must include logged-in anonymous-link org-admin and guest-list rights proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /\.\.\/backend-king-php\/tests\/call-access-invited-user-org-removal-contract\.sh/,
  'IAM Call Access contract gate must include the invited-user organization-removal proof',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
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
  /alpha_tenant_member_without_organization[\s\S]*organization_memberships[\s\S]*\[\][\s\S]*not_on_guest_list/s,
  'seed-matrix spec must prove a tenant member without organization receives no organization-based rights',
);
assert.match(
  seedMatrixSpec,
  /expectOpenLinkCreatesNoPersonalizedBinding[\s\S]*e2e_anon_logged_in_005[\s\S]*anonymous_open_logged_in_org_admin_own_org_direct/s,
  'seed-matrix spec must prove own-organization admins can use anonymous links without personalized binding',
);
assert.match(
  seedMatrixSpec,
  /lobby\/queue\/join[\s\S]*e2e_anon_logged_in_006[\s\S]*anonymous_open_logged_in_org_admin_foreign_org_lobby/s,
  'seed-matrix spec must prove foreign organization admins cannot direct-join through anonymous links',
);
assert.match(
  seedMatrixSpec,
  /expectOpenLinkDoesNotModifyGuestList[\s\S]*e2e_anon_logged_in_007[\s\S]*anonymous_open_logged_in_guest_list_user_direct/s,
  'seed-matrix spec must prove guest-list users can use anonymous links without guest-list mutation',
);
assert.match(
  seedMatrixHelper,
  /const boundUser = link\.link_kind === 'personal' \? targetUser : null[\s\S]*participant_user_id: boundUser\?\.id \|\| null/s,
  'seed helper must keep open anonymous link payloads free of personalized participant binding',
);
assert.match(
  coreOrgSessionSpec,
  /e2e_org_001-004[\s\S]*alpha_normal_user[\s\S]*normalUser\.organization_memberships[\s\S]*role: 'member'/,
  'core organization/session E2E spec must map organization creation, registration, and User role',
);
assert.match(
  coreOrgSessionSpec,
  /e2e_org_001-004[\s\S]*alpha_org_admin[\s\S]*organizationAdmin\.organization_memberships[\s\S]*role: 'admin'/,
  'core organization/session E2E spec must map organization Admin role',
);
assert.match(
  coreOrgSessionSpec,
  /e2e_org_007[\s\S]*session-state[\s\S]*auth_failed/s,
  'core organization/session E2E spec must prove logged-out browser state has no active account session',
);
assert.match(
  coreOrgSessionSpec,
  /logged-in account remains the active account[\s\S]*authorization[\s\S]*not\.toBe\(temporaryGuest\.id\)[\s\S]*account_type[\s\S]*account/,
  'core organization/session E2E spec must prove call links keep the logged-in registered account',
);
assert.match(
  coreOrgSessionSpec,
  /user without organization cannot receive organization-based call rights[\s\S]*organization_memberships[\s\S]*\[\][\s\S]*not_on_guest_list/s,
  'core organization/session E2E spec must prove users without organizations receive no org-based rights',
);
assert.match(
  coreOrgSessionBackendContract,
  /\/api\/admin\/users[\s\S]*\/api\/governance\/organizations[\s\S]*\/api\/auth\/login[\s\S]*\/api\/auth\/logout/s,
  'backend core organization/session contract must exercise governance, user registration, login, and logout routes',
);
assert.match(
  coreOrgSessionBackendContract,
  /videochat_user_is_organization_admin_for_call[\s\S]*tenant-only user without organization should be forbidden/s,
  'backend core organization/session contract must prove org-admin rights and no-org denial from server state',
);
assert.match(
  coreOrgSessionBackendContract,
  /verified_user_id[\s\S]*verified_session_id[\s\S]*opening a call link must not revoke the logged-in account session/s,
  'backend core organization/session contract must prove logged-in call-link opening preserves the account session',
);
assert.match(
  seedMatrixSpec,
  /removed_organization_memberships[\s\S]*manage_organizations[\s\S]*false[\s\S]*removedDirectJoin\.allowed\)\.toBe\(false\)/s,
  'seed-matrix spec must prove removed organization membership does not mint tenant or direct-join rights',
);
assert.match(
  mainJourneySmokeSpec,
  /e2e_journey_002_registered_logged_out_invitee_uses_temp_account/,
  'main journey smoke split must cover the registered logged-out personalized-link path from SPRINT section 32',
);
assert.match(
  mainJourneySmokeSpec,
  /registered_logged_out_personalized_uses_temporary_account[\s\S]*registered_account_user_key[\s\S]*account_type[\s\S]*guest[\s\S]*not\.toBe\(registeredAccount\.id\)/,
  'registered logged-out main journey must prove the temporary account is used instead of the existing registered account',
);
assert.match(
  mainJourneySmokeSpec,
  /sessionRequest\.headers\(\)\.authorization[\s\S]*toBe\(''\)[\s\S]*postDataJsonOrNull\(sessionRequest\)[\s\S]*toBeNull\(\)/,
  'registered logged-out main journey must prove no bearer or verified identity proof is sent',
);
assert.match(
  mainJourneySmokeSpec,
  /e2e_journey_003 logged-in own personalized link keeps the account through lobby admission/,
  'main journey smoke split must cover the logged-in own personalized-link path from SPRINT section 32',
);
assert.match(
  mainJourneySmokeSpec,
  /e2e_journey_010 logged-out anonymous link creates a least-privilege guest, admits, leaves, and rejoins/,
  'main journey smoke split must cover the logged-out anonymous lobby/admit/rejoin path from SPRINT section 32',
);
for (const namedTest of [
  'e2e_journey_020_invalidated_invite_link_denied',
  'e2e_journey_021_rescheduled_call_old_link_invalid_new_link_valid',
  'e2e_journey_022_deleted_call_revokes_all_temp_access',
  'e2e_journey_023_explicit_call_end_revokes_all_join_paths',
]) {
  assert.match(
    terminalMainJourneysSpec,
    new RegExp(`test\\('${namedTest}'`),
    `terminal main-journey spec must include ${namedTest}`,
  );
}
assert.match(
  terminalMainJourneysSpec,
  /oldSessionPostCount[\s\S]*toBe\(0\)[\s\S]*startPersonalizedLinkSession[\s\S]*viewerCanModerateCall[\s\S]*toBe\(false\)/s,
  'reschedule main journey must deny stale links and prove the new link uses current least-privilege permissions',
);
assert.match(
  terminalMainJourneysSpec,
  /call_deleted[\s\S]*workspace-call-view[\s\S]*toHaveCount\(0\)[\s\S]*deletedSessionPostCount[\s\S]*toBe\(0\)/s,
  'deleted-call main journey must prove temporary call access cannot rejoin and stale links do not issue sessions',
);
assert.match(
  terminalMainJourneysSpec,
  /call_lifecycle[\s\S]*status: 'ended'[\s\S]*owner_explicit_end[\s\S]*callStatus\)\.toBe\('ended'\)/s,
  'explicit-end main journey must prove participants receive an ended lifecycle state',
);
for (const namedTest of [
  'e2e_anon_logged_out_009_kicked_guest_cannot_direct_rejoin',
  'e2e_rejoin_004_kicked_temp_user_cannot_direct_rejoin',
  'e2e_rejoin_005_kick_overrides_previous_admission',
]) {
  assert.match(
    mainJourneySmokeSpec,
    new RegExp(`test\\('${namedTest}'`),
    `main journey smoke split must include ${namedTest}`,
  );
}
assert.match(
  mainJourneySmokeSpec,
  /kicked_requires_renewed_admission[\s\S]*expectDirectRejoinRequiresRenewedApproval/s,
  'main journey smoke split must prove kicked temporary users cannot direct-rejoin without renewed approval',
);
assert.match(
  anonymousDisabledBrowserSpec,
  /e2e_anon_logged_out_011_disabled_anonymous_link_allows_no_lobby_entry[\s\S]*call_access_not_found[\s\S]*lobbyFrames[\s\S]*toBe\(0\)/s,
  'disabled anonymous browser proof must show the link creates no lobby entry',
);
assert.match(
  anonymousDisabledBackendContract,
  /videochat_disable_anonymous_call_access_link[\s\S]*videochat_iam_anonymous_disabled_guest_participant_count[\s\S]*must not create a lobby participant/s,
  'backend proof must reject disabled anonymous links before temporary guest or lobby entry creation',
);
assert.match(
  ciGate,
  /call-access-anonymous-disabled-link-contract\.sh/,
  'IAM CI gate must include the disabled anonymous link backend proof',
);
assert.match(
  mainJourneySmokeSpec,
  /installCallAccessSeedRoutes[\s\S]*installCallAccessFakeRealtime/s,
  'main journey smoke split must compose the deterministic IAM seed routes with fake realtime admission',
);
assert.match(
  ownerAbsenceBrowserSpec,
  /e2e_journey_024_owner_absence_countdown_then_auto_end[\s\S]*owner_absent_timeout/s,
  'owner absence browser proof must cover countdown followed by automatic owner-absence end',
);
assert.match(
  ownerAbsenceBrowserSpec,
  /e2e_journey_025_owner_absence_countdown_then_reconnect_cancels_end[\s\S]*owner_present[\s\S]*active/s,
  'owner absence browser proof must cover owner return cancelling countdown while the call remains active',
);
assert.match(
  ownerAbsenceBrowserSpec,
  /realtime_owner_absence\.php[\s\S]*VIDEOCHAT_OWNER_ABSENCE_TIMER_MS[\s\S]*VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS/s,
  'owner absence browser proof must derive timer values from the backend realtime owner-absence contract',
);
assert.match(
  mainJourneySmokeSpec,
  /verified_user_id[\s\S]*verified_session_id[\s\S]*account_type[\s\S]*account/s,
  'main journey smoke split must prove own personalized links preserve the logged-in registered account',
);
assert.match(
  mainJourneySmokeSpec,
  /guest_name[\s\S]*account_type[\s\S]*guest[\s\S]*is_guest[\s\S]*true/s,
  'main journey smoke split must prove anonymous logged-out links create a temporary guest identity',
);
assert.match(
  mainJourneySmokeSpec,
  /platform_admin[\s\S]*false[\s\S]*tenant_admin[\s\S]*false[\s\S]*manage_lobby[\s\S]*false[\s\S]*admit_participants[\s\S]*false/s,
  'main journey smoke split must prove no IAM privilege escalation across the journeys',
);
assert.match(
  mainJourneySmokeSpec,
  /noMediaSecretPayload[\s\S]*expectNoForbiddenNeedles/s,
  'main journey smoke split must assert no media/auth secrets or foreign call data leak in the journeys',
);
assert.match(
  seedMatrixHelper,
  /VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON/,
  'seed-matrix helper must support compose smoke injection when contracts/v1 is outside the frontend container mount',
);
assert.match(
  seedMatrixHelper,
  /installCallAccessSeedRoutes/,
  'seed-matrix helper must expose deterministic IAM seed routes',
);
assert.match(
  seedRuntimeHelper,
  /installCallAccessFakeRealtime/,
  'seed runtime helper must expose fake realtime admission',
);

const liveFixtureModule = await import(pathToFileURL(path.join(
  repoRoot,
  'demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js',
)).href);

assert.equal(
  liveFixtureModule.iamCallAccessLiveFixtureContractVersion,
  'iam-call-access-live-fixtures.v1',
  'live fixture helper must expose a stable foundation contract version',
);
for (const scope of [
  'tenant_probe',
  'organization',
  'user',
  'role',
  'call',
  'access_link',
  'lobby_probe',
  'session_probe',
  'audit_probe',
]) {
  assert.ok(
    liveFixtureModule.iamCallAccessFixtureScopes.includes(scope),
    `live fixture helper must cover ${scope}`,
  );
}
assert.equal(
  liveFixtureModule.iamFixtureEmail('Alpha User', 'Run 42'),
  'iam-run-42-user-alpha-user@example.test',
  'live fixture user emails must be deterministic from run id and key',
);
assert.equal(
  liveFixtureModule.iamFixtureRoomId('Alpha Room', 'Run 42'),
  'iam-run-42-room-alpha-room',
  'live fixture room ids must be deterministic from run id and key',
);
assert.equal(
  liveFixtureModule.iamFixtureFingerprint('access-id'),
  'sha256:539b3ed57456a8c8822ef9152c97e409cdb1626e1731cc2bef9c96c1d82520d4',
  'audit probes must use the backend audit fingerprint shape',
);
const liveFactory = liveFixtureModule.createIamCallAccessFixtureFactory({
  runId: 'contract',
  fetchImpl: async () => {
    throw new Error('contract should not call the network');
  },
});
for (const method of [
  'login',
  'tenantProbe',
  'sessionProbe',
  'switchTenant',
  'ensureOrganization',
  'createRole',
  'createOrReuseUser',
  'updateUser',
  'createCall',
  'listCalls',
  'findCallByRoomId',
  'createOrReuseCall',
  'deleteCall',
  'createAccessLink',
  'resolveAccessLink',
  'startAccessSession',
  'lobbyProbe',
  'auditProbe',
  'cleanupCreated',
]) {
  assert.equal(typeof liveFactory[method], 'function', `live fixture factory must expose ${method}`);
}
const lobbyProbe = liveFixtureModule.callAccessLobbyProbe({
  origin: 'http://127.0.0.1:18080',
  session: { token: 'sess_contract' },
  call: { id: 'call-contract', room_id: 'room-contract' },
  targetUserId: 42,
});
assert.equal(lobbyProbe.websocket_url, 'ws://127.0.0.1:18080/ws?room=room-contract&call_id=call-contract&session=sess_contract');
assert.equal(lobbyProbe.frames.queue_join.type, 'lobby/queue/join');
assert.equal(lobbyProbe.frames.allow.target_user_id, 42);

const auditProbe = liveFixtureModule.callAccessAuditProbe({
  tenant: { id: 7 },
  call: { id: 'call-contract' },
  accessLink: { id: 'access-contract' },
  session: { token: 'sess_contract' },
  targetUser: { id: 42 },
});
assert.equal(auditProbe.filters.tenant_id, 7);
assert.equal(auditProbe.filters.call_id, 'call-contract');
assert.ok(auditProbe.expected_event_types.includes('call_access_link_opened'));
assert.ok(auditProbe.forbidden_payload_keys.includes('token'));
assert.doesNotMatch(
  liveFixtureHelper,
  /context\.route|route\.fulfill|FakeWebSocket|installCallAccessSeedRoutes/,
  'live fixture helper must not be a Playwright route-stub layer',
);
assert.doesNotMatch(
  liveFixtureHelper,
  /Math\.random|randomUUID|randomBytes|Date\.now/,
  'live fixture helper must remain deterministic and avoid wall-clock/random ids',
);
for (const endpoint of [
  '/api/auth/login',
  '/api/auth/session-state',
  '/api/tenants',
  '/api/auth/tenant',
  '/api/governance/organizations',
  '/api/governance/roles',
  '/api/admin/users',
  '/api/calls',
  '/access-link',
  '/api/call-access/',
  '/session',
]) {
  assert.ok(liveFixtureHelper.includes(endpoint), `live fixture helper must use ${endpoint}`);
}
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
