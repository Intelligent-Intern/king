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
  const adaptiveLayers = read('src/domain/realtime/sfu/adaptiveQualityLayers.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const recoveryReasons = read('src/domain/realtime/sfu/recoveryReasons.js');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');

  requireContains(receiverFeedback, 'RECEIVER_RENDER_LAG_PRESSURE_MS = 900', 'receiver render lag threshold');
  requireContains(receiverFeedback, 'maybeSendReceiverRenderLagFeedback', 'receiver render lag feedback helper');
  requireContains(receiverFeedback, "'sfu_receiver_render_lag'", 'receiver render lag pressure reason');
  requireContains(receiverFeedback, 'maybeSendReceiverSequenceGapFeedback', 'receiver sequence gap feedback helper');
  requireContains(receiverFeedback, "'sfu_receiver_sequence_gap'", 'receiver missed sequence pressure reason');
  requireContains(receiverFeedback, 'maybeSendReceiverLayerPreference', 'receiver adaptive layer preference helper');
  requireContains(adaptiveLayers, 'return false;', 'unchanged adaptive layer preference is not periodically resent');
  requireContains(receiverFeedback, 'receiver_render_latency_ms', 'receiver feedback includes render latency');
  requireContains(receiverFeedback, 'missing_frame_count', 'receiver feedback includes missing sequence count');
  requireContains(receiverFeedback, "requested_action: 'force_full_keyframe'", 'sequence-gap feedback explicitly asks for publisher keyframe');
  requireContains(receiverFeedback, 'request_full_keyframe: true', 'sequence-gap feedback sets full keyframe flag');
  requireContains(receiverFeedback, 'subscriber_send_latency_ms', 'receiver feedback includes subscriber pressure evidence');
  requireContains(receiverFeedback, 'buildSfuLayerPreferencePayload', 'receiver feedback can request automatic primary/thumbnail layers');
  requireContains(frameDecode, 'createSfuReceiverFeedback', 'frame decoder uses receiver feedback helper');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverRenderLagFeedback', 'render path sends receiver lag pressure');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverSequenceGapFeedback', 'continuity path sends sequence-gap pressure');
  requireContains(frameDecode, 'receiverFeedback.maybeSendReceiverLayerPreference', 'render path sends adaptive layer preference');
  requireContains(mediaStack, 'sendRemoteSfuVideoQualityPressure: (peer, publisherId, reason, nowMs, payload = {}) => {', 'media stack wires receiver feedback to signaling');
  requireContains(mediaStack, "type: 'call/media-quality-pressure'", 'receiver feedback uses existing quality-pressure signaling');
  requireContains(mediaStack, 'resolveSfuRecoveryRequestedAction(normalizedReason, payload?.requested_action)', 'receiver feedback preserves explicit requested actions');
  requireContains(mediaStack, 'request_full_keyframe: Boolean(payload?.request_full_keyframe) || requestFullKeyframe', 'receiver feedback marks explicit keyframe requests');
  assert.equal(
    mediaStack.includes("|| requestedVideoLayer === 'primary'"),
    false,
    'primary layer preference must not turn into a full-keyframe recovery request',
  );
  requireContains(recoveryReasons, "'sfu_receiver_sequence_gap'", 'sequence gaps are full-keyframe recovery reasons');
  requireContains(recoveryReasons, "'sfu_remote_video_never_started'", 'never-started video is a full-keyframe recovery reason');
  requireContains(socketLifecycle, 'shouldRequestSfuFullKeyframeForReason(sourceReason)', 'socket fallback pressure also promotes recovery reasons to full keyframes');
  assert.equal(
    socketLifecycle.includes('|| primaryLayerRequested'),
    false,
    'publisher must reserve full keyframes for explicit or real recovery reasons',
  );
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
