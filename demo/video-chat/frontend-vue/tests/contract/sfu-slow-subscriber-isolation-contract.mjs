import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-slow-subscriber-isolation-contract] FAIL: ${message}`);
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
  const helper = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  const store = read('../backend-king-php/domain/realtime/realtime_sfu_store.php');
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');

  requireContains(store, "require_once __DIR__ . '/realtime_sfu_subscriber_budget.php';", 'store loads subscriber budget helper');
  requireContains(helper, 'function videochat_sfu_subscriber_video_send_budget_ms(): int', 'subscriber send budget helper');
  requireContains(helper, 'function videochat_sfu_subscriber_video_send_cooldown_ms(): int', 'subscriber cooldown helper');
  requireContains(helper, 'function videochat_sfu_direct_fanout_frame(', 'direct fanout helper');
  requireContains(helper, '$blockedUntilMs > $nowMs', 'slow subscriber skip gate');
  requireContains(helper, "videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_slow_subscriber_skipped'", 'slow subscriber skip telemetry');
  requireContains(helper, "videochat_sfu_log_runtime_event('sfu_direct_fanout_slow_subscriber_video_isolated'", 'slow subscriber isolation telemetry');
  requireContains(helper, '$frameForSubscriber = $outboundFrame;', 'per-subscriber send state is isolated');
  requireContains(helper, 'videochat_sfu_send_outbound_message($subClient[\'websocket\'], $frameForSubscriber', 'fanout still sends to available subscribers');
  requireContains(gateway, '$slowSubscriberVideoBlockedUntilMsByClient = [];', 'gateway keeps per-subscriber video cooldown state');
  requireContains(gateway, 'videochat_sfu_direct_fanout_frame(', 'gateway delegates direct fanout to budgeted helper');
  assert.equal(
    gateway.includes("videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_binary_required_failed'"),
    false,
    'direct fanout failure handling must live in the budgeted helper so slow subscribers can be isolated',
  );

  process.stdout.write('[sfu-slow-subscriber-isolation-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
