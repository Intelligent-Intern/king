import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const joinSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const backendSafeScreen = read('demo/video-chat/backend-king-php/tests/call-access-safe-screen-privacy-contract.php');
const backendSafeScreenShell = read('demo/video-chat/backend-king-php/tests/call-access-safe-screen-privacy-contract.sh');
const backendAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const ciWire = read('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');

for (const sentinel of [
  'Expired Private Strategy Call',
  'expired-owner-offer-sdp',
  'candidate:expired-private-ice',
  'turn:expired-private-token',
  'media-token-expired-private',
  'whiteboard-expired-private',
  'call-app-expired-private-session',
  'launch-token-expired-private',
]) {
  assert.ok(joinSpec.includes(sentinel), `join safe-screen spec must include expired sentinel ${sentinel}`);
}

assert.match(
  joinSpec,
  /stale and denied call-access links render safe screens without private payload data[\s\S]*label: 'ended link'[\s\S]*label: 'expired link'[\s\S]*label: 'deleted link'[\s\S]*label: 'disabled user link'/,
  'safe-screen E2E must cover stale ended, expired, deleted, and inactive-user link states',
);
assert.match(
  joinSpec,
  /expectTextDoesNotContain\(await joinDialog\.innerText\(\), item\.privateNeedles, item\.label\)[\s\S]*toHaveCount\(0\)[\s\S]*sessionPostCount[\s\S]*toBe\(0\)[\s\S]*not\.toContain\('should_not_bind'\)[\s\S]*not\.toContain\('\/workspace\/call'\)/,
  'safe-screen E2E must hide hostile payload data, suppress session issuance, avoid denied session storage, and stay out of workspace',
);
assert.match(
  joinView,
  /resetJoinContextDetails\(\);[\s\S]*state\.contextError = localizedApiErrorMessage\(payload, t\('public\.join\.resolve_failed'\)\);/,
  'JoinView must reset call-specific details before rendering localized denied-link copy',
);
assert.match(
  joinView,
  /state\.joinError = localizedApiErrorMessage\(errorPayload, t\('public\.join\.start_session_failed'\)\);/,
  'JoinView must render session-denial UI from stable error codes rather than backend message/result payloads',
);

for (const sentinel of [
  'expired-owner-offer-sdp-',
  'candidate:expired-private-ice-',
  'turn:expired-private-token-',
  'media-token-',
  'whiteboard-',
  'launch-token-',
  'cookie-secret-',
]) {
  assert.ok(backendSafeScreen.includes(sentinel), `backend safe-screen contract must include protocol sentinel ${sentinel}`);
}
assert.match(
  backendSafeScreen,
  /videochat_call_access_safe_screen_assert_redacted[\s\S]*!\s*str_contains\(\$lowerBody, \$text\)[\s\S]*!isset\(\$payload\['result'\]\)/,
  'backend safe-screen contract must reject raw sensitive text and result envelopes on denied responses',
);
assert.match(
  backendSafeScreen,
  /\$route\(\$guessedAccessId, '\/join', 'GET'\)[\s\S]*\$route\(\$expiredAccessId, '\/join', 'GET'\)[\s\S]*\$route\(\$endedAccessId, '\/join', 'GET'\)[\s\S]*\$route\(\$disabledAccessId, '\/join', 'GET'\)/,
  'backend safe-screen contract must cover invalid, expired, terminal, and inactive target join responses',
);
assert.match(
  backendSafeScreen,
  /\$route\(\s*\$activeAccessId,\s*'\/join',\s*'GET',\s*\['Authorization' => 'Bearer sess_safe_screen_wrong_current'\][\s\S]*\$route\(\s*\$activeAccessId,\s*'\/session',\s*'POST',\s*\['Authorization' => 'Bearer sess_safe_screen_wrong_current'/,
  'backend safe-screen contract must cover foreign/wrong-account join and session responses',
);
assert.match(
  backendSafeScreen,
  /\$issuerCalls === 0[\s\S]*SELECT COUNT\(\*\) FROM sessions WHERE id LIKE 'sess_safe_screen_should_not_issue%'/,
  'backend safe-screen contract must prove denied paths do not issue or persist sessions',
);
assert.match(
  backendSafeScreenShell,
  /call-access-safe-screen-privacy-contract\.php/,
  'backend safe-screen shell wrapper must run the PHP contract',
);
assert.ok(
  backendAggregate.includes('"call-access-safe-screen-privacy-contract.sh"'),
  'IAM SQLite aggregate must include the safe-screen privacy backend contract',
);

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.ok(
  iamScript.includes('node tests/contract/call-access-safe-screen-final-contract.mjs'),
  'IAM package contract script must run the safe-screen final contract',
);
assert.ok(
  ciWire.includes('frontend-vue/tests/contract/call-access-safe-screen-final-contract.mjs'),
  'IAM CI wire contract must require the safe-screen final contract',
);
assert.ok(
  ciWire.includes('backend-king-php/tests/call-access-safe-screen-privacy-contract.sh'),
  'IAM CI wire contract must require the backend safe-screen privacy contract',
);

const iamCommandPaths = new Set(matrix.commands?.['frontend:contract:iam-call-access']?.paths || []);
for (const pathName of [
  'frontend-vue/tests/contract/call-access-safe-screen-final-contract.mjs',
  'backend-king-php/tests/call-access-safe-screen-privacy-contract.php',
  'backend-king-php/tests/call-access-safe-screen-privacy-contract.sh',
]) {
  assert.ok(iamCommandPaths.has(pathName), `IAM release metadata must list ${pathName}`);
}

process.stdout.write('[call-access-safe-screen-final-contract] PASS\n');
