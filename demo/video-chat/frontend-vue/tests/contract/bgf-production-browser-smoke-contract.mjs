import fs from 'node:fs';

function readUtf8(path) {
  return fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
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

const packageJson = JSON.parse(readUtf8('package.json'));
const playwrightConfig = readUtf8('playwright.config.js');
const smokeSpec = readUtf8('tests/e2e/background-production-browser-smoke.spec.js');

if (packageJson.scripts['test:e2e:production-browser-smoke'] !== 'PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE=1 VIDEOCHAT_PRODUCTION_BROWSER_SMOKE=1 playwright test tests/e2e/background-production-browser-smoke.spec.js --workers=1 --project=production-chromium --project=production-firefox') {
  throw new Error('[bgf-production-browser-smoke-contract] production browser smoke script must run Chrome/Chromium and Firefox against the deployed smoke spec');
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
