import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-multi-session-device-safety.spec.js');
const callAccessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const backendSessionContract = read('demo/video-chat/backend-king-php/tests/call-access-session-contract.php');

assert.match(
  packageJson.scripts['test:contract:iam-call-access'],
  /call-access-multi-session-device-safety-contract\.mjs/,
  'IAM call-access contract script must include the multi-session/device safety contract',
);

assert.match(
  e2eSpec,
  /same user can open the same personalized link in two browser\/device contexts without cross-session data/,
  'Playwright E2E must cover same user using the same personalized link in two browser/device contexts',
);
assert.match(
  e2eSpec,
  /releaseBothRequests[\s\S]*sessionRequests\.length >= 2[\s\S]*await bothSessionRequests/,
  'same-user E2E must submit concurrent session requests instead of serial-only coverage',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{accountA\.sessionToken\}`[\s\S]*verified_user_id:\s*accountA\.userId[\s\S]*verified_session_id:\s*accountA\.sessionId/,
  'same-user E2E must prove browser A sends its own bearer and verified session snapshot',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{accountB\.sessionToken\}`[\s\S]*verified_user_id:\s*accountB\.userId[\s\S]*verified_session_id:\s*accountB\.sessionId/,
  'same-user E2E must prove browser B sends its own bearer and verified session snapshot',
);
assert.match(
  e2eSpec,
  /storedA\.sessionToken\)\.toBe\(accountA\.issuedSessionToken\)[\s\S]*storedB\.sessionToken\)\.toBe\(accountB\.issuedSessionToken\)[\s\S]*not\.toBe\(storedB\.sessionToken\)/,
  'same-user E2E must keep local browser sessions isolated after join',
);

assert.match(
  e2eSpec,
  /different user opening the same personalized link is review-flagged without rebinding or leaks/,
  'Playwright E2E must cover another logged-in user using the same personalized link',
);
assert.match(
  e2eSpec,
  /flag:\s*'duplicate_personalized_link'[\s\S]*state:\s*'manual_review_required'[\s\S]*access_fingerprint:\s*'sha256:same-link-other-device'/,
  'different-user E2E must require a private duplicate review flag',
);
assert.match(
  e2eSpec,
  /expect\(sessionPostCount\)\.toBe\(0\)[\s\S]*expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'different-user E2E must prove no session issuance or workspace navigation happens',
);

assert.match(
  e2eSpec,
  /login switch while host-verification warning state is pending fails closed/,
  'Playwright E2E must cover login switch while warning/host-verification state is pending',
);
assert.match(
  e2eSpec,
  /sessionAuthorization\)\.toBe\(`Bearer \$\{switchedAccount\.sessionToken\}`\)[\s\S]*verified_user_id:\s*verifiedAccount\.userId[\s\S]*verified_session_id:\s*verifiedAccount\.sessionId/,
  'warning login-switch E2E must send current bearer with the original verified snapshot and fail closed',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionToken\)\.toBe\(switchedAccount\.sessionToken\)[\s\S]*not\.toBe\('sess_hidden_warning_should_not_bind'\)/,
  'warning login-switch E2E must preserve the current account session and reject leaked sessions',
);

assert.match(
  e2eSpec,
  /session expiry while waiting in lobby clears the stale session without entering the call/,
  'Playwright E2E must cover session expiry while waiting in lobby',
);
assert.match(
  e2eSpec,
  /expireOnRecovery = true[\s\S]*page\.reload[\s\S]*storedAfterExpiry\.sessionToken \|\| ''\)\.toBe\(''\)[\s\S]*not\.toContain\('\/workspace\/call'\)/,
  'lobby expiry E2E must clear the stale call-access session and avoid workspace entry after refresh',
);
assert.match(
  e2eSpec,
  /session expiry in call workspace redirects to login and clears the stale call session/,
  'Playwright E2E must cover session expiry while already in the call workspace',
);
assert.match(
  e2eSpec,
  /toHaveURL\(\/\\\/login\\\?redirect=\/[\s\S]*workspace-call-view[\s\S]*toHaveCount\(0\)[\s\S]*storedAfterExpiry\.sessionToken \|\| ''\)\.toBe\(''\)/,
  'call expiry E2E must redirect to login, remove workspace UI, and clear storage',
);

assert.match(
  e2eSpec,
  /refresh after failed host verification preserves the current account and refetches safe state/,
  'Playwright E2E must cover refresh during/after host verification',
);
assert.match(
  e2eSpec,
  /host_name:\s*'wrong_host_name'[\s\S]*page\.reload[\s\S]*joinGetCount\)\.toBe\(2\)[\s\S]*sessionPostCount\)\.toBe\(1\)/,
  'host verification refresh E2E must refetch context without replaying session issuance',
);
assert.match(
  e2eSpec,
  /refresh while account-update email confirmation is pending keeps confirmation account-bound/,
  'Playwright E2E must cover refresh while email confirmation is pending',
);
assert.match(
  e2eSpec,
  /sent_to_logged_in_account\)\.toBe\(true\)[\s\S]*sent_to_link_account\)\.toBe\(false\)[\s\S]*sessionPostCount\)\.toBe\(0\)/,
  'pending email confirmation E2E must stay account-bound and avoid call-session issuance',
);

assert.match(
  callAccessContract,
  /INSERT INTO call_participants[\s\S]*ON CONFLICT\(call_id, email\) DO UPDATE SET[\s\S]*user_id = excluded\.user_id[\s\S]*source = 'internal'/,
  'call-access participant creation must be idempotent for same-link multi-device joins',
);
assert.match(
  callAccessContract,
  /WHEN call_participants\.call_role = 'owner' THEN 'owner'[\s\S]*WHEN excluded\.invite_state = 'allowed' THEN 'allowed'/,
  'participant upsert must preserve owner role and only promote invite state deliberately',
);
assert.match(
  backendSessionContract,
  /same logged-in user device A should issue from reusable open link[\s\S]*same logged-in user device B should issue from reusable open link/,
  'backend session contract must issue two same-user device sessions',
);
assert.match(
  backendSessionContract,
  /same user concurrent devices must not create duplicate participant rows/,
  'backend session contract must prove same-user devices leave one participant row',
);
assert.match(
  backendSessionContract,
  /same user devices should keep separate call-access sessions/,
  'backend session contract must preserve separate browser sessions while deduplicating participants',
);

console.log('[call-access-multi-session-device-safety-contract] PASS');
