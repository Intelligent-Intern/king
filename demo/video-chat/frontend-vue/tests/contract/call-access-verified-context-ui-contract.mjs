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

console.log('[call-access-verified-context-ui-contract] PASS');
