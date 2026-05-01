import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-replay-pacing-slow-subscriber-contract] FAIL: ${message}`);
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

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const helper = read('../backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  const relay = read('../backend-king-php/domain/realtime/realtime_sfu_broker_replay.php');
  const gateway = read('../backend-king-php/domain/realtime/realtime_sfu_gateway.php');

  requireContains(helper, 'function videochat_sfu_subscriber_replay_video_send_budget_ms(): int', 'replay send budget helper');
  requireContains(helper, 'function videochat_sfu_subscriber_replay_delta_max_age_ms(): int', 'replay stale delta age helper');
  requireContains(helper, 'function videochat_sfu_subscriber_replay_max_batch_frames(): int', 'replay batch cap helper');
  requireContains(helper, 'function videochat_sfu_prune_replay_frames_for_subscriber(', 'replay stale/keyframe pruning helper');
  requireContains(helper, 'function videochat_sfu_prioritize_replay_keyframes_for_subscriber(', 'replay prioritizes keyframes before deltas for reconnecting subscribers');
  requireContains(helper, 'videochat_sfu_frame_replay_age_ms($frame)', 'replay pruning uses frame age');
  requireContains(helper, 'videochat_sfu_frame_is_delta($frame)', 'replay pruning distinguishes deltas from keyframes');
  requireContains(helper, '$index < $firstKeyframeIndexByTrack[$trackKey]', 'replay drops deltas before available keyframes');
  requireContains(helper, 'sfu_frame_replay_stale_delta_pruned', 'stale replay delta diagnostic');
  requireContains(helper, 'sfu_frame_replay_pre_keyframe_delta_pruned', 'pre-keyframe replay delta diagnostic');
  requireContains(helper, 'sfu_frame_replay_keyframe_prioritized', 'keyframe-first replay diagnostic');
  requireContains(helper, 'function videochat_sfu_send_replay_frames_to_subscriber(', 'budgeted replay send helper');
  requireContains(helper, 'sfu_frame_replay_slow_subscriber_skipped', 'replay slow subscriber skip diagnostic');
  requireContains(helper, 'sfu_frame_replay_slow_subscriber_isolated', 'replay slow subscriber isolation diagnostic');
  requireContains(helper, 'videochat_sfu_subscriber_replay_video_send_budget_ms()', 'replay uses stricter send budget');

  requireContains(relay, 'videochat_sfu_send_replay_frames_to_subscriber(', 'live relay/SQLite replay delegate to budgeted subscriber sender');
  requireContains(relay, "'live_relay_poll'", 'live relay labels replay send path');
  requireContains(relay, "'sqlite_frame_buffer_poll'", 'SQLite replay labels replay send path');
  requireNotContains(relay, 'videochat_sfu_send_outbound_message($websocket, $frame, [', 'replay loops must not bypass subscriber pacing');

  requireContains(gateway, '$slowSubscriberVideoBlockedUntilMsByClient = [];', 'gateway keeps shared subscriber cooldown state');
  requireContains(gateway, '$slowSubscriberVideoBlockedUntilMsByClient', 'gateway passes shared subscriber cooldown to replay pollers');

  process.stdout.write('[sfu-replay-pacing-slow-subscriber-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
