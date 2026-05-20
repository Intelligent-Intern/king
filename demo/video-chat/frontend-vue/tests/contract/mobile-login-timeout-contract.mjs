import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

const router = readFrontend('src/http/router.ts');
const session = readFrontend('src/domain/auth/session.ts');

assert.match(
  router,
  /const isLoginRoute = to\.path === '\/login'[\s\S]*if \(!isLoginRoute\) \{[\s\S]*if \(sessionState\.sessionToken\) \{[\s\S]*await ensureSessionRecovery\(\)[\s\S]*else if \(!sessionState\.recovered\) \{[\s\S]*await ensureSessionRecovery\(\)[\s\S]*const loggedIn = isAuthenticated\(\)/,
  'login route must not block on stale stored-session recovery before rendering the form',
);
assert.match(
  router,
  /if \(requiresAuth && !loggedIn\) \{[\s\S]*path:\s*'\/login'[\s\S]*query:\s*to\.fullPath !== '\/' \? \{ redirect:\s*to\.fullPath \}/,
  'protected routes must still recover and redirect unauthenticated users to login',
);
assert.match(
  session,
  /fetchBackend\('\/api\/auth\/login', \{[\s\S]*method:\s*'POST'[\s\S]*serialize:\s*false,[\s\S]*networkRetryCount:\s*1,[\s\S]*timeoutMs:\s*15_000,[\s\S]*body:\s*JSON\.stringify/,
  'password login must bypass the global backend queue and use one bounded request',
);
assert.match(
  session,
  /fetchBackend\('\/api\/auth\/session-state', \{[\s\S]*method:\s*'GET'[\s\S]*headers:\s*sessionHeaders\(\),[\s\S]*serialize:\s*false,[\s\S]*networkRetryCount:\s*1,[\s\S]*timeoutMs:\s*6_000/,
  'stored-session recovery must bypass the global backend queue with a short bounded probe',
);

console.log('[mobile-login-timeout-contract] PASS');
