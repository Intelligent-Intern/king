import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-source-readback-contract] FAIL: ${message}`);
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
  const sourceReadback = readFrontend('src/domain/realtime/local/publisherSourceReadback.js');
  const runtimeSwitching = readFrontend('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const framePayload = readFrontend('src/lib/sfu/framePayload.ts');
  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(workspaceConfig, 'maxDrawImageMs', 'profiles define drawImage budget');
  requireContains(workspaceConfig, 'maxReadbackMs', 'profiles define getImageData budget');
  requireContains(publisherPipeline, 'sourceReadbackController.readFrame({', 'publisher reads source frames through source readback controller');
  requireContains(sourceReadback, 'drawImageMs > drawBudgetMs || readbackMs > readbackBudgetMs', 'publisher bounds source readback before encode');
  requireContains(sourceReadback, 'sfu_source_readback_budget_exceeded', 'publisher reports source readback budget pressure');
  requireContains(sourceReadback, 'canvas_get_image_data_budget_exceeded', 'publisher distinguishes getImageData pressure');
  requireContains(sourceReadback, 'publisher_source_readback', 'publisher reports source readback transport stage');
  requireContains(runtimeSwitching, "'sfu_source_readback_budget_exceeded'", 'readback pressure bypasses downgrade cooldown');
  requireContains(framePayload, 'budget_max_draw_image_ms', 'frame payload carries draw budget');
  requireContains(framePayload, 'budget_max_readback_ms', 'frame payload carries readback budget');
  requireContains(backendStore, 'budget_max_draw_image_ms', 'backend preserves draw budget');
  requireContains(backendStore, 'budget_max_readback_ms', 'backend preserves readback budget');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { SFU_VIDEO_QUALITY_PROFILE_BUDGETS } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    for (const [profileId, budget] of Object.entries(SFU_VIDEO_QUALITY_PROFILE_BUDGETS)) {
      assert.ok(budget.maxDrawImageMs > 0, `${profileId} draw budget must be positive`);
      assert.ok(budget.maxReadbackMs > 0, `${profileId} readback budget must be positive`);
      assert.ok(budget.maxDrawImageMs < budget.maxEncodeMs, `${profileId} draw budget must fit inside encode budget`);
      assert.ok(budget.maxReadbackMs < budget.maxEncodeMs, `${profileId} readback budget must fit inside encode budget`);
    }

    process.stdout.write('[sfu-source-readback-contract] PASS\n');
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
