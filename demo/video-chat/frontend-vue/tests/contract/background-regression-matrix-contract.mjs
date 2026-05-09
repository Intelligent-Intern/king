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

function assertNonBlankString(value, label) {
  assert.equal(typeof value, 'string', `${label} must be a string`);
  assert.notEqual(value.trim(), '', `${label} must not be blank`);
}

function assertEvidenceEventArray(value, label, { allowEmpty = true } = {}) {
  assert.ok(Array.isArray(value), `${label} must be an array`);
  if (!allowEmpty) assert.ok(value.length > 0, `${label} must not be empty`);
  for (const item of value) {
    const type = typeof item;
    assert.ok(type === 'string' || (item && type === 'object'), `${label} entries must be strings or event objects`);
    if (type === 'string') assert.notEqual(item.trim(), '', `${label} string entries must not be blank`);
    else assert.ok(String(item.text || item.message || item.type || '').trim(), `${label} event entries must carry text/message/type`);
  }
}

function assertSafeSelectedBackend(value, label) {
  assertNonBlankString(value, label);
  const normalized = value.trim().toLowerCase();
  assert.ok(![
    'sinet',
    'bodypix',
    'tfjs',
    'canvas_2d_segmenter',
    'canvas-2d-segmenter',
    'confidence-mask',
    'confidence_mask',
    'softmax_matte',
    'sigmoid_matte',
  ].includes(normalized), `${label} must not use weaker matte backend: ${value}`);
  assert.ok([
    'worker-segmenter',
    'worker_segmenter',
    'user_avatar_placeholder',
    'standard_avatar',
    'uploaded_avatar',
    'unfiltered_video',
  ].includes(normalized), `${label} must be worker-segmenter or an explicit user fallback: ${value}`);
}

function assertCaptureStatus(value, label) {
  assertNonBlankString(value, label);
  assert.ok([
    'init_ok',
    'init_failed',
    'segment_failed',
    'ok',
    'failed',
    'not_run',
  ].includes(value), `${label} has unexpected status: ${value}`);
}

