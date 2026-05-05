import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-auto-readback-downgrade-contract] FAIL: ${message}`);
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
  const controllerPath = path.resolve(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts');

  requireContains(sfuTransport, 'wlvcSourceReadbackFailureCount', 'SFU transport state keeps source-readback consecutive failure count');
  requireContains(publisherBackpressureController, "kind === 'source_readback_failure'", 'publisher controller has source-readback decision path');
  requireContains(publisherBackpressureController, 'function handleSourceReadbackBudgetFailure', 'publisher controller handles source-readback before generic send failures');
  requireContains(publisherBackpressureController, 'sfu_source_readback_profile_downshift', 'publisher controller emits source-readback profile downshift diagnostics');
  requireContains(publisherBackpressureController, 'resetWlvcSourceReadbackFailureCounters', 'publisher controller resets source-readback streaks');
  requireContains(publisherPipeline, "'sfu_source_readback_budget_exceeded'", 'publisher pipeline passes source-readback failures into controller');

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

  const firstReadbackFailure = decidePublisherBackpressureAction({
    kind: 'source_readback_failure',
    reason: 'sfu_source_readback_budget_exceeded',
    sourceReadbackFailureCount: 1,
  }, pressureConfig);
  assert.equal(
    firstReadbackFailure.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
    false,
    'first source-readback failure must pause/drop but not downshift yet',
  );

  const secondReadbackFailure = decidePublisherBackpressureAction({
    kind: 'source_readback_failure',
    reason: 'sfu_source_readback_budget_exceeded',
    sourceReadbackFailureCount: 2,
  }, pressureConfig);
  assert.ok(
    secondReadbackFailure.actions.includes(PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
    'second consecutive source-readback failure must downshift before another source frame is read',
  );

  const diagnostics = [];
  const downgradeReasons = [];
  const state = {
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
    wlvcSourceReadbackFailureCount: 0,
    wlvcSourceReadbackFailureFirstAtMs: 0,
    wlvcSourceReadbackFailureLastLogAtMs: 0,
    sfuVideoRecoveryLastAtMs: 0,
  };
  const controller = createPublisherBackpressureController({
    callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
    captureClientDiagnostic: (event) => diagnostics.push(event),
    downgradeSfuVideoQualityAfterEncodePressure: (reason) => {
      downgradeReasons.push(reason);
      return true;
    },
    getMediaRuntimePath: () => 'wlvc_wasm',
    getRemotePeerCount: () => 1,
    getShouldConnectSfu: () => true,
    onRestartSfu: () => {},
    resetWlvcEncoderAfterDroppedEncodedFrame: () => {},
    sfuAutoQualityDowngradeBackpressureWindowMs: 1000,
    sfuAutoQualityDowngradeSendFailureThreshold: 2,
    sfuAutoQualityDowngradeSkipThreshold: 2,
    sfuBackpressureLogCooldownMs: 0,
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
    state,
  });
  const failureDetails = {
    reason: 'sfu_source_readback_budget_exceeded',
    stage: 'dom_canvas_compatibility_readback',
    source: 'dom_canvas_compatibility_get_image_data_budget_exceeded',
    transportPath: 'publisher_source_readback',
    bufferedAmount: 0,
    publisher_frame_trace_id: 'pub_test_1',
  };

  controller.handleWlvcFrameSendFailure(0, 'track-readback', 'sfu_source_readback_budget_exceeded', failureDetails);
  assert.equal(downgradeReasons.length, 0, 'first source-readback failure must not downshift');
  assert.equal(state.wlvcSourceReadbackFailureCount, 1, 'first source-readback failure starts the source streak');
  assert.equal(state.wlvcFrameSendFailureCount, 0, 'source-readback failures must not pollute generic send-failure count');

  controller.handleWlvcFrameSendFailure(0, 'track-readback', 'sfu_source_readback_budget_exceeded', failureDetails);
  assert.deepEqual(
    downgradeReasons,
    ['sfu_source_readback_budget_exceeded'],
    'second consecutive source-readback failure downshifts through the automatic profile switcher',
  );
  assert.equal(state.wlvcSourceReadbackFailureCount, 0, 'successful downshift resets source-readback streak');
  assert.equal(state.wlvcFrameSendFailureCount, 0, 'source-readback downshift remains separate from send-failure count');
  assert.ok(
    diagnostics.some((event) => event?.eventType === 'sfu_source_readback_profile_downshift'),
    'source-readback downshift diagnostic must be emitted immediately',
  );

  process.stdout.write('[sfu-auto-readback-downgrade-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
