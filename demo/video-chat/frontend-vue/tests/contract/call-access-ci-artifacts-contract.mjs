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

function assertNoArtifactSecretMetadata(value, label) {
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
  assert.doesNotMatch(
    text,
    /[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/i,
    `${label} must not include UUID-shaped access links or call ids`,
  );
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const playwrightConfig = readText('demo/video-chat/frontend-vue/playwright.config.js');
const scripts = packageJson.scripts || {};
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');

assert.match(
  callAccessE2eScript,
  /^PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests\/e2e\/call-access-join\.spec\.js tests\/e2e\/call-access-seed-matrix\.spec\.js tests\/e2e\/call-access-calendar-unregistered-invite\.spec\.js tests\/e2e\/call-access-admin-join-boundaries\.spec\.js --workers=1$/,
  'IAM Call Access E2E command must be stable, focused, serial, and opt into deterministic artifacts',
);
assert.match(
  iamContractScript,
  /node tests\/contract\/call-access-ci-artifacts-contract\.mjs/,
  'IAM contract gate must run the CI artifact contract before browser proof drift can merge',
);

assert.match(
  playwrightConfig,
  /const iamCallAccessArtifacts = process\.env\.PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS === '1';/,
  'Playwright config must expose an IAM-only artifact retention switch',
);
assert.match(
  playwrightConfig,
  /const iamCallAccessArtifactOutputDir = process\.env\.PLAYWRIGHT_IAM_CALL_ACCESS_OUTPUT_DIR \|\| 'test-results\/iam-call-access';/,
  'IAM Playwright artifacts must have a deterministic output directory with an env override',
);
assert.match(
  playwrightConfig,
  /const iamCallAccessHtmlReportDir = process\.env\.PLAYWRIGHT_IAM_CALL_ACCESS_HTML_REPORT_DIR \|\| 'playwright-report\/iam-call-access';/,
  'IAM Playwright HTML report must have a deterministic folder with an env override',
);
assert.match(
  playwrightConfig,
  /trace:\s*iamCallAccessArtifacts \? 'retain-on-failure' : 'on-first-retry'/,
  'IAM browser proof must retain failure traces on the first failed run',
);
assert.match(
  playwrightConfig,
  /screenshot:\s*'only-on-failure'/,
  'IAM browser proof must retain screenshots only for failures',
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

assertNoArtifactSecretMetadata('test-results/iam-call-access', 'IAM artifact output directory');
assertNoArtifactSecretMetadata('playwright-report/iam-call-access', 'IAM HTML report directory');
assertNoArtifactSecretMetadata(callAccessE2eScript, 'IAM E2E command');

assert.doesNotMatch(
  callAccessE2eScript,
  /background|media-security|media-reconnect|sfu|gossip|btgf/i,
  'IAM artifact proof command must not pull Background/Gossip/SFU/MediaSecurity/BTGF tests',
);
assert.doesNotMatch(
  playwrightConfig.match(/iamCallAccessArtifactOutputDir[\s\S]*?iamCallAccessHtmlReportDir[\s\S]*?;/)?.[0] || '',
  /Date\.now|Math\.random|crypto\.randomUUID|process\.pid|testInfo\.title|testInfo\.project/i,
  'IAM artifact paths must not be generated from dynamic titles, ids, or runtime data',
);

process.stdout.write('[call-access-ci-artifacts-contract] PASS\n');
