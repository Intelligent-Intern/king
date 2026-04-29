import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { createMediaSecuritySession } from '../../src/domain/realtime/media/security.js';
import { measureProtectedSfuFrameBudget } from '../../src/domain/realtime/media/protectedFrameBudget.js';

function fail(message) {
  throw new Error(`[sfu-security-throughput-budget-contract] FAIL: ${message}`);
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
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const protectedBudget = read('src/domain/realtime/media/protectedFrameBudget.js');
  const workspaceConfig = read('src/domain/realtime/workspace/config.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');

  requireContains(runtimeConfig, 'export const SFU_PROTECTED_MEDIA_ENABLED = true;', 'protected SFU media is enabled');
  requireContains(protectedBudget, 'measureProtectedSfuFrameBudget', 'protected media budget helper');
  requireContains(protectedBudget, 'protected_envelope_bytes', 'protected envelope byte metric');
  requireContains(protectedBudget, 'protection_overhead_bytes', 'protected overhead byte metric');
  requireContains(protectedBudget, 'protection_overhead_ratio', 'protected overhead ratio metric');
  requireContains(workspaceConfig, 'maxEncodedBytesPerFrame: 180 * 1024', 'rescue profile has explicit encoded-byte budget');
  requireContains(publisherPipeline, 'measureProtectedSfuFrameBudget({ protectedFrame, plaintextBytes: encodedPayloadBytes, maxPayloadBytes: maxEncodedPayloadBytes })', 'publisher measures protected frame overhead before send');
  requireContains(publisherPipeline, "reason: 'sfu_protected_media_budget_pressure'", 'publisher drops protected frames that exceed the active budget');
  requireContains(runtimeSwitching, "'sfu_protected_media_budget_pressure'", 'protected overhead pressure bypasses downgrade cooldown');
  requireContains(frameDecode, 'keyframe_required_after_recovery: true', 'wrong-key protected decrypt recovery requires fresh keyframe');
  requireContains(frameDecode, 'shouldRecoverMediaSecurityFromFrameError(error)', 'protected decrypt errors trigger handshake recovery');

  const alice = createMediaSecuritySession({ callId: 'call-budget', roomId: 'room-budget', userId: 101 });
  const bob = createMediaSecuritySession({ callId: 'call-budget', roomId: 'room-budget', userId: 202 });
  alice.markParticipantSet([202]);
  bob.markParticipantSet([101]);
  await bob.handleHelloSignal(101, (await alice.buildHelloSignal(202, 'wlvc_sfu')).payload);
  await alice.handleHelloSignal(202, (await bob.buildHelloSignal(101, 'wlvc_sfu')).payload);
  await bob.handleSenderKeySignal(101, (await alice.buildSenderKeySignal(202)).payload);
  await alice.handleSenderKeySignal(202, (await bob.buildSenderKeySignal(101)).payload);

  const plaintext = new Uint8Array(2048);
  plaintext.fill(7);
  const protectedFrame = await alice.protectFrame({
    data: plaintext,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_wasm',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-budget',
    timestamp: 123,
  });
  const measured = measureProtectedSfuFrameBudget({
    protectedFrame,
    plaintextBytes: plaintext.byteLength,
    maxPayloadBytes: 180 * 1024,
  });
  assert.equal(measured.ok, true, 'normal protected frame must fit the rescue profile budget');
  assert.ok(measured.metrics.protected_envelope_bytes > plaintext.byteLength, 'protected envelope must measure transport overhead');
  assert.ok(measured.metrics.protection_overhead_bytes > 0, 'protection overhead must be positive and explicit');

  const nearBudget = measureProtectedSfuFrameBudget({
    protectedFrame,
    plaintextBytes: 180 * 1024,
    maxPayloadBytes: measured.metrics.protected_envelope_bytes - 1,
  });
  assert.equal(nearBudget.ok, false, 'protected overhead must fail closed when the envelope exceeds budget');

  process.stdout.write('[sfu-security-throughput-budget-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
