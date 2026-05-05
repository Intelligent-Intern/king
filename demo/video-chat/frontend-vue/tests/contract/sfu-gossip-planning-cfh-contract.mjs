import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

async function main() {
  const receiverFeedback = read('src/domain/realtime/sfu/receiverFeedback.ts');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.ts');
  const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');

  requireContains(receiverFeedback, 'coordinateSfuKeyframeRecoveryRequest', 'receiver feedback uses the keyframe coordinator');
  requireContains(receiverFeedback, "keyframe_recovery_owner: 'sfu_per_publisher_keyframe_coordinator'", 'keyframe diagnostics name the single owner');
  requireContains(receiverFeedback, 'sfu_receiver_keyframe_request_coalesced', 'coalesced keyframe requests are observable');
  requireContains(frameDecode, 'clearSfuKeyframeRecoveryCoordinator', 'rendered keyframes clear the coordinator window');
  requireContains(publisherBackpressureController, 'resolveSfuReceiverCountAwareSendBudget', 'publisher backpressure uses receiver-count-aware send budget');
  requireContains(publisherBackpressureController, 'receiver_count_send_budget', 'publisher diagnostics include receiver-count send budget');
  requireContains(publisherBackpressureController, 'receiverFanoutTooHigh', 'publisher decisions account for receiver fanout');
  requireContains(sfuTransport, 'wlvcRemoteKeyframeRequestLastByKey: new Map()', 'SFU transport initializes remote keyframe coalescing state');
  requireContains(runtimeHealth, 'runSfuPublisherStallRecoveryLadder', 'runtime health uses targeted publisher stall recovery ladder');
  requireContains(runtimeHealth, 'recoverSfuPublisherBeforeReconnect', 'stall monitor has a targeted recovery step before reconnect');
  requireContains(runtimeHealth, "recovery_ladder_step: 'keyframe'", 'stall recovery requests keyframes as a ladder step');
  requireContains(runtimeHealth, "type: 'call/media-security-sync-request'", 'stall recovery can request security resync before reconnect');
  requireContains(runtimeHealth, '&& !targetedFrozenRecovery.recovered', 'frozen-video reconnect is gated behind targeted recovery');
  requireContains(runtimeHealth, '&& !targetedStallRecovery.recovered', 'never-started reconnect is gated behind targeted recovery');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const keyframe = await server.ssrLoadModule('/src/domain/realtime/sfu/keyframeRecoveryCoordinator.ts');
    const sendBudget = await server.ssrLoadModule('/src/domain/realtime/sfu/sendBudget.ts');
    const stallRecovery = await server.ssrLoadModule('/src/domain/realtime/sfu/stallRecoveryLadder.ts');
    const backpressure = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');

    const owner = {};
    const first = keyframe.coordinateSfuKeyframeRecoveryRequest(owner, {
      publisherId: 'pub-a',
      publisherUserId: 22,
      reason: 'sfu_remote_video_decoder_waiting_keyframe',
      trackId: 'video-main',
    }, 10_000);
    assert.equal(first.emit, true, 'first keyframe request should emit');
    const duplicate = keyframe.coordinateSfuKeyframeRecoveryRequest(owner, {
      publisherId: 'pub-a',
      publisherUserId: 22,
      reason: 'sfu_remote_video_decoder_waiting_keyframe',
      trackId: 'video-main',
    }, 10_250);
    assert.equal(duplicate.coalesced, true, 'duplicate keyframe request should coalesce inside active window');

    const budget = sendBudget.resolveSfuReceiverCountAwareSendBudget({
      baseBufferedBytes: 1_200_000,
      baseWireBytesPerSecond: 2_400_000,
      chunkCount: 48,
      receiverCount: 3,
      websocketBufferedAmount: 500_000,
    });
    assert.equal(budget.receiver_count, 3);
    assert.equal(budget.chunk_count, 48);
    assert.ok(budget.receiver_adjusted_buffered_bytes < 1_200_000, 'receiver fanout must tighten buffered budget');

    const fanoutDecision = backpressure.decidePublisherBackpressureAction({
      kind: 'send_failure',
      reason: 'sfu_projected_buffer_budget_exceeded',
      bufferedAmount: 500_000,
      chunkCount: 48,
      receiverCount: 3,
      sendFailureCount: 1,
    }, {
      highWaterBytes: 1_200_000,
      lowWaterBytes: 600_000,
      criticalBytes: 2_400_000,
      backpressureWindowMs: 1000,
      hardResetAfterMs: 3000,
    });
    assert.ok(
      fanoutDecision.actions.includes(backpressure.PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT),
      'three-participant projected send pressure must request profile downshift',
    );
    assert.equal(fanoutDecision.stage_telemetry.receiver_count, 3);
    assert.equal(fanoutDecision.stage_telemetry.chunk_count, 48);

    const ladderPeer = { userId: 31 };
    const ladderDiagnostics = [];
    const ladder = stallRecovery.runSfuPublisherStallRecoveryLadder({
      captureClientDiagnostic: (event) => ladderDiagnostics.push(event),
      peer: ladderPeer,
      publisherId: 'pub-lag',
      reason: 'remote_video_never_started',
      nowMs: 50_000,
      resubscribe: () => true,
      requestKeyframe: () => {
        throw new Error('keyframe step should not run after resubscribe succeeds');
      },
    });
    assert.equal(ladder.recovered, true);
    assert.equal(ladder.step, 'resubscribe');
    assert.equal(ladderDiagnostics[0].payload.recovery_ladder_step, 'resubscribe');
  } finally {
    await server.close();
  }

  process.stdout.write('[sfu-gossip-planning-cfh-contract] PASS\n');
}

main().catch((error) => {
  throw new Error(`[sfu-gossip-planning-cfh-contract] FAIL: ${error instanceof Error ? error.message : 'unknown failure'}`);
});
