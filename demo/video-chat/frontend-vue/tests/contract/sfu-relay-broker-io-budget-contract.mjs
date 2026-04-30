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
  const binaryPayload = read('../backend-king-php/domain/realtime/realtime_sfu_binary_payload.php');
  const subscriberBudget = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');

  requireContains(relay, 'function videochat_sfu_live_frame_relay_max_record_bytes(array $frame): int', 'live relay has a per-record byte budget');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_max_room_bytes(): int', 'live relay has a room byte budget');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_cleanup_interval_ms(): int', 'live relay cleanup cadence is bounded');
  requireContains(relay, 'function videochat_sfu_live_frame_relay_should_cleanup(string $roomId, int $nowMs): bool', 'live relay cleanup is rate-limited per room');
  requireContains(relay, 'strlen($encoded) > videochat_sfu_live_frame_relay_max_record_bytes($frame)', 'oversized relay records are rejected before synchronous file write');
  requireContains(relay, '$keptBytes > videochat_sfu_live_frame_relay_max_room_bytes()', 'cleanup enforces aggregate room bytes');
  requireContains(relay, '$keptBytes -= max(0, (int) ($oldest[\'bytes\'] ?? 0));', 'cleanup drains aggregate byte accounting');
  requireContains(relay, 'videochat_sfu_live_frame_relay_should_cleanup($normalizedRoomId, $nowMs)', 'publish path uses bounded cleanup cadence');
  requireContains(gateway, '$relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);', 'hot path sends JSON-safe relay copy');
  requireContains(gateway, "'payload_bytes' => $payloadBytes", 'SFU broker frames preserve measured protected payload bytes');
  requireContains(gateway, 'videochat_sfu_transport_payload_bytes($msg, $protectedFrame, $dataBinary)', 'SFU gateway derives payload bytes before broker budgeting');
  requireContains(gateway, 'videochat_sfu_drop_stale_ingress_frame_if_needed($websocket, $outboundFrame, $roomId, (string) $clientId)', 'SFU gateway drops stale ingress frames before relay fanout');
  requireContains(subscriberBudget, 'function videochat_sfu_frame_latency_budget_ms(array $frame): int', 'SFU latency budget is shared by ingress and replay');
  requireContains(subscriberBudget, "type' => 'sfu/publisher-pressure'", 'SFU stale ingress guard feeds publisher pressure');
  requireContains(subscriberBudget, 'sfu_frame_replay_stale_frame_pruned', 'SFU replay prunes stale frames, not only stale deltas');
  requireContains(binaryPayload, 'function videochat_sfu_transport_payload_bytes(array $frame', 'shared payload-byte helper protects relay/broker budgets');
  requireContains(store, 'function videochat_sfu_frame_buffer_max_record_bytes(array $frame): int', 'SQLite frame buffer has a per-record byte budget');
  requireContains(store, 'function videochat_sfu_frame_buffer_max_rows_per_room(): int', 'SQLite frame buffer has a per-room row budget');
  requireContains(store, 'function videochat_sfu_frame_buffer_should_cleanup(string $roomId, int $nowMs): bool', 'SQLite frame buffer cleanup is rate-limited per room');
  requireContains(store, 'strlen($encoded) > videochat_sfu_frame_buffer_max_record_bytes($storedFrame)', 'oversized SQLite frame records are rejected before insert');
  requireContains(store, 'videochat_sfu_trim_frame_buffer_room($pdo, $normalizedRoomId)', 'SQLite frame buffer trims room rows');
  requireContains(gateway, 'function videochat_sfu_configure_broker_database', 'SFU broker has isolated SQLite configuration');
  requireContains(gateway, "PRAGMA journal_mode = WAL", 'SFU broker uses WAL on tmpfs');
  requireContains(gateway, "PRAGMA synchronous = OFF", 'SFU broker disables durable sync only for ephemeral media replay');
  requireContains(gateway, "PRAGMA temp_store = MEMORY", 'SFU broker keeps temp storage in memory');
  requireContains(gateway, "sfu_broker_database_opened", 'SFU broker emits runtime storage diagnostics');

  process.stdout.write('[sfu-relay-broker-io-budget-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
