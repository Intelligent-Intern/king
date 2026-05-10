import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const contractName = 'vcap-readiness-gate-split-contract';
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');

function fail(message) {
  throw new Error(`[${contractName}] FAIL: ${message}`);
}

function readJson(relativePath) {
  return JSON.parse(fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8'));
}

function readVideoChat(relativePath) {
  return fs.readFileSync(path.resolve(videoChatRoot, relativePath), 'utf8');
}

function assertPathExists(relativePath, label) {
  const absolutePath = path.resolve(frontendRoot, relativePath);
  assert.ok(fs.existsSync(absolutePath), `${label} must exist: ${relativePath}`);
}

function assertScript(scripts, name, expected) {
  assert.equal(scripts[name], expected, `${name} must stay a focused VCAP gate`);
}

function expandNpmScript(scripts, name, seen = new Set()) {
  assert.equal(typeof scripts[name], 'string', `${name} must exist`);
  if (seen.has(name)) return '';
  seen.add(name);

  const command = scripts[name];
  const nested = [];
  for (const match of command.matchAll(/\bnpm run ([a-z0-9:_-]+)/gi)) {
    nested.push(expandNpmScript(scripts, match[1], seen));
  }
  return [command, ...nested].filter(Boolean).join('\n');
}

function assertNoMatches(source, forbidden, label) {
  for (const [pattern, reason] of forbidden) {
    assert.doesNotMatch(source, pattern, `${label} must not include ${reason}`);
  }
}

try {
  const packageJson = readJson('package.json');
  const scripts = packageJson.scripts || {};

  const expectedScripts = {
    'test:contract:vcap:gate-split': 'node tests/contract/vcap-readiness-gate-split-contract.mjs',
    'test:contract:vcap:capability-media-plan': 'node tests/contract/client-capabilities-media-plan-contract.mjs && php ../backend-king-php/tests/media-capability-plan-contract.php',
    'test:contract:vcap:package-config': 'npm run test:contract:vcap:gate-split && node tests/contract/call-app-package-layout-contract.mjs && node tests/contract/backend-origin-production-contract.mjs && node tests/contract/bgf-production-browser-smoke-contract.mjs',
    'test:vcap:readiness:local': 'npm run test:contract:vcap:capability-media-plan && npm run test:contract:vcap:package-config && npm run build && npm run test:contract:build-size',
    'test:vcap:readiness:online-browser': '../scripts/bgf-production-browser-smoke.sh',
  };

  for (const [name, command] of Object.entries(expectedScripts)) {
    assertScript(scripts, name, command);
  }

  for (const [relativePath, label] of [
    ['tests/contract/vcap-readiness-gate-split-contract.mjs', 'VCAP gate split contract'],
    ['tests/contract/client-capabilities-media-plan-contract.mjs', 'frontend capability/media-plan contract'],
    ['../backend-king-php/tests/media-capability-plan-contract.php', 'backend capability/media-plan contract'],
    ['tests/contract/call-app-package-layout-contract.mjs', 'package layout contract'],
    ['tests/contract/backend-origin-production-contract.mjs', 'production origin config contract'],
    ['tests/contract/bgf-production-browser-smoke-contract.mjs', 'static browser-smoke config contract'],
    ['tests/contract/frontend-build-chunk-size-contract.mjs', 'build package size contract'],
  ]) {
    assertPathExists(relativePath, label);
  }

  const localGate = expandNpmScript(scripts, 'test:vcap:readiness:local');
  for (const required of [
    'client-capabilities-media-plan-contract.mjs',
    'media-capability-plan-contract.php',
    'vcap-readiness-gate-split-contract.mjs',
    'call-app-package-layout-contract.mjs',
    'backend-origin-production-contract.mjs',
    'bgf-production-browser-smoke-contract.mjs',
    'vite build',
    'frontend-build-chunk-size-contract.mjs',
  ]) {
    assert.ok(localGate.includes(required), `local VCAP readiness must include ${required}`);
  }

  assert.ok(
    !localGate.includes('../scripts/bgf-production-browser-smoke.sh'),
    'local VCAP readiness must leave the online browser smoke in its own optional gate',
  );

  const forbiddenLocalPatterns = [
    [/\bplaywright\s+test\b/, 'browser execution'],
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy\.sh\b/, 'deploy.sh invocation'],
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy-smoke\.sh\b/, 'deploy-smoke invocation'],
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/prod-debug\.sh\b/, 'production debug invocation'],
    [/\b(curl|wget|ssh|scp|rsync|certbot|hcloud|terraform|kubectl|doctl|aws|az|gcloud)\b/, 'network or infrastructure tooling'],
    [/\bdocker(?:\s+compose|-compose)?\s+(?:up|down|restart|rm|kill|pull|push|exec|run)\b/, 'mutating Docker tooling'],
  ];
  assertNoMatches(localGate, forbiddenLocalPatterns, 'local VCAP readiness');

  const onlineGate = scripts['test:vcap:readiness:online-browser'];
  assert.ok(
    onlineGate.includes('../scripts/bgf-production-browser-smoke.sh'),
    'online browser gate must use the dedicated browser smoke runner',
  );
  assertNoMatches(onlineGate, [
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy\.sh\b/, 'deploy.sh invocation'],
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy-smoke\.sh\b/, 'deploy-smoke invocation'],
  ], 'online VCAP browser smoke gate');

  const browserSmokeRunner = readVideoChat('scripts/bgf-production-browser-smoke.sh');
  assert.match(browserSmokeRunner, /NPM_SCRIPT="test:e2e:production-browser-smoke"/, 'browser smoke runner must delegate to the existing e2e smoke script');
  assert.match(browserSmokeRunner, /VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN/, 'browser smoke runner must keep dry-run support for local command validation');
  assertNoMatches(browserSmokeRunner, [
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy\.sh\b/, 'deploy.sh invocation'],
    [/(^|\s)(?:\.{0,2}\/)?(?:demo\/video-chat\/)?scripts\/deploy-smoke\.sh\b/, 'deploy-smoke invocation'],
    [/\bdocker(?:\s+compose|-compose)?\s+(?:up|down|restart|rm|kill|pull|push|exec|run)\b/, 'mutating Docker tooling'],
  ], 'online VCAP browser smoke runner');

  process.stdout.write(`[${contractName}] PASS\n`);
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
