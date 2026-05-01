import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-real-media-plane-architecture-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireNotContains(source, needle, label) {
  assert.equal(source.includes(needle), false, `${label} must not contain: ${needle}`);
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
  const architecture = readRepo('documentation/dev/video-chat/real-media-plane-architecture.md');
  const throughput = readRepo('documentation/dev/video-chat/sfu-throughput-path.md');
  const packageJson = readRepo('demo/video-chat/frontend-vue/package.json');

  requireContains(sprint, '[x] `[real-media-plane-contract]`', 'sprint closes the real-media-plane contract issue');
  requireContains(
    architecture,
    'MediaStreamTrack -> encoder -> packet/datagram media transport -> SFU packet/layer forwarder -> jitter buffer/keyframe/layer recovery -> native renderer',
    'target real media path',
  );
  requireContains(architecture, 'WebSocket/TCP `bufferedAmount` is not the final congestion-control layer for', 'WebSocket is not final media congestion control');
  requireContains(architecture, 'Whole-frame WebSocket relay is a fallback transport, not the target data', 'whole-frame WebSocket relay is fallback only');
  requireContains(architecture, 'packet or datagram pacing', 'packet/datagram pacing requirement');
  requireContains(architecture, 'NACK/PLI recovery', 'NACK/PLI or equivalent recovery requirement');
  requireContains(architecture, 'per-subscriber layer routing', 'per-subscriber layer routing requirement');
  requireContains(architecture, 'receiver jitter buffering', 'jitter buffer requirement');
  requireContains(architecture, 'backend-routed diagnostics', 'backend-routed diagnostics requirement');
  requireContains(architecture, 'SQLite and live-relay buffers are replay/fallback infrastructure only', 'SQLite/live relay fallback boundary');
  requireContains(throughput, 'real-media-plane-architecture.md', 'throughput path links to real media plane target');
  requireContains(packageJson, 'sfu-real-media-plane-architecture-contract.mjs', 'SFU contract script includes real media plane contract');

  requireNotContains(
    `${sprint}\n${architecture}`,
    'WebSocket/TCP is the final video media plane',
    'forbidden final WebSocket media-plane claim',
  );
  requireNotContains(
    `${sprint}\n${architecture}`,
    'bufferedAmount is the final congestion-control layer',
    'forbidden bufferedAmount final congestion-control claim',
  );

  process.stdout.write('[sfu-real-media-plane-architecture-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
