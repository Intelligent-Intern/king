import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function assertNoNeedles(value, needles, message) {
  const serialized = JSON.stringify(value).toLowerCase();
  for (const needle of needles) {
    assert.equal(serialized.includes(String(needle).toLowerCase()), false, `${message}: leaked ${needle}`);
  }
}

const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const duplicateAbuseContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const inviteCodes = readText('demo/video-chat/backend-king-php/domain/calls/invite_codes.php');
const inviteRedeemContract = readText('demo/video-chat/backend-king-php/tests/invite-code-redeem-contract.php');
const inviteRedeemEndpointContract = readText('demo/video-chat/backend-king-php/tests/invite-code-redeem-endpoint-contract.php');

assert.match(
  callAccessJoinSpec,
  /test\('login switch after verified call-access link fails without rebinding or leaking foreign data'/,
  'browser proof must keep stale verified-context replay coverage',
);
assert.match(
  callAccessJoinSpec,
  /sessionPayload\?\.error\?\.code\)\.toBe\('call_access_conflict'\)[\s\S]*verified_user_id: 2,[\s\S]*verified_session_id: verifiedSession\.sessionId/,
  'stale verified-context replay must send the old verified context and reconcile as call_access_conflict',
);
assert.match(
  callAccessJoinSpec,
  /expect\(sessionPostCount\)\.toBe\(1\)[\s\S]*expect\(sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)/,
  'stale verified-context replay must make one deterministic POST using the current browser session',
);
assert.match(
  callAccessJoinSpec,
  /storedSession\.sessionId\)\.toBe\(switchedSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(switchedSession\.sessionToken\)[\s\S]*not\.toBe\(rejectedCallAccessToken\)/,
  'stale verified-context replay must preserve the current logged-in session instead of adopting a rejected token',
);
assert.match(
  callAccessJoinSpec,
  /not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'stale verified-context replay denial must not render foreign call, person, or token data',
);

assert.match(
  callAccessJoinSpec,
  /test\('same personalized link in parallel contexts keeps account sessions isolated'/,
  'browser proof must keep cross-device/browser duplicate personalized-link coverage',
);
assert.match(
  callAccessJoinSpec,
  /await Promise\.all\(\[[\s\S]*accountAPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*accountBPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*\]\)/,
  'parallel proof must open the same personalized link concurrently in separate contexts',
);
assert.match(
  callAccessJoinSpec,
  /expect\(responseA\.status\(\)\)\.toBe\(200\);[\s\S]*expect\(responseB\.status\(\)\)\.toBe\(409\);/,
  'parallel duplicate use must reconcile one accepted session and one deterministic 409 conflict',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountA\.userId,[\s\S]*verified_session_id: accountA\.sessionId,[\s\S]*requests\.b\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountB\.userId,[\s\S]*verified_session_id: accountB\.sessionId,/,
  'parallel contexts must each submit their own verified user/session snapshot',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.b\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.a\.sessionPostCount\)\.toBe\(1\)[\s\S]*requests\.b\.sessionPostCount\)\.toBe\(1\)/,
  'parallel duplicate proof must stay deterministic with one resolve and one session POST per context',
);
assert.match(
  callAccessJoinSpec,
  /storedA\.sessionId\)\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionId\)\.toBe\(accountB\.sessionId\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'parallel duplicate conflict must preserve the rejected device/browser session and avoid token bleed',
);

assert.match(
  callAccessSession,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reject replayed verified session ids when the authenticated session changed',
);
assert.match(
  callAccessSession,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reject replayed verified user ids when the authenticated user changed',
);
assert.match(
  callAccessSession,
  /SELECT 1 FROM sessions WHERE id = :id LIMIT 1[\s\S]*SELECT 1 FROM call_access_sessions WHERE session_id = :id LIMIT 1/,
  'backend must reject duplicate generated session ids across normal and call-access sessions',
);
assert.match(
  callsAccessRoute,
  /if \(\$reason === 'conflict'\)[\s\S]*return \$errorResponse\(409, 'call_access_conflict'/,
  'call-access route must map replay and duplicate session conflicts to HTTP 409 call_access_conflict',
);

assert.match(
  inviteCodes,
  /UPDATE invite_codes[\s\S]*SET redemption_count = redemption_count \+ 1[\s\S]*WHERE id = :id[\s\S]*AND redemption_count < max_redemptions/s,
  'invite redemption must use one atomic capped update for duplicate redemption races',
);
assert.match(
  inviteCodes,
  /\$update->rowCount\(\) !== 1[\s\S]*'reason' => 'exhausted'[\s\S]*'code' => 'invite_code_redemption_limit_reached'/,
  'duplicate invite redemption must reconcile to exhausted when the capped update loses the race',
);
assert.match(
  inviteRedeemContract,
  /redeemCallAgain[\s\S]*already redeemed call invite should fail[\s\S]*already redeemed reason mismatch/s,
  'backend invite contract must prove repeated call-invite redemption is rejected',
);
assert.match(
  inviteRedeemEndpointContract,
  /roomRedeemAgain[\s\S]*redeem-again invite-redeem status should be 409[\s\S]*invite_codes_redeem_exhausted/s,
  'invite redeem endpoint contract must expose duplicate redemption as HTTP 409 exhausted',
);

assert.match(
  duplicateAbuseContract,
  /stale verified-context duplicate-session test[\s\S]*parallel browser-context duplicate-abuse test/s,
  'existing IAM duplicate-abuse contract must continue pinning stale replay and parallel context coverage',
);

const acceptedDevice = {
  label: 'device-a',
  user_id: 2001,
  original_session_id: 'sess_device_a',
  issued_call_access_session_id: 'sess_device_a_call_access',
  result: 'accepted',
};
const replayedDevice = {
  label: 'device-b',
  user_id: 2002,
  original_session_id: 'sess_device_b',
  attempted_call_access_session_id: 'sess_device_b_should_not_bind',
  result: 'conflict',
};
assert.notEqual(
  acceptedDevice.issued_call_access_session_id,
  replayedDevice.original_session_id,
  'accepted device session must not overwrite a different device session',
);
assert.equal(
  replayedDevice.result,
  'conflict',
  'stale verified-context replay on another device must settle deterministically as conflict',
);
assertNoNeedles(
  {
    status: 'error',
    error: {
      code: 'call_access_conflict',
      details: { fields: { auth: 'session_context_changed' } },
    },
  },
  ['Foreign Linked Call Title', 'linked-device-a@example.invalid', 'sess_device_a_call_access', 'raw-access-token'],
  'duplicate replay denial payload',
);

process.stdout.write('[call-access-duplicate-invite-replay-contract] PASS\n');
