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

  const compose = readUtf8(path.join(repoVideoChatRoot, 'docker-compose.v1.yml'));
  assert.ok(compose.includes('VIDEOCHAT_ASSET_VERSION: "${VIDEOCHAT_ASSET_VERSION:-}"'), 'compose builds must forward the asset version');

  const deploy = readUtf8(path.join(repoVideoChatRoot, 'scripts/deploy.sh'));
  assert.ok(deploy.includes('VIDEOCHAT_ASSET_VERSION=\\${ASSET_VERSION}'), 'remote bootstrap must persist an initial asset version');
  assert.ok(deploy.includes('set_env_value VIDEOCHAT_ASSET_VERSION "\\$(date -u +%Y%m%d%H%M%S)"'), 'deploy must rotate the asset version on each release');

  const edge = readUtf8(path.join(repoVideoChatRoot, 'edge/edge.php'));
  assert.ok(edge.includes("'X-KingRT-Asset-Version'"), 'edge must expose the asset version header on static responses');
  assert.ok(edge.includes('use ($staticRoot, $writeResponse, $contentType, $cdnDomains, $assetVersion)'), 'edge static handler must capture the asset version for response headers');

  console.log('[asset-cache-busting-contract] PASS');
} catch (error) {
  console.error(`[asset-cache-busting-contract] FAIL: ${error.message}`);
  process.exit(1);
}
