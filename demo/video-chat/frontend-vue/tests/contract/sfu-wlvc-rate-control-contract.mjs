import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-wlvc-rate-control-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

async function main() {
  const workspaceConfig = readFrontend('src/domain/realtime/workspace/config.ts');
  const publisherPipeline = readFrontend('src/domain/realtime/local/publisherPipeline.ts');
  const sfuTransport = readFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');
  const publisherBackpressureController = readFrontend('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
  const runtimeSwitching = readFrontend('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
  const sfuClient = readFrontend('src/lib/sfu/sfuClient.ts');
  const outboundFrameBudget = readFrontend('src/lib/sfu/outboundFrameBudget.ts');
  const framePayload = readFrontend('src/lib/sfu/framePayload.ts');
  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(workspaceConfig, 'payloadSoftLimitRatio', 'profile budgets define soft payload limit ratio');
  requireContains(publisherPipeline, 'payloadSoftLimitBytes', 'publisher computes active soft payload byte limit');
  requireContains(publisherPipeline, 'encodedPayloadBytes >= payloadSoftLimitBytes || encodeMs > encodeBudgetMs', 'publisher acts before hard payload/socket pressure');
  requireContains(publisherPipeline, "reason: 'sfu_wlvc_rate_budget_pressure'", 'publisher reports WLVC rate-budget pressure');
  requireContains(sfuPublisherControl, 'pressureReason = String(details?.reason', 'publisher controller preserves exact payload pressure reason');
  requireContains(sfuPublisherControl, "CADENCE_THROTTLE: 'cadence_throttle'", 'publisher controller can cadence-throttle motion deltas before profile downshift');
  requireContains(sfuPublisherControl, 'resolveWlvcEncodeIntervalMs', 'publisher controller exposes active WLVC cadence interval');
  requireContains(sfuPublisherControl, 'motion_delta_cadence_multiplier', 'publisher diagnostics expose motion delta cadence multiplier');
  requireContains(sfuPublisherControl, "eventType: 'sfu_wlvc_motion_delta_cadence_throttled'", 'publisher controller reports cadence throttling to backend diagnostics');
  requireContains(sfuPublisherControl, "qualityRecoveryProbe('sfu_wlvc_motion_delta_recovered'", 'publisher controller probes quality recovery after stable motion deltas');
  requireContains(sfuPublisherControl, 'if (decisionHasAction(decision, PUBLISHER_BACKPRESSURE_ACTIONS.PROFILE_DOWNSHIFT))', 'publisher controller downshifts only after the pressure decision permits it');
  requireContains(publisherPipeline, 'resolveWlvcEncodeIntervalMs(', 'publisher encode loop uses automatic cadence control');
  requireContains(sfuTransport, 'resolveWlvcEncodeIntervalMs: publisherBackpressureController.resolveWlvcEncodeIntervalMs', 'SFU transport exposes cadence control to the publisher pipeline');
  requireContains(publisherBackpressureController, 'SFU_WLVC_MOTION_DELTA_PROFILE_DOWNSHIFT_THRESHOLD', 'payload-pressure profile downshift has an explicit repeated-pressure threshold');
  requireContains(publisherBackpressureController, "'sfu_wire_rate_budget_exceeded'", 'wire-rate send budget failures are explicit quality pressure');
  requireContains(publisherBackpressureController, 'budgetSendFailure || sendFailureCount >= sendFailureThreshold', 'budget send failures downshift before repeated socket pressure');
  requireContains(publisherBackpressureController, 'details?.retryAfterMs ?? details?.retry_after_ms', 'wire budget retry windows reach publisher encode throttling');
  requireContains(publisherBackpressureController, 'send_failure_pause_ms: sendFailurePauseMs', 'send failure diagnostics expose the applied encode pause');
  requireContains(runtimeSwitching, "'sfu_wlvc_rate_budget_pressure'", 'rate-budget pressure bypasses downgrade cooldown');
  requireContains(runtimeSwitching, "'sfu_wire_rate_budget_exceeded'", 'wire-rate pressure bypasses downgrade cooldown');
  requireContains(sfuClient, 'sfu_wire_rate_budget_exceeded', 'SFU client reports rolling wire-rate send drops');
  requireContains(outboundFrameBudget, 'budget_max_wire_bytes_per_second', 'rolling wire-rate guard follows quality profile budget');
  requireContains(framePayload, 'budget_payload_soft_limit_bytes', 'frame payload carries soft byte limit');
  requireContains(framePayload, 'budget_payload_soft_limit_ratio', 'frame payload carries soft ratio');
  requireContains(backendStore, 'budget_payload_soft_limit_bytes', 'backend preserves soft byte limit');
  requireContains(backendStore, 'budget_payload_soft_limit_ratio', 'backend preserves soft ratio');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { SFU_VIDEO_QUALITY_PROFILE_BUDGETS } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.ts');
    for (const [profileId, budget] of Object.entries(SFU_VIDEO_QUALITY_PROFILE_BUDGETS)) {
      assert.ok(budget.payloadSoftLimitRatio >= 0.5, `${profileId} soft payload ratio must be enforceable`);
      assert.ok(budget.payloadSoftLimitRatio < 1, `${profileId} soft payload ratio must trip before hard cap`);
    }

    process.stdout.write('[sfu-wlvc-rate-control-contract] PASS\n');
  } finally {
    await server.close();
  }
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
