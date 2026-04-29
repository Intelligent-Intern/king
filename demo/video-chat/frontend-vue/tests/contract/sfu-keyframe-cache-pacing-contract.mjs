import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-keyframe-cache-pacing-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readFromFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readFromRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

async function main() {
  const workspaceConfig = readFromFrontend('src/domain/realtime/workspace/config.js');
  const publisherPipeline = readFromFrontend('src/domain/realtime/local/publisherPipeline.js');
  const sfuTransport = readFromFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const framePayload = readFromFrontend('src/lib/sfu/framePayload.ts');
  const kingSfuStore = readFromRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(workspaceConfig, 'minKeyframeRetryMs', 'profile budgets expose keyframe retry pacing');
  requireContains(publisherPipeline, 'forcedKeyframeRecoveryPending', 'publisher tracks forced keyframe recovery state');
  requireContains(publisherPipeline, 'keyframeRetryBlockedUntilMs', 'publisher blocks repeated forced keyframe retries');
  requireContains(publisherPipeline, '&& !forcedKeyframeRecoveryPending', 'publisher disables selective patches while a full-frame recovery keyframe is pending');
  requireContains(publisherPipeline, 'budget_min_keyframe_retry_ms', 'publisher emits keyframe retry budget telemetry');
  requireContains(publisherPipeline, 'keyframe_retry_after_ms', 'publisher reports retry delay after payload pressure');
  requireContains(sfuTransport, 'keyframe_retry_after_ms', 'transport diagnostics preserve keyframe retry pacing');
  requireContains(framePayload, 'budget_min_keyframe_retry_ms', 'binary frame metadata preserves keyframe retry budget');
  requireContains(kingSfuStore, 'budget_min_keyframe_retry_ms', 'King SFU relay preserves keyframe retry budget metadata');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const budgets = config.SFU_VIDEO_QUALITY_PROFILE_BUDGETS;

    for (const profileId of ['rescue', 'realtime', 'balanced', 'quality']) {
      assert.ok(
        budgets[profileId].minKeyframeRetryMs >= config.SFU_VIDEO_QUALITY_PROFILES[profileId].encodeIntervalMs,
        `${profileId} keyframe retry budget must be at least one encode interval`,
      );
    }
    assert.ok(
      budgets.rescue.minKeyframeRetryMs > budgets.quality.minKeyframeRetryMs,
      'lower profiles must pace repeated recovery keyframes more aggressively than quality',
    );

    process.stdout.write('[sfu-keyframe-cache-pacing-contract] PASS\n');
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
