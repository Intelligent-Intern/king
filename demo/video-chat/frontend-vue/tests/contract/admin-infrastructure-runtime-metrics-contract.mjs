import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

try {
  const inventory = readUtf8('backend-king-php/domain/infrastructure/infrastructure_inventory.php');
  const compose = readUtf8('docker-compose.v1.yml');
  const deploy = readUtf8('scripts/deploy.sh');
  const packageJson = readUtf8('frontend-vue/package.json');

  assert.ok(inventory.includes("'VIDEOCHAT_INFRA_LOCAL_NODE_NAME'"), 'Hetzner inventory must read explicit local node name');
  assert.ok(inventory.includes("'VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP'"), 'Hetzner inventory must read explicit local public IP');
  assert.ok(inventory.includes('$resources = array_merge($resources, $localResourceUsage);'), 'Hetzner local row must merge live CPU/RAM usage');
  assert.ok(compose.includes('VIDEOCHAT_INFRA_LOCAL_NODE_NAME: "${VIDEOCHAT_INFRA_LOCAL_NODE_NAME:-${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME:-}}"'), 'compose must pass local node name into backend');
  assert.ok(compose.includes('VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP: "${VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP:-${VIDEOCHAT_DEPLOY_PUBLIC_IP:-${VIDEOCHAT_DEPLOY_HOST:-}}}"'), 'compose must pass local public IP into backend');
  assert.ok(deploy.includes('VIDEOCHAT_INFRA_LOCAL_NODE_NAME=\\${INFRA_LOCAL_NODE_NAME}'), 'deploy env must persist local node name');
  assert.ok(deploy.includes('VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP=\\${INFRA_LOCAL_PUBLIC_IP}'), 'deploy env must persist local public IP');
  assert.ok(deploy.includes('VIDEOCHAT_INFRA_LOCAL_NODE_NAME: "\\${INFRA_LOCAL_NODE_NAME}"'), 'deploy compose override must pass local node name');
  assert.ok(deploy.includes('VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP: "\\${INFRA_LOCAL_PUBLIC_IP}"'), 'deploy compose override must pass local public IP');
  assert.ok(packageJson.includes('admin-infrastructure-runtime-metrics-contract.mjs'), 'package scripts must expose infra runtime metrics contract');

  console.log('[admin-infrastructure-runtime-metrics-contract] PASS');
} catch (error) {
  console.error(`[admin-infrastructure-runtime-metrics-contract] FAIL: ${error.message}`);
  process.exit(1);
}
