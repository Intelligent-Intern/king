import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-relay-broker-io-budget-contract] FAIL: ${message}`);
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
  const relay = read('../backend-king-php/domain/realtime/realtime_sfu_broker_replay.php');
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const store = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(relay, 'function videochat_sfu_live_frame_relay_max_record_bytes(array $frame): int', 'live relay has a per-record byte budget');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_max_room_bytes(): int', 'live relay has a room byte budget');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_cleanup_interval_ms(): int', 'live relay cleanup cadence is bounded');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_should_cleanup(string $roomId, int $nowMs): bool', 'live relay cleanup is rate-limited per room');
  requireContains(relay, 'strlen($encoded) > videochat_sfu_live_frame_relay_max_record_bytes($frame)', 'oversized relay records are rejected before synchronous file write');
  requireContains(relay, '$keptBytes > videochat_sfu_live_frame_relay_max_room_bytes()', 'cleanup enforces aggregate room bytes');
  requireContains(relay, '$keptBytes -= max(0, (int) ($oldest[\'bytes\'] ?? 0));', 'cleanup drains aggregate byte accounting');
  requireContains(relay, 'videochat_sfu_live_frame_relay_should_cleanup($normalizedRoomId, $nowMs)', 'publish path uses bounded cleanup cadence');
  requireContains(gateway, '$relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);', 'hot path sends JSON-safe relay copy');
  assert.ok(!store.includes('CREATE TABLE IF NOT EXISTS sfu_frames'), 'SFU media frames must not be persisted in SQLite');
  assert.ok(!store.includes('INSERT INTO sfu_frames'), 'SFU media frame payloads must not be inserted into SQLite');

  process.stdout.write('[sfu-relay-broker-io-budget-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
