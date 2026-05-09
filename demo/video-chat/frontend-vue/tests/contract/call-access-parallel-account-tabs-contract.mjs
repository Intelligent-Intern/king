import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  callAccessE2eSuiteText,
  iamCallAccessContractSuiteText,
} from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-parallel-account-tabs.spec.js');
const deviceBrowserE2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-link-device-browser.spec.js');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-parallel-account-tabs-contract.php');
const backendWrapper = read('demo/video-chat/backend-king-php/tests/call-access-parallel-account-tabs-contract.sh');

const scripts = packageJson.scripts || {};
const callAccessE2eScript = callAccessE2eSuiteText;
const callAccessContractScript = iamCallAccessContractSuiteText;
const callAccessMatrixPaths = new Set(matrix.commands?.['frontend:e2e:call-access']?.paths || []);

assert.match(
  String(scripts['test:e2e:call-access'] || ''),
  /call-access-e2e-suite\.mjs/,
  'package script must expose the Call Access E2E suite helper',
);
assert.match(
  String(scripts['test:contract:iam-call-access'] || ''),
  /iam-call-access-contract-suite\.mjs/,
  'package script must expose the IAM Call Access contract suite helper',
);

assert.match(
  e2eSpec,
  /e2e_duplicate_link_005\/e2e_duplicate_link_006 parallel account tabs detect duplicate use without merging sessions/,
  'parallel account tabs E2E must name the concurrent duplicate-link and no-inconsistent-assignment cases',
);
assert.match(
  e2eSpec,
  /createAccountTab\(browser,\s*baseURL,\s*linkedAccount[\s\S]*createAccountTab\(browser,\s*baseURL,\s*otherAccount/,
  'parallel account tabs E2E must open the same personalized link for two accounts',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{linkedAccount\.sessionToken\}`[\s\S]*verified_user_id:\s*linkedAccount\.userId[\s\S]*verified_session_id:\s*linkedAccount\.sessionId/,
  'linked account tab must send its own bearer plus verified user/session proof',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{otherAccount\.sessionToken\}`[\s\S]*verified_user_id:\s*otherAccount\.userId[\s\S]*verified_session_id:\s*otherAccount\.sessionId/,
  'other account tab must send its own bearer plus verified user/session proof',
);
assert.match(
  e2eSpec,
  /duplicate_personalized_link[\s\S]*manual_review_required[\s\S]*raw_link_identifier_logged:\s*false[\s\S]*account_email_logged:\s*false[\s\S]*host_name_logged:\s*false/,
  'parallel duplicate denial must require a privacy-safe manual-review flag',
);
assert.match(
  e2eSpec,
  /otherRuntimeSession[\s\S]*sessionToken:\s*otherAccount\.sessionToken[\s\S]*not\.toBe\(deniedCallAccessSession\)[\s\S]*not\.toBe\(linkedCallAccessSession\)/,
  'other account tab must keep its own runtime session after denied parallel use',
);
assert.match(
  e2eSpec,
  /duplicate denial must not render \$\{value\}/,
  'parallel duplicate denial must not render foreign account, host, or denied-session details',
);
assert.match(
  deviceBrowserE2eSpec,
  /duplicate abuse detection works after logout\/login switch in the same browser/,
  'device/browser duplicate E2E must cover same-browser logout/login switch abuse detection',
);
assert.match(
  deviceBrowserE2eSpec,
  /logoutSession[\s\S]*duplicate_personalized_link[\s\S]*same_browser_logout_login_switch/,
  'same-browser switch E2E must exercise the real logout path and require a duplicate review flag',
);
assert.match(
  deviceBrowserE2eSpec,
  /e2e_duplicate_link_007\/e2e_duplicate_link_008 cross-device and cross-browser duplicate use is review-flagged/,
  'device/browser duplicate E2E must name the cross-device and cross-browser matrix cases',
);
assert.match(
  deviceBrowserE2eSpec,
  /King IAM E2E Mobile Device B[\s\S]*cross_device_duplicate[\s\S]*Mozilla\/5\.0 King IAM E2E Firefox Browser C[\s\S]*cross_browser_duplicate/,
  'device/browser duplicate E2E must drive distinct device and browser contexts',
);
assert.match(
  deviceBrowserE2eSpec,
  /toHaveLength\(0\)[\s\S]*readRuntimeSession\(device\.page\)[\s\S]*readRuntimeSession\(otherBrowser\.page\)/,
  'device/browser duplicate E2E must prove denied contexts get no session and keep their own accounts',
);

assert.match(
  backendContract,
  /e2e_duplicate_link_005_concurrent_two_accounts_same_link_detected/,
  'backend contract must name the concurrent two-account duplicate-link case',
);
assert.match(
  backendContract,
  /wrong-account parallel session should fail safely with verified-context conflict/,
  'backend contract must reject wrong-account parallel session issuance',
);
assert.match(
  backendContract,
  /parallel target tabs should persist two call-access sessions/,
  'backend contract must allow same linked account tabs to keep separate sessions',
);
assert.match(
  backendContract,
  /wrong-account parallel tab must not persist a session/,
  'backend contract must prove the wrong account gets no session',
);
assert.match(
  backendContract,
  /wrong-account parallel tab must not become a call participant/,
  'backend contract must prove the wrong account is not merged into participants',
);
assert.match(
  backendContract,
  /join_opened[\s\S]*session_verified_context/,
  'backend contract must prove duplicate audit stages for open and session races',
);
assert.match(
  callAccessSession,
  /function videochat_call_access_record_context_switch_review[\s\S]*videochat_call_access_record_duplicate_personalized_link_review[\s\S]*session_context_changed/,
  'session issuance must review-flag stale verified browser context before returning early context-switch conflicts',
);
assert.match(
  callAccessSession,
  /videochat_call_access_record_duplicate_personalized_link_review\([\s\S]*'session_verified_context'/,
  'session issuance must review-flag duplicate personalized links during verified-context session races',
);
assert.match(
  backendContract,
  /same-browser logout\/login switch should deny duplicate personalized-link session/,
  'backend contract must prove same-browser logout/login switch sessions are duplicate-flagged',
);
assert.match(
  backendContract,
  /cross-device duplicate session should be review-flagged[\s\S]*cross-browser duplicate session should be review-flagged/,
  'backend contract must prove cross-device and cross-browser duplicate sessions are review-flagged',
);
assert.match(
  backendContract,
  /same-browser\/device\/browser duplicate sessions must not persist issued sessions[\s\S]*same-browser\/device\/browser duplicate sessions must not reassign personalized link/,
  'backend contract must prove denied same-browser/device/browser duplicate sessions do not issue sessions or reassign the link',
);

assert.match(
  backendWrapper,
  /pdo_sqlite[\s\S]*call-access-parallel-account-tabs-contract\.php/,
  'backend wrapper must report SQLite skips and run the PHP contract when available',
);
assert.match(
  callAccessE2eScript,
  /tests\/e2e\/call-access-parallel-account-tabs\.spec\.js/,
  'focused call-access E2E script must run the parallel account tabs spec',
);
assert.match(
  callAccessE2eScript,
  /tests\/e2e\/call-access-duplicate-link-device-browser\.spec\.js/,
  'focused call-access E2E script must run the duplicate link device/browser spec',
);
assert.match(
  callAccessContractScript,
  /call-access-parallel-account-tabs-contract\.mjs/,
  'IAM call-access contract script must run the static parallel account tabs proof',
);
assert.match(
  callAccessContractScript,
  /call-access-parallel-account-tabs-contract\.sh/,
  'IAM call-access contract script must run the backend parallel account tabs proof',
);
assert.match(
  ciGate,
  /call-access-parallel-account-tabs-contract\.mjs/,
  'IAM CI static gate must include the parallel account tabs static proof',
);
assert.match(
  ciGate,
  /call-access-parallel-account-tabs-contract\.sh/,
  'IAM CI SQLite gate must include the backend parallel account tabs proof',
);
assert.ok(
  callAccessMatrixPaths.has('frontend-vue/tests/e2e/call-access-parallel-account-tabs.spec.js'),
  'call-access matrix paths must include the parallel account tabs E2E spec',
);
assert.ok(
  callAccessMatrixPaths.has('frontend-vue/tests/e2e/call-access-duplicate-link-device-browser.spec.js'),
  'call-access matrix paths must include the duplicate link device/browser E2E spec',
);

console.log('[call-access-parallel-account-tabs-contract] PASS');
