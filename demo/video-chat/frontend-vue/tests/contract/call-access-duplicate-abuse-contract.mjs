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

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function assertContains(source, literal, message) {
  assert.match(source, new RegExp(escapeRegExp(literal)), message);
}

const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const inviteCodes = readText('demo/video-chat/backend-king-php/domain/calls/invite_codes.php');
const inviteRedeemEndpointContract = readText('demo/video-chat/backend-king-php/tests/invite-code-redeem-endpoint-contract.php');

const staleVerifiedContextTest = 'login switch after verified call-access link fails without rebinding or leaking foreign data';
const parallelContextTest = 'same personalized link in parallel contexts keeps account sessions isolated';

assertContains(
  callAccessJoinSpec,
  `test('${staleVerifiedContextTest}'`,
  'E2E coverage must keep the stale verified-context duplicate-session test',
);
assertContains(
  callAccessJoinSpec,
  `test('${parallelContextTest}'`,
  'E2E coverage must keep the parallel browser-context duplicate-abuse test',
);

assert.match(
  callAccessJoinSpec,
  /sessionResponse\.status\(\)\)\.toBe\(409\)[\s\S]*sessionPayload\?\.error\?\.code\)\.toBe\('call_access_conflict'\)/,
  'stale verified-context join must be rejected as HTTP 409 call_access_conflict',
);
assert.match(
  callAccessJoinSpec,
  /expect\(sessionPostCount\)\.toBe\(1\)[\s\S]*expect\(sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)[\s\S]*verified_user_id: 2,[\s\S]*verified_session_id: verifiedSession\.sessionId/s,
  'stale verified-context join must make one POST with current auth and original verified context',
);
assert.match(
  callAccessJoinSpec,
  /storedSession\.sessionId\)\.toBe\(switchedSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(switchedSession\.sessionToken\)[\s\S]*not\.toBe\(rejectedCallAccessToken\)/,
  'rejected stale verified-context session must not overwrite the current browser session',
);
assert.match(
  callAccessJoinSpec,
  /not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'stale verified-context denial must not render foreign call, person, or rejected token data',
);

assert.match(
  callAccessJoinSpec,
  /const accountAPage = await createPublicJoinPage\(browser, baseURL\);[\s\S]*const accountBPage = await createPublicJoinPage\(browser, baseURL\);/,
  'parallel abuse proof must use separate browser contexts for separate devices/browsers',
);
assert.match(
  callAccessJoinSpec,
  /await Promise\.all\(\[[\s\S]*accountAPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*accountBPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*\]\)/,
  'parallel abuse proof must exercise simultaneous use of the same personalized link',
);
assert.match(
  callAccessJoinSpec,
  /expect\(responseA\.status\(\)\)\.toBe\(200\);[\s\S]*expect\(responseB\.status\(\)\)\.toBe\(409\);/,
  'parallel duplicate use must deterministically reconcile one accepted session and one 409 conflict',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountA\.userId,[\s\S]*verified_session_id: accountA\.sessionId,[\s\S]*requests\.b\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountB\.userId,[\s\S]*verified_session_id: accountB\.sessionId,/,
  'parallel contexts must each send their own verified user/session context',
);
assert.match(
  callAccessJoinSpec,
  /storedA\.sessionId\)\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionId\)\.toBe\(accountB\.sessionId\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'parallel conflict must preserve the rejected browser session and avoid cross-device token bleed',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.b\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.a\.sessionPostCount\)\.toBe\(1\)[\s\S]*requests\.b\.sessionPostCount\)\.toBe\(1\)/,
  'parallel proof must stay deterministic: exactly one resolve and one session POST per context',
);
assert.match(
  callAccessJoinSpec,
  /not\.toContainText\('Foreign Linked Call Title'\)[\s\S]*foreignNeedlesForB[\s\S]*not\.toContainText\(value\)/,
  'parallel conflict denial must not leak foreign linked-call or peer session data',
);

assert.match(
  callAccessSession,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reject verified-session/authenticated-session drift as a conflict',
);
assert.match(
  callAccessSession,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reject verified-user/authenticated-user drift as a conflict',
);
assert.match(
  callAccessSession,
  /SELECT 1 FROM sessions WHERE id = :id LIMIT 1[\s\S]*SELECT 1 FROM call_access_sessions WHERE session_id = :id LIMIT 1/,
  'backend call-access session ids must be unavailable if seen in either sessions or call_access_sessions',
);
assert.match(
  callAccessSession,
  /!videochat_call_access_session_id_available\(\$pdo, \$sessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'session' => 'session_id_not_available'/,
  'backend must reject duplicate generated session ids instead of reusing them',
);
assert.match(
  callsAccessRoute,
  /if \(\$reason === 'conflict'\)[\s\S]*return \$errorResponse\(409, 'call_access_conflict'/,
  'HTTP route must surface duplicate/session conflicts as 409 call_access_conflict',
);

assert.match(
  inviteCodes,
  /redemption_count < max_redemptions/,
  'invite redemption update must preserve an atomic redemption cap',
);
assert.match(
  inviteCodes,
  /\$update->rowCount\(\) !== 1[\s\S]*'reason' => 'exhausted'[\s\S]*'invite_code_redemption_limit_reached'/,
  'duplicate invite redemption race must reconcile as exhausted when no row is updated',
);
assert.match(
  inviteRedeemEndpointContract,
  /roomRedeemAgain[\s\S]*status'\] \?\? 0\) === 409[\s\S]*invite_codes_redeem_exhausted/,
  'backend endpoint proof must reject duplicate invite redemption with 409 invite_codes_redeem_exhausted',
);

process.stdout.write('[call-access-duplicate-abuse-contract] PASS\n');
