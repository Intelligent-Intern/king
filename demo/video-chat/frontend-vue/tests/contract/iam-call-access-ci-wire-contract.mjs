import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function assertIncludes(source, needle, message) {
  assert.ok(String(source || '').includes(needle), message);
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const ciWorkflow = readText('.github/workflows/ci.yml');

const scripts = packageJson.scripts || {};
const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');
const iamLocalRunDocsScript = String(scripts['test:contract:iam-local-run-docs'] || '');
const iamCiGateScript = String(scripts['test:ci:iam-call-access'] || '');
const iamStaticGateScript = String(scripts['test:ci:iam-call-access:static'] || '');
const iamSqliteGateScript = String(scripts['test:ci:iam-call-access:sqlite'] || '');
const iamDockerGateScript = String(scripts['test:ci:iam-call-access:docker'] || '');
const iamFullGateScript = String(scripts['test:ci:iam-call-access:full'] || '');
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
const lobbyConcurrencyScript = String(scripts['test:e2e:lobby-concurrency'] || '');
const bannedGatePattern = /test:contract:(background|media)|background-|media-reconnect|media-security|sfu-|gossip-/;
const bannedCommandPattern = /npm run test:contract:(background|media|sfu|gossip)|node tests\/contract\/(?:background-|media-security|sfu-|gossip-)|\.\.\/backend-king-php\/tests\/realtime-gossip/i;

const requiredIamContractPaths = [
  'frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs',
  'frontend-vue/tests/contract/iam-local-run-docs-contract.mjs',
  'frontend-vue/tests/contract/iam-sprint-03-inventory-contract.mjs',
  'frontend-vue/tests/contract/iam-sprint-04-focused-wire-contract.mjs',
  'frontend-vue/tests/contract/call-access-ci-artifacts-contract.mjs',
  'frontend-vue/tests/contract/call-access-forged-identifiers-contract.mjs',
  'frontend-vue/tests/contract/call-access-tampered-verified-context-contract.mjs',
  'frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs',
  'frontend-vue/tests/contract/call-access-logout-login-switch-contract.mjs',
  'frontend-vue/tests/contract/call-access-logout-switch-extract-contract.mjs',
  'frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
  'frontend-vue/tests/contract/call-access-anonymous-guest-manipulation-contract.mjs',
  'frontend-vue/tests/contract/call-access-temp-call-link-boundaries-contract.mjs',
  'frontend-vue/tests/contract/call-access-disabled-links-fail-closed-contract.mjs',
  'frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs',
  'frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs',
  'frontend-vue/tests/contract/call-access-calendar-invite-join-contract.mjs',
  'frontend-vue/tests/contract/call-access-registered-logged-out-handoff-contract.mjs',
  'frontend-vue/tests/contract/call-access-registered-logged-in-invitee-contract.mjs',
  'frontend-vue/tests/contract/call-access-registered-invitee-extract-contract.mjs',
  'frontend-vue/tests/contract/call-access-personalized-temp-reuse-contract.mjs',
  'frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs',
  'frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs',
  'frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs',
  'frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs',
  'frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs',
  'frontend-vue/tests/contract/call-access-removed-members-contract.mjs',
  'frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs',
  'frontend-vue/tests/contract/call-access-stale-role-org-switch-contract.mjs',
  'frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs',
  'frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs',
  'frontend-vue/tests/contract/call-access-identity-mismatch-review-flow-contract.mjs',
  'frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
  'frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs',
  'frontend-vue/tests/contract/iam-guest-list-revocation-extraction-contract.mjs',
  'frontend-vue/tests/contract/call-access-link-privacy-contract.mjs',
  'frontend-vue/tests/contract/call-access-safe-screen-final-contract.mjs',
  'frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs',
  'frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs',
  'frontend-vue/tests/contract/call-access-cross-org-contract.mjs',
  'frontend-vue/tests/contract/call-access-foreign-link-review-audit-contract.mjs',
  'frontend-vue/tests/contract/call-access-invalid-expired-anonymous-link-contract.mjs',
  'frontend-vue/tests/contract/iam-lobby-management-moderator-rights-contract.mjs',
  'frontend-vue/tests/contract/call-access-terminal-states-contract.mjs',
  'frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs',
  'frontend-vue/tests/contract/call-access-lobby-concurrency-contract.mjs',
  'frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs',
  'frontend-vue/tests/contract/call-access-account-isolation-contract.mjs',
  'frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs',
  'frontend-vue/tests/contract/call-access-callapp-revocation-contract.mjs',
  'frontend-vue/tests/contract/call-access-route-guard-ui-contract.mjs',
  'frontend-vue/tests/contract/call-access-realtime-scope-contract.mjs',
  'backend-king-php/tests/call-access-anonymous-temp-rights-contract.php',
  'backend-king-php/tests/call-access-terminal-join-contract.sh',
  'backend-king-php/tests/call-guest-list-direct-join-contract.sh',
  'backend-king-php/tests/call-access-cross-org-contract.sh',
  'backend-king-php/tests/realtime-lobby-concurrency-contract.sh',
  'backend-king-php/tests/call-access-membership-removal-contract.sh',
  'backend-king-php/tests/call-access-stale-organization-role-contract.sh',
  'backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh',
  'backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh',
];

const requiredIamSupportingPaths = [
  'backend-king-php/tests/call-access-anonymous-temp-rights-docker-proof.sh',
  'backend-king-php/tests/call-access-safe-screen-privacy-contract.php',
  'backend-king-php/tests/call-access-safe-screen-privacy-contract.sh',
  'backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php',
  'backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.sh',
  'backend-king-php/tests/call-access-foreign-link-review-audit-contract.sh',
  'backend-king-php/tests/call-access-invalid-expired-anonymous-link-contract.sh',
  'backend-king-php/tests/call-access-anonymous-lobby-contract.sh',
  'backend-king-php/tests/call-temporary-moderator-contract.sh',
  'backend-king-php/tests/realtime-lobby-security-contract.sh',
  'backend-king-php/tests/call-access-guest-list-membership-docker-proof.sh',
  'backend-king-php/tests/call-access-cross-org-stale-role-docker-proof.sh',
];

assert.notEqual(iamContractScript, '', 'package.json must expose test:contract:iam-call-access');
assert.doesNotMatch(
  iamContractScript,
  bannedGatePattern,
  'IAM call-access contract gate must not invoke media, background, SFU, or gossip gates',
);
assert.equal(
  iamLocalRunDocsScript,
  'node tests/contract/iam-local-run-docs-contract.mjs',
  'package.json must expose a stable local IAM run docs contract script',
);
assert.equal(
  iamCiGateScript,
  '../scripts/iam-call-access-ci-gate.sh --full',
  'package.json must expose the canonical local IAM CI gate wrapper',
);
assert.equal(
  iamStaticGateScript,
  '../scripts/iam-call-access-ci-gate.sh --static',
  'package.json must expose the host-safe static IAM CI gate wrapper',
);
assert.equal(
  iamSqliteGateScript,
  '../scripts/iam-call-access-ci-gate.sh --sqlite',
  'package.json must expose the SQLite IAM backend proof wrapper',
);
assert.equal(
  iamDockerGateScript,
  '../scripts/iam-call-access-ci-gate.sh --docker',
  'package.json must expose the IAM Docker fallback proof wrapper',
);
assert.equal(
  iamFullGateScript,
  '../scripts/iam-call-access-ci-gate.sh --full',
  'package.json must expose the explicit full IAM CI gate wrapper',
);
for (const contractPath of requiredIamContractPaths) {
  const scriptPath = contractPath.startsWith('frontend-vue/')
    ? contractPath.slice('frontend-vue/'.length)
    : `../${contractPath}`;
  assertIncludes(
    iamContractScript,
    scriptPath,
    `test:contract:iam-call-access must execute ${contractPath}`,
  );
}

assert.match(
  callAccessE2eScript,
  /^PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests\/e2e\/call-access-join\.spec\.js tests\/e2e\/call-access-seed-matrix\.spec\.js tests\/e2e\/call-access-calendar-unregistered-invite\.spec\.js tests\/e2e\/call-access-admin-join-boundaries\.spec\.js --workers=1$/,
  'focused call-access E2E script must stay limited to join, deterministic seed-matrix, calendar unregistered invite, and admin join boundary specs with IAM artifact retention enabled',
);
assert.doesNotMatch(
  callAccessE2eScript,
  /background|media|sfu|gossip/,
  'focused call-access E2E script must not pull media/background specs',
);
assert.match(
  lobbyConcurrencyScript,
  /^playwright test tests\/e2e\/lobby-concurrency-ui\.spec\.js$/,
  'focused lobby concurrency E2E script must stay a single lobby queue spec',
);

const requiredReleaseCommands = new Set(matrix.release_gate?.required_iam_call_access_commands || []);
for (const commandId of [
  'frontend:contract:iam-call-access',
  'frontend:e2e:call-access',
  'frontend:e2e:lobby-concurrency',
]) {
  assert.ok(
    requiredReleaseCommands.has(commandId),
    `release gate metadata must require ${commandId}`,
  );
}

const iamCommand = matrix.commands?.['frontend:contract:iam-call-access'] || {};
assert.equal(iamCommand.kind, 'npm_script', 'IAM contract command metadata must be an npm script');
assert.equal(iamCommand.working_directory, 'frontend-vue', 'IAM contract command must run from frontend-vue');
assert.equal(iamCommand.script, 'test:contract:iam-call-access', 'IAM contract command must bind to the stable package script');
assert.equal(iamCommand.command, 'npm run test:contract:iam-call-access', 'IAM contract command must expose the executable npm command');
const iamCommandPaths = new Set(Array.isArray(iamCommand.paths) ? iamCommand.paths : []);
for (const contractPath of requiredIamContractPaths) {
  assert.ok(iamCommandPaths.has(contractPath), `IAM contract command metadata must list ${contractPath}`);
}
for (const supportingPath of requiredIamSupportingPaths) {
  assert.ok(
    iamCommandPaths.has(supportingPath),
    `IAM contract command metadata must list Docker runtime proof support path ${supportingPath}`,
  );
}
assert.match(
  readText('demo/video-chat/backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh'),
  /find "\$\{SCRIPT_DIR\}" -maxdepth 1 -type f -name '\*docker-proof\.sh'/,
  'IAM Docker runtime wrapper must discover the listed Docker proof scripts from the backend tests directory',
);
const iamCiGatePath = path.join(repoRoot, 'demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const iamCiGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
assert.ok(
  (fs.statSync(iamCiGatePath).mode & 0o111) !== 0,
  'IAM CI gate wrapper must be executable',
);
assert.doesNotMatch(
  iamCiGate,
  bannedCommandPattern,
  'IAM CI gate wrapper must not execute parked Background/Gossip/SFU/Media gates',
);
assert.match(
  iamCiGate,
  /STATIC_CONTRACTS=\([\s\S]*iam-call-access-ci-wire-contract\.mjs[\s\S]*iam-local-run-docs-contract\.mjs/,
  'IAM CI gate wrapper must keep the host-safe static hygiene contracts explicit',
);
assert.match(
  iamCiGate,
  /--sqlite[\s\S]*iam-call-access-sqlite-runtime-proof\.sh/,
  'IAM CI gate wrapper must expose the SQLite backend proof mode',
);
assert.match(
  iamCiGate,
  /--docker[\s\S]*iam-backend-docker-runtime-proof-wrapper\.sh/,
  'IAM CI gate wrapper must expose Docker proof wrapper mode',
);
const sqliteProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
assert.match(
  sqliteProof,
  /Host PHP lacks pdo_sqlite; using container fallback/,
  'IAM SQLite runtime proof must announce Docker fallback when host pdo_sqlite is missing',
);
assert.match(
  sqliteProof,
  /docker-php-ext-install pdo_sqlite/,
  'IAM SQLite runtime proof must install pdo_sqlite inside the Docker fallback when needed',
);

const callAccessCommand = matrix.commands?.['frontend:e2e:call-access'] || {};
assert.deepEqual(
  callAccessCommand.paths,
  [
    'frontend-vue/tests/e2e/call-access-join.spec.js',
    'frontend-vue/tests/e2e/call-access-seed-matrix.spec.js',
    'frontend-vue/tests/e2e/call-access-calendar-unregistered-invite.spec.js',
    'frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js',
  ],
  'Call Access E2E metadata must stay limited to stable Sprint 02 call-access specs',
);
const lobbyCommand = matrix.commands?.['frontend:e2e:lobby-concurrency'] || {};
assert.deepEqual(
  lobbyCommand.paths,
  ['frontend-vue/tests/e2e/lobby-concurrency-ui.spec.js'],
  'Lobby concurrency E2E metadata must stay limited to the lobby queue proof',
);

const iamCiIndex = ciWorkflow.indexOf('npm run test:contract:iam-call-access');
const releaseGateCiIndex = ciWorkflow.indexOf('npm run test:e2e:release-gate');
assert.ok(iamCiIndex >= 0, 'canonical CI must run the IAM call-access contract gate');
assert.ok(releaseGateCiIndex >= 0, 'canonical CI must keep the frontend E2E release gate');
assert.ok(
  iamCiIndex < releaseGateCiIndex,
  'canonical CI should run IAM call-access contracts before E2E release-gate metadata validation',
);

process.stdout.write('[iam-call-access-ci-wire-contract] PASS\n');
