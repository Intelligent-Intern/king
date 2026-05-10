import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

function requireNotIncludes(source, needle, message) {
  assert.equal(source.includes(needle), false, message);
}

const evidence = read('documentation/iam9-13-duplicate-abuse-devices.md');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const staticGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const joinSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');
const duplicateDeviceContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs');
const duplicateAbuseContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs');
const duplicateReplayContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = read('demo/video-chat/backend-king-php/http/module_calls_access.php');

requireIncludes(
  evidence,
  'local/iam-e2e-duplicate-abuse-device-browser-proof-3',
  'IAM9-13 evidence must name the inspected source branch',
);
requireIncludes(
  evidence,
  '2cd67944d703767871327c64df89f0d4005fcddc',
  'IAM9-13 evidence must record the inspected source tip',
);
requireMatch(
  evidence,
  /two independent browser\/device sessions open the same personalized join link\s+concurrently/s,
  'IAM9-13 evidence must scope the value to cross-device duplicate abuse',
);
requireMatch(
  evidence,
  /deterministic:\s+one call-access session is accepted and\s+the competing device receives `409 call_access_conflict`/s,
  'IAM9-13 evidence must preserve the one-winner one-conflict race result',
);
requireMatch(
  evidence,
  /does not import the\s+separate IAM9-14 baseline branch\s+`local\/iam-e2e-duplicate-link-abuse-device-browser`/s,
  'IAM9-13 evidence must keep the IAM9-14 baseline out of this extraction',
);

requireMatch(
  joinSpec,
  /test\('same personalized link in parallel contexts keeps account sessions isolated'/,
  'browser E2E must keep the cross-device duplicate-abuse scenario',
);
requireMatch(
  joinSpec,
  /const accountAPage = await createPublicJoinPage\(browser, baseURL\);[\s\S]*const accountBPage = await createPublicJoinPage\(browser, baseURL\);/,
  'IAM9-13 proof must use separate browser contexts to model separate devices',
);
requireMatch(
  joinSpec,
  /await Promise\.all\(\[[\s\S]*accountAPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*accountBPage\.page\.goto\(`\/join\/\$\{accessId\}`\),[\s\S]*\]\)/,
  'IAM9-13 proof must open the same personalized link concurrently',
);
requireMatch(
  joinSpec,
  /expect\(responseA\.status\(\)\)\.toBe\(200\);[\s\S]*expect\(responseB\.status\(\)\)\.toBe\(409\);/,
  'IAM9-13 proof must settle duplicate use as one 200 and one 409',
);
requireMatch(
  joinSpec,
  /expect\(requests\.a\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountA\.sessionToken\}`\);[\s\S]*expect\(requests\.b\.sessionAuthorization\)\.toBe\(`Bearer \$\{accountB\.sessionToken\}`\);/,
  'each device must redeem with its own bearer token',
);
requireMatch(
  joinSpec,
  /requests\.a\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountA\.userId,[\s\S]*verified_session_id: accountA\.sessionId,[\s\S]*requests\.b\.sessionBody\)\.toEqual\(\{[\s\S]*verified_user_id: accountB\.userId,[\s\S]*verified_session_id: accountB\.sessionId,/,
  'each device must submit its own verified user/session snapshot',
);
requireMatch(
  joinSpec,
  /storedB\.sessionId\)\.toBe\(accountB\.sessionId\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountA\.issuedCallAccessToken\)[\s\S]*storedB\.sessionToken\)\.not\.toBe\(accountB\.rejectedCallAccessToken\)/,
  'rejected device must keep its original session and avoid token bleed',
);
requireMatch(
  joinSpec,
  /await expect\(dialogB\)\.toContainText\('This call link cannot be used for the current call state\.'\);[\s\S]*await expect\(dialogB\)\.not\.toContainText\('Foreign Linked Call Title'\);[\s\S]*foreignNeedlesForB[\s\S]*not\.toContainText\(value\)/,
  'rejected device must render a safe conflict without foreign metadata',
);

requireMatch(
  duplicateDeviceContract,
  /separate browser contexts[\s\S]*same personalized link[\s\S]*one success and one 409 conflict[\s\S]*must not adopt either call-access token/s,
  'focused duplicate-device contract must pin IAM9-13 cross-device abuse',
);
requireMatch(
  duplicateAbuseContract,
  /parallel browser-context duplicate-abuse test[\s\S]*parallel conflict denial must not leak foreign linked-call or peer session data/s,
  'duplicate-abuse contract must keep parallel no-leak coverage',
);
requireMatch(
  duplicateReplayContract,
  /parallel duplicate conflict must preserve the rejected device\/browser session and avoid token bleed/s,
  'duplicate replay contract must keep rejected-device token isolation',
);

requireMatch(
  callAccessSession,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*'reason' => 'conflict'[\s\S]*'auth' => 'session_context_changed'/,
  'backend must reject verified-session drift as a conflict',
);
requireMatch(
  callAccessSession,
  /SELECT 1 FROM sessions WHERE id = :id LIMIT 1[\s\S]*SELECT 1 FROM call_access_sessions WHERE session_id = :id LIMIT 1/,
  'backend must reject generated call-access session ids already used in either session store',
);
requireMatch(
  callsAccessRoute,
  /if \(\$reason === 'conflict'\)[\s\S]*return \$errorResponse\(409, 'call_access_conflict', 'Call access cannot be used for the current call state\.'/,
  'HTTP route must map duplicate/session conflicts to safe 409 call_access_conflict responses',
);

const iamPackageGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const iam913Script = String(packageJson.scripts?.['test:contract:iam9-13-duplicate-abuse-devices'] || '');
requireIncludes(
  iamPackageGate,
  'node tests/contract/call-access-duplicate-device-browser-contract.mjs',
  'full IAM package gate must keep the stable duplicate-device contract',
);
requireIncludes(
  iam913Script,
  'node tests/contract/iam9-13-duplicate-abuse-devices-contract.mjs',
  'package scripts must expose the focused IAM9-13 static proof',
);
requireIncludes(
  staticGate,
  'node tests/contract/iam9-13-duplicate-abuse-devices-contract.mjs',
  'static IAM gate must include the focused IAM9-13 proof',
);
requireNotIncludes(
  iam913Script,
  'call-access-duplicate-link-device-browser.spec.js',
  'IAM9-13 script must not wire the stale source Playwright spec',
);

process.stdout.write('[iam9-13-duplicate-abuse-devices-contract] PASS\n');
