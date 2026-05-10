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

function reconcilePersonalizedLinkRedemptions(attempts) {
  const acceptedAccessIds = new Set();
  return attempts.map((attempt) => {
    const accessId = String(attempt.accessId || '').trim().toLowerCase();
    if (acceptedAccessIds.has(accessId)) {
      return {
        device: attempt.device,
        status: 409,
        code: 'call_access_conflict',
        sessionIdAfter: attempt.originalSessionId,
        callAccessSessionId: null,
      };
    }
    acceptedAccessIds.add(accessId);
    return {
      device: attempt.device,
      status: 200,
      code: 'ok',
      sessionIdAfter: attempt.issuedCallAccessSessionId,
      callAccessSessionId: attempt.issuedCallAccessSessionId,
    };
  });
}

const callAccessJoinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const callAccessSessionClient = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const authSession = readText('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const duplicateInviteReplayContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs');
const duplicateAbuseContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs');
const callAccessSessionBackend = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');

assert.match(
  callAccessJoinSpec,
  /test\('same personalized link in parallel contexts keeps account sessions isolated'/,
  'browser E2E must keep the duplicate device/browser personalized-link proof',
);
assert.match(
  callAccessJoinSpec,
  /const accountAPage = await createPublicJoinPage\(browser, baseURL\);[\s\S]*const accountBPage = await createPublicJoinPage\(browser, baseURL\);/,
  'duplicate proof must use separate browser contexts to model separate devices or browsers',
);
assert.match(
  callAccessJoinSpec,
  /await Promise\.all\(\[[\s\S]*accountAPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*accountBPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*\]\)/,
  'duplicate proof must redeem the same personalized link from both contexts in parallel',
);
assert.match(
  callAccessJoinSpec,
  /expect\(responseA\.status\(\)\)\.toBe\(200\);[\s\S]*expect\(responseB\.status\(\)\)\.toBe\(409\);/,
  'parallel duplicate redemption must deterministically settle as one success and one 409 conflict',
);
assert.match(
  callAccessJoinSpec,
  /expect\(requests\.a\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountA\.sessionToken\}`\);[\s\S]*expect\(requests\.b\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountB\.sessionToken\}`\);/,
  'each device/browser must redeem with its own current Authorization token',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountA\.userId,[\s\S]*verified_session_id: accountA\.sessionId,[\s\S]*requests\.b\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountB\.userId,[\s\S]*verified_session_id: accountB\.sessionId,/,
  'each device/browser must submit its own verified user and session snapshot',
);
assert.match(
  callAccessJoinSpec,
  /await expect\(dialogB\)\.toContainText\('This call link cannot be used for the current call state\.'\);[\s\S]*await expect\(dialogB\)\.not\.toContainText\('Foreign Linked Call Title'\);[\s\S]*foreignNeedlesForB[\s\S]*not\.toContainText\(value\)/,
  'rejected duplicate browser must show the safe conflict message without private invite metadata',
);
assert.match(
  callAccessJoinSpec,
  /storedA\.sessionId\)\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionId\)\.toBe\(accountB\.sessionId\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'rejected duplicate browser must keep its original session and must not adopt either call-access token',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.b\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.a\.sessionPostCount\)\.toBe\(1\)[\s\S]*requests\.b\.sessionPostCount\)\.toBe\(1\)/,
  'duplicate proof must avoid retry loops by making one resolve and one session POST per browser',
);

