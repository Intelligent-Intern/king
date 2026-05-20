import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoVideoChatRoot = path.resolve(frontendRoot, '..');

function readUtf8(file) {
  return fs.readFileSync(file, 'utf8');
}

function countMatches(source, pattern) {
  return Array.from(source.matchAll(pattern)).length;
}

function functionBlock(source, functionName) {
  const signature = `function ${functionName}(`;
  const start = source.indexOf(signature);
  assert.notEqual(start, -1, `${functionName} must exist`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `${functionName} must have a function body`);
  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, index + 1);
    }
  }
  throw new Error(`${functionName} body must be closed`);
}

try {
  const viteConfig = readUtf8(path.join(frontendRoot, 'vite.config.js'));
  assert.ok(viteConfig.includes('const buildAssetVersion = resolveAssetVersion();'), 'vite config must derive one build asset version');
  assert.ok(viteConfig.includes('kingrt-asset-version'), 'vite config must register the asset version plugin');
  assert.ok(viteConfig.includes('(?:assets|cdn)'), 'vite config must rewrite /assets and /cdn paths');
  assert.ok(viteConfig.includes('VIDEOCHAT_ASSET_VERSION'), 'vite config must accept deploy-time asset versions');
  assert.ok(viteConfig.includes("'import.meta.env.VIDEOCHAT_ASSET_VERSION': JSON.stringify(buildAssetVersion)"), 'vite config must inject the build asset version into browser code');
  assert.ok(viteConfig.includes('VIDEOCHAT_PRODUCTION_SOURCEMAPS'), 'vite config must accept deploy-time sourcemap mode');
  assert.ok(viteConfig.includes("return 'hidden';"), 'vite config must support hidden production sourcemaps for internal stacktrace mapping');
  assert.ok(viteConfig.includes('sourcemap: productionSourcemap'), 'vite build must wire the resolved sourcemap mode');
  assert.ok(viteConfig.includes('const hasTerminalFilename = /\\/[^/?#]+\\.[A-Za-z0-9]+$/.test(assetPath);'), 'vite config must only append asset versions to concrete asset files');
  assert.ok(viteConfig.includes('if (!hasTerminalFilename) {'), 'vite config must leave /cdn directory base paths unchanged');

  const frontendDockerfile = readUtf8(path.join(frontendRoot, 'Dockerfile'));
  assert.ok(frontendDockerfile.includes('ARG VIDEOCHAT_ASSET_VERSION=""'), 'frontend image must accept build-time asset version');
  assert.ok(frontendDockerfile.includes('ENV VIDEOCHAT_ASSET_VERSION="${VIDEOCHAT_ASSET_VERSION}"'), 'frontend image must expose build-time asset version to Vite');
  assert.ok(frontendDockerfile.includes('ARG VIDEOCHAT_PRODUCTION_SOURCEMAPS=""'), 'frontend image must accept build-time sourcemap mode');
  assert.ok(frontendDockerfile.includes('ENV VIDEOCHAT_PRODUCTION_SOURCEMAPS="${VIDEOCHAT_PRODUCTION_SOURCEMAPS}"'), 'frontend image must expose build-time sourcemap mode to Vite');

  const edgeDockerfile = readUtf8(path.join(repoVideoChatRoot, 'edge/Dockerfile'));
  assert.ok(edgeDockerfile.includes('ARG VIDEOCHAT_ASSET_VERSION=""'), 'edge image must accept build-time asset version');
  assert.ok(edgeDockerfile.includes('ENV VIDEOCHAT_ASSET_VERSION="${VIDEOCHAT_ASSET_VERSION}"'), 'edge image must expose build-time asset version to the frontend build');
  assert.ok(edgeDockerfile.includes('ARG VIDEOCHAT_PRODUCTION_SOURCEMAPS=""'), 'edge image must accept build-time sourcemap mode');
  assert.ok(edgeDockerfile.includes('ENV VIDEOCHAT_PRODUCTION_SOURCEMAPS="${VIDEOCHAT_PRODUCTION_SOURCEMAPS}"'), 'edge image must expose build-time sourcemap mode to the frontend build');

  const app = readUtf8(path.join(frontendRoot, 'src/App.vue'));
  assert.ok(app.includes("const BUILD_VERSION = String(import.meta.env.VIDEOCHAT_ASSET_VERSION || '').trim();"), 'app must read the current build version');
  assert.ok(app.includes("const BUILD_VERSION_HEADER = 'x-kingrt-asset-version';"), 'app must compare against the edge build-version header');
  assert.ok(app.includes('window.location.reload();'), 'app must hard-reload stale tabs after a deploy');
  assert.ok(app.includes("function isCallWorkspacePath(path = '')"), 'app must identify active call workspace routes before deploy reloads');
  assert.ok(app.includes("startsWith('/workspace/call')"), 'app must treat active call workspace routes as reload-deferred');
  assert.ok(app.includes('function scheduleBuildVersionReload()'), 'app must centralize deploy reload scheduling');
  assert.ok(app.includes('function currentBrowserPath()'), 'app must inspect the actual browser path before deploy reloads');
  const scheduleBuildVersionReloadBlock = functionBlock(app, 'scheduleBuildVersionReload');
  const checkForBuildVersionMismatchBlock = functionBlock(app, 'checkForBuildVersionMismatch');
  assert.doesNotMatch(app, /CALL_WORKSPACE_FORCE_RELOAD|callWorkspaceForceReload|handleCallWorkspaceForceReload|syncCallWorkspaceForceReload/, 'app must not keep the old active-call force-reload loop');
  assert.equal(
    countMatches(app, /window\.setInterval\(/g),
    1,
    'app must keep only the build-version guard interval',
  );
  assert.match(
    app,
    /window\.setInterval\(handleBuildVersionGuardTrigger, BUILD_VERSION_CHECK_INTERVAL_MS\)/,
    'the only App interval must be the deploy asset-version guard',
  );
  assert.doesNotMatch(
    app,
    /window\.setInterval\([^\n]*window\.location\.reload\(\)/,
    'active call workspaces must not have a timer loop that can reload the page',
  );
  assert.match(
    scheduleBuildVersionReloadBlock,
    /buildVersionReloadPending = true;[\s\S]*if \(isCallWorkspacePath\(route\.path\) \|\| isCallWorkspacePath\(currentBrowserPath\(\)\)\) \{[\s\S]*return;[\s\S]*\}[\s\S]*window\.location\.reload\(\);/,
    'app must defer build-version reload while a call workspace is active',
  );
  assert.match(
    checkForBuildVersionMismatchBlock,
    /if \(isCallWorkspacePath\(route\.path\) \|\| isCallWorkspacePath\(currentBrowserPath\(\)\)\) return;[\s\S]*fetchLiveBuildVersion\(\)/,
    'app must not run build_check polling while a call workspace is active',
  );
  assert.match(
    app,
    /watch\([\s\S]*buildVersionReloadPending[\s\S]*!isCallWorkspacePath\(route\.path\)[\s\S]*!isCallWorkspacePath\(currentBrowserPath\(\)\)[\s\S]*window\.location\.reload\(\)/,
    'app must reload deferred stale builds after leaving the call workspace',
  );

  const assetVersionSupport = readUtf8(path.join(frontendRoot, 'src/support/assetVersion.ts'));
  assert.ok(assetVersionSupport.includes("const INVALIDATE_TYPES = new Set(['assets/invalidate', 'assets.invalidate']);"), 'asset version helper must understand websocket invalidation frames');
  assert.ok(assetVersionSupport.includes("query.set('asset_version', BUILD_VERSION);"), 'asset version helper must append the frontend build version to websocket queries');
  assert.ok(assetVersionSupport.includes('ASSET_RELOAD_ATTEMPT_STORAGE_KEY'), 'asset version helper must remember stale asset hints per build');
  assert.ok(assetVersionSupport.includes("query.set('asset_reload_attempted', '1');"), 'asset version helper must tell realtime sockets after stale assets were already handled');
  assert.ok(assetVersionSupport.includes('function shouldUseAssetVersionHintOnly()'), 'asset version helper must switch active call routes to hint-only handling');
  assert.ok(assetVersionSupport.includes("window.dispatchEvent(new CustomEvent('kingrt:asset-version-hint'"), 'asset version helper must expose stale deploys as an internal browser hint');
  assert.match(
    assetVersionSupport,
    /function handleStaleAssetVersion[\s\S]*shouldUseAssetVersionHintOnly\(\)[\s\S]*recordAssetVersionHint[\s\S]*return !Boolean\(options\?\.allowReconnect\);[\s\S]*return hardReload/,
    'asset version helper must not hard-reload active call workspaces',
  );
  assert.ok(assetVersionSupport.includes("closeReason !== 'asset_version_mismatch'"), 'asset version helper must react to websocket close reasons from stale builds');
  assert.ok(assetVersionSupport.includes('handleAssetVersionConnectionFailure'), 'asset version helper must probe runtime after pre-open websocket failures');
  assert.ok(assetVersionSupport.includes("fetchBackend('/api/runtime'"), 'asset version helper must probe the public runtime endpoint for stale pre-open sockets');
  assert.ok(assetVersionSupport.includes('handleAssetLoadFailure'), 'asset version helper must expose dynamic import asset failure recovery');
  assert.ok(assetVersionSupport.includes('failed to fetch dynamically imported module'), 'asset version helper must detect stale dynamic import failures');
  assert.ok(assetVersionSupport.includes('ASSET_LOAD_FAILURE_RELOAD_STORAGE_KEY'), 'asset version helper must prevent stale chunk reload loops');

  const clientDiagnostics = readUtf8(path.join(frontendRoot, 'src/support/clientDiagnostics.ts'));
  assert.ok(clientDiagnostics.includes('handleAssetLoadFailure'), 'global client diagnostics must invoke asset-load recovery for stale chunks');
  assert.ok(clientDiagnostics.includes("'vite:preloadError'"), 'global client diagnostics must handle Vite preload errors from stale chunks');

  const adminSync = readUtf8(path.join(frontendRoot, 'src/support/adminSyncSocket.ts'));
  assert.ok(adminSync.includes('appendAssetVersionQuery'), 'admin sync websocket must advertise the current asset version');
  assert.ok(adminSync.includes('handleAssetVersionSocketPayload'), 'admin sync websocket must consume asset invalidation frames without passing them to app sync handlers');

  const workspaceApi = readUtf8(path.join(frontendRoot, 'src/domain/realtime/workspace/api.ts'));
  assert.ok(workspaceApi.includes('appendAssetVersionQuery'), 'call workspace websocket URLs must advertise the current asset version');

  const workspaceSocketLifecycle = readUtf8(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts'));
  assert.ok(!workspaceSocketLifecycle.includes('failOverAfterAssetVersionProbe'), 'call workspace websocket must not run stale-asset reconnect failover loops');
  assert.ok(workspaceSocketLifecycle.includes('handleAssetVersionSocketClose(event)'), 'call workspace websocket close handling must consume active-call stale-asset closes as hints');

  const sfuClient = readUtf8(path.join(frontendRoot, 'src/lib/sfu/sfuClient.ts'));
  assert.ok(sfuClient.includes('failToNextCandidateAfterAssetVersionProbe'), 'SFU websocket must probe live asset version before failover on pre-open failure');
  assert.ok(sfuClient.includes('handleAssetVersionConnectionFailure({ allowReconnect: true })'), 'SFU websocket stale-asset probes must be hint-only for active calls');
  assert.ok(sfuClient.includes('handleAssetVersionSocketClose(event, { allowReconnect: true })'), 'SFU websocket stale-asset closes must not trigger page reloads');

  const compose = readUtf8(path.join(repoVideoChatRoot, 'docker-compose.v1.yml'));
  assert.ok(compose.includes('VIDEOCHAT_ASSET_VERSION: "${VIDEOCHAT_ASSET_VERSION:-}"'), 'compose builds must forward the asset version');
  assert.ok(compose.includes('VIDEOCHAT_PRODUCTION_SOURCEMAPS: "${VIDEOCHAT_PRODUCTION_SOURCEMAPS:-}"'), 'compose builds must forward the sourcemap mode');
  assert.ok(compose.includes('VIDEOCHAT_KING_SERVER_MODE: ws') && compose.includes('VIDEOCHAT_ASSET_VERSION: "${VIDEOCHAT_ASSET_VERSION:-}"'), 'compose runtime services must expose the asset version to websocket workers');

  const deploy = readUtf8(path.join(repoVideoChatRoot, 'scripts/deploy.sh'));
  assert.ok(deploy.includes('VIDEOCHAT_ASSET_VERSION=\\${ASSET_VERSION}'), 'remote bootstrap must persist an initial asset version');
  assert.ok(deploy.includes('set_env_value VIDEOCHAT_ASSET_VERSION "\\$(date -u +%Y%m%d%H%M%S)"'), 'deploy must rotate the asset version on each release');
  assert.ok(deploy.includes('VIDEOCHAT_PRODUCTION_SOURCEMAPS=hidden'), 'remote bootstrap must enable hidden production sourcemaps');
  assert.ok(deploy.includes('set_env_value VIDEOCHAT_PRODUCTION_SOURCEMAPS hidden'), 'deploy must preserve hidden production sourcemaps on release updates');

  const edge = readUtf8(path.join(repoVideoChatRoot, 'edge/edge.php'));
  assert.ok(edge.includes("'X-KingRT-Asset-Version'"), 'edge must expose the asset version header on static responses');
  assert.match(edge, /use \(\$staticRoot,[\s\S]*\$assetVersion/, 'edge static handler must capture the asset version for response headers');

  const runtimeModule = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/http/module_runtime.php'));
  assert.ok(runtimeModule.includes("$payload['asset_version'] = $assetVersion;"), 'public runtime endpoint must expose current asset version for stale websocket failure recovery');

  const realtimeAssetVersion = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/domain/realtime/realtime_asset_version.php'));
  assert.ok(realtimeAssetVersion.includes("'type' => 'assets/invalidate'"), 'realtime asset version helper must expose an assets invalidation frame');
  assert.ok(realtimeAssetVersion.includes("'force_reload' => false"), 'realtime asset invalidation frames must not force active call reloads');
  assert.ok(realtimeAssetVersion.includes("'hint_only' => true"), 'realtime asset invalidation frames must be hint-only');
  assert.ok(realtimeAssetVersion.includes('function videochat_realtime_disconnect_stale_asset_client'), 'realtime asset version helper must expose a stale-client disconnect helper');
  assert.ok(realtimeAssetVersion.includes('bool $closeStaleSocket = true'), 'stale-client helper must allow active call sockets to stay open');
  assert.ok(realtimeAssetVersion.includes('if (!$closeStaleSocket) {'), 'stale-client helper must support hint-only websocket handling');
  assert.ok(realtimeAssetVersion.includes("king_client_websocket_close($websocket, 1012, 'asset_version_mismatch')"), 'stale-client disconnect helper must close stale sockets');

  const realtimeWsReconnect = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/http/module_realtime_websocket_reconnect.php'));
  assert.ok(realtimeWsReconnect.includes('function videochat_realtime_websocket_disconnect_stale_asset_client'), 'presence websocket must expose a stale-client disconnect helper');
  assert.ok(realtimeWsReconnect.includes('videochat_realtime_disconnect_stale_asset_client('), 'presence websocket stale-client helper must use the shared stale-client disconnect helper');
  assert.ok(realtimeWsReconnect.includes('bool $closeStaleSocket = true'), 'presence websocket stale-client helper must expose hint-only mode');

  const realtimeWs = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/http/module_realtime_websocket.php'));
  assert.ok(
    realtimeWs.includes('videochat_realtime_websocket_disconnect_stale_asset_client(')
      && realtimeWsReconnect.includes('videochat_realtime_disconnect_stale_asset_client('),
    'presence websocket must use the shared stale-client disconnect helper through its reconnect wrapper',
  );
  assert.ok(realtimeWs.includes('$clientAssetReloadAttempted'), 'presence websocket must read the client reload-attempt flag');
  assert.ok(realtimeWs.includes('$keepStaleAssetCallSocketOpen'), 'presence websocket must not close active call clients after deploy asset rotation');
  assert.ok(realtimeWs.includes('$staleAssetHintSent'), 'presence websocket must not spam stale-asset hints while keeping call sockets open');
  assert.ok(realtimeWs.includes('videochat_realtime_websocket_disconnect_stale_asset_client(')
    && realtimeWs.includes('false'), 'presence websocket must send active-call stale-asset hints without closing the socket');
  assert.ok((realtimeWs.match(/\$disconnectStaleAssetClient\(\)/g) || []).length >= 2, 'presence websocket must invalidate stale clients on connect and during the live loop');

  const realtimeSfu = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/domain/realtime/realtime_sfu_gateway.php'));
  assert.ok(realtimeSfu.includes('videochat_realtime_disconnect_stale_asset_client('), 'sfu websocket must use the shared stale-client disconnect helper');
  assert.ok((realtimeSfu.match(/\$disconnectStaleAssetClient\(\)/g) || []).length >= 2, 'sfu websocket must invalidate stale clients on connect and during the live loop');

  console.log('[asset-cache-busting-contract] PASS');
} catch (error) {
  console.error(`[asset-cache-busting-contract] FAIL: ${error.message}`);
  process.exit(1);
}
