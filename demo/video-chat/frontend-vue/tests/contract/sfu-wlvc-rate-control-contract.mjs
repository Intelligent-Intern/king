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
  const workspaceConfig = readFrontend('src/domain/realtime/workspace/config.js');
  const publisherPipeline = readFrontend('src/domain/realtime/local/publisherPipeline.js');
  const sfuTransport = readFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const runtimeSwitching = readFrontend('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const framePayload = readFrontend('src/lib/sfu/framePayload.ts');
  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(workspaceConfig, 'payloadSoftLimitRatio', 'profile budgets define soft payload limit ratio');
  requireContains(publisherPipeline, 'payloadSoftLimitBytes', 'publisher computes active soft payload byte limit');
  requireContains(publisherPipeline, 'encodedPayloadBytes >= payloadSoftLimitBytes || encodeMs > encodeBudgetMs', 'publisher acts before hard payload/socket pressure');
  requireContains(publisherPipeline, "reason: 'sfu_wlvc_rate_budget_pressure'", 'publisher reports WLVC rate-budget pressure');
  requireContains(sfuTransport, 'pressureReason = String(details?.reason', 'transport preserves exact payload pressure reason');
  requireContains(sfuTransport, 'downgradeSfuVideoQualityAfterEncodePressure(pressureReason)', 'transport downshifts from exact rate pressure reason');
  requireContains(runtimeSwitching, "'sfu_wlvc_rate_budget_pressure'", 'rate-budget pressure bypasses downgrade cooldown');
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
    const { SFU_VIDEO_QUALITY_PROFILE_BUDGETS } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
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