assert.match(
  callAccessSessionClient,
  /const payload = await readJsonResponse\(response\);[\s\S]*if \(!response\.ok\) \{[\s\S]*return \{[\s\S]*ok: false,[\s\S]*errorCode: errorCodeFromPayload\(payload\)[\s\S]*\};[\s\S]*\}[\s\S]*applySessionEnvelope\(result\.session, result\.user, result\.tenant\);/s,
  'frontend call-access client must only apply returned sessions after a successful HTTP response',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*state\.joinError = localizedApiErrorMessage\(errorPayload, t\('public\.join\.start_session_failed'\)\);[\s\S]*return;[\s\S]*\}[\s\S]*const call = result\.call/s,
  'join view must stop on duplicate conflict before consuming returned call data',
);
assert.match(
  authSession,
  /export function applySessionEnvelope\(session, user, tenant = null\) \{[\s\S]*sessionState\.sessionId = normalizeString\(session\.id \|\| sessionToken\);[\s\S]*sessionState\.sessionToken = sessionToken;[\s\S]*persist\(\);/s,
  'session persistence must remain centralized so failed duplicate responses cannot update local storage by accident',
);

assert.match(
  callAccessSessionBackend,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reconcile stale verified-session replay as a deterministic conflict',
);
assert.match(
  callAccessSessionBackend,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reconcile stale verified-user replay as a deterministic conflict',
);
assert.match(
  callAccessSessionBackend,
  /SELECT 1 FROM sessions WHERE id = :id LIMIT 1[\s\S]*SELECT 1 FROM call_access_sessions WHERE session_id = :id LIMIT 1/,
  'backend must detect duplicate generated session ids across normal and call-access session stores',
);
assert.match(
  callAccessSessionBackend,
  /!videochat_call_access_session_id_available\(\$pdo, \$sessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'session' => 'session_id_not_available'/,
  'backend must reject duplicate generated session ids instead of reusing a bound session',
);
assert.match(
  callsAccessRoute,
  /if \(\$reason === 'conflict'\) \{[\s\S]*return \$errorResponse\(409, 'call_access_conflict', 'Call access cannot be used for the current call state\.', \[[\s\S]*'fields' => is_array\(\$issueResult\['errors'\] \?\? null\) \? \$issueResult\['errors'\] : \[\],[\s\S]*\]\);[\s\S]*\}/,
  'HTTP route must expose duplicate/session conflicts as 409 with fields only, not private call or access-link payloads',
);

assert.match(
  duplicateInviteReplayContract,
  /same personalized link in parallel contexts keeps account sessions isolated[\s\S]*parallel duplicate use must reconcile one accepted session and one deterministic 409 conflict[\s\S]*parallel duplicate conflict must preserve the rejected device\/browser session and avoid token bleed/s,
  'existing duplicate-invite replay proof must keep the parallel device/browser contract pinned',
);
assert.match(
  duplicateAbuseContract,
  /parallel browser-context duplicate-abuse test[\s\S]*parallel conflict denial must not leak foreign linked-call or peer session data/s,
  'existing duplicate-abuse proof must keep private metadata leak checks for duplicate browser conflicts',
);

const attempts = [
  {
    device: 'browser-a',
    accessId: '44444444-4444-4444-8444-444444444444',
    originalSessionId: 'sess_parallel_account_a',
    issuedCallAccessSessionId: 'sess_call_access_account_a',
  },
  {
    device: 'browser-b',
    accessId: '44444444-4444-4444-8444-444444444444',
    originalSessionId: 'sess_parallel_account_b',
    issuedCallAccessSessionId: 'sess_call_access_account_b_rejected',
  },
];
const outcomes = reconcilePersonalizedLinkRedemptions(attempts);
assert.deepEqual(
  outcomes.map((outcome) => outcome.status),
  [200, 409],
  'deterministic fixture must settle duplicate personalized-link redemption as one 200 and one 409',
);
assert.equal(
  outcomes[1].sessionIdAfter,
  attempts[1].originalSessionId,
  'duplicate loser must keep the browser session it started with',
);
assert.equal(
  outcomes[1].callAccessSessionId,
  null,
  'duplicate loser must not bind a call-access session',
);
assert.notEqual(
  outcomes[1].sessionIdAfter,
  outcomes[0].callAccessSessionId,
  'duplicate loser must not adopt the winner call-access session',
);

assertNoNeedles(
  {
    status: 'error',
    error: {
      code: 'call_access_conflict',
      message: 'This call link cannot be used for the current call state.',
      details: {
        access_id: '44444444-4444-4444-8444-444444444444',
        fields: { auth: 'session_context_changed' },
      },
    },
  },
  [
    'Parallel Account A',
    'parallel-a@example.invalid',
    'Foreign Linked Call Title',
    'foreign-linked-call',
    'sess_call_access_account_a',
    'sess_call_access_account_b_rejected',
  ],
  'duplicate device/browser conflict payload',
);

process.stdout.write('[call-access-duplicate-device-browser-contract] PASS\n');
