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
const personalizedIdentitySpec = read('tests/e2e/call-access-personalized-identity.spec.js');

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
  /personal call-access link starts a call-scoped session and waits for host admission/,
  'live public join E2E must keep the logged-out personalized-link backend path',
);
assert.match(
  personalizedIdentitySpec,
  /logged-out personalized link starts the linked call session without identity proof/,
  'personalized identity E2E must cover logged-out personalized links',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(sessionAuthorization\)\.toBe\(''\)[\s\S]*expect\(sessionBody\)\.toBeNull\(\)/,
  'logged-out personalized E2E must prove no bearer or verified identity proof is sent',
);
assert.match(
  personalizedIdentitySpec,
  /same-account personalized link sends verified identity proof and adopts only its own session/,
  'personalized identity E2E must cover same-account personalized links',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(sessionAuthorization\)\.toBe\(`Bearer \$\{account\.sessionToken\}`\)[\s\S]*expect\(sessionBody\)\.toEqual\(\{\s*verified_user_id:\s*account\.userId,\s*verified_session_id:\s*account\.sessionId,\s*\}\)/,
  'same-account E2E must prove current bearer and verified user/session proof are sent',
);
assert.match(
  personalizedIdentitySpec,
  /session switch after verified personalized link fails without rebinding or leaking data/,
  'personalized identity E2E must cover session switch after verified link context',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(sessionAuthorization\)\.toBe\(`Bearer \$\{switchedSession\.sessionToken\}`\)[\s\S]*expect\(sessionBody\)\.toEqual\(\{\s*verified_user_id:\s*verifiedSession\.userId,\s*verified_session_id:\s*verifiedSession\.sessionId,\s*\}\)/,
  'session-switch E2E must prove session issuance uses the current bearer with the original verified snapshot',
);
assert.match(
  personalizedIdentitySpec,
  /not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)[\s\S]*not\.toContainText\(rejectedCallAccessToken\)/,
  'session-switch E2E must prove foreign call/person/session details are not rendered',
);
assert.match(
  personalizedIdentitySpec,
  /storedSession\.sessionId\)\.toBe\(switchedSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(switchedSession\.sessionToken\)[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(rejectedCallAccessToken\)/,
  'session-switch E2E must prove the failed response does not bind a new session',
);
assert.match(
  personalizedIdentitySpec,
  /logout after verified personalized link fails closed before session issuance/,
  'personalized identity E2E must cover logout after verified link context',
);
assert.match(
  personalizedIdentitySpec,
  /const \{ logoutSession, sessionState \} = await import\('\/src\/domain\/auth\/session\.ts'\);[\s\S]*await logoutSession\(\)/,
  'logout E2E must exercise the real browser logout/session-clear path',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(sessionPostCount\)\.toBe\(0\)/,
  'logout E2E must prove no call-access session request is issued after verified context is logged out',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'logout E2E must prove the browser does not enter the workspace',
);
assert.match(
  personalizedIdentitySpec,
  /logout denial must not render \$\{value\}/,
  'logout E2E must prove foreign call/invite/host/session data is not rendered',
);
assert.match(
  personalizedIdentitySpec,
  /storedSession\.sessionToken \|\| ''\)\.toBe\(''\)[\s\S]*JSON\.stringify\(storedSession\)\)\.not\.toContain\(rejectedSessionToken\)/,
  'logout E2E must prove no foreign call-access session is adopted',
);
assert.match(
  personalizedIdentitySpec,
  /wrong-account strong personalized mismatch denies access without foreign data exposure/,
  'personalized identity E2E must cover wrong-account strong mismatch',
);
assert.match(
  personalizedIdentitySpec,
  /expect\(sessionPayload\?\.error\?\.code\)\.toBe\('call_access_forbidden'\)[\s\S]*expect\(sessionPayload\?\.error\?\.details\?\.mismatch\)\.toBe\('strong_personalized_link'\)/,
  'wrong-account E2E must require a strong-mismatch denial',
);
assert.match(
  personalizedIdentitySpec,
  /dialog denial must not render \$\{value\}/,
  'wrong-account E2E must prove foreign details are not rendered',
);
assert.match(
  personalizedIdentitySpec,
  /storedSession\.sessionId\)\.toBe\(wrongAccount\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(deniedSessionToken\)/,
  'wrong-account E2E must prove the denied response does not bind a new session',
);

console.log('[call-access-verified-context-ui-contract] PASS');
