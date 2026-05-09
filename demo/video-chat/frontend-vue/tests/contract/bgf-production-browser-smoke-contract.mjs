import fs from 'node:fs';

function readUtf8(path) {
  return fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
}

function readVideoChatUtf8(path) {
  return fs.readFileSync(new URL(`../../../${path}`, import.meta.url), 'utf8');
}

function requireContains(source, needle, label) {
  if (!source.includes(needle)) {
    throw new Error(`[bgf-production-browser-smoke-contract] Missing ${label}: ${needle}`);
  }
}

function requireMissing(source, needle, label) {
  if (source.includes(needle)) {
    throw new Error(`[bgf-production-browser-smoke-contract] Forbidden ${label}: ${needle}`);
  }
}

function requireMatches(source, pattern, label) {
  if (!pattern.test(source)) {
    throw new Error(`[bgf-production-browser-smoke-contract] Missing ${label}: ${pattern}`);
  }
}

function requireNotMatches(source, pattern, label) {
  if (pattern.test(source)) {
    throw new Error(`[bgf-production-browser-smoke-contract] Forbidden ${label}: ${pattern}`);
  }
}

const packageJson = JSON.parse(readUtf8('package.json'));
const playwrightConfig = readUtf8('playwright.config.js');
const smokeSpec = readUtf8('tests/e2e/background-production-browser-smoke.spec.js');
const runnerScript = readVideoChatUtf8('scripts/bgf-production-browser-smoke.sh');

if (packageJson.scripts['test:e2e:production-browser-smoke'] !== 'PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE=1 VIDEOCHAT_PRODUCTION_BROWSER_SMOKE=1 playwright test tests/e2e/background-production-browser-smoke.spec.js --workers=1 --project=production-chromium --project=production-firefox') {
  throw new Error('[bgf-production-browser-smoke-contract] production browser smoke script must run Chrome/Chromium and Firefox against the deployed smoke spec');
}

requireMatches(runnerScript, /^#!\/usr\/bin\/env bash/, 'bash runner shebang');
requireContains(runnerScript, 'set -euo pipefail', 'strict bash runner mode');
requireContains(runnerScript, 'LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"', 'local env file source');
requireContains(runnerScript, 'preserved_values', 'explicit env override preservation');
requireContains(runnerScript, 'source "${LOCAL_ENV_FILE}"', 'local env loading');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_DOMAIN:-${DEPLOY_DOMAIN:-${VIDEOCHAT_V1_PUBLIC_HOST:-kingrt.com}}', 'kingrt.com deploy domain default');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_APP_DOMAIN', 'deployed app domain normalization');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_API_DOMAIN', 'deployed API domain normalization');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_WS_DOMAIN', 'deployed websocket domain normalization');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_SFU_DOMAIN', 'deployed SFU domain normalization');
requireContains(runnerScript, 'PLAYWRIGHT_PRODUCTION_BASE_URL', 'deployed app origin export');
requireContains(runnerScript, 'VITE_VIDEOCHAT_BACKEND_ORIGIN', 'deployed API origin export');
requireContains(runnerScript, 'VITE_VIDEOCHAT_WS_ORIGIN', 'deployed websocket origin export');
requireContains(runnerScript, 'VITE_VIDEOCHAT_SFU_ORIGIN', 'deployed SFU origin export');
requireContains(runnerScript, 'VIDEOCHAT_PRODUCTION_ADMIN_EMAIL', 'production admin credential support');
requireContains(runnerScript, 'VIDEOCHAT_PRODUCTION_USER_EMAIL', 'production user credential support');
requireContains(runnerScript, 'VIDEOCHAT_E2E_ADMIN_PASSWORD', 'E2E admin credential support');
requireContains(runnerScript, 'VIDEOCHAT_E2E_USER_PASSWORD', 'E2E user credential support');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE', 'deploy admin password file support');
requireContains(runnerScript, 'VIDEOCHAT_DEPLOY_USER_PASSWORD_FILE', 'deploy user password file support');
requireContains(runnerScript, 'VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN', 'dry-run mode');
requireContains(runnerScript, 'print_dry_run()', 'redacted dry-run config output');
requireContains(runnerScript, 'redacted_value()', 'secret redaction helper');
requireContains(runnerScript, 'set_env_var PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE "1"', 'Playwright smoke flag export');
requireContains(runnerScript, 'set_env_var VIDEOCHAT_PRODUCTION_BROWSER_SMOKE "1"', 'videochat smoke flag export');
requireContains(runnerScript, 'NPM_SCRIPT="test:e2e:production-browser-smoke"', 'frontend npm smoke script selection');
requireContains(runnerScript, 'npm run "${NPM_SCRIPT}"', 'frontend npm smoke command');

