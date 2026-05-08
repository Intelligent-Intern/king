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

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-parallel-account-tabs.spec.js');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-parallel-account-tabs-contract.php');
const backendWrapper = read('demo/video-chat/backend-king-php/tests/call-access-parallel-account-tabs-contract.sh');

const scripts = packageJson.scripts || {};
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
const callAccessContractScript = String(scripts['test:contract:iam-call-access'] || '');
const callAccessMatrixPaths = new Set(matrix.commands?.['frontend:e2e:call-access']?.paths || []);

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
  backendContract,
  /e2e_duplicate_link_005_concurrent_two_accounts_same_link_detected/,
  'backend contract must name the concurrent two-account duplicate-link case',
);
assert.match(
  backendContract,
  /wrong-account parallel session should fail safely with conflict/,
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

console.log('[call-access-parallel-account-tabs-contract] PASS');
