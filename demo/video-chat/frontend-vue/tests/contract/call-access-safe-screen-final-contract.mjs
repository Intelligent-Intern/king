import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const joinSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const seedSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const privacySpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-privacy-foreign-data.spec.js');
const privacyContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-privacy-foreign-data-contract.mjs');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

for (const sentinel of [
  'Expired Private Strategy Call',
  'expired-owner-offer-sdp',
  'candidate:expired-private-ice',
  'turn:expired-private-token',
  'whiteboard-expired-private',
  'launch-token-expired-private',
]) {
  assert.ok(joinSpec.includes(sentinel), `join safe-screen spec must include expired sentinel ${sentinel}`);
}

assert.match(
  joinSpec,
  /label: 'expired link'[\s\S]*code: 'call_access_expired'[\s\S]*expectTextDoesNotContain\(joinDialog, item\.privateNeedles, item\.label\)[\s\S]*toHaveCount\(0\)[\s\S]*sessionPostCount[\s\S]*toBe\(0\)[\s\S]*not\.toContain\('should_not_bind'\)/,
  'expired/not-found/denied join safe screens must hide private data, suppress join, avoid session issuance, and avoid storage adoption',
);

for (const sentinel of [
  'participants',
  'session_token',
  'media_token',
  'turn_credential',
  'candidate',
  'sdp',
  'ice',
  'call_apps',
  'call-app-sessions',
  'launch_token',
  'whiteboard',
  'crdt',
]) {
  assert.ok(seedSpec.includes(`'${sentinel}'`), `direct-join safe-screen proof must include protocol sentinel ${sentinel}`);
}

assert.match(
  seedSpec,
  /const deniedDirectJoinScenarios = \[[\s\S]*direct_join_system_admin_deleted_call_denied[\s\S]*direct_join_system_admin_ended_call_denied[\s\S]*direct_join_normal_non_guest_user_denied[\s\S]*direct_join_forged_client_admin_role_denied/s,
  'direct-join denied matrix must include deleted, ended, not-on-guest-list, and forged-role denials',
);

assert.match(
  seedSpec,
  /for \(const scenarioKey of deniedDirectJoinScenarios\)[\s\S]*expect\(resolvePayload\?\.result\?\.state\)\.toBe\('forbidden'\)[\s\S]*expect\(resolvePayload\?\.result\?\.call \?\? null\)\.toBe\(null\)[\s\S]*expectNoSafeScreenLeakage\(\s*JSON\.stringify\(resolvePayload\),\s*directJoinNetworkNeedles\(call\),[\s\S]*expectNoSafeScreenLeakage\(\s*await page\.locator\('body'\)\.innerText\(\),\s*directJoinContentNeedles\(call\),/s,
  'denied direct-join safe screens must assert no call payload, no network leakage, and no rendered call data',
);

assert.match(
  seedSpec,
  /for \(const scenarioKey of authDeniedDirectJoinScenarios\)[\s\S]*authResponse\.status\(\)\)\.toBe\(401\)[\s\S]*expectNoSafeScreenLeakage\(\s*JSON\.stringify\(authPayload\),\s*directJoinNetworkNeedles\(call\),[\s\S]*expectNoSafeScreenLeakage\(\s*await page\.locator\('body'\)\.innerText\(\),\s*directJoinContentNeedles\(call\),/s,
  'auth-denied direct-join safe screens must fail before call resolution and avoid network/rendered leakage',
);

assert.match(
  privacySpec,
  /expectNoForeignData\(sessionResponseBody, foreignNeedles, 'strong-mismatch browser network response'\)[\s\S]*expect\(storedSession\.sessionToken\)\.not\.toBe\('sess_foreign_denied_should_not_bind'\)/,
  'strong mismatch denied session responses must not leak or adopt denied session data',
);
assert.match(
  privacyContract,
  /backendStrongMismatch[\s\S]*wrong-host no-leak responses/,
  'privacy contract must pin backend denied/no-leak behavior',
);

assert.ok(
  iamCallAccessContractSuiteText.includes('node tests/contract/call-access-safe-screen-final-contract.mjs'),
  'IAM contract script must run the safe-screen final contract',
);
assert.ok(
  ciGate.includes('"tests/contract/call-access-safe-screen-final-contract.mjs"'),
  'IAM CI static gate must include the safe-screen final contract',
);

process.stdout.write('[call-access-safe-screen-final-contract] PASS\n');
