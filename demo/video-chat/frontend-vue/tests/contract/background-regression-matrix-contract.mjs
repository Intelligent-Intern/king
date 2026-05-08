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
    ['mediapipe_gpu', 'mediapipe_cpu', 'sinet_wasm', 'degraded_visible_source'],
    'backend ladder must prefer MediaPipe GPU, isolated MediaPipe CPU, SINet/WASM, then visible-source degraded mode',
  );

  const [gpu, cpu, sinet, degraded] = ladder;
  assert.ok(gpu.enabled_when.includes('gpu_available'), 'MediaPipe GPU requires healthy GPU availability');
  assert.equal(gpu.on_init_failure, 'quarantine_backend_then_try_mediapipe_cpu');
  assert.ok(cpu.enabled_when.includes('cpu_delegate_isolated_from_gpu'), 'MediaPipe CPU is valid only when isolated from GPU internals');
  assert.equal(cpu.on_gpu_signature, 'quarantine_backend_then_try_sinet_wasm');
  assert.ok(sinet.enabled_when.includes('sinet_assets_available'), 'SINet/WASM requires local SINet assets');
  assert.equal(sinet.on_init_failure, 'quarantine_backend_then_use_degraded_visible_source');
  assert.deepEqual(degraded.required_behavior, [
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
    'cooldown_state',
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

function assertCurrentRuntimeBoundaries(fixture) {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const selector = readUtf8('src/domain/realtime/background/backendSelector.ts');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');

  assert.equal(fixture.current_runtime_baseline.production_default_backend, 'sinet_wasm');
  assert.equal(fixture.current_runtime_baseline.mediapipe_not_default, true);
  assert.equal(fixture.current_runtime_baseline.sinet_cooldown_ms, 60000);

  requireContains(selector, "backend: 'sinet_wasm'", 'current production backend selector');
  requireContains(stream, 'const BACKGROUND_SEGMENTER_INIT_RETRY_MS = 60000;', 'current SINet quarantine window');
  requireContains(stream, 'let sinetSegmenterUnavailableUntil = 0;', 'current SINet quarantine state');
  requireContains(stream, 'if (segmentationBackendInitPromise) return segmentationBackendInitPromise;', 'current init idempotency');
  requireContains(stream, 'sinetSegmenterUnavailableUntil = epochNowMs() + BACKGROUND_SEGMENTER_INIT_RETRY_MS;', 'current SINet failure cooldown');
  requireContains(stream, "requested: 'sinet-wasm'", 'current backend diagnostics');
  requireMissing(stream, 'ImageSegmenter.createFromOptions', 'production stream must not directly instantiate MediaPipe');
  requireMissing(stream, "delegate === 'GPU' ? 'GPU' : 'CPU'", 'production stream must not switch MediaPipe delegates directly');

  requireContains(worker, 'ImageSegmenter.createFromOptions', 'local MediaPipe worker fixture boundary');
  requireContains(worker, "delegate: delegate === 'GPU' ? 'GPU' : 'CPU'", 'local MediaPipe delegate boundary');
  requireContains(worker, "const glCtx = renderCanvas.getContext('webgl2');", 'local MediaPipe category-mask WebGL boundary');
  requireContains(worker, 'new DrawingUtils(glCtx)', 'local MediaPipe DrawingUtils WebGL boundary');
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
  assertCurrentRuntimeBoundaries(fixture);

  console.log('[background-regression-matrix-contract] PASS');
} catch (error) {
  console.error(`[background-regression-matrix-contract] FAIL: ${error.message}`);
  process.exit(1);
}
