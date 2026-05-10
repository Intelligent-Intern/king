import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  getSeedCall,
  getSeedUser,
  sessionStorageKey,
  storedSessionForSeedUser,
} from '../../tests/e2e/helpers/callAccessSeedMatrix.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function createContextStorage(initialSession = null) {
  const rows = new Map();
  if (initialSession) {
    rows.set(sessionStorageKey, JSON.stringify(initialSession));
  }
  return {
    getItem: (key) => rows.get(key) ?? null,
    setItem: (key, value) => rows.set(key, String(value)),
    removeItem: (key) => rows.delete(key),
    snapshot: () => Object.fromEntries(rows.entries()),
  };
}

function readStoredSession(storage) {
  const raw = storage.getItem(sessionStorageKey);
  return raw ? JSON.parse(raw) : null;
}

function persistSession(storage, session) {
  if (!session?.sessionToken) {
    storage.removeItem(sessionStorageKey);
    return;
  }
  storage.setItem(sessionStorageKey, JSON.stringify({
    sessionId: session.sessionId,
    sessionToken: session.sessionToken,
    expiresAt: session.expiresAt,
  }));
}

function authHeaderFor(storage) {
  const session = readStoredSession(storage);
  const token = String(session?.sessionToken || '').trim();
  return token === '' ? '' : `Bearer ${token}`;
}

const sessionSource = readText('demo/video-chat/frontend-vue/src/domain/auth/session.ts');
const callAccessSessionSource = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');

const alphaCall = getSeedCall('alpha_active');
const ownerUser = getSeedUser('alpha_call_owner');
const normalUser = getSeedUser('alpha_normal_user');
const ownerSession = storedSessionForSeedUser('alpha_call_owner', 'alpha_active');
const normalSession = storedSessionForSeedUser('alpha_normal_user', 'alpha_active');

assert.equal(alphaCall.owner_user_key, 'alpha_call_owner', 'alpha active call must keep the owner account fixture');
assert.equal(ownerSession.userId, ownerUser.id, 'owner session fixture must belong to the owner account');
assert.equal(normalSession.userId, normalUser.id, 'normal-user session fixture must belong to the normal account');
assert.notEqual(ownerSession.userId, normalSession.userId, 'account-isolation proof must use distinct users');
assert.notEqual(ownerSession.sessionToken, normalSession.sessionToken, 'account-isolation proof must use distinct session tokens');
assert.equal(ownerSession.tenant.slug, normalSession.tenant.slug, 'same-tenant account switch must not hide accidental account bleed behind tenant changes');

const switchedContext = createContextStorage(ownerSession);
assert.equal(readStoredSession(switchedContext).sessionToken, ownerSession.sessionToken, 'precondition: first account is stored');
switchedContext.removeItem(sessionStorageKey);
assert.equal(readStoredSession(switchedContext), null, 'logout must remove the previous stored session before the next login');
persistSession(switchedContext, normalSession);
const switchedStored = readStoredSession(switchedContext);
assert.equal(switchedStored.sessionToken, normalSession.sessionToken, 'login switch must store the newly authenticated account token');
assert.notEqual(switchedStored.sessionToken, ownerSession.sessionToken, 'login switch must not keep the logged-out account token');
assert.doesNotMatch(
  JSON.stringify(switchedContext.snapshot()),
  new RegExp(`${ownerSession.sessionToken}|${ownerUser.email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`),
  'post-switch storage must not retain previous account token or email',
);

const ownerTab = createContextStorage(ownerSession);
const normalTab = createContextStorage(normalSession);
assert.equal(authHeaderFor(ownerTab), `Bearer ${ownerSession.sessionToken}`, 'owner tab must authorize with only the owner token');
assert.equal(authHeaderFor(normalTab), `Bearer ${normalSession.sessionToken}`, 'normal-user tab must authorize with only the normal-user token');
ownerTab.removeItem(sessionStorageKey);
assert.equal(authHeaderFor(ownerTab), '', 'logging out one isolated tab context must clear only that context');
assert.equal(authHeaderFor(normalTab), `Bearer ${normalSession.sessionToken}`, 'parallel isolated tab context must keep its own account token');
persistSession(ownerTab, normalSession);
assert.equal(authHeaderFor(ownerTab), authHeaderFor(normalTab), 'after explicit login switch, the old tab must use the new account token');
assert.notEqual(authHeaderFor(ownerTab), `Bearer ${ownerSession.sessionToken}`, 'after explicit login switch, old account authorization must be gone');

assert.match(
  sessionSource,
  /const STORAGE_KEY = 'ii_videocall_v1_session'/,
  'session storage key must remain explicit and stable for isolation proofs',
);
assert.match(
  sessionSource,
  /if \(!sessionState\.sessionToken\) \{[\s\S]*storage\.removeItem\(STORAGE_KEY\)[\s\S]*return;[\s\S]*\}/,
  'persist must remove browser storage when no session token remains',
);
assert.match(
  sessionSource,
  /export async function logoutSession\(\)[\s\S]*finally \{[\s\S]*clearSessionState\(\);[\s\S]*setRecoveryState\('idle'\);[\s\S]*\}/,
  'logout must clear local session state even when the backend logout request fails',
);
assert.match(
  sessionSource,
  /export function clearSessionState\(\)[\s\S]*sessionState\.sessionId = '';[\s\S]*sessionState\.sessionToken = '';[\s\S]*persist\(\);/,
  'clearSessionState must blank session id and token before persisting',
);
assert.match(
  sessionSource,
  /export async function loginWithPassword\([\s\S]*applySessionEnvelope\(payload\.session,\s*payload\.user,\s*payload\.tenant\)/,
  'password login must replace the client session from the backend session envelope',
);
assert.match(
  callAccessSessionSource,
  /if \(verifiedContext && String\(sessionState\.sessionToken \|\| ''\)\.trim\(\) === ''\) \{[\s\S]*call_access_conflict/,
  'call-access session issuance must fail closed when verified login context disappears during an account switch',
);
assert.match(
  callAccessSessionSource,
  /headers\.authorization = `Bearer \$\{token\}`/,
  'call-access session issuance must bind requests to the current account token',
);
assert.match(
  seedMatrixHelper,
  /export async function installStoredSeedSession\(context,[\s\S]*context\.addInitScript\([\s\S]*localStorage\.setItem\(key,\s*JSON\.stringify\(value\)\)/,
  'seed matrix helper must install account sessions per browser context for parallel-tab isolation proof',
);
assert.match(
  seedMatrixSpec,
  /createDirectJoinProbePage[\s\S]*installStoredSeedSession\(context,\s*principalUserKey,\s*callKey\)/,
  'seed matrix spec must exercise API probes with an account session installed into the active browser context',
);

process.stdout.write('[call-access-account-isolation-contract] PASS\n');
