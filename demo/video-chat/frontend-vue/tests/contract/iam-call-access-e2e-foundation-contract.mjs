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
const e2eSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const mainJourneySmokeSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-main-journey-smoke.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const liveFixtureHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js');
const backendContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const smoke = readText('demo/video-chat/scripts/smoke.sh');
const auth = readText('demo/video-chat/backend-king-php/support/auth.php');
const authCache = readText('demo/video-chat/backend-king-php/support/auth_session_cache.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');

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
  /\.\.\/backend-king-php\/tests\/call-access-membership-removal-contract\.sh/,
  'IAM Call Access contract gate must include the backend membership-removal proof',
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
assert.match(
  mainJourneySmokeSpec,
  /installCallAccessSeedRoutes[\s\S]*installCallAccessFakeRealtime/s,
  'main journey smoke split must compose the deterministic IAM seed routes with fake realtime admission',
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

process.stdout.write('[iam-call-access-e2e-foundation-contract] PASS\n');
