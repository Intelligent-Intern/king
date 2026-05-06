import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[background-pipeline-merge-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const blurProcessorPath = path.resolve(frontendRoot, 'src/lib/wavelet/blur-processor.ts');
  assert.equal(fs.existsSync(blurProcessorPath), false, 'experiment blur-processor must not be revived implicitly');

  const processorPipeline = read('src/lib/wavelet/processor-pipeline.ts');
  assert.ok(!processorPipeline.includes('BackgroundBlurProcessor'), 'wavelet processor-pipeline must not depend on experiment blur processor');
  assert.ok(!processorPipeline.includes('PreEncodeBlurCompositor'), 'wavelet processor-pipeline must not pin obsolete blur compositor wiring');

  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
  assert.ok(mediaOrchestration.includes('backgroundFilterController'), 'local media orchestration must remain the production background pipeline');
  assert.ok(mediaOrchestration.includes('function defaultApplyControlStateToLocalTracks'), 'local media orchestration must keep a local track control fallback');
  assert.ok(mediaOrchestration.includes("track.enabled = controlState.micEnabled !== false"), 'local media fallback must apply microphone state');
  assert.ok(mediaOrchestration.includes("track.enabled = controlState.cameraEnabled !== false"), 'local media fallback must apply camera state');

  const workspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
  assert.ok(workspaceView.includes("import { BackgroundFilterController } from './background/controller';"), 'workspace must use the production background controller');
  assert.ok(workspaceView.includes('const backgroundFilterController = new BackgroundFilterController();'), 'workspace must instantiate the production background controller');

  process.stdout.write('[background-pipeline-merge-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
