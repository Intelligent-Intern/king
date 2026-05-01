import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-profile-budget-contract] FAIL: ${message}`);
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
  const workspaceConfigSource = read('src/domain/realtime/workspace/config.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  const publisherBudgetSource = `${publisherPipeline}\n${publisherFrameTrace}`;
  const sfuClient = read('src/lib/sfu/sfuClient.ts');

  requireContains(workspaceConfigSource, 'SFU_VIDEO_QUALITY_PROFILE_BUDGETS', 'profile budget export');
  requireContains(workspaceConfigSource, 'resolveSfuVideoQualityProfileBudget', 'profile budget resolver');
  requireContains(publisherBudgetSource, 'budget_max_encoded_bytes_per_frame', 'publisher emits encoded budget telemetry');
  requireContains(publisherBudgetSource, 'budget_max_wire_bytes_per_second', 'publisher emits wire budget telemetry');
  requireContains(publisherBudgetSource, 'budget_expected_recovery', 'publisher emits expected recovery behavior');
  requireContains(publisherBudgetSource, 'readback_frame_rate', 'publisher emits profile readback FPS telemetry');
  requireContains(publisherBudgetSource, 'keyframe_interval', 'publisher emits profile keyframe cadence telemetry');
  requireContains(sfuClient, 'budget_max_queue_age_ms', 'client enforces queue-age budget');
  requireContains(sfuClient, 'budget_max_buffered_bytes', 'client enforces buffered-byte budget');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const profiles = config.SFU_VIDEO_QUALITY_PROFILES;
    const budgets = config.SFU_VIDEO_QUALITY_PROFILE_BUDGETS;

    for (const profileId of ['rescue', 'realtime', 'balanced', 'quality']) {
      const profile = profiles[profileId];
      const budget = budgets[profileId];
      assert.equal(profile.id, profileId, `${profileId} profile must expose its id`);
      assert.ok(budget.maxEncodedBytesPerFrame > 0, `${profileId} encoded frame budget must be positive`);
      assert.ok(budget.maxKeyframeBytesPerFrame >= budget.maxEncodedBytesPerFrame, `${profileId} keyframe budget must cover delta budget`);
      assert.ok(budget.maxWireBytesPerSecond > 0, `${profileId} wire budget must be positive`);
      assert.ok(budget.maxEncodeMs > 0, `${profileId} encode-ms budget must be positive`);
      assert.ok(budget.maxQueueAgeMs > 0, `${profileId} queue-age budget must be positive`);
      assert.ok(budget.maxBufferedBytes > 0, `${profileId} buffered-byte budget must be positive`);
      assert.ok(profile.readbackFrameRate > 0, `${profileId} readback frame rate must be positive`);
      assert.ok(profile.readbackIntervalMs > 0, `${profileId} readback interval must be positive`);
      assert.ok(profile.keyFrameInterval > 0, `${profileId} keyframe interval must be positive`);
      assert.equal(profile.maxEncodedBytesPerFrame, budget.maxEncodedBytesPerFrame, `${profileId} profile must carry encoded budget`);
      assert.equal(profile.maxWireBytesPerSecond, budget.maxWireBytesPerSecond, `${profileId} profile must carry wire budget`);
      assert.equal(config.resolveSfuVideoQualityProfileBudget(profileId), budget, `${profileId} resolver returns canonical budget`);
    }

    assert.ok(budgets.rescue.maxEncodedBytesPerFrame < budgets.realtime.maxEncodedBytesPerFrame, 'rescue encoded budget must be below realtime');
    assert.ok(budgets.realtime.maxEncodedBytesPerFrame < budgets.balanced.maxEncodedBytesPerFrame, 'realtime encoded budget must be below balanced');
    assert.ok(budgets.balanced.maxEncodedBytesPerFrame < budgets.quality.maxEncodedBytesPerFrame, 'balanced encoded budget must be below quality');
    assert.ok(budgets.rescue.maxBufferedBytes < budgets.quality.maxBufferedBytes, 'lower profiles must enforce lower buffered-byte pressure');

    process.stdout.write('[sfu-profile-budget-contract] PASS\n');
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
