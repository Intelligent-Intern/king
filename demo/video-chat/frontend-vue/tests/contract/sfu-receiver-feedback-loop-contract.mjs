import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-receiver-feedback-loop-contract] FAIL: ${message}`);
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
  const receiverFeedback = read('src/domain/realtime/sfu/receiverFeedback.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');

  requireContains(receiverFeedback, 'RECEIVER_RENDER_LAG_PRESSURE_MS = 900', 'receiver render lag threshold');
  requireContains(receiverFeedback, 'maybeSendReceiverRenderLagFeedback', 'receiver render lag feedback helper');
  requireContains(receiverFeedback, "'sfu_receiver_render_lag'", 'receiver render lag pressure reason');
  requireContains(receiverFeedback, 'maybeSendReceiverSequenceGapFeedback', 'receiver sequence gap feedback helper');
  requireContains(receiverFeedback, "'sfu_receiver_sequence_gap'", 'receiver missed sequence pressure reason');
  requireContains(receiverFeedback, 'receiver_render_latency_ms', 'receiver feedback includes render latency');
  requireContains(receiverFeedback, 'missing_frame_count', 'receiver feedback includes missing sequence count');
  requireContains(receiverFeedback, 'subscriber_send_latency_ms', 'receiver feedback includes subscriber pressure evidence');
  requireContains(frameDecode, 'createSfuReceiverFeedback', 'frame decoder uses receiver feedback helper');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverRenderLagFeedback', 'render path sends receiver lag pressure');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverSequenceGapFeedback', 'continuity path sends sequence-gap pressure');
  requireContains(mediaStack, 'sendRemoteSfuVideoQualityPressure: (peer, publisherId, reason, nowMs, payload = {}) => {', 'media stack wires receiver feedback to signaling');
  requireContains(mediaStack, "type: 'call/media-quality-pressure'", 'receiver feedback uses existing quality-pressure signaling');
  requireContains(mediaStack, "requested_action: requestFullKeyframe ? 'force_full_keyframe' : 'downgrade_outgoing_video'", 'receiver feedback can request a full keyframe when the decoder is waiting');
  requireContains(mediaStack, 'request_full_keyframe: requestFullKeyframe', 'receiver feedback marks explicit keyframe requests');
  requireContains(socketLifecycle, 'downgradeSfuVideoQualityAfterEncodePressure', 'publisher downshifts after receiver pressure');
  requireContains(socketLifecycle, 'requestWlvcFullFrameKeyframe', 'publisher handles explicit full-keyframe receiver pressure');
  requireContains(socketLifecycle, "eventType: 'sfu_remote_quality_pressure_received'", 'publisher records receiver pressure diagnostics');

  process.stdout.write('[sfu-receiver-feedback-loop-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
