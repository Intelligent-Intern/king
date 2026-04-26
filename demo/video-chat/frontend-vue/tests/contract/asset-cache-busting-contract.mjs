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

try {
  const viteConfig = readUtf8(path.join(frontendRoot, 'vite.config.js'));
  assert.ok(viteConfig.includes('const buildAssetVersion = resolveAssetVersion();'), 'vite config must derive one build asset version');
  assert.ok(viteConfig.includes('kingrt-asset-version'), 'vite config must register the asset version plugin');
  assert.ok(viteConfig.includes('(?:assets|cdn)'), 'vite config must rewrite /assets and /cdn paths');
  assert.ok(viteConfig.includes('VIDEOCHAT_ASSET_VERSION'), 'vite config must accept deploy-time asset versions');
  assert.ok(viteConfig.includes('const hasTerminalFilename = /\\/[^/?#]+\\.[A-Za-z0-9]+$/.test(assetPath);'), 'vite config must only append asset versions to concrete asset files');
  assert.ok(viteConfig.includes('if (!hasTerminalFilename) {'), 'vite config must leave /cdn directory base paths unchanged');

  const frontendDockerfile = readUtf8(path.join(frontendRoot, 'Dockerfile'));
  assert.ok(frontendDockerfile.includes('ARG VIDEOCHAT_ASSET_VERSION=""'), 'frontend image must accept build-time asset version');
  assert.ok(frontendDockerfile.includes('ENV VIDEOCHAT_ASSET_VERSION="${VIDEOCHAT_ASSET_VERSION}"'), 'frontend image must expose build-time asset version to Vite');

  const edgeDockerfile = readUtf8(path.join(repoVideoChatRoot, 'edge/Dockerfile'));
  assert.ok(edgeDockerfile.includes('ARG VIDEOCHAT_ASSET_VERSION=""'), 'edge image must accept build-time asset version');
  assert.ok(edgeDockerfile.includes('ENV VIDEOCHAT_ASSET_VERSION="${VIDEOCHAT_ASSET_VERSION}"'), 'edge image must expose build-time asset version to the frontend build');

  const app = readUtf8(path.join(frontendRoot, 'src/App.vue'));
  assert.ok(app.includes("const BUILD_VERSION = String(import.meta.env.VIDEOCHAT_ASSET_VERSION || '').trim();"), 'app must read the current build version');
  assert.ok(app.includes("const BUILD_VERSION_HEADER = 'x-kingrt-asset-version';"), 'app must compare against the edge build-version header');
  assert.ok(app.includes('window.location.reload();'), 'app must hard-reload stale tabs after a deploy');

  const assetVersionSupport = readUtf8(path.join(frontendRoot, 'src/support/assetVersion.js'));
  assert.ok(assetVersionSupport.includes("const INVALIDATE_TYPES = new Set(['assets/invalidate', 'assets.invalidate']);"), 'asset version helper must understand websocket invalidation frames');
  assert.ok(assetVersionSupport.includes("query.set('asset_version', BUILD_VERSION);"), 'asset version helper must append the frontend build version to websocket queries');
  assert.ok(assetVersionSupport.includes("closeReason !== 'asset_version_mismatch'"), 'asset version helper must react to websocket close reasons from stale builds');

  const adminSync = readUtf8(path.join(frontendRoot, 'src/support/adminSyncSocket.js'));
  assert.ok(adminSync.includes('appendAssetVersionQuery'), 'admin sync websocket must advertise the current asset version');
  assert.ok(adminSync.includes('handleAssetVersionSocketPayload'), 'admin sync websocket must reload on invalidation frames');

  const workspaceApi = readUtf8(path.join(frontendRoot, 'src/domain/realtime/workspace/api.js'));
  assert.ok(workspaceApi.includes('appendAssetVersionQuery'), 'call workspace websocket URLs must advertise the current asset version');

  const compose = readUtf8(path.join(repoVideoChatRoot, 'docker-compose.v1.yml'));
  assert.ok(compose.includes('VIDEOCHAT_ASSET_VERSION: "${VIDEOCHAT_ASSET_VERSION:-}"'), 'compose builds must forward the asset version');
  assert.ok(compose.includes('VIDEOCHAT_KING_SERVER_MODE: ws') && compose.includes('VIDEOCHAT_ASSET_VERSION: "${VIDEOCHAT_ASSET_VERSION:-}"'), 'compose runtime services must expose the asset version to websocket workers');

  const deploy = readUtf8(path.join(repoVideoChatRoot, 'scripts/deploy.sh'));
  assert.ok(deploy.includes('VIDEOCHAT_ASSET_VERSION=\\${ASSET_VERSION}'), 'remote bootstrap must persist an initial asset version');
  assert.ok(deploy.includes('set_env_value VIDEOCHAT_ASSET_VERSION "\\$(date -u +%Y%m%d%H%M%S)"'), 'deploy must rotate the asset version on each release');

  const edge = readUtf8(path.join(repoVideoChatRoot, 'edge/edge.php'));
  assert.ok(edge.includes("'X-KingRT-Asset-Version'"), 'edge must expose the asset version header on static responses');
  assert.ok(edge.includes('use ($staticRoot, $writeResponse, $contentType, $cdnDomains, $assetVersion)'), 'edge static handler must capture the asset version for response headers');

  const realtimeAssetVersion = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/domain/realtime/realtime_asset_version.php'));
  assert.ok(realtimeAssetVersion.includes("'type' => 'assets/invalidate'"), 'realtime asset version helper must expose an assets invalidation frame');
  assert.ok(realtimeAssetVersion.includes('function videochat_realtime_disconnect_stale_asset_client'), 'realtime asset version helper must expose a stale-client disconnect helper');
  assert.ok(realtimeAssetVersion.includes("king_client_websocket_close($websocket, 1012, 'asset_version_mismatch')"), 'stale-client disconnect helper must close stale sockets');

  const realtimeWs = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/http/module_realtime_websocket.php'));
  assert.ok(realtimeWs.includes('videochat_realtime_disconnect_stale_asset_client('), 'presence websocket must use the shared stale-client disconnect helper');
  assert.ok((realtimeWs.match(/\$disconnectStaleAssetClient\(\)/g) || []).length >= 2, 'presence websocket must invalidate stale clients on connect and during the live loop');

  const realtimeSfu = readUtf8(path.join(repoVideoChatRoot, 'backend-king-php/domain/realtime/realtime_sfu_gateway.php'));
  assert.ok(realtimeSfu.includes('videochat_realtime_disconnect_stale_asset_client('), 'sfu websocket must use the shared stale-client disconnect helper');
  assert.ok((realtimeSfu.match(/\$disconnectStaleAssetClient\(\)/g) || []).length >= 2, 'sfu websocket must invalidate stale clients on connect and during the live loop');

  console.log('[asset-cache-busting-contract] PASS');
} catch (error) {
  console.error(`[asset-cache-busting-contract] FAIL: ${error.message}`);
  process.exit(1);
}
