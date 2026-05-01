import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-production-socket-proxy-budget-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const packageJson = read('package.json');
  const probe = read('tests/e2e/production-socket-proxy-budget.mjs');
  const throughputDoc = fs.readFileSync(path.resolve(frontendRoot, '..', '..', '..', 'documentation/dev/video-chat/sfu-throughput-path.md'), 'utf8');

  requireContains(
    packageJson,
    '"test:e2e:production-socket-proxy-budget": "node tests/e2e/production-socket-proxy-budget.mjs"',
    'package script exposes production socket/proxy budget probe',
  );
  requireContains(probe, 'CONTINUATION_THRESHOLD_BYTES = 65_535', 'probe measures around websocket continuation threshold');
  requireContains(probe, 'QUALITY_MAX_PAYLOAD_BYTES = 5632 * 1024', 'probe exercises full quality profile payload budget');
  requireContains(probe, 'QUALITY_MAX_BUFFERED_BYTES = 8 * 1024 * 1024', 'probe enforces quality bufferedAmount budget');
  requireContains(probe, 'CRITICAL_BUFFERED_BYTES = 10 * 1024 * 1024', 'probe fails before critical backpressure');
  requireContains(probe, 'connectSfuSocket', 'probe opens real production SFU sockets');
  requireContains(probe, "role: 'publisher'", 'probe opens publisher path');
  requireContains(probe, "role: 'subscriber'", 'probe opens subscriber path');
  requireContains(probe, 'waitForBinaryFrame', 'probe verifies server-to-subscriber binary delivery');
  requireContains(probe, 'assertNoSocketFailure', 'probe treats SFU errors and websocket closes as failures');
  requireContains(throughputDoc, 'network_proxy', 'throughput doc keeps production proxy stage');

  process.stdout.write('[sfu-production-socket-proxy-budget-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
