import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function serialized(value) {
  return JSON.stringify(value);
}

function assertRedactedSafePayload(payload, label, forbiddenNeedles) {
  const body = serialized(payload);
  for (const needle of forbiddenNeedles) {
    assert.doesNotMatch(body, new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'), `${label} must not expose ${needle}`);
  }
  assert.equal(payload.result?.call ?? null, null, `${label} must not include a call payload`);
  assert.equal(payload.result?.access_link ?? null, null, `${label} must not include an access-link payload`);
  assert.equal(payload.result?.target_user ?? null, null, `${label} must not include a target user`);
  assert.equal(payload.result?.redemption ?? null, null, `${label} must not include invite redemption data`);
  assert.equal(payload.result?.invite_code ?? null, null, `${label} must not include invite-code data`);
}

const accessRoutes = readRepo('demo/video-chat/backend-king-php/http/module_calls_access.php');
const accessPublic = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callRoutes = readRepo('demo/video-chat/backend-king-php/http/module_calls.php');
const inviteRoutes = readRepo('demo/video-chat/backend-king-php/http/module_invites.php');
const inviteCodes = readRepo('demo/video-chat/backend-king-php/domain/calls/invite_codes.php');
const joinView = readRepo('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const routeResolution = readRepo('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/routeResolution.ts');
const callAccessJoinSpec = readRepo('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const backendPrivacyContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php');
const inviteCopyContract = readRepo('demo/video-chat/backend-king-php/tests/invite-code-copy-boundary-contract.php');
const inviteRedeemContract = readRepo('demo/video-chat/backend-king-php/tests/invite-code-redeem-endpoint-contract.php');
const callCreateEndpointContract = readRepo('demo/video-chat/backend-king-php/tests/call-create-endpoint-contract.php');

assert.match(
  accessRoutes,
  /preg_match\('#\^\/api\/call-access\/\(\[A-Fa-f0-9-\]\{36\}\)\/join\$#'/,
  'public join route must only dispatch UUID-shaped call-access ids',
);
assert.match(
  accessRoutes,
  /preg_match\('#\^\/api\/call-access\/\(\[A-Fa-f0-9-\]\{36\}\)\/session\$#'/,
  'public session route must only dispatch UUID-shaped call-access ids',
);
assert.match(
  accessPublic,
  /if \(\$normalizedAccessId === ''\)[\s\S]*'reason' => 'validation_failed'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'target_hint' => \['participant_email' => null\]/,
  'domain public access resolution must redact invalid call-access ids',
);
assert.match(
  accessPublic,
  /if \(!is_array\(\$accessLink\)\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'target_hint' => \['participant_email' => null\]/,
  'domain public access resolution must redact forged call-access ids',
);
assert.match(
  accessRoutes,
  /if \(\$reason === 'not_found'\)[\s\S]*return \$errorResponse\(404,\s*'call_access_not_found',\s*'Call access link does not exist\.'\);/,
  'public join and authenticated access routes must return safe not-found without echoing the forged access id',
);
assert.match(
  accessRoutes,
  /if \(\$reason === 'expired'\)[\s\S]*return \$errorResponse\(410,\s*'call_access_expired',\s*'Call access link has expired\.'\);/,
  'expired forged or stale access ids must not be echoed in API details',
);
assert.doesNotMatch(
  accessRoutes,
  /'access_id'\s*=>\s*strtolower\(trim\(\$accessId\)\)/,
  'call-access route errors must not echo guessed access ids',
);

assert.match(
  inviteRoutes,
  /preg_match\('#\^\/api\/invite-codes\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/copy\$#'/,
  'invite copy route must constrain forged invite id shape before dispatch',
);
assert.match(
  inviteRoutes,
  /if \(\$copyReason === 'not_found'\)[\s\S]*return \$errorResponse\(404,\s*'invite_codes_not_found'[\s\S]*'fields' => is_array\(\$copyResult\['errors'\]/,
  'forged invite copy ids must return a stable not-found code with only field metadata',
);
assert.match(
  inviteCodes,
  /if \(!is_array\(\$invite\)\)[\s\S]*'reason' => 'not_found'[\s\S]*'errors' => \['invite_code' => 'invite_code_not_found'\][\s\S]*'invite_code' => null[\s\S]*'copy' => null/,
  'forged invite ids must not return invite previews or copy payloads',
);
assert.match(
  inviteRoutes,
  /if \(\$redeemReason === 'not_found'\)[\s\S]*return \$errorResponse\(404,\s*'invite_codes_redeem_not_found'[\s\S]*'fields' => is_array\(\$redeemResult\['errors'\]/,
  'forged invite redeem codes must return a stable not-found code with only field metadata',
);
assert.match(
  inviteCodes,
  /if \(!is_array\(\$invite\)\)[\s\S]*'reason' => 'not_found'[\s\S]*'errors' => \['code' => 'invite_code_not_found'\][\s\S]*'redemption' => null/,
  'forged invite redeem codes must not return invite redemption data',
);

assert.match(
  callRoutes,
  /preg_match\('#\^\/api\/calls\/resolve\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\$#'/,
  'direct call resolve route must constrain forged call refs before dispatch',
);
assert.match(
  callRoutes,
  /return \$jsonResponse\(200,\s*\[[\s\S]*'state' => 'not_found'[\s\S]*'resolved_as' => ''[\s\S]*'reason' => 'route_call_ref_not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null/,
  'forged call ids must resolve to a redacted browser-safe not-found envelope',
);
assert.match(
  callCreateEndpointContract,
  /resolve-missing must not emit HTTP 404[\s\S]*resolve-missing state mismatch/,
  'backend endpoint contract must prove forged call ids return the browser-safe not-found state',
);

assert.match(
  joinView,
  /if \(!CALL_UUID_PATTERN\.test\(accessId\)\)[\s\S]*resetJoinContextDetails\(\)[\s\S]*call_access_validation_failed/,
  'public join UI must reject malformed call-access ids before any backend request',
);
assert.match(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\) \{[\s\S]*payload = payload && typeof payload === 'object'[\s\S]*\? payload[\s\S]*: \{ error: \{ code: 'call_access_validation_failed' \} \};[\s\S]*state\.contextError = localizedApiErrorMessage/,
  'public join UI must preserve forged-id backend stable codes before rendering safe localized copy',
);
assert.match(
  callAccessJoinSpec,
  /invalid call-access link renders safe state without foreign call data[\s\S]*Private Foreign Call[\s\S]*not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*toHaveCount\(0\)/,
  'browser E2E must prove forged call-access ids render no foreign call/person data and no join action',
);
assert.match(
  routeResolution,
  /if \(looksLikeUuid\) \{[\s\S]*error: isExpired \? 'route_call_access_expired' : 'route_call_ref_not_found'[\s\S]*fallbackRouteName[\s\S]*refs\.router\.replace/,
  'workspace UI must route forged UUID call ids to a safe dashboard fallback instead of entering a room',
);
assert.match(
  routeResolution,
  /accessId: isExpired \? normalized\.toLowerCase\(\) : ''[\s\S]*callId: ''[\s\S]*roomId: 'lobby'/,
  'workspace UI must not persist forged call ids as resolved access or call identifiers',
);

assert.match(
  backendPrivacyContract,
  /guessedAccessId = '11111111-1111-4111-8111-111111111111'[\s\S]*guessed join should return 404[\s\S]*guessed join response/,
  'backend privacy contract must prove guessed call-access ids return redacted 404 payloads',
);
assert.match(
  inviteCopyContract,
  /\/api\/invite-codes\/00000000-0000-4000-8000-000000000000\/copy[\s\S]*missing copy status should be 404/,
  'backend invite copy contract must prove forged invite ids fail closed',
);
assert.match(
  inviteRedeemContract,
  /unknownCodeResponse[\s\S]*invite_codes_redeem_not_found/,
  'backend invite redeem contract must prove forged invite codes fail closed',
);

const forbiddenNeedles = [
  'private-forged-call-id',
  'Private Forged Call',
  'foreign-forged@example.invalid',
  '11111111-1111-4111-8111-111111111111',
  '00000000-0000-4000-8000-000000000000',
  'invite-secret-forged-code',
];
assertRedactedSafePayload({
  status: 'ok',
  result: {
    state: 'not_found',
    resolved_as: '',
    reason: 'route_call_ref_not_found',
    access_link: null,
    call: null,
  },
}, 'forged call-id resolve envelope', forbiddenNeedles);
assertRedactedSafePayload({
  status: 'error',
  error: {
    code: 'call_access_not_found',
    message: 'Call access link does not exist.',
  },
}, 'forged call-access envelope', forbiddenNeedles);
assertRedactedSafePayload({
  status: 'error',
  error: {
    code: 'invite_codes_not_found',
    message: 'Invite code does not exist.',
    details: { fields: { invite_code: 'invite_code_not_found' } },
  },
}, 'forged invite-id envelope', forbiddenNeedles);

process.stdout.write('[call-access-forged-identifiers-contract] PASS\n');
