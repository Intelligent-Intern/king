import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../../../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function fail(message) {
  throw new Error(`[iam-ci-artifacts-contract] FAIL: ${message}`);
}

try {
  const workflow = readText('.github/workflows/ci.yml');
  const smoke = readText('demo/video-chat/scripts/smoke.sh');
  const playwrightConfig = readText('demo/video-chat/frontend-vue/playwright.config.js');
  const docs = readText('documentation/dev/video-chat.md');
  const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
  const iamContractSuite = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs');
  const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
  const scripts = packageJson.scripts || {};

  assert.match(
    String(scripts['test:contract:iam-call-access'] || ''),
    /iam-call-access-contract-suite\.mjs/,
    'IAM Call Access contract package script must run the shared contract suite',
  );
  assert.match(
    iamContractSuite,
    /tests\/contract\/iam-ci-artifacts-contract\.mjs/,
    'IAM Call Access contract suite must include CI artifact proof',
  );
  assert.match(
    ciGate,
    /tests\/contract\/iam-ci-artifacts-contract\.mjs/,
    'IAM call-access CI gate must run the CI artifact proof in host-safe mode',
  );

  assert.match(
    workflow,
    /Run video-chat compose smoke gate[\s\S]*VIDEOCHAT_SMOKE_COMPOSE_ONLY=1[\s\S]*VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1[\s\S]*VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts\/video-chat-smoke[\s\S]*bash demo\/video-chat\/scripts\/smoke\.sh/,
    'canonical CI must run the compose smoke, including the focused Call Access E2E suite, with an artifact directory',
  );
  assert.match(
    workflow,
    /Upload video-chat E2E failure artifacts[\s\S]*if: failure\(\) && matrix\.shard-index == 1[\s\S]*actions\/upload-artifact@v6[\s\S]*video-chat-smoke-e2e-failure-artifacts[\s\S]*compat-artifacts\/video-chat-smoke\//,
    'canonical CI must upload video-chat E2E failure artifacts when the smoke gate fails',
  );

  assert.match(
    smoke,
    /collect_compose_artifacts\(\)/,
    'compose smoke must have a dedicated failure artifact collector',
  );
  assert.match(
    smoke,
    /manifest\.env[\s\S]*compose-ps\.txt[\s\S]*compose-all\.log/,
    'compose smoke artifacts must include a manifest, compose status, and aggregate logs',
  );
  assert.match(
    smoke,
    /videochat-backend-v1 videochat-backend-ws-v1 videochat-backend-sfu-v1 videochat-frontend-v1/,
    'compose smoke artifacts must include backend, websocket, SFU, and frontend service logs',
  );
  assert.match(
    smoke,
    /videochat-frontend-v1:\/app\/test-results[\s\S]*playwright-test-results/,
    'compose smoke artifacts must copy Playwright test-results from the frontend container',
  );
  assert.match(
    smoke,
    /videochat-frontend-v1:\/app\/playwright-report[\s\S]*playwright-report/,
    'compose smoke artifacts must copy the Playwright HTML report when present',
  );
  assert.match(
    smoke,
    /frontend-call-access-e2e-failure/,
    'compose smoke must tag focused Call Access E2E failures in the artifact manifest',
  );
  assert.match(
    smoke,
    /-e "CI=1"[\s\S]*npm run test:e2e:call-access -- --reporter=list --workers=1/,
    'compose smoke must run the focused Call Access E2E command with CI Playwright artifact settings',
  );

  assert.match(
    playwrightConfig,
    /screenshot:\s*'only-on-failure'/,
    'Playwright must retain screenshots for failed E2E tests',
  );
  assert.match(
    playwrightConfig,
    /trace:\s*'on-first-retry'/,
    'Playwright must retain retry traces for failed/flaky E2E tests',
  );
  assert.match(
    playwrightConfig,
    /video:\s*process\.env\.CI \? 'retain-on-failure' : 'off'/,
    'Playwright must retain E2E video on CI failures without forcing local video capture',
  );

  assert.match(
    docs,
    /VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts\/video-chat-smoke/,
    'video-chat docs must document the CI artifact directory for the compose smoke',
  );
  assert.match(
    docs,
    /video-chat-smoke-e2e-failure-artifacts/,
    'video-chat docs must document the GitHub Actions failure artifact name',
  );
  assert.match(
    docs,
    /playwright-test-results[\s\S]*compose-all\.log/,
    'video-chat docs must document that Playwright artifacts and compose logs are collected',
  );

  process.stdout.write('[iam-ci-artifacts-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
