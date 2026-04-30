import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-control-data-plane-split-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

try {
  const sprint = readRepo('SPRINT.md');
  const sfuClient = readRepo('demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts');
  const mediaTransport = readRepo('demo/video-chat/frontend-vue/src/lib/sfu/mediaTransport.ts');
  const gateway = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const store = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  const packageJson = readRepo('demo/video-chat/frontend-vue/package.json');

  requireContains(sprint, '[x] `[sfu-control-data-plane-split]`', 'sprint closes the control/data split issue');
  requireContains(mediaTransport, 'SFU_CONTROL_TRANSPORT_WEBSOCKET', 'control transport has a named websocket id');
  requireContains(mediaTransport, 'SFU_MEDIA_TRANSPORT_WEBSOCKET_FALLBACK', 'media transport has a named fallback id');
  requireContains(mediaTransport, 'class SfuWebSocketFallbackMediaTransport', 'websocket media fallback is isolated behind a transport class');
  requireContains(sfuClient, 'private mediaTransport: SfuWebSocketFallbackMediaTransport', 'SFU client owns media transport abstraction');
  requireContains(sfuClient, 'this.mediaTransport.sendBinaryFrame(encoded)', 'SFU client sends binary media through media transport abstraction');
  requireContains(sfuClient, 'media_transport: sendResult.transportPath', 'SFU client records media transport path');
  requireContains(sfuClient, 'control_transport: SFU_CONTROL_TRANSPORT_WEBSOCKET', 'SFU client records control transport path');
  requireContains(gateway, 'function videochat_sfu_control_transport_id(): string', 'backend names SFU control transport');
  requireContains(gateway, 'function videochat_sfu_fallback_media_transport_id(): string', 'backend names fallback media transport');
  requireContains(gateway, "'media_transport_role' => 'fallback_until_real_media_plane'", 'backend welcome frame marks media fallback role');
  requireContains(gateway, "'control_transport' => videochat_sfu_control_transport_id()", 'backend frame metadata includes control transport');
  requireContains(gateway, "'media_transport' => videochat_sfu_fallback_media_transport_id()", 'backend frame metadata includes media transport');
  requireContains(store, "'control_transport' => ['control_transport', 'controlTransport']", 'backend preserves control transport metadata');
  requireContains(store, "'media_transport' => ['media_transport', 'mediaTransport']", 'backend preserves media transport metadata');
  requireContains(packageJson, 'sfu-control-data-plane-split-contract.mjs', 'SFU contract script includes control/data split contract');

  process.stdout.write('[sfu-control-data-plane-split-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
