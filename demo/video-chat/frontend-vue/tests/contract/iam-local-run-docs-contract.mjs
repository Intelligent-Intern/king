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

const docs = readText('documentation/dev/video-chat/iam-call-access-local-tests.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const scripts = packageJson.scripts || {};
const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const ciWire = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const sqliteProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const dockerWrapper = readText('demo/video-chat/backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh');

const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
const bannedGatePattern = /test:contract:(background|media)|background-|media-reconnect|media-security|sfu-|gossip-/i;
const bannedCommandPattern = /npm run test:contract:(background|media|sfu|gossip)|node tests\/contract\/(?:background-|media-security|sfu-|gossip-)|\.\.\/backend-king-php\/tests\/realtime-gossip/i;

assert.match(
  String(scripts['test:contract:iam-local-run-docs'] || ''),
  /^node tests\/contract\/iam-local-run-docs-contract\.mjs$/,
  'package.json must expose the local IAM run documentation contract',
);
assert.match(
  iamContractScript,
  /node tests\/contract\/iam-local-run-docs-contract\.mjs/,
  'canonical IAM contract gate must execute the local run documentation contract',
);
assert.doesNotMatch(
  iamContractScript,
  bannedGatePattern,
  'canonical IAM contract gate must not invoke parked Background/Gossip/SFU/Media gates',
);
assert.match(
  String(scripts['test:ci:iam-call-access'] || ''),
  /^\.\.\/scripts\/iam-call-access-ci-gate\.sh --full$/,
  'package.json must expose the canonical IAM CI gate wrapper',
);
assert.match(
  String(scripts['test:ci:iam-call-access:static'] || ''),
  /^\.\.\/scripts\/iam-call-access-ci-gate\.sh --static$/,
  'package.json must expose the host-safe static IAM gate',
);
assert.match(
  String(scripts['test:ci:iam-call-access:sqlite'] || ''),
  /^\.\.\/scripts\/iam-call-access-ci-gate\.sh --sqlite$/,
  'package.json must expose the SQLite IAM backend proof gate',
);
assert.match(
  String(scripts['test:ci:iam-call-access:docker'] || ''),
  /^\.\.\/scripts\/iam-call-access-ci-gate\.sh --docker$/,
  'package.json must expose the IAM docker-proof wrapper gate',
);
assert.match(
  String(scripts['test:ci:iam-call-access:full'] || ''),
  /^\.\.\/scripts\/iam-call-access-ci-gate\.sh --full$/,
  'package.json must expose the explicit full IAM gate',
);

assert.match(
  callAccessE2eScript,
  /^PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests\/e2e\/call-access-join\.spec\.js tests\/e2e\/call-access-seed-matrix\.spec\.js tests\/e2e\/call-access-calendar-unregistered-invite\.spec\.js tests\/e2e\/call-access-admin-join-boundaries\.spec\.js --workers=1$/,
  'focused Call Access E2E script must stay limited to stable serial IAM specs',
);
assert.doesNotMatch(
  callAccessE2eScript,
  /background|media|sfu|gossip/i,
  'focused Call Access E2E script must not pull parked media/background specs',
);

for (const command of [
  'npm run test:ci:iam-call-access:static',
  'npm run test:ci:iam-call-access',
  'npm run test:ci:iam-call-access:full',
  'npm run test:ci:iam-call-access:sqlite',
  'npm run test:ci:iam-call-access:docker',
  'npm run test:e2e:call-access -- --reporter=list',
  'npx playwright test tests/e2e/call-access-join.spec.js --workers=1 --reporter=list',
]) {
  assert.match(docs, new RegExp(command.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `local IAM docs must include ${command}`);
}

assert.match(
  docs,
  /does not invoke Background,\s+Gossip,\s+SFU,\s+MediaSecurity,\s+or BTGF\s+gates/,
  'local IAM docs must state the IAM gate boundary',
);
assert.match(
  docs,
  /pdo_sqlite[\s\S]*Docker PHP fallback[\s\S]*blocked local environment/,
  'local IAM docs must explain host pdo_sqlite and Docker fallback behavior',
);
assert.match(
  docs,
  /--workers=1[\s\S]*access-link and[\s\S]*session state do not race/,
  'local IAM docs must explain serial focused browser execution',
);
assert.match(
  docs,
  /VIDEOCHAT_SMOKE_COMPOSE_ONLY=1[\s\S]*VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1[\s\S]*bash demo\/video-chat\/scripts\/smoke\.sh/,
  'local IAM docs must include the compose smoke command',
);
assert.match(
  docs,
  /VIDEOCHAT_SMOKE_SKIP_IAM_CI_GATE=1[\s\S]*after the[\s\S]*host-safe IAM gate has already passed/,
  'local IAM docs must bound the IAM gate skip escape hatch',
);

assert.match(ciGate, /--static[\s\S]*Run host-safe IAM command hygiene contracts/, 'CI gate script must document --static');
assert.match(ciGate, /--sqlite[\s\S]*SQLite IAM backend runtime proof/, 'CI gate script must document --sqlite');
assert.match(ciGate, /--docker[\s\S]*docker-proof wrappers/, 'CI gate script must document --docker');
assert.match(ciGate, /STATIC_CONTRACTS=\([\s\S]*iam-call-access-ci-wire-contract\.mjs[\s\S]*iam-ci-artifacts-contract\.mjs[\s\S]*iam-local-run-docs-contract\.mjs/, 'CI gate script must keep host-safe static IAM hygiene and artifact contracts explicit');
assert.match(ciGate, /test:contract:iam-call-access/, 'CI gate script must preserve the canonical package gate');
assert.doesNotMatch(ciGate, bannedCommandPattern, 'CI gate script must not execute parked test gates');

assert.match(
  sqliteProof,
  /Host PHP lacks pdo_sqlite; using container fallback/,
  'SQLite proof wrapper must clearly announce Docker fallback',
);
assert.match(sqliteProof, /docker-php-ext-install pdo_sqlite/, 'SQLite proof wrapper must install pdo_sqlite in Docker when needed');
assert.match(sqliteProof, /IAM_SQLITE_CONTRACTS/, 'SQLite proof wrapper must keep the focused contract override hook');
assert.match(
  dockerWrapper,
  /find "\$\{SCRIPT_DIR\}" -maxdepth 1 -type f -name '\*docker-proof\.sh'/,
  'Docker runtime proof wrapper must discover docker proof scripts from backend tests',
);
assert.match(dockerWrapper, /no docker-proof scripts found/, 'Docker runtime proof wrapper must fail closed when no proofs are present');
assert.match(
  ciWire,
  /frontend-vue\/tests\/contract\/iam-local-run-docs-contract\.mjs/,
  'CI wire contract must require the local run docs proof path',
);

process.stdout.write('[iam-local-run-docs-contract] PASS\n');