for (const [pattern, label] of [
  [/\bdeploy\.sh\b/, 'deploy script invocation'],
  [/\bdeploy-smoke\.sh\b/, 'deploy smoke invocation'],
  [/\bprod-debug\.sh\b/, 'prod debug invocation'],
  [/\bcertbot\b/, 'certificate tooling'],
  [/\bdocker\s+compose\b/, 'compose mutation tooling'],
  [/(^|[^\w-])ssh([^\w-]|$)/, 'SSH command'],
  [/\b(dig|nslookup|hcloud|terraform|kubectl)\b/, 'DNS or infrastructure tooling'],
  [/\bplaywright\s+test\b/, 'direct Playwright command instead of npm script'],
  [/\b(curl|wget)\b/, 'direct network probe tooling'],
]) {
  requireNotMatches(runnerScript, pattern, label);
}

requireContains(playwrightConfig, 'PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE', 'production Playwright mode flag');
requireContains(playwrightConfig, 'production-chromium', 'production Chromium project');
requireContains(playwrightConfig, 'production-firefox', 'production Firefox project');
requireContains(playwrightConfig, 'https://app.kingrt.com', 'deployed app default origin');
requireContains(playwrightConfig, "protocol: 'https'", 'deployed HTTPS API origin protocol');
requireContains(playwrightConfig, "protocol: 'wss'", 'deployed websocket origin protocol');
requireContains(playwrightConfig, "subdomain: 'api'", 'deployed API origin subdomain');
requireContains(playwrightConfig, "subdomain: 'sfu'", 'deployed SFU origin subdomain');
requireContains(playwrightConfig, "subdomain: 'ws'", 'deployed websocket origin subdomain');
requireContains(playwrightConfig, 'webServer: localWebServer', 'local webserver must stay local-only');

requireContains(smokeSpec, "BACKGROUND_SMOKE_FLAG = 'bgf07-segmentation-unavailable'", 'named BGF-07 smoke flag');
requireContains(smokeSpec, "query.set('kingrt_background_smoke', BACKGROUND_SMOKE_FLAG)", 'deployed smoke query flag');
requireContains(smokeSpec, "query.set('kingrt_background_force_segmentation_unavailable', '1')", 'deployed smoke force query');
requireContains(smokeSpec, 'createInvitedCallViaApi', 'real deployed call creation');
requireContains(smokeSpec, 'createPersonalAccessJoinPath', 'real deployed participant join path');
requireContains(smokeSpec, 'admitFirstLobbyUser', 'real lobby admission');
requireContains(smokeSpec, 'measureNativeAudioBridgeEnergy', 'audio proof');
requireContains(smokeSpec, 'sfuRemoteVideoSnapshot', 'remote video proof');
requireContains(smokeSpec, 'getDisplayMedia', 'screen-share proof');
requireContains(smokeSpec, 'local_screen_share_started', 'screen-share started diagnostics');
requireContains(smokeSpec, 'local_screen_share_stopped', 'screen-share stopped diagnostics');
requireContains(smokeSpec, 'local_background_backend_init', 'background backend diagnostics');
requireContains(smokeSpec, 'local_background_matte_rejected', 'background matte rejection diagnostics');
requireContains(smokeSpec, 'local_background_replacement_unavailable', 'background unavailable diagnostics');
requireContains(smokeSpec, 'local_background_replacement_modal_choice', 'background modal choice diagnostics');
requireContains(smokeSpec, "'Use standard avatar',", 'standard avatar choice assertion');
requireContains(smokeSpec, "'Upload avatar',", 'uploaded avatar choice assertion');
requireContains(smokeSpec, "'Send unfiltered video'", 'unfiltered video choice assertion');
requireContains(smokeSpec, 'socketFailureCount(afterFocus)', 'focus stability socket assertion');
requireContains(smokeSpec, 'reconnectDiagnostics(afterFocus)', 'focus stability reconnect diagnostics assertion');
requireContains(smokeSpec, 'testInfo.outputPath', 'browser proof artifacts');
requireContains(smokeSpec, 'browser.version()', 'browser version proof');

requireMissing(smokeSpec, 'backgroundReplacementUnavailablePromptOpen = true', 'direct Vue modal state mutation');
requireMissing(smokeSpec, 'forceBackgroundUnavailablePrompt', 'synthetic modal helper');

console.log('[bgf-production-browser-smoke-contract] PASS');
