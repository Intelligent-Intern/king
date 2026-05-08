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

function readJson(relativePath) {
  return JSON.parse(readUtf8(relativePath));
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

function assertStringArray(value, label) {
  assert.ok(Array.isArray(value), `${label} must be an array`);
  assert.ok(value.length > 0, `${label} must not be empty`);
  for (const item of value) {
    assert.equal(typeof item, 'string', `${label} entries must be strings`);
    assert.notEqual(item.trim(), '', `${label} entries must not be blank`);
  }
}

function requireAnyStringContaining(strings, needle, label) {
  assertStringArray(strings, label);
  assert.ok(strings.some((item) => item.includes(needle)), `${label} missing signature fragment: ${needle}`);
}

function assertCaptureEnvironment(fixture) {
  const capture = fixture.capture_environment;
  assert.equal(capture.captured_at, '2026-05-08T19:11:22+02:00');
  assert.ok(capture.os.includes('Linux 6.8.0-110-generic'), 'capture environment must record the exact Linux kernel baseline');
  assert.equal(capture.probe_url, 'http://127.0.0.1:5177/');
  assert.equal(capture.playwright_version, '1.59.1');
  assert.equal(capture.probe_scope, 'worker INIT only; no production SINet or degraded matte code changes');
}

function assertFailureShape(fixture) {
  const failure = fixture.known_failure;
  assert.equal(failure.id, 'chromium_mediapipe_gpu_service_init_failure');
  assert.equal(failure.browser_family, 'chromium');
  assert.equal(failure.phase, 'segmentation_backend_init');
  assert.equal(failure.classification, 'gpu_service_init_failure');

  assert.deepEqual(failure.reproduction, {
    browser: 'Chrome Stable',
    version: '147.0.7727.55',
    delegate: 'CPU',
    launcher_flags: ['--disable-gpu', '--disable-software-rasterizer', '--disable-3d-apis'],
    result: 'INIT_ERROR',
    cpu_delegate_touches_gpu_internals: true,
  });

  const groups = failure.shape.must_match_groups;
  assert.ok(Array.isArray(groups), 'failure shape must expose matcher groups');
  assert.deepEqual(
    groups.map((group) => group.id),
    ['mediapipe_segmenter_init', 'chromium_gpu_service', 'init_failure'],
    'failure shape must distinguish MediaPipe init, Chromium GPU-service, and init-failure signals',
  );
  for (const group of groups) {
    assertStringArray(group.any, `failure shape matcher ${group.id}`);
  }
  const gpuServiceGroup = groups.find((group) => group.id === 'chromium_gpu_service');
  requireAnyStringContaining(gpuServiceGroup.any, 'Service "kGpuService"', 'Chrome GPU-service matcher group');
  requireAnyStringContaining(gpuServiceGroup.any, 'gl_context_webgl.cc', 'Chrome GPU-service matcher group');
  const initFailureGroup = groups.find((group) => group.id === 'init_failure');
  requireAnyStringContaining(initFailureGroup.any, 'emscripten_webgl_create_context() returned error 0', 'Chrome init-failure matcher group');

  const exactSignatures = failure.shape.exact_console_signatures;
  requireAnyStringContaining(exactSignatures, 'delegate: CPU', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'gl_context_webgl.cc:91] Couldn\'t create webGL 2 context.', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'Service "kGpuService"', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'TensorsToSegmentationCalculator', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'was not provided and cannot be created', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'emscripten_webgl_create_context() returned error 0', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'StartGraph failed', 'known Chrome exact failure signatures');
  requireAnyStringContaining(exactSignatures, 'INIT_ERROR: INTERNAL: Service "kGpuService"', 'known Chrome exact failure signatures');

  const cpuRisk = failure.shape.cpu_delegate_gpu_touch_risk;
  assert.equal(cpuRisk.delegate, 'CPU');
  assert.equal(cpuRisk.treat_as_unsafe_when_gpu_signature_present, true);
  assert.deepEqual(cpuRisk.local_worker_signals, [
    'ImageSegmenter.createFromOptions',
    "delegate === 'GPU' ? 'GPU' : 'CPU'",
    'DrawingUtils',
    "getContext('webgl2')",
  ]);
  requireAnyStringContaining(cpuRisk.known_console_signatures, 'delegate: CPU', 'CPU delegate GPU-touch signatures');
  requireAnyStringContaining(cpuRisk.known_console_signatures, 'gl_context.cc:407] GL version: 3.0', 'CPU delegate GPU-touch signatures');
  requireAnyStringContaining(cpuRisk.known_console_signatures, 'renderer: WebKit WebGL', 'CPU delegate GPU-touch signatures');
}

function assertBackendLadder(fixture) {
  const ladder = fixture.backend_ladder;
  assert.ok(Array.isArray(ladder), 'backend ladder must be an array');
  assert.deepEqual(
    ladder.map((step) => step.backend),
    ['worker_segmenter', 'user_avatar_placeholder', 'unfiltered_video'],
    'background unavailable path must use Pierre worker, then explicit user choice',
  );

  const [worker, avatar, unfiltered] = ladder;
  assert.ok(worker.enabled_when.includes('worker_available'), 'MediaPipe must stay scoped to the worker backend');
  assert.equal(worker.on_init_failure, 'keep_source_visible_then_prompt_user');
  assert.ok(avatar.enabled_when.includes('user_chooses_standard_or_uploaded_avatar'), 'avatar requires explicit user choice');
  assert.deepEqual(avatar.required_behavior, [
    'signal_static_avatar_once',
    'keep_audio_tracks_live',
    'do_not_stream_avatar_frames',
    'do_not_apply_synthetic_background',
  ]);
  assert.ok(unfiltered.enabled_when.includes('user_chooses_unfiltered_video'), 'unfiltered video requires explicit user choice');
  assert.deepEqual(unfiltered.required_behavior, [
    'keep_source_video_visible',
    'keep_audio_tracks_live',
    'keep_published_media_alive',
    'do_not_apply_synthetic_background_over_person',
  ]);
}

function assertQuarantine(fixture) {
  assert.equal(fixture.quarantine.cooldown_ms_min, 60000, 'quarantine cooldown must be at least the current 60s retry window');
  assert.deepEqual(fixture.quarantine.scope_keys, ['browser_family', 'backend', 'delegate', 'model_source']);
  assert.deepEqual(fixture.quarantine.idempotency, {
    single_transition_per_cooldown_window: true,
    do_not_retry_failed_backend_per_frame: true,
    do_not_restart_media_tracks: true,
    do_not_reload_page_or_call: true,
    same_failure_same_window_keeps_selected_fallback: true,
  });
}

function assertDiagnostics(fixture) {
  assert.deepEqual(fixture.diagnostics_required, [
    'selected_backend',
    'failed_backend',
    'browser_family',
    'gpu_availability',
    'model_source',
    'fallback_reason',
    'user_choice_required',
  ]);
}

function assertBrowserMatrixSchema(fixture) {
  const matrix = fixture.browser_matrix_required;
  assert.deepEqual(
    matrix.map((entry) => entry.browser),
    ['Chrome Stable', 'Chromium Ubuntu', 'Firefox'],
    'browser regression matrix must preserve the sprint-required browser set',
  );
  for (const entry of matrix) {
    assertStringArray(entry.required_fields, `${entry.browser} required fields`);
    assert.ok(entry.required_fields.includes('version'), `${entry.browser} must record version`);
    assert.ok(entry.required_fields.includes('selected_backend'), `${entry.browser} must record selected backend`);
    assert.ok(entry.required_fields.includes('console_signatures'), `${entry.browser} must record console signatures`);
  }
}

function assertBrowserBehaviorRows(fixture) {
  const rows = fixture.browser_behavior_rows;
  assert.ok(Array.isArray(rows), 'browser behavior rows must be an array');
  assert.deepEqual(
    rows.map((entry) => entry.browser),
    ['Chrome Stable', 'Chromium Ubuntu', 'Firefox'],
    'browser behavior rows must capture Chrome Stable, Chromium Ubuntu, and Firefox in order',
  );

  const expectedVersions = {
    'Chrome Stable': '147.0.7727.55',
    'Chromium Ubuntu': '147.0.7727.116 snap',
    Firefox: '148.0.2',
  };
  for (const row of rows) {
    assert.equal(row.version, expectedVersions[row.browser], `${row.browser} must record the exact captured version`);
    assert.equal(typeof row.os, 'string', `${row.browser} must record OS/executable context`);
    assert.notEqual(row.os.trim(), '', `${row.browser} OS/executable context must not be blank`);
    assert.equal(row.selected_backend, 'worker_segmenter', `${row.browser} must keep the worker segmenter as backend choice`);
    assert.equal(row.mediapipe_demo_result.gpu, 'INIT_DONE', `${row.browser} MediaPipe demo GPU delegate result`);
    assert.equal(row.mediapipe_demo_result.cpu, 'INIT_DONE', `${row.browser} MediaPipe demo CPU delegate result`);
    assert.equal(row.cpu_delegation_touches_gpu_internals, true, `${row.browser} must record CPU delegate GPU/WebGL internals`);
    requireAnyStringContaining(row.console_signatures, 'gl_context.cc:407] GL version: 3.0', `${row.browser} console signatures`);
    requireAnyStringContaining(row.console_signatures, 'OpenGL error checking is disabled', `${row.browser} console signatures`);

    assert.equal(row.king_production_path.selected_backend, 'worker_segmenter', `${row.browser} production path backend`);
    assert.equal(row.king_production_path.mediapipe_scope, 'worker_only', `${row.browser} production MediaPipe scope`);
    assert.equal(row.king_production_path.on_init_failure, 'keep_source_visible_then_prompt_user', `${row.browser} production init-failure behavior`);
    assert.equal(row.king_production_path.uses_sinet_or_degraded_matte, false, `${row.browser} production path must not use SINet or degraded matte`);
  }

  const chrome = rows.find((row) => row.browser === 'Chrome Stable');
  assert.equal(chrome.known_failure_capture_id, fixture.known_failure.id, 'Chrome row must reference the known GPU-service failure capture');
  requireAnyStringContaining(chrome.console_signatures, 'Service "kGpuService"', 'Chrome failing console signatures');
  requireAnyStringContaining(chrome.console_signatures, 'StartGraph failed', 'Chrome failing console signatures');
  requireAnyStringContaining(chrome.console_signatures, 'emscripten_webgl_create_context() returned error 0', 'Chrome failing console signatures');

  const chromium = rows.find((row) => row.browser === 'Chromium Ubuntu');
  assert.equal(chromium.known_failure_capture_id, fixture.known_failure.id, 'Chromium row must share the known Chromium-family failure id');

  const firefox = rows.find((row) => row.browser === 'Firefox');
  assert.equal(firefox.known_failure_capture_id, null, 'Firefox row must not claim the Chromium GPU-service failure id');
  requireAnyStringContaining(firefox.console_signatures, 'renderer: NVIDIA GeForce GTX 980, or similar', 'Firefox console signatures');
}

function assertCurrentRuntimeBoundaries(fixture) {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const workerBackend = readUtf8('src/domain/realtime/background/backendWorkerSegmenter.js');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');
  const modal = readUtf8('src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue');
  const orchestration = readUtf8('src/domain/realtime/local/mediaOrchestration.ts');
  const avatarSignal = readUtf8('src/domain/realtime/background/avatarFallbackSignal.ts');
  const unavailablePrompt = readUtf8('src/domain/realtime/background/unavailablePrompt.ts');

  assert.equal(fixture.current_runtime_baseline.production_default_backend, 'worker-segmenter');
  assert.equal(fixture.current_runtime_baseline.mediapipe_is_worker_scoped, true);
  assert.equal(fixture.current_runtime_baseline.segmentation_unavailable_prompts_user, true);

  requireContains(stream, "import { acquireWorkerSegmenterBackendLease } from './backendWorkerSegmenter';", 'current production worker backend');
  requireContains(stream, 'if (segmentationBackendInitPromise) return segmentationBackendInitPromise;', 'current init idempotency');
  requireContains(stream, "requested: 'worker-segmenter'", 'current backend diagnostics');
  requireContains(stream, 'notifySegmentationUnavailable', 'current unavailable prompt hook');
  requireMissing(stream, 'ImageSegmenter.createFromOptions', 'production stream must not directly instantiate MediaPipe');
  requireMissing(stream, "delegate === 'GPU' ? 'GPU' : 'CPU'", 'production stream must not switch MediaPipe delegates directly');
  requireMissing(stream, 'createSinetWasmSegmentationBackend', 'production stream must not add SINet fallback backend');
  requireMissing(stream, 'backendSelector', 'production stream must not add degraded matte backend selector');

  requireContains(workerBackend, "kind: 'worker-segmenter'", 'worker backend identity');
  requireContains(worker, 'ImageSegmenter.createFromOptions', 'local MediaPipe worker fixture boundary');
  requireContains(worker, "delegate: delegate === 'GPU' ? 'GPU' : 'CPU'", 'local MediaPipe delegate boundary');
  requireContains(worker, "const glCtx = renderCanvas.getContext('webgl2');", 'local MediaPipe category-mask WebGL boundary');
  requireContains(worker, 'new DrawingUtils(glCtx)', 'local MediaPipe DrawingUtils WebGL boundary');
  requireContains(modal, 'background_use_standard_avatar', 'standard avatar choice');
  requireContains(modal, 'background_upload_avatar', 'uploaded avatar choice');
  requireContains(modal, 'background_send_unfiltered', 'unfiltered video choice');
  requireContains(orchestration, 'handleBackgroundReplacementUnavailable({', 'unavailable prompt handler');
  requireContains(orchestration, 'createBackgroundFallbackAudioOnlyStream(rawStream)', 'avatar fallback audio-only stream');
  requireContains(orchestration, 'syncBackgroundFallbackControlState(true)', 'static avatar signal');
  requireMissing(avatarSignal, 'captureStream', 'avatar fallback frame streaming');
  requireContains(unavailablePrompt, "eventType: 'local_background_replacement_unavailable'", 'field diagnostic');
}

try {
  const fixture = readJson('tests/contract/background-regression-matrix-fixture.json');
  assert.equal(fixture.fixture_version, 1);
  assertStringArray(fixture.source_basis, 'source_basis');
  assertCaptureEnvironment(fixture);
  assertFailureShape(fixture);
  assertDiagnostics(fixture);
  assertBackendLadder(fixture);
  assertQuarantine(fixture);
  assertBrowserMatrixSchema(fixture);
  assertBrowserBehaviorRows(fixture);
  assertCurrentRuntimeBoundaries(fixture);

  console.log('[background-regression-matrix-contract] PASS');
} catch (error) {
  console.error(`[background-regression-matrix-contract] FAIL: ${error.message}`);
  process.exit(1);
}
