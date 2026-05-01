import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-throughput-path-contract] FAIL: ${message}`);
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
  const doc = readRepo('documentation/dev/video-chat/sfu-throughput-path.md');

  [
    'camera_capture',
    'background_processing',
    'dom_canvas_readback',
    'wlvc_encode',
    'selective_tile_planning',
    'binary_envelope_build',
    'outbound_queue',
    'browser_websocket_buffer',
    'network_proxy',
    'king_websocket_receive',
    'king_binary_decode',
    'sfu_relay_fanout_broker',
    'king_websocket_send',
    'receiver_decode',
    'receiver_render',
  ].forEach((stageId) => {
    requireContains(doc, `\`${stageId}\``, `documented throughput stage ${stageId}`);
  });

  [
    'frame_sequence',
    'outgoing_video_quality_profile',
    'payload bytes',
    'wire payload bytes',
    'queued_age_ms',
    'drain wait ms',
    'King receive timestamp',
    'fanout latency',
    'subscriber send latency',
    'render latency',
  ].forEach((metric) => {
    requireContains(doc, metric, `documented throughput metric ${metric}`);
  });

  const packageJson = readRepo('demo/video-chat/frontend-vue/package.json');
  requireContains(
    packageJson,
    'sfu-throughput-path-contract.mjs',
    'SFU contract script includes path analysis contract',
  );

  process.stdout.write('[sfu-throughput-path-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
