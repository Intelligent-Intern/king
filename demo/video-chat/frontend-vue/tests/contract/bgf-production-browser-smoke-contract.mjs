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
const nativeAudioHarness = readUtf8('tests/e2e/helpers/nativeAudioTransferHarness.js');
const mediaPreferences = readUtf8('src/domain/realtime/media/preferences.ts');
const screenSharePublisher = readUtf8('src/domain/realtime/local/screenSharePublisher.js');
const runnerScript = readVideoChatUtf8('scripts/bgf-production-browser-smoke.sh');

if (packageJson.scripts['test:e2e:production-browser-smoke'] !== 'PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE=1 VIDEOCHAT_PRODUCTION_BROWSER_SMOKE=1 playwright test tests/e2e/background-production-browser-smoke.spec.js --workers=1 --project=production-chromium --project=production-firefox') {
  throw new Error('[bgf-production-browser-smoke-contract] production browser smoke script must run Chrome/Chromium and Firefox against the deployed smoke spec');
}

requireMatches(runnerScript, /^#!\/usr\/bin\/env bash/, 'bash runner shebang');
requireContains(runnerScript, 'set -euo pipefail', 'strict bash runner mode');
requireContains(runnerScript, 'LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"', 'local env file path');
requireContains(runnerScript, 'preserved_values', 'explicit env override preservation');
requireContains(runnerScript, 'local local_env_names=(', 'local env allowlist');
requireContains(runnerScript, 'declare -A allowed_env_names', 'local env allowlist map');
requireContains(runnerScript, 'parse_local_env_value()', 'inert local env parser');
requireContains(runnerScript, 'parse_single_quoted_env_value()', 'single-quoted local env value parser');
requireContains(runnerScript, 'parse_double_quoted_env_value()', 'double-quoted local env value parser');
requireContains(runnerScript, 'while IFS= read -r raw_line', 'line-by-line local env parsing');
requireContains(runnerScript, '^export[[:space:]]+(.+)$', 'optional export prefix parsing');
requireMissing(runnerScript, 'source "${LOCAL_ENV_FILE}"', 'source-based local env loading');
requireMissing(runnerScript, '. "${LOCAL_ENV_FILE}"', 'dot-source local env loading');
requireNotMatches(runnerScript, /(^|\n)\s*set\s+-a(\s|$)/, 'set -a env sourcing');
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
  [/\bdocker(?:\s+compose|-compose)\b/, 'compose mutation tooling'],
  [/(^|[^\w-])ssh([^\w-]|$)/, 'SSH command'],
  [/\b(dig|nslookup|hcloud|terraform|kubectl|doctl|aws|az|gcloud|cloudflare|cfcli)\b/, 'DNS or infrastructure tooling'],
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
requireMatches(playwrightConfig, /function assertNonLoopbackProductionOrigin[\s\S]*localhost[\s\S]*127\.0\.0\.1[\s\S]*0\.0\.0\.0[\s\S]*::1/, 'production smoke loopback origin rejection helper');
requireMatches(playwrightConfig, /assertNonLoopbackProductionOrigin\('baseURL', origins\.baseURL[\s\S]*assertNonLoopbackProductionOrigin\('backendOrigin', origins\.backendOrigin[\s\S]*assertNonLoopbackProductionOrigin\('sfuOrigin', origins\.sfuOrigin[\s\S]*assertNonLoopbackProductionOrigin\('wsOrigin', origins\.wsOrigin/, 'all production smoke origins are validated');

requireContains(smokeSpec, "BACKGROUND_SMOKE_FLAG = 'bgf07-segmentation-unavailable'", 'named BGF-07 smoke flag');
requireContains(smokeSpec, "query.set('kingrt_background_smoke', BACKGROUND_SMOKE_FLAG)", 'deployed smoke query flag');
requireContains(smokeSpec, "query.set('kingrt_background_force_segmentation_unavailable', '1')", 'deployed smoke force query');
requireContains(smokeSpec, 'createInvitedCallViaApi', 'real deployed call creation');
requireContains(smokeSpec, 'createPersonalAccessJoinPath', 'real deployed participant join path');
requireContains(smokeSpec, 'admitFirstLobbyUser', 'real lobby admission');
requireContains(smokeSpec, 'measureNativeAudioBridgeEnergy', 'audio proof');
requireContains(smokeSpec, 'sfuRemoteVideoSnapshot', 'remote video proof');
requireContains(smokeSpec, 'getDisplayMedia', 'screen-share proof');
requireContains(screenSharePublisher, "eventType: 'local_screen_share_participant_started'", 'actual screen-share participant started diagnostic');
requireContains(screenSharePublisher, "eventType: 'local_screen_share_participant_stopped'", 'actual screen-share participant stopped diagnostic');
requireContains(smokeSpec, "SCREEN_SHARE_STARTED_EVENT = 'local_screen_share_participant_started'", 'screen-share started diagnostics');
requireContains(smokeSpec, "SCREEN_SHARE_STOPPED_EVENT = 'local_screen_share_participant_stopped'", 'screen-share stopped diagnostics');
requireMissing(smokeSpec, "expectDiagnostics(admin.page, ['local_screen_share_started']", 'canonical-only screen-share started assertion');
requireMissing(smokeSpec, "expectDiagnostics(admin.page, ['local_screen_share_stopped']", 'canonical-only screen-share stopped assertion');
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
requireMissing(smokeSpec, 'adminCredentials as defaultAdminCredentials', 'production smoke default admin credentials');
requireMissing(smokeSpec, 'userCredentials as defaultUserCredentials', 'production smoke default user credentials');
requireMissing(smokeSpec, 'defaultAdminCredentials', 'production smoke admin default fallback');
requireMissing(smokeSpec, 'defaultUserCredentials', 'production smoke user default fallback');
requireMissing(smokeSpec, '|| defaults.email', 'production smoke email default fallback');
requireMissing(smokeSpec, '|| defaults.password', 'production smoke password default fallback');
requireContains(smokeSpec, 'VIDEOCHAT_PRODUCTION_ADMIN_EMAIL', 'explicit production admin email env');
requireContains(smokeSpec, 'VIDEOCHAT_PRODUCTION_ADMIN_PASSWORD', 'explicit production admin password env');
requireContains(smokeSpec, 'VIDEOCHAT_PRODUCTION_USER_EMAIL', 'explicit production user email env');
requireContains(smokeSpec, 'VIDEOCHAT_PRODUCTION_USER_PASSWORD', 'explicit production user password env');
requireMatches(smokeSpec, /const status = Number\(request\.status \|\| 0\);[\s\S]*status >= 200 && status < 300/, 'diagnostic proof only counts 2xx posts');
requireMatches(smokeSpec, /failedDiagnosticStatuses[\s\S]*toEqual\(\[\]\)/, 'failed diagnostics posts fail the smoke');
requireMatches(smokeSpec, /expectDiagnostics[\s\S]*timeout:\s*45_000/, 'diagnostics poll exceeds normal flush timer');
requireContains(smokeSpec, 'function sanitizeUrl', 'artifact URL sanitizer');
requireContains(smokeSpec, 'function sanitizeArtifactValue', 'artifact payload sanitizer');
requireContains(smokeSpec, 'sanitizeDiagnosticBody', 'diagnostics body sanitizer');
requireContains(smokeSpec, 'REDACTED_MEDIA_PAYLOAD', 'media payload redaction marker');
requireContains(smokeSpec, 'JSON.stringify(sanitizeArtifactValue(payload), null, 2)', 'artifact writes sanitized JSON');
requireContains(smokeSpec, 'sentCount: Array.isArray(socket.sent) ? socket.sent.length : 0', 'socket sent count only');
requireContains(smokeSpec, 'sentCount: Number(socket.sentCount || 0)', 'persisted socket sent count summary');
requireMatches(smokeSpec, /session\|token\|access\|invite\|link/, 'session and token-like artifact redaction keys');
requireMissing(smokeSpec, 'sent: socket.sent', 'raw websocket sent payload persistence');
requireContains(smokeSpec, 'async function safeSmokeScreenshot', 'masked smoke screenshot helper');
requireContains(smokeSpec, 'mask: [', 'screenshots mask media and sensitive UI');
requireContains(smokeSpec, 'async function waitForSocketSettle(page', 'socket settle before focus proof');
requireMatches(smokeSpec, /function isProductionSmokeSocketUrl[\s\S]*\/\(\?:ws\|sfu\)/, 'socket failure proof filters production WS/SFU sockets');
requireMissing(smokeSpec, 'await admin.page.waitForTimeout(1_500);', 'fixed focus wait instead of socket settle');

requireMissing(smokeSpec, 'backgroundReplacementUnavailablePromptOpen = true', 'direct Vue modal state mutation');
requireMissing(smokeSpec, 'forceBackgroundUnavailablePrompt', 'synthetic modal helper');
const mediaPrefsVersion = mediaPreferences.match(/CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION = (\d+);/)?.[1] || '';
if (mediaPrefsVersion === '') {
  throw new Error('[bgf-production-browser-smoke-contract] Missing media preferences version constant');
}
requireContains(smokeSpec, `outgoing_video_quality_profile_version: ${mediaPrefsVersion},`, 'smoke media prefs version matches runtime');
requireContains(nativeAudioHarness, `outgoing_video_quality_profile_version: ${mediaPrefsVersion},`, 'native audio harness media prefs version matches runtime');
requireMissing(nativeAudioHarness, 'outgoing_video_quality_profile_version: 3', 'stale native audio harness prefs version');
requireMissing(nativeAudioHarness, ".includes('/sfu')", 'substring SFU URL matching');

console.log('[bgf-production-browser-smoke-contract] PASS');
