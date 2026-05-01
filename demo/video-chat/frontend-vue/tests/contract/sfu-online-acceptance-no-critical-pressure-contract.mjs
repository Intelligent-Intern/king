import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-online-acceptance-no-critical-pressure-contract] FAIL: ${message}`);
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
  const packageJson = read('package.json');
  const pressureGate = read('tests/e2e/online-sfu-pressure-acceptance.mjs');
  const harness = read('tests/e2e/helpers/nativeAudioTransferHarness.js');
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');

  requireContains(
    packageJson,
    '"test:e2e:online-sfu-pressure": "node tests/e2e/online-sfu-pressure-acceptance.mjs"',
    'package script exposes the online no-critical-pressure gate',
  );
  requireContains(pressureGate, 'highMotionVideo: true', 'online gate uses high-motion capture');
  requireContains(pressureGate, 'assertNoManualVideoQualitySelect', 'online gate rejects manual quality controls');
  requireContains(pressureGate, 'assertAutomaticQualityTransitions', 'online gate observes automatic profile changes');
  requireContains(pressureGate, 'assertAutomaticQualityTransitionsOrNoPressure', 'online gate accepts no downshift only with explicit low-pressure proof');
  requireContains(pressureGate, 'MAX_NO_TRANSITION_BUFFERED_BYTES = 1024 * 1024', 'online gate caps no-transition pressure budget');
  requireContains(pressureGate, 'pressure_below_auto_downshift_threshold', 'online gate reports no-transition pressure proof');
  requireContains(pressureGate, 'MAX_TRANSIENT_BLACK_SAMPLES = 1', 'online gate allows only one transient black remote sample');
  requireContains(pressureGate, 'transient_remote_black_frame', 'online gate labels the only accepted transient black frame');
  requireContains(pressureGate, 'final remote video stayed black or missing', 'online gate fails black final remote video');
  requireContains(pressureGate, 'remote video did not recover enough healthy samples', 'online gate requires remote video recovery proof');
  requireContains(pressureGate, 'installSlowSubscriberNetwork', 'online gate simulates one slow subscriber');
  requireContains(pressureGate, 'uploadThroughput: -1', 'slow-subscriber gate does not throttle the publisher upload path');
  requireContains(pressureGate, 'sfu_send_backpressure_critical', 'online gate blocks critical publisher pressure');
  requireContains(pressureGate, 'sfu_source_readback_budget_exceeded', 'online gate blocks publisher source-readback budget failures');
  requireContains(pressureGate, 'sfu_source_readback_budget_pressure', 'online gate blocks backend source-readback diagnostics');
  requireContains(pressureGate, 'sfu_source_readback_profile_downshift', 'online gate blocks source-readback recovery downshifts');
  requireContains(pressureGate, "request:client-diagnostics", 'online gate records backend client diagnostics POST bodies');
  requireContains(pressureGate, '/api\\/user\\/client-diagnostics', 'online gate watches backend diagnostics submissions');
  requireContains(pressureGate, 'remote video frozen', 'online gate blocks remote freeze diagnostics');
  requireContains(pressureGate, 'CRITICAL_BUFFERED_BYTES = 6 * 1024 * 1024', 'online gate has a critical bufferedAmount ceiling');
  requireContains(pressureGate, 'MAX_ACCEPTED_BUFFERED_BYTES = 4 * 1024 * 1024', 'online gate enforces the quality profile buffer budget');
  requireContains(pressureGate, 'socketFailureCount', 'online gate fails on SFU socket close/error during media flow');
  requireContains(pressureGate, 'luma > 8', 'online gate fails black remote video');
  requireContains(harness, 'highMotionVideo = false', 'media shim keeps high-motion opt-in');
  requireContains(harness, 'resolveVideoSettings', 'media shim honors per-call getUserMedia capture constraints');
  requireContains(harness, 'maxBufferedAmountAfterSend', 'socket instrumentation reports send buffer pressure');
  requireContains(runtimeConfig, 'export const SFU_PROTECTED_MEDIA_ENABLED = true;', 'protected SFU media remains enabled for the acceptance gate');

  process.stdout.write('[sfu-online-acceptance-no-critical-pressure-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
