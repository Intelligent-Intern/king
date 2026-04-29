import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-publisher-backpressure-controller-contract] FAIL: ${message}`);
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

async function main() {
  const controllerPath = path.resolve(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');

  requireContains(publisherBackpressureController, 'export const PUBLISHER_BACKPRESSURE_ACTIONS', 'central publisher action vocabulary');
  requireContains(publisherBackpressureController, 'export function decidePublisherBackpressureAction', 'pure publisher decision function');
  requireContains(publisherBackpressureController, 'export function createPublisherBackpressureController', 'stateful publisher controller factory');
  requireContains(publisherBackpressureController, "PAUSE_ENCODE: 'pause_encode'", 'controller can pause encode loop');
  requireContains(publisherBackpressureController, "DROP_FRAME: 'drop_frame'", 'controller can drop frames');
  requireContains(publisherBackpressureController, "PROFILE_DOWNSHIFT: 'profile_downshift'", 'controller can downshift profile');
  requireContains(publisherBackpressureController, "REQUEST_KEYFRAME: 'request_keyframe'", 'controller can request keyframe recovery');
  requireContains(publisherBackpressureController, "SOCKET_RESTART: 'socket_restart'", 'controller can restart only stuck sockets');
  requireContains(publisherBackpressureController, 'stage_telemetry', 'controller decisions carry stage telemetry');
  requireContains(publisherBackpressureController, 'receiver_render_latency_ms', 'controller accepts receiver render pressure');
  requireContains(publisherBackpressureController, 'subscriber_send_latency_ms', 'controller accepts subscriber send pressure');
  requireContains(publisherBackpressureController, 'wlvcBackpressurePauseUntilMs', 'controller owns bounded encode pauses');
  requireContains(publisherBackpressureController, 'sfuWlvcSendBufferLowWaterBytes', 'controller owns low-water resume pressure');
  requireContains(publisherBackpressureController, 'sfuWlvcSendBufferHighWaterBytes', 'controller owns high-water pressure');
  requireContains(publisherBackpressureController, 'sfuWlvcSendBufferCriticalBytes', 'controller owns critical stuck-socket pressure');
  requireContains(publisherBackpressureController, 'sfuWlvcEncodeFailureThreshold', 'controller owns runtime encode failure threshold');
  requireContains(publisherBackpressureController, 'function requestWlvcFullFrameKeyframe', 'controller owns receiver-requested full-frame keyframe recovery');
  requireContains(publisherPipeline, 'remoteKeyframeRequestPending', 'publisher pipeline observes receiver-requested full-frame keyframe recovery');

  requireContains(sfuTransport, "import { createPublisherBackpressureController } from './publisherBackpressureController';", 'SFU transport delegates publisher pressure decisions');
  requireContains(sfuTransport, 'createPublisherBackpressureController(options)', 'SFU transport instantiates publisher controller');
  assert.equal(
    sfuTransport.includes('downgradeSfuVideoQualityAfterEncodePressure('),
    false,
    'SFU transport must not make direct quality downshift decisions outside the publisher controller',
  );
  requireContains(mediaStack, 'handleWlvcRuntimeEncodeError: sfuTransport.handleWlvcRuntimeEncodeError', 'media stack wires runtime encode failures into publisher controller');
  requireContains(mediaStack, 'sfuWlvcEncodeFailureThreshold: constants.wlvcEncodeFailureThreshold', 'media stack passes runtime encode threshold to publisher controller');
  requireContains(publisherPipeline, 'handleWlvcRuntimeEncodeError({', 'publisher pipeline delegates runtime encode failure downshift decisions');
  assert.equal(
    publisherPipeline.includes('refs.downgradeSfuVideoQualityAfterEncodePressure'),
    false,
    'publisher pipeline must not bypass the publisher backpressure controller',
  );

  const {
    PUBLISHER_BACKPRESSURE_ACTIONS,
    createPublisherBackpressureController,
    decidePublisherBackpressureAction,
  } = await import(pathToFileURL(controllerPath).href);

  const pressureConfig = {
    backpressureWindowMs: 1000,
    criticalBytes: 2400,
    hardResetAfterMs: 3000,
    highWaterBytes: 1200,
    lowWaterBytes: 600,
    sendFailureThreshold: 2,
    skipThreshold: 2,
  };

  const skipDecision = decidePublisherBackpressureAction({
    kind: 'encode_backpressure',
    bufferedAmount: 1300,
    skipCount: 2,
    sustainedBackpressureMs: 900,
  }, pressureConfig);
  assert.deepEqual(
    skipDecision.actions,
    [
      PUBLISHER_BACKPRESSURE_ACTIONS.PAUSE_ENCODE,
      PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME,
      PUBLISHER_BACKPRESSURE_ACTIONS.REQUEST_KEYFRAME,
      PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT,
    ],
    'repeated bounded-queue skips must pause, drop, request keyframe, and downshift before critical pressure',
  );

  const stuckDecision = decidePublisherBackpressureAction({
    kind: 'encode_backpressure',
    bufferedAmount: 3000,
    skipCount: 5,
    sustainedBackpressureMs: 3500,
  }, pressureConfig);
  assert.ok(
    stuckDecision.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.SOCKET_RESTART),
    'only sustained critical buffer pressure may ask for socket restart',
  );

  const payloadDecision = decidePublisherBackpressureAction({
    kind: 'payload_pressure',
    payloadBytes: 2_000_000,
    payloadPressureCount: 1,
    encodeMs: 48,
  }, pressureConfig);
  assert.ok(payloadDecision.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.DROP_FRAME), 'payload pressure drops before send');
  assert.ok(payloadDecision.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT), 'payload pressure downshifts profile');

  const wireBudgetDecision = decidePublisherBackpressureAction({
    kind: 'send_failure',
    reason: 'sfu_wire_rate_budget_exceeded',
    bufferedAmount: 0,
    sendFailureCount: 1,
  }, pressureConfig);
  assert.ok(
    wireBudgetDecision.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
    'wire-rate budget send failures downshift immediately before browser buffering can become critical',
  );

  const throttleState = {
    wlvcBackpressureSkipCount: 0,
    wlvcBackpressureFirstAtMs: 0,
    wlvcBackpressureLastLogAtMs: 0,
    wlvcBackpressurePauseUntilMs: 0,
    wlvcPayloadPressureCount: 0,
    wlvcPayloadPressureFirstAtMs: 0,
    wlvcPayloadPressureLastLogAtMs: 0,
    wlvcFrameSendFailureLastLogAtMs: Date.now(),
    wlvcFrameSendFailureCount: 0,
    wlvcFrameSendFailureFirstAtMs: 0,
    sfuVideoRecoveryLastAtMs: 0,
  };
  const throttleStartedAtMs = Date.now();
  const controller = createPublisherBackpressureController({
    callMediaPrefs: { outgoingVideoQualityProfile: 'realtime' },
    captureClientDiagnostic: () => {},
    downgradeSfuVideoQualityAfterEncodePressure: () => false,
    getMediaRuntimePath: () => 'wlvc_wasm',
    getRemotePeerCount: () => 1,
    getShouldConnectSfu: () => true,
    onRestartSfu: () => {},
    resetWlvcEncoderAfterDroppedEncodedFrame: () => {},
    sfuAutoQualityDowngradeBackpressureWindowMs: 1000,
    sfuAutoQualityDowngradeSendFailureThreshold: 2,
    sfuAutoQualityDowngradeSkipThreshold: 2,
    sfuBackpressureLogCooldownMs: 999999,
    sfuConnectRetryDelayMs: 10,
    sfuConnected: { value: true },
    sfuVideoRecoveryReconnectCooldownMs: 5000,
    sfuWlvcBackpressureHardResetAfterMs: 3000,
    sfuWlvcBackpressureMaxPauseMs: 2500,
    sfuWlvcBackpressureMinPauseMs: 350,
    sfuWlvcEncodeFailureThreshold: 18,
    sfuWlvcSendBufferCriticalBytes: 2400,
    sfuWlvcSendBufferHighWaterBytes: 1200,
    sfuWlvcSendBufferLowWaterBytes: 600,
    state: throttleState,
  });
  controller.handleWlvcFrameSendFailure(0, 'track-wire', 'sfu_wire_rate_budget_exceeded', {
    reason: 'sfu_wire_rate_budget_exceeded',
    retryAfterMs: 900,
    bufferedAmount: 0,
  });
  assert.ok(
    throttleState.wlvcBackpressurePauseUntilMs >= throttleStartedAtMs + 900,
    'wire-rate retryAfterMs must throttle the encoder for the measured rolling-budget retry window',
  );
  const keyframeStartedAtMs = Date.now();
  assert.equal(
    controller.requestWlvcFullFrameKeyframe('sfu_remote_video_decoder_waiting_keyframe', {
      sender_user_id: 7,
      publisher_id: 'sfu_1',
    }),
    true,
    'receiver keyframe-wait pressure must be accepted by the publisher controller',
  );
  assert.ok(
    throttleState.wlvcRemoteKeyframeRequestUntilMs >= keyframeStartedAtMs + 3000,
    'receiver keyframe-wait pressure must hold selective patches closed until a full-frame keyframe is sent',
  );
  assert.equal(
    throttleState.wlvcRemoteKeyframeRequestCount,
    1,
    'receiver keyframe-wait pressure increments the explicit full-frame keyframe request counter',
  );

  const receiverDecision = decidePublisherBackpressureAction({
    kind: 'receiver_feedback',
    receiverRenderLatencyMs: 1200,
  }, {
    receiverLagPressureMs: 900,
  });
  assert.deepEqual(
    receiverDecision.actions,
    [PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT],
    'receiver feedback can downshift publisher before sender socket reaches critical pressure',
  );

  process.stdout.write('[sfu-publisher-backpressure-controller-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
