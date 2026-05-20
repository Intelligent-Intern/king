import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function section(source, start, end, label) {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `${label} start missing`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `${label} end missing`);
  return source.slice(startIndex, endIndex);
}

const app = readFrontend('src/App.vue');
const assetVersion = readFrontend('src/support/assetVersion.ts');
const socketLifecycle = readFrontend('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const packageJson = JSON.parse(readFrontend('package.json'));

assert.match(
  app,
  /const BUILD_VERSION_CHECK_INTERVAL_MS = 30000;/,
  'build-version guard may poll for stale assets, but the call workspace must suppress reloads',
);
assert.match(
  app,
  /function isCallWorkspacePath\(path = ''\) \{[\s\S]*startsWith\('\/workspace\/call'\)/,
  'app must recognize active call workspace paths',
);

const scheduleReloadBlock = section(
  app,
  'function scheduleBuildVersionReload() {',
  '\n\nasync function checkForBuildVersionMismatch()',
  'build-version reload scheduler',
);
assert.match(
  scheduleReloadBlock,
  /if \(isCallWorkspacePath\(route\.path\) \|\| isCallWorkspacePath\(currentBrowserPath\(\)\)\) \{[\s\S]*return;[\s\S]*\}[\s\S]*window\.location\.reload\(\);/,
  'build-version mismatch must not hard-reload active call pages',
);

const mismatchCheckBlock = section(
  app,
  'async function checkForBuildVersionMismatch() {',
  '\n\nfunction handleBuildVersionGuardTrigger()',
  'build-version mismatch check',
);
assert.match(
  mismatchCheckBlock,
  /if \(isCallWorkspacePath\(route\.path\) \|\| isCallWorkspacePath\(currentBrowserPath\(\)\)\) return;[\s\S]*fetchLiveBuildVersion/,
  'active call pages must skip the periodic build-version fetch/reload path entirely',
);

const routeWatchBlock = section(
  app,
  'watch(\n  () => route.path,',
  '\n\nonMounted',
  'build-version route watcher',
);
assert.match(
  routeWatchBlock,
  /buildVersionReloadPending[\s\S]*!isCallWorkspacePath\(route\.path\)[\s\S]*!isCallWorkspacePath\(currentBrowserPath\(\)\)[\s\S]*window\.location\.reload\(\);/,
  'a deferred stale-asset reload may only happen after the user leaves the call workspace',
);

assert.match(
  assetVersion,
  /function shouldUseAssetVersionHintOnly\(\) \{[\s\S]*return isCallWorkspacePath\(currentPathname\(\)\);[\s\S]*\}/,
  'asset-version support must downgrade active call mismatches to a non-reload hint',
);
const staleAssetBlock = section(
  assetVersion,
  "function handleStaleAssetVersion(reason = 'asset_version_mismatch', targetAssetVersion = '', options = {}) {",
  '\n\nfunction assetLoadFailureText',
  'asset-version stale handler',
);
assert.match(
  staleAssetBlock,
  /if \(shouldUseAssetVersionHintOnly\(\)\) \{[\s\S]*recordAssetVersionHint\(reason, targetAssetVersion\);[\s\S]*return !Boolean\(options\?\.allowReconnect\);[\s\S]*\}[\s\S]*return hardReload\(reason, targetAssetVersion\);/,
  'active call asset-version mismatch must emit a hint instead of hard-reloading',
);
assert.match(
  assetVersion,
  /window\.dispatchEvent\(new CustomEvent\('kingrt:asset-version-hint'/,
  'active call stale-asset handling must still expose a diagnostic hint',
);

assert.doesNotMatch(
  socketLifecycle,
  /location\.reload|window\.location\.reload|setInterval\([^)]*reload/,
  'call workspace websocket lifecycle must not contain browser reload loops',
);
assert.doesNotMatch(
  `${app}\n${assetVersion}\n${socketLifecycle}`,
  /120_?000[\s\S]{0,120}(?:reload|location\.reload)|(?:reload|location\.reload)[\s\S]{0,120}120_?000/i,
  'the previous two-minute active-call reload behavior must not exist',
);
assert.match(
  packageJson.scripts['test:contract:asset-cache-busting'],
  /active-call-no-auto-reload-contract\.mjs/,
  'asset-cache contract suite must include active-call no-auto-reload proof',
);

console.log('[active-call-no-auto-reload-contract] PASS');
