import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-king-receive-loop-fairness-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireIntFunctionAtMost(source, functionName, maxValue) {
  const pattern = new RegExp(`function ${functionName}\\(\\): int\\s*\\{\\s*return\\s+(\\d+);\\s*\\}`, 'm');
  const match = source.match(pattern);
  assert.ok(match, `${functionName} missing`);
  assert.ok(Number(match[1]) <= maxValue, `${functionName} must be <= ${maxValue}, got ${match[1]}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const relay = read('../backend-king-php/domain/realtime/realtime_sfu_broker_replay.php');

  requireIntFunctionAtMost(relay, 'videochat_sfu_receive_poll_timeout_ms', 20);
  requireIntFunctionAtMost(relay, 'videochat_sfu_live_frame_relay_poll_batch_limit', 16);
  requireContains(relay, 'function videochat_sfu_broker_poll_interval_ms(): int', 'broker poll interval helper');
  requireContains(relay, 'videochat_sfu_live_frame_relay_poll_batch_limit()', 'live relay poll uses bounded batches');
  requireContains(gateway, 'king_client_websocket_receive($websocket, videochat_sfu_receive_poll_timeout_ms())', 'receive loop uses short bounded poll timeout');
  requireContains(gateway, 'videochat_sfu_broker_poll_interval_ms()', 'broker poll cadence is explicit');
  assert.equal(
    gateway.includes('king_client_websocket_receive($websocket, 100)'),
    false,
    'SFU receive loop must not sleep 100ms before checking incoming media frames',
  );

  process.stdout.write('[sfu-king-receive-loop-fairness-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