function assertFailureShape(fixture) {
  const failure = fixture.known_failure;
  assert.equal(failure.id, 'chromium_mediapipe_gpu_service_init_failure');
  assert.equal(failure.browser_family, 'chromium');
  assert.equal(failure.phase, 'segmentation_backend_init');
  assert.equal(failure.classification, 'gpu_service_init_failure');

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

  const cpuRisk = failure.shape.cpu_delegate_gpu_touch_risk;
  assert.equal(cpuRisk.delegate, 'CPU');
  assert.equal(cpuRisk.treat_as_unsafe_when_gpu_signature_present, true);
  assert.deepEqual(cpuRisk.local_worker_signals, [
    'ImageSegmenter.createFromOptions',
    "delegate === 'GPU' ? 'GPU' : 'CPU'",
    'DrawingUtils',
    "getContext('webgl2')",
  ]);
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

function assertBrowserMatrixEvidence(fixture) {
  const required = fixture.browser_matrix_required;
  const requiredBrowsers = required.map((entry) => entry.browser);
  const requiredByBrowser = new Map(required.map((entry) => [entry.browser, entry.required_fields]));
  const status = fixture.browser_matrix_evidence_status;
  const evidence = fixture.browser_matrix_evidence;

  assert.ok(status && typeof status === 'object', 'browser matrix evidence status must be present');
  assert.equal(status.bgf_01_status, 'open', 'BGF-01 must stay open until Firefox evidence exists');
  assert.ok(status.reason.includes('Firefox'), 'open BGF-01 evidence status must name the missing Firefox capture');
  assert.deepEqual(status.captured_browsers, ['Chrome Stable', 'Chromium Ubuntu']);
  assert.deepEqual(status.missing_browsers, ['Firefox']);
  assert.equal(status.capture_script, 'tests/e2e/background-regression-capture.mjs');
  assertNonBlankString(status.capture_mode, 'browser matrix evidence capture mode');

  assert.ok(Array.isArray(evidence), 'browser matrix evidence must be an array');
  assert.deepEqual(
    evidence.map((entry) => entry.browser),
    status.captured_browsers,
    'browser matrix evidence must record only the browsers captured so far',
  );

  const missing = requiredBrowsers.filter((browser) => !status.captured_browsers.includes(browser));
  assert.deepEqual(missing, status.missing_browsers, 'evidence status must honestly list missing required browsers');
  assert.ok(!evidence.some((entry) => entry.browser === 'Firefox'), 'Firefox evidence must not be faked');

  for (const entry of evidence) {
    assert.ok(requiredByBrowser.has(entry.browser), `${entry.browser} must be in the required browser matrix`);
    assert.equal(entry.schema_version, 'king.bgf.browser_regression_capture.v1');
    assertNonBlankString(entry.browser_engine, `${entry.browser} browser_engine`);
    assertNonBlankString(entry.browser_family, `${entry.browser} browser_family`);
    assertNonBlankString(entry.version, `${entry.browser} version`);
    assertNonBlankString(entry.os, `${entry.browser} os`);
    assert.ok(!Number.isNaN(Date.parse(entry.captured_at)), `${entry.browser} captured_at must be an ISO timestamp`);
    assertNonBlankString(entry.capture_command, `${entry.browser} capture command`);
    assertNonBlankString(entry.gpu_availability, `${entry.browser} gpu availability`);
    assertNonBlankString(entry.model_source, `${entry.browser} model source`);
    assertSafeSelectedBackend(entry.selected_backend, `${entry.browser} selected backend`);
    assertCaptureStatus(entry.mediapipe_gpu_result, `${entry.browser} MediaPipe GPU result`);
    assertCaptureStatus(entry.mediapipe_cpu_result, `${entry.browser} MediaPipe CPU result`);
    assertEvidenceEventArray(entry.console_signatures, `${entry.browser} console signatures`);

    for (const field of requiredByBrowser.get(entry.browser)) {
      assert.ok(Object.prototype.hasOwnProperty.call(entry, field), `${entry.browser} evidence missing required field: ${field}`);
    }

    const cpuTouch = entry.cpu_delegate_gpu_touch;
    assert.ok(cpuTouch && typeof cpuTouch === 'object', `${entry.browser} CPU delegate GPU-touch evidence must be present`);
    assert.equal(typeof cpuTouch.observed, 'boolean', `${entry.browser} CPU delegate GPU-touch observed flag must be boolean`);
    assertEvidenceEventArray(
      cpuTouch.gpu_touch_signatures,
      `${entry.browser} CPU delegate GPU-touch signatures`,
      { allowEmpty: !cpuTouch.observed },
    );
    assertEvidenceEventArray(cpuTouch.console_signatures, `${entry.browser} CPU delegate GPU-service signatures`);

    const paths = entry.path_results;
    assert.ok(paths && typeof paths === 'object', `${entry.browser} path results must be present`);
    const workerDirect = paths.mediapipe_worker_direct;
    const production = paths.king_production_background_stream;
    assert.ok(workerDirect && typeof workerDirect === 'object', `${entry.browser} worker-direct path result missing`);
    assert.ok(production && typeof production === 'object', `${entry.browser} production stream path result missing`);
    assert.equal(workerDirect.gpu.status, entry.mediapipe_gpu_result, `${entry.browser} GPU path status must match top-level evidence`);
    assert.equal(workerDirect.cpu.status, entry.mediapipe_cpu_result, `${entry.browser} CPU path status must match top-level evidence`);
    assertSafeSelectedBackend(workerDirect.gpu.selected_backend, `${entry.browser} GPU worker selected backend`);
    assertSafeSelectedBackend(workerDirect.cpu.selected_backend, `${entry.browser} CPU worker selected backend`);
    assertCaptureStatus(production.status, `${entry.browser} production stream status`);
    assertSafeSelectedBackend(production.selected_backend, `${entry.browser} production selected backend`);
    assert.equal(production.active, true, `${entry.browser} production background stream must stay active`);
    assert.equal(production.unavailable_callbacks_count, 0, `${entry.browser} production path must not require fallback when init succeeds`);
  }
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

  requireContains(workerBackend, "kind: 'worker-segmenter'", 'worker backend identity');
  requireContains(worker, 'ImageSegmenter.createFromOptions', 'local MediaPipe worker fixture boundary');
  requireContains(worker, "delegate: delegate === 'GPU' ? 'GPU' : 'CPU'", 'local MediaPipe delegate boundary');
  requireContains(worker, "const glCtx = renderCanvas.getContext('webgl2');", 'local MediaPipe category-mask WebGL boundary');
  requireContains(worker, 'new DrawingUtils(glCtx)', 'local MediaPipe DrawingUtils WebGL boundary');
  requireContains(worker, "error: 'production_category_mask_unavailable'", 'category mask fail-closed boundary');
  requireMissing(worker, 'confidenceMaskValues', 'weaker confidence-mask fallback');
  requireMissing(worker, 'outputConfidenceMasks', 'weaker confidence-mask output');
  requireContains(stream, 'enterSegmentationUnavailable(matteRejection.reason', 'rejected matte user-choice transition');
  requireContains(stream, 'resolveSegmentErrorUnavailable(segmentation)', 'worker segment error user-choice transition');
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
  assertFailureShape(fixture);
  assertDiagnostics(fixture);
  assertBackendLadder(fixture);
  assertQuarantine(fixture);
  assertBrowserMatrixSchema(fixture);
  assertBrowserMatrixEvidence(fixture);
  assertCurrentRuntimeBoundaries(fixture);

  console.log('[background-regression-matrix-contract] PASS');
} catch (error) {
  console.error(`[background-regression-matrix-contract] FAIL: ${error.message}`);
  process.exit(1);
}
