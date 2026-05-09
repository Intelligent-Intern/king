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

const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-invalidation-contract.php');
const backendHelper = read('demo/video-chat/backend-king-php/tests/call-access-invitation-invalidation-helper.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/call-access-invalidation-contract.sh');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-invite-invalidation.spec.js');

assert.match(
  iamCallAccessContractSuiteText,
  /call-access-link-invalidation-durability-contract\.mjs/,
  'IAM call-access contract script must include the link invalidation durability contract',
);

assert.match(
  callAccessE2eSuiteText,
  /call-access-invite-invalidation\.spec\.js/,
  'call-access E2E script must run the invite invalidation browser proof',
);

assert.match(
  ciGate,
  /call-access-link-invalidation-durability-contract\.mjs/,
  'IAM CI gate must include the static durability proof',
);
assert.match(
  ciGate,
  /tests\/call-access-invalidation-contract\.sh/,
  'IAM CI gate must keep the SQLite invalidation backend proof wired',
);

assert.match(
  backendContract,
  /--restart-probe/,
  'backend invalidation contract must expose a child-process restart probe',
);
assert.match(
  backendContract,
  /videochat_call_access_invalidation_contract_assert_restart_survives\(\$databasePath, \$restart, \$label\)/,
  'backend invalidation contract must run the restart probe against the same SQLite database',
);
assert.match(
  backendContract,
  /videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions\([\s\S]*'invalidated-before-use'/,
  'backend invalidation contract must prove invalidated state across browser/device/session contexts before restart',
);
assert.match(
  backendContract,
  /videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions\([\s\S]*'application-restart-ci'/,
  'backend restart probe must re-run cross-context denial after process restart',
);

assert.match(
  backendHelper,
  /function videochat_iam_invitation_invalidation_seed_account_session/,
  'backend helper must seed distinct account sessions for cross-session invalidation checks',
);
assert.match(
  backendHelper,
  /King IAM invalidated-link Chromium browser A[\s\S]*King IAM invalidated-link mobile device B[\s\S]*King IAM invalidated-link fresh session C/,
  'backend helper must use distinct browser, device, and fresh-session request contexts',
);
assert.match(
  backendHelper,
  /Authorization'\] = 'Bearer ' \. \$authorizationSessionId/,
  'backend HTTP route proof must exercise authenticated session requests after invalidation',
);
assert.match(
  backendHelper,
  /invalidated link must not persist any cross-context call-access sessions/,
  'backend helper must assert no replacement call-access session is persisted across contexts',
);

assert.match(
  backendShell,
  /Host PHP lacks pdo_sqlite; using container fallback/,
  'backend invalidation shell proof must use Docker when host PHP lacks pdo_sqlite',
);
assert.match(
  backendShell,
  /php tests\/call-access-invalidation-contract\.php/,
  'Docker fallback must execute the SQLite invalidation contract in the container PHP runtime',
);

assert.match(
  e2eSpec,
  /invalidated personalized link stays denied across browser device and session contexts/,
  'Playwright E2E must cover invalidated links across isolated browser/device/session contexts',
);
assert.match(
  e2eSpec,
  /userAgent:\s*'King IAM invalidated-link mobile device B'/,
  'Playwright E2E must model a second device context',
);
assert.match(
  e2eSpec,
  /storedA\.sessionToken\)\.toBe\(accountA\.sessionToken\)[\s\S]*storedB\.sessionToken\)\.toBe\(accountB\.sessionToken\)[\s\S]*not\.toBe\(storedB\.sessionToken\)/,
  'Playwright E2E must preserve distinct stored sessions after invalidated-link denial',
);
assert.match(
  e2eSpec,
  /sessionPostCountA\)\.toBe\(0\)[\s\S]*sessionPostCountB\)\.toBe\(0\)/,
  'Playwright E2E must prove invalidated links do not issue replacement sessions in either context',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\(dialogA, privateNeedles[\s\S]*expectTextDoesNotContain\(dialogB, privateNeedles/,
  'Playwright E2E must prove both contexts render safe no-leak invalid-link state',
);

console.log('[call-access-link-invalidation-durability-contract] PASS');
