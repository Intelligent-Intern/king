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

function assertNoPrivateArtifactMetadata(value, label) {
  const text = String(value || '');
  assert.doesNotMatch(
    text,
    /\b(?:authorization|bearer|cookie|set-cookie|session(?:id|token)?|token|secret|password|participant_email|verified_session_id|verified_user_id)\b/i,
    `${label} must not include private auth/person metadata`,
  );
  assert.doesNotMatch(
    text,
    /\b(?:access[_-]?id|access[_-]?link|invite[_-]?(?:id|secret|token)|join[_-]?path)\b/i,
    `${label} must not include access-link or invite identifiers`,
  );
}

const workflow = readText('.github/workflows/ci.yml');
const smoke = readText('demo/video-chat/scripts/smoke.sh');
const playwrightConfig = readText('demo/video-chat/frontend-vue/playwright.config.js');
const docs = readText('documentation/dev/video-chat.md');
const localRunDocs = readText('documentation/dev/video-chat/iam-call-access-local-tests.md');
const ciGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const scripts = packageJson.scripts || {};
const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');
const iamCommandPaths = new Set(
  Array.isArray(matrix.commands?.['frontend:contract:iam-call-access']?.paths)
    ? matrix.commands['frontend:contract:iam-call-access'].paths
    : [],
);

assert.match(
  iamContractScript,
  /node tests\/contract\/iam-ci-artifacts-contract\.mjs/,
  'IAM Call Access contract package script must include the CI artifact proof',
);
assert.ok(
  iamCommandPaths.has('frontend-vue/tests/contract/iam-ci-artifacts-contract.mjs'),
  'IAM release-gate metadata must list the CI artifact proof contract',
);
assert.match(
  ciGate,
  /STATIC_CONTRACTS=\([\s\S]*node tests\/contract\/iam-ci-artifacts-contract\.mjs[\s\S]*\)/,
  'IAM call-access CI gate must run the CI artifact proof in host-safe mode',
);

assert.match(
  workflow,
  /Run video-chat compose smoke gate[\s\S]*VIDEOCHAT_SMOKE_COMPOSE_ONLY=1[\s\S]*VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1[\s\S]*VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts\/video-chat-smoke[\s\S]*bash demo\/video-chat\/scripts\/smoke\.sh/,
  'canonical CI must run compose smoke with a deterministic artifact directory',
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
  /backend-health-timeout[\s\S]*frontend-health-timeout[\s\S]*runtime-health-timeout[\s\S]*frontend-call-access-e2e-failure/,
  'compose smoke must tag readiness and focused Call Access E2E failures in the artifact manifest',
);
assert.match(
  smoke,
  /-e "CI=1"[\s\S]*PLAYWRIGHT_IAM_CALL_ACCESS_OUTPUT_DIR='test-results\/iam-call-access'[\s\S]*PLAYWRIGHT_IAM_CALL_ACCESS_HTML_REPORT_DIR='playwright-report\/iam-call-access'[\s\S]*npm run test:e2e:call-access -- --reporter=list --workers=1/,
  'compose smoke must run focused Call Access E2E with CI Playwright artifact settings',
);

assert.match(
  playwrightConfig,
  /screenshot:\s*'only-on-failure'/,
  'Playwright must retain screenshots for failed E2E tests',
);
assert.match(
  playwrightConfig,
  /trace:\s*iamCallAccessArtifacts \? 'retain-on-failure' : 'on-first-retry'/,
  'IAM browser proof must retain failure traces on the first failed run',
);
assert.match(
  playwrightConfig,
  /video:\s*process\.env\.CI \? 'retain-on-failure' : 'off'/,
  'Playwright must retain E2E video on CI failures without forcing local video capture',
);
assert.match(
  playwrightConfig,
  /outputDir:\s*iamCallAccessArtifacts \? iamCallAccessArtifactOutputDir : undefined/,
  'IAM browser proof must route failure artifacts into the deterministic output directory',
);
assert.match(
  playwrightConfig,
  /\['html', \{ open: 'never', outputFolder: iamCallAccessHtmlReportDir \}\]/,
  'IAM browser proof must emit a deterministic HTML report folder in artifact mode',
);

for (const artifactPath of [
  'compat-artifacts/video-chat-smoke',
  'test-results/iam-call-access',
  'playwright-report/iam-call-access',
]) {
  assertNoPrivateArtifactMetadata(artifactPath, artifactPath);
}

for (const docSource of [docs, localRunDocs]) {
  assert.match(
    docSource,
    /VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts\/video-chat-smoke/,
    'video-chat docs must document the CI artifact directory for the compose smoke',
  );
  assert.match(
    docSource,
    /video-chat-smoke-e2e-failure-artifacts/,
    'video-chat docs must document the GitHub Actions failure artifact name',
  );
  assert.match(
    docSource,
    /playwright-test-results[\s\S]*compose-all\.log/,
    'video-chat docs must document that Playwright artifacts and compose logs are collected',
  );
}

process.stdout.write('[iam-ci-artifacts-contract] PASS\n');
