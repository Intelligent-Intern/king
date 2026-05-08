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

function exists(relativePath) {
  return fs.existsSync(path.join(frontendRoot, relativePath));
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.equal(source.includes(needle), false, `${label} must not contain: ${needle}`);
}

try {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const compositor = readUtf8('src/domain/realtime/background/pipeline/compositorStage.js');
  const workerBackend = readUtf8('src/domain/realtime/background/backendWorkerSegmenter.js');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');
  const orchestration = readUtf8('src/domain/realtime/local/mediaOrchestration.ts');
  const avatarFallback = readUtf8('src/domain/realtime/background/avatarFallbackSignal.ts');
  const staticAvatarRender = readUtf8('src/domain/realtime/background/staticAvatarRender.ts');
  const unavailablePrompt = readUtf8('src/domain/realtime/background/unavailablePrompt.ts');
  const preferences = readUtf8('src/domain/realtime/media/preferences.ts');
  const modal = readUtf8('src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue');
  const participantUi = readUtf8('src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  const videoLayout = readUtf8('src/domain/realtime/workspace/callWorkspace/videoLayout.ts');
  const workspace = readUtf8('src/domain/realtime/CallWorkspaceView.vue');
  const template = readUtf8('src/domain/realtime/CallWorkspaceView.template.html');

  assert.equal(exists('src/domain/realtime/background/backendSinetWasm.js'), false, 'production SINet backend must not be revived');
  assert.equal(exists('src/domain/realtime/background/backendSelector.ts'), false, 'production SINet selector must not be revived');
  assert.equal(exists('src/domain/realtime/background/maskPostprocess.js'), false, 'deleted SINet matte postprocess must not be revived');

  requireContains(stream, "import { acquireWorkerSegmenterBackendLease } from './backendWorkerSegmenter';", 'Pierre worker segmenter stream');
  requireContains(stream, "requested: 'worker-segmenter'", 'worker segmenter diagnostics');
  requireContains(stream, 'function notifySegmentationUnavailable(reason, failures = [])', 'segmentation unavailable callback');
  requireContains(stream, "handle.reason = 'segmentation_unavailable';", 'segmentation unavailable handle state');
  requireContains(stream, "mode: hasRenderableMatte ? runtimeConfig.mode : 'off'", 'source-visible warmup and failure rendering');
  requireContains(stream, 'for (const audioTrack of sourceStream.getAudioTracks()) out.addTrack(audioTrack);', 'audio track preservation');
  requireMissing(stream, 'createSinetWasmSegmentationBackend', 'SINet production backend');
  requireMissing(stream, "requested: 'sinet-wasm'", 'SINet diagnostics');

  requireContains(compositor, 'float maskAlpha = uHasMask == 1 ? smoothstep(uMaskLow, uMaskHigh, featherMask(vUv)) : 0.0;', 'contour alpha smoothing');
  requireContains(compositor, 'gl_FragColor = vec4(mix(background.rgb, frame.rgb, maskAlpha), 1.0);', 'hard foreground/background composite');
  requireMissing(compositor, 'Math.exp', 'compositor sigmoid or softmax');
  requireMissing(compositor, 'softmax', 'compositor softmax');
  requireMissing(compositor, 'sigmoid', 'compositor sigmoid');

  requireContains(workerBackend, "kind: 'worker-segmenter'", 'Pierre worker backend identity');
  requireContains(workerBackend, "const workerUrl = new URL('./workers/imageSegmenterWorker.js', import.meta.url);", 'worker module boundary');
  requireContains(worker, 'ImageSegmenter.createFromOptions', 'MediaPipe worker boundary');
  requireContains(worker, "delegate: delegate === 'GPU' ? 'GPU' : 'CPU'", 'MediaPipe delegate boundary');
  requireContains(worker, "const glCtx = renderCanvas.getContext('webgl2');", 'MediaPipe category-mask WebGL boundary');
  requireMissing(worker, 'Math.exp', 'worker softmax/sigmoid fallback');
  requireMissing(worker, 'softmax', 'worker softmax fallback');
  requireMissing(worker, 'sigmoid', 'worker sigmoid fallback');

  requireContains(orchestration, 'handleBackgroundReplacementUnavailable({', 'modal prompt trigger');
  requireContains(unavailablePrompt, 'openBackgroundReplacementUnavailablePrompt({', 'prompt state update');
  requireContains(unavailablePrompt, "eventType: 'local_background_replacement_unavailable'", 'field diagnostic');
  requireContains(orchestration, 'createBackgroundFallbackAudioOnlyStream(rawStream)', 'avatar placeholder audio-only local stream');
  requireContains(orchestration, "backgroundFilterBackend = 'avatar_placeholder'", 'avatar backend state');
  requireContains(orchestration, 'syncBackgroundFallbackControlState(true)', 'avatar control-state signal');
  requireContains(avatarFallback, 'for (const audioTrack of sourceStream.getAudioTracks())', 'avatar fallback audio preservation');
  requireContains(avatarFallback, 'out.addTrack(audioTrack);', 'avatar fallback audio track copy');
  requireMissing(avatarFallback, 'captureStream', 'avatar fallback video stream');
  requireContains(staticAvatarRender, "node.dataset.callStaticAvatar = '1';", 'static avatar render node');
  requireContains(participantUi, 'backgroundFallbackControlStateFromPrefs(callMediaPrefs)', 'control-state includes static avatar mode');
  requireContains(videoLayout, 'if (hasStaticAvatarForUserId(userId))', 'video layout static avatar route');
  requireContains(preferences, 'DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL', 'standard avatar fallback');
  requireContains(preferences, 'backgroundReplacementUnavailablePromptOpen', 'modal visibility state');
  requireContains(preferences, 'useCallBackgroundFallbackAvatar', 'avatar choice action');
  requireContains(preferences, 'clearCallBackgroundFallbackVideo', 'unfiltered choice action');
  requireContains(modal, 'background_use_standard_avatar', 'standard avatar button');
  requireContains(modal, 'background_upload_avatar', 'upload avatar button');
  requireContains(modal, 'background_send_unfiltered', 'unfiltered video button');
  requireContains(workspace, "import BackgroundReplacementUnavailableModal from './background/BackgroundReplacementUnavailableModal.vue';", 'workspace modal import');
  requireContains(template, '<BackgroundReplacementUnavailableModal :reconfigure-background="reconfigureLocalBackgroundFilterOnly" />', 'workspace modal mount');

  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
