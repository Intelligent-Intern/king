import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-high-motion-payload-contract] FAIL: ${message}`);
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
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');

  requireContains(publisherPipeline, 'planSelectiveTilePatch(imageData, previousFullFrameImageData', 'publisher attempts selective tile patch before full-frame fallback');
  requireContains(publisherPipeline, 'planBackgroundSnapshotPatch(imageData, previousFullFrameImageData', 'publisher attempts background snapshot patch before repeated full-frame bursts');
  requireContains(publisherPipeline, 'encodedPayloadBytes > maxEncodedPayloadBytes', 'publisher drops hard over-budget payloads before send');
  requireContains(publisherPipeline, 'encodedPayloadBytes >= payloadSoftLimitBytes', 'publisher drops soft over-budget payloads before send');
  requireContains(publisherPipeline, "reason: 'sfu_wlvc_rate_budget_pressure'", 'publisher flags near-budget high-motion payload pressure');
  requireContains(sfuPublisherControl, "pressureReason = String(details?.reason || 'sfu_high_motion_payload_pressure')", 'publisher controller preserves high-motion pressure reason');
  requireContains(sfuPublisherControl, 'resetWlvcEncoderAfterDroppedEncodedFrame(pressureReason)', 'publisher controller forces encoder recovery after payload drop');
  requireContains(sfuPublisherControl, 'forced_next_keyframe: true', 'publisher controller marks a forced recoverable keyframe plan');
  requireContains(runtimeSwitching, "'sfu_high_motion_payload_pressure'", 'high-motion pressure downshifts without waiting for cooldown');
  requireContains(runtimeSwitching, "'sfu_wlvc_rate_budget_pressure'", 'near-budget payload pressure downshifts without waiting for cooldown');

  process.stdout.write('[sfu-high-motion-payload-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
