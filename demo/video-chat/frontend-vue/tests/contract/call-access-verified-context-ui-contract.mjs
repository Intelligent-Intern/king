import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const admissionGate = read('src/domain/calls/access/admissionGate.ts');
const callAccessSession = read('src/domain/calls/access/callAccessSession.ts');
const joinView = read('src/domain/calls/access/JoinView.vue');
const authSession = read('src/domain/auth/session.ts');
const callAccessJoinSpec = read('tests/e2e/call-access-join.spec.js');

assert.match(
  admissionGate,
  /export function callAccessVerifiedContextFromSession\([\s\S]*userId[\s\S]*sessionId[\s\S]*sessionToken[\s\S]*return null[\s\S]*return \{\s*userId,\s*sessionId,\s*sessionToken,\s*\}/,
  'admission gate must expose a stable verified user/session snapshot helper',
);

assert.match(
  joinView,
  /verifiedAccessContext:\s*null/,
  'public join state must carry a stable verified context snapshot',
);
assert.match(
  joinView,
  /state\.verifiedAccessContext\s*=\s*callAccessVerifiedContextFromSession\(sessionState\)/,
  'public join must capture the logged-in context after link verification',
);
assert.match(
  joinView,
  /loginWithCallAccess\(accessId,\s*\{[\s\S]*verifiedContext:\s*state\.verifiedAccessContext[\s\S]*\}\)/,
  'public join must pass the verified context into call-access session issuance',
);

assert.match(
  callAccessSession,
  /body\.verified_user_id\s*=\s*verifiedContext\.userId/,
  'call-access session request body must include verified_user_id',
);
assert.match(
  callAccessSession,
  /body\.verified_session_id\s*=\s*verifiedContext\.sessionId/,
  'call-access session request body must include verified_session_id',
);
assert.match(
  callAccessSession,
  /headers\.authorization\s*=\s*`Bearer \$\{token\}`/,
  'call-access session request must send the current session token when present',
);
assert.match(
  callAccessSession,
  /verifiedContext[\s\S]*sessionState\.sessionToken[\s\S]*status:\s*409[\s\S]*errorCode:\s*'call_access_conflict'/,
  'call-access session request must fail safely if verified context exists after local logout',
);
assert.match(
  authSession,
  /export function applySessionEnvelope\(/,
  'auth session envelope application must remain shared after extracting call-access login',
);
assert.doesNotMatch(
  authSession,
  /export async function loginWithCallAccess/,
  'call-access login request logic belongs with public join/access helpers',
);

assert.match(
  callAccessJoinSpec,
  /login switch after verified call-access link fails without rebinding or leaking foreign data/,
  'public join E2E must cover login switch after verified link context',
);
assert.match(
  callAccessJoinSpec,
  /sessionRequestAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)/,
  'login-switch E2E must prove session issuance uses the current bearer token',
);
assert.match(
  callAccessJoinSpec,
  /sessionRequestBody\)\.toEqual\(\{\s*verified_user_id:\s*2,\s*verified_session_id:\s*verifiedSession\.sessionId,\s*\}\)/,
  'login-switch E2E must prove the verified user/session snapshot is sent',
);
assert.match(
  callAccessJoinSpec,
  /expect\(sessionPayload\?\.error\?\.code\)\.toBe\('call_access_conflict'\)/,
  'login-switch E2E must require a safe conflict from the route guard',
);
assert.match(
  callAccessJoinSpec,
  /not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'login-switch E2E must prove foreign call/person/session details are not rendered',
);
assert.match(
  callAccessJoinSpec,
  /storedSession\.sessionId\)\.toBe\(switchedSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(switchedSession\.sessionToken\)[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(rejectedCallAccessToken\)/,
  'login-switch E2E must prove the failed call-access response does not bind a new session',
);
assert.match(
  callAccessJoinSpec,
  /expect\(joinGetCount\)\.toBe\(1\)[\s\S]*expect\(sessionPostCount\)\.toBe\(1\)/,
  'login-switch E2E must guard against reload or duplicate session POST loops',
);
assert.match(
  callAccessJoinSpec,
  /logout during verified call-access link context fails closed without leaking or joining/,
  'public join E2E must cover logout after verified link context',
);
assert.match(
  callAccessJoinSpec,
  /const \{ logoutSession, sessionState \} = await import\('\/src\/domain\/auth\/session\.ts'\);[\s\S]*await logoutSession\(\)/,
  'logout E2E must exercise the real browser logout/session-clear path',
);
assert.match(
  callAccessJoinSpec,
  /expect\(sessionPostCount\)\.toBe\(0\)/,
  'logout E2E must prove no call-access session request is issued after verified context is logged out',
);
assert.match(
  callAccessJoinSpec,
  /expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'logout E2E must prove the browser does not enter the workspace',
);
assert.match(
  callAccessJoinSpec,
  /logout denial must not render \$\{value\}/,
  'logout E2E must prove foreign call/invite/host/session data is not rendered',
);
assert.match(
  callAccessJoinSpec,
  /storedSession\.sessionToken \|\| ''\)\.toBe\(''\)[\s\S]*JSON\.stringify\(storedSession\)\)\.not\.toContain\(rejectedSessionToken\)/,
  'logout E2E must prove no foreign call-access session is adopted',
);

assert.match(
  callAccessJoinSpec,
  /same personalized link in parallel contexts keeps account sessions isolated/,
  'public join E2E must cover parallel use of one personalized link by two different logged-in accounts',
);
assert.match(
  callAccessJoinSpec,
  /createPublicJoinPage\(browser, baseURL\)[\s\S]*createPublicJoinPage\(browser, baseURL\)[\s\S]*Promise\.all\(\[[\s\S]*page\.goto\(`\/join\/\$\{accessId\}`\)[\s\S]*page\.goto\(`\/join\/\$\{accessId\}`\)/,
  'parallel-account E2E must use separate browser contexts opening the same personalized link concurrently',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountA\.sessionToken\}`\)[\s\S]*requests\.b\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountB\.sessionToken\}`\)/,
  'parallel-account E2E must prove each session POST uses its own current bearer token',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.sessionBody\)\.toEqual\(\{\s*verified_user_id:\s*accountA\.userId,\s*verified_session_id:\s*accountA\.sessionId,\s*\}\)[\s\S]*requests\.b\.sessionBody\)\.toEqual\(\{\s*verified_user_id:\s*accountB\.userId,\s*verified_session_id:\s*accountB\.sessionId,\s*\}\)/,
  'parallel-account E2E must prove verified link contexts are not crossed between accounts',
);
assert.match(
  callAccessJoinSpec,
  /storedA\.sessionToken\)\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.toBe\(accountB\.sessionToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'parallel-account E2E must prove localStorage/session state remains isolated after mixed success and conflict responses',
);
assert.match(
  callAccessJoinSpec,
  /dialogB[\s\S]*not\.toContainText\('Foreign Linked Call Title'\)[\s\S]*foreignNeedlesForB[\s\S]*not\.toContainText\(value\)/,
  'parallel-account E2E must prove the rejected second account sees no foreign UI data',
);
assert.match(
  callAccessJoinSpec,
  /requests\.a\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.b\.joinGetCount\)\.toBe\(1\)[\s\S]*requests\.a\.sessionPostCount\)\.toBe\(1\)[\s\S]*requests\.b\.sessionPostCount\)\.toBe\(1\)/,
  'parallel-account E2E must guard against reload loops or duplicate session POSTs in either context',
);

console.log('[call-access-verified-context-ui-contract] PASS');
