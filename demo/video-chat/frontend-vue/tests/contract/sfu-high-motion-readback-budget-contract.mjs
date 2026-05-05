import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-high-motion-readback-budget-contract] FAIL: ${message}`);
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
  const packageJson = read('package.json');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.ts');
  const benchmarkSource = read('src/domain/realtime/local/publisherReadbackBudgetBenchmark.ts');

  requireContains(packageJson, 'sfu-high-motion-readback-budget-contract.mjs', 'SFU contract suite includes high-motion readback proof');
  requireContains(sourceReadback, 'workerResult.drawImageMs > drawBudgetMs || workerResult.readbackMs > readbackBudgetMs', 'Offscreen worker readback is budget gated');
  requireContains(sourceReadback, 'offscreen_worker_draw_image_budget_exceeded', 'Offscreen worker draw budget has exact pressure marker');
  requireContains(sourceReadback, 'offscreen_worker_get_image_data_budget_exceeded', 'Offscreen worker readback budget has exact pressure marker');
  requireContains(sourceReadback, 'Publisher OffscreenCanvas worker source readback exceeded', 'Offscreen worker pressure stays in publisher source-readback stage');
  requireContains(benchmarkSource, 'HIGH_MOTION_READBACK_BACKEND_COSTS', 'high-motion benchmark declares backend costs');
  requireContains(benchmarkSource, 'HIGH_MOTION_READBACK_BENCHMARK_SOURCE', 'high-motion benchmark declares source motion');
  requireContains(benchmarkSource, 'evaluateHighMotionReadbackBudgets', 'high-motion benchmark evaluates every profile');
  requireContains(benchmarkSource, 'resolveDomCanvasCompatibilityProfile(videoProfile)', 'DOM compatibility benchmark uses capped fallback profile');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.ts');
    const capabilities = await server.ssrLoadModule('/src/domain/realtime/local/capturePipelineCapabilities.ts');
    const benchmark = await server.ssrLoadModule('/src/domain/realtime/local/publisherReadbackBudgetBenchmark.ts');
    const domPolicy = await server.ssrLoadModule('/src/domain/realtime/local/domCanvasFallbackPolicy.ts');

    const { PUBLISHER_CAPTURE_BACKENDS } = capabilities;
    const profileIds = ['rescue', 'realtime', 'balanced', 'quality'];
    const scenarios = [
      {
        label: 'video-frame-copy',
        expectedBackend: PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY,
        expectedReadbackMethod: 'video_frame_copy_to_rgba',
        captureCapabilities: {
          preferredCaptureBackend: PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY,
          supportsMediaStreamTrackProcessor: true,
          supportsVideoFrameCopyTo: true,
          supportsVideoFrameClose: true,
        },
      },
      {
        label: 'offscreen-worker',
        expectedBackend: PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER,
        expectedReadbackMethod: 'offscreen_canvas_worker_readback',
        captureCapabilities: {
          preferredCaptureBackend: PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER,
          supportsMediaStreamTrackProcessor: true,
          supportsOffscreenCanvas2d: true,
          supportsOffscreenCanvasTransfer: true,
          supportsWorker: true,
        },
      },
      {
        label: 'dom-canvas-compatibility',
        expectedBackend: PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK,
        expectedReadbackMethod: domPolicy.DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
        captureCapabilities: {
          preferredCaptureBackend: PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK,
          supportsDomCanvasFallback: true,
          supportsDomCanvasReadback: true,
        },
      },
    ];

    for (const scenario of scenarios) {
      const results = benchmark.evaluateHighMotionReadbackBudgets({
        profiles: config.SFU_VIDEO_QUALITY_PROFILES,
        captureCapabilities: scenario.captureCapabilities,
      });
      assert.equal(results.length, profileIds.length, `${scenario.label} must evaluate every supported profile`);

      for (const profileId of profileIds) {
        const profile = config.SFU_VIDEO_QUALITY_PROFILES[profileId];
        const result = results.find((entry) => entry.profileId === profileId);
        assert.ok(result, `${scenario.label}/${profileId} must have benchmark result`);
        assert.equal(result.selectedCaptureBackend, scenario.expectedBackend, `${scenario.label}/${profileId} selected backend`);
        assert.equal(result.readbackMethod, scenario.expectedReadbackMethod, `${scenario.label}/${profileId} readback method`);
        assert.equal(result.highMotionChangedPixelRatio, 1, `${scenario.label}/${profileId} must represent full-frame motion`);
        assert.ok(result.frameWidth > 0 && result.frameHeight > 0, `${scenario.label}/${profileId} frame size must be positive`);
        assert.ok(result.frameWidth <= profile.frameWidth, `${scenario.label}/${profileId} frame width must not exceed profile`);
        assert.ok(result.frameHeight <= profile.frameHeight, `${scenario.label}/${profileId} frame height must not exceed profile`);
        assert.ok(result.drawImageMs <= profile.maxDrawImageMs, `${scenario.label}/${profileId} draw ${result.drawImageMs}ms exceeds ${profile.maxDrawImageMs}ms`);
        assert.ok(result.readbackMs <= profile.maxReadbackMs, `${scenario.label}/${profileId} readback ${result.readbackMs}ms exceeds ${profile.maxReadbackMs}ms`);
        assert.ok(result.ok, `${scenario.label}/${profileId} must stay inside draw/readback budgets`);
        assert.ok(result.readbackFrameRate <= profile.captureFrameRate + 0.5, `${scenario.label}/${profileId} readback FPS must not outrun capture FPS`);

        if (scenario.expectedBackend === PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK) {
          assert.ok(result.frameWidth <= domPolicy.DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH, `${profileId} DOM fallback width must be capped`);
          assert.ok(result.frameHeight <= domPolicy.DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT, `${profileId} DOM fallback height must be capped`);
          assert.ok(result.readbackFrameRate <= domPolicy.DOM_CANVAS_COMPATIBILITY_MAX_FPS, `${profileId} DOM fallback FPS must be capped`);
        }
      }
    }

    process.stdout.write('[sfu-high-motion-readback-budget-contract] PASS\n');
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
