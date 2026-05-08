import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

try {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const backend = readUtf8('src/domain/realtime/background/backendWorkerSegmenter.js');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');
  const orchestration = readUtf8('src/domain/realtime/local/mediaOrchestration.ts');
  const avatarSignal = readUtf8('src/domain/realtime/background/avatarFallbackSignal.ts');
  const videoLayout = readUtf8('src/domain/realtime/workspace/callWorkspace/videoLayout.ts');
  const unavailablePrompt = readUtf8('src/domain/realtime/background/unavailablePrompt.ts');
  const modal = readUtf8('src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue');
  const messages = readUtf8('src/modules/localization/callWorkspaceMessages.js');

  requireContains(stream, "import { acquireWorkerSegmenterBackendLease } from './backendWorkerSegmenter';", 'production background stream');
  requireContains(stream, 'segmentationBackendLease = await acquireWorkerSegmenterBackendLease({', 'production backend lease');
  requireContains(stream, 'if (segmentationBackendInitPromise) return segmentationBackendInitPromise;', 'idempotent backend init');
  requireContains(stream, 'notifySegmentationUnavailable', 'segmentation unavailable notification');
  requireContains(stream, "mode: hasRenderableMatte ? runtimeConfig.mode : 'off'", 'source-visible warmup/failure mode');
  requireMissing(stream, 'createSinetWasmSegmentationBackend', 'production stream SINet backend');
  requireMissing(stream, 'backendSelector', 'production stream backend selector');

  requireContains(backend, 'Worker-based MediaPipe segmentation backend.', 'Pierre worker backend documentation');
  requireContains(backend, 'SHARED_BACKEND_IDLE_TTL_MS = 60000', 'shared backend warm retention');
  requireContains(backend, 'acquireWorkerSegmenterBackendLease', 'exclusive backend lease API');
  requireContains(backend, 'await backend.resetSession?.();', 'lease reset before reuse');
  requireContains(backend, "kind: 'worker-segmenter'", 'worker backend identity');
  requireContains(backend, 'queueLatestFrame(frameParams);', 'latest-frame queue under worker pressure');

  requireContains(worker, 'loadModuleFactory(resolvedWasm);', 'MediaPipe wasm factory init');
  requireContains(worker, 'sanitizeFilesetPaths(await FilesetResolver.forVisionTasks(resolvedWasm))', 'Vite-safe fileset paths');
  requireContains(worker, 'modelAssetBuffer: new Uint8Array(modelBuffer)', 'local model buffer load');
  requireContains(worker, 'outputCategoryMask: true', 'category mask output');
  requireContains(worker, 'outputConfidenceMasks: true', 'confidence mask fallback output');
  requireMissing(worker, 'cdn.jsdelivr.net', 'worker CDN source');
  requireMissing(worker, 'unpkg.com', 'worker CDN source');

  requireContains(orchestration, 'onSegmentationUnavailable: (details = {}) => {', 'local media prompt hook');
  requireContains(orchestration, 'handleBackgroundReplacementUnavailable({', 'prompt handler call');
  requireContains(unavailablePrompt, 'openBackgroundReplacementUnavailablePrompt({', 'prompt state update');
  requireContains(unavailablePrompt, "eventType: 'local_background_replacement_unavailable'", 'field diagnostic');
  requireContains(orchestration, 'createBackgroundFallbackAudioOnlyStream(rawStream)', 'avatar fallback keeps only audio stream');
  requireContains(orchestration, 'syncBackgroundFallbackControlState(true)', 'avatar fallback sends static state');
  requireContains(avatarSignal, 'backgroundFallbackControlStateFromPrefs', 'static avatar control-state payload');
  requireContains(videoLayout, 'staticAvatarNodeForUserId(userId)', 'static avatar tile rendering');
  requireContains(modal, 'useDefaultAvatar', 'standard avatar action');
  requireContains(modal, 'handleAvatarFile', 'uploaded avatar action');
  requireContains(modal, 'sendUnfilteredVideo', 'unfiltered video action');
  requireContains(modal, 'clearCallBackgroundFallbackVideo();', 'unfiltered choice support');
  requireContains(messages, 'calls.workspace.background_unavailable_title', 'modal localization title');
  requireContains(messages, 'calls.workspace.background_send_unfiltered', 'unfiltered localization');

  console.log('[background-king-wasm-contract] PASS production uses Pierre worker pipeline with explicit user alternative');
} catch (error) {
  console.error(`[background-king-wasm-contract] FAIL: ${error.message}`);
  process.exit(1);
}
