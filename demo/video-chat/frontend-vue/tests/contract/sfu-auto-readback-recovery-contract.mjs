import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-auto-readback-recovery-contract] FAIL: ${message}`);
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

function baseTransportState() {
  return {
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
    wlvcSourceReadbackStableStartedAtMs: 0,
    wlvcSourceReadbackStableSampleCount: 0,
    wlvcSourceReadbackLastSuccessAtMs: 0,
    wlvcSourceReadbackLastDrawMs: 0,
    wlvcSourceReadbackLastReadbackMs: 0,
    sfuAutoQualityDowngradeLastAtMs: 0,
    sfuAutoQualityRecoveryLastAtMs: 0,
    sfuVideoRecoveryLastAtMs: 0,
  };
}

function createController(createPublisherBackpressureController, overrides = {}) {
  return createPublisherBackpressureController({
    callMediaPrefs: { outgoingVideoQualityProfile: 'rescue' },
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
    ...overrides,
  });
}

async function main() {
  const controllerPath = path.resolve(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const runtimeConfigPath = path.resolve(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts');

  requireContains(runtimeConfig, 'SFU_AUTO_QUALITY_RECOVERY_NEXT', 'runtime config defines upward profile order');
  requireContains(sfuTransport, 'wlvcSourceReadbackStableStartedAtMs', 'SFU transport state stores the readback stable window');
  requireContains(sfuTransport, 'sfuAutoQualityRecoveryLastAtMs', 'SFU transport state throttles automatic quality recovery');
  requireContains(publisherBackpressureController, 'noteWlvcSourceReadbackSuccess', 'publisher controller exposes source-readback success accounting');
  requireContains(publisherBackpressureController, 'sfu_source_readback_recovered', 'publisher controller asks for an up-probe after stable readback');
  requireContains(publisherPipeline, 'noteWlvcSourceReadbackSuccess({', 'publisher pipeline reports readback success after a sent frame');
  requireContains(runtimeSwitching, 'probeSfuVideoQualityAfterStableReadback', 'runtime switcher owns automatic upward profile probes');
  requireContains(runtimeSwitching, 'sfu_source_readback_profile_upshift', 'runtime switcher emits backend diagnostics for upward probes');
  requireContains(runtimeSwitching, "qualityDirection === 'up'", 'existing profile switch callback supports automatic recovery without extra CallWorkspace wiring');

  const {
    SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS,
    SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS,
  } = await import(pathToFileURL(runtimeConfigPath).href);
  const { createPublisherBackpressureController } = await import(pathToFileURL(controllerPath).href);

  const nowMs = Date.now();
  const state = baseTransportState();
  state.wlvcSourceReadbackStableStartedAtMs = nowMs - SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS - 250;
  state.wlvcSourceReadbackStableSampleCount = 4;
  const probes = [];
  let restarts = 0;
  const controller = createController(createPublisherBackpressureController, {
    state,
    probeSfuVideoQualityAfterStableReadback: (reason, details) => {
      probes.push({ reason, details });
      return true;
    },
    onRestartSfu: () => { restarts += 1; },
  });
  const probed = controller.noteWlvcSourceReadbackSuccess({
    nowMs,
    trackId: 'track-up',
    drawImageMs: 4,
    readbackMs: 5,
    drawBudgetMs: 10,
    readbackBudgetMs: 10,
    readbackMethod: 'video_frame_copy_to_rgba',
    sourceBackend: 'video_frame_processor',
    frameWidth: 320,
    frameHeight: 180,
  });
  assert.equal(probed, true, 'stable low-timing readback window must request one upward quality probe');
  assert.equal(probes.length, 1, 'only one recovery probe is emitted');
  assert.equal(probes[0].reason, 'sfu_source_readback_recovered', 'recovery probe reason is explicit');
  assert.equal(probes[0].details.stable_window_ms, SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS, 'probe includes the stable-window threshold');
  assert.equal(restarts, 0, 'quality recovery must not restart the SFU socket');
  assert.equal(state.wlvcSourceReadbackStableStartedAtMs, 0, 'successful probe resets the stable window');

  const highTimingState = baseTransportState();
  highTimingState.wlvcSourceReadbackStableStartedAtMs = nowMs - SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS - 250;
  const highTimingController = createController(createPublisherBackpressureController, {
    state: highTimingState,
    probeSfuVideoQualityAfterStableReadback: () => {
      throw new Error('high readback timing must not probe upward');
    },
  });
  assert.equal(highTimingController.noteWlvcSourceReadbackSuccess({
    nowMs,
    drawImageMs: 4,
    readbackMs: 9,
    drawBudgetMs: 10,
    readbackBudgetMs: 10,
  }), false, 'readback timing above the recovery ratio must reset instead of probing');
  assert.equal(highTimingState.wlvcSourceReadbackStableStartedAtMs, 0, 'bad timing clears the recovery window');

  const cooldownState = baseTransportState();
  cooldownState.wlvcSourceReadbackStableStartedAtMs = nowMs - SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS - 250;
  cooldownState.sfuAutoQualityDowngradeLastAtMs = nowMs - Math.max(1, SFU_AUTO_QUALITY_RECOVERY_MIN_INTERVAL_MS - 1000);
  const cooldownController = createController(createPublisherBackpressureController, {
    state: cooldownState,
    probeSfuVideoQualityAfterStableReadback: () => {
      throw new Error('recent quality downshift must block recovery probing');
    },
  });
  assert.equal(cooldownController.noteWlvcSourceReadbackSuccess({
    nowMs,
    drawImageMs: 4,
    readbackMs: 5,
    drawBudgetMs: 10,
    readbackBudgetMs: 10,
  }), false, 'recent downshift cooldown blocks upward recovery');

  const balancedCeilingState = baseTransportState();
  balancedCeilingState.wlvcSourceReadbackStableStartedAtMs = nowMs - SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS - 250;
  const balancedCeilingController = createController(createPublisherBackpressureController, {
    callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
    state: balancedCeilingState,
    probeSfuVideoQualityAfterStableReadback: () => {
      throw new Error('balanced is the automatic stability ceiling and must not probe quality');
    },
  });
  assert.equal(balancedCeilingController.noteWlvcSourceReadbackSuccess({
    nowMs,
    drawImageMs: 4,
    readbackMs: 5,
    drawBudgetMs: 10,
    readbackBudgetMs: 10,
  }), false, 'automatic recovery stops at balanced instead of probing quality');
  assert.equal(balancedCeilingState.wlvcSourceReadbackStableStartedAtMs, 0, 'balanced ceiling clears the recovery window');

  const fallbackState = baseTransportState();
  fallbackState.wlvcSourceReadbackStableStartedAtMs = nowMs - SFU_AUTO_QUALITY_RECOVERY_STABLE_WINDOW_MS - 250;
  const fallbackCalls = [];
  const fallbackController = createController(createPublisherBackpressureController, {
    state: fallbackState,
    downgradeSfuVideoQualityAfterEncodePressure: (reason, details) => {
      fallbackCalls.push({ reason, details });
      return true;
    },
  });
  assert.equal(fallbackController.noteWlvcSourceReadbackSuccess({
    nowMs,
    drawImageMs: 4,
    readbackMs: 5,
    drawBudgetMs: 10,
    readbackBudgetMs: 10,
  }), true, 'controller falls back to the existing profile switch callback');
  assert.equal(fallbackCalls[0].reason, 'sfu_source_readback_recovered', 'fallback callback carries the recovery reason');
  assert.equal(fallbackCalls[0].details.direction, 'up', 'fallback callback marks the switch as an upward probe');

  process.stdout.write('[sfu-auto-readback-recovery-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
