import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[background-runtime-diagnostics-contract] missing ${label}: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.equal(source.includes(needle), false, `[background-runtime-diagnostics-contract] ${label} must not contain: ${needle}`);
}

const diagnostics = read('src/domain/realtime/background/diagnostics/runtimeDiagnostics.js');
const stream = read('src/domain/realtime/background/stream.ts');
const unavailablePrompt = read('src/domain/realtime/background/unavailablePrompt.ts');
const modal = read('src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue');
const worker = read('src/domain/realtime/background/workers/imageSegmenterWorker.js');
const payloadBuilder = diagnostics.slice(
  diagnostics.indexOf('export function createBackgroundRuntimeDiagnosticPayload'),
  diagnostics.indexOf('export function shouldEmitBackgroundRuntimeDiagnostic'),
);

requireContains(diagnostics, 'BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS = 60000', 'runtime diagnostic throttle');
requireContains(diagnostics, 'shouldEmitBackgroundRuntimeDiagnostic', 'shared throttle helper');
requireContains(diagnostics, 'reportClientDiagnostic(diagnostic)', 'existing diagnostics channel fallback');
requireContains(diagnostics, 'browser_family', 'browser family field');
requireContains(diagnostics, 'model_source', 'model source field');
requireContains(diagnostics, 'gpu_available', 'GPU availability field');
requireContains(diagnostics, 'gpu_failure_signature', 'GPU failure signature field');
requireContains(diagnostics, 'reason_user_choice_required', 'user-choice-required reason field');
requireContains(diagnostics, 'captureBackgroundBackendInitDiagnostic', 'backend init diagnostic helper');
requireContains(diagnostics, "eventType: 'local_background_backend_init'", 'backend init event');
requireContains(diagnostics, 'captureBackgroundMatteRejectionDiagnostic', 'matte rejection diagnostic helper');
requireContains(diagnostics, "eventType: 'local_background_matte_rejected'", 'matte rejection event');
requireContains(diagnostics, 'captureBackgroundModalChoiceDiagnostic', 'modal choice diagnostic helper');
requireContains(diagnostics, "eventType: 'local_background_replacement_modal_choice'", 'modal choice event');
requireContains(diagnostics, 'normalizeBackgroundFailureSignature', 'sanitized failure signatures');
requireContains(diagnostics, '.replace(/[A-Za-z0-9+/=_-]{48,}/g,', 'opaque value redaction');
requireContains(diagnostics, 'DEFAULT_MODEL_ASSET', 'model asset source classification');

for (const forbidden of [
  'MediaStream',
  'ImageData',
  'sourceFrame',
  'source_frame',
  'matteMaskValues',
  'mask_values',
  'sdp',
  'ice_candidate',
  'authorization',
  'token',
]) {
  requireMissing(payloadBuilder, forbidden, 'diagnostic helper payload');
}

requireContains(stream, 'captureBackgroundBackendInitDiagnostic({', 'stream backend init diagnostics');
requireContains(stream, "phase: 'starting'", 'backend init starting phase');
requireContains(stream, "phase: 'ready'", 'backend init ready phase');
requireContains(stream, "phase: 'failed'", 'backend init failure phase');
requireContains(stream, 'resolveBackgroundMatteRejection(segmentation)', 'stream matte rejection classifier');
requireContains(stream, 'captureBackgroundMatteRejectionDiagnostic({', 'stream matte rejection diagnostic emit');

requireContains(unavailablePrompt, 'createBackgroundRuntimeDiagnosticPayload({', 'unavailable transition payload helper');
requireContains(unavailablePrompt, 'shouldEmitBackgroundRuntimeDiagnostic(diagnosticThrottleKey', 'unavailable transition throttle');
requireContains(unavailablePrompt, "eventType: 'local_background_replacement_unavailable'", 'unavailable transition event');
requireContains(unavailablePrompt, 'reasonUserChoiceRequired', 'unavailable reason user choice field');

requireContains(modal, 'captureBackgroundModalChoiceDiagnostic', 'modal choice diagnostic import');
requireContains(modal, "captureBackgroundModalChoiceDiagnostic('standard_avatar'", 'standard avatar choice diagnostic');
requireContains(modal, "captureBackgroundModalChoiceDiagnostic('uploaded_avatar'", 'uploaded avatar choice diagnostic');
requireContains(modal, "captureBackgroundModalChoiceDiagnostic('unfiltered_video'", 'unfiltered video choice diagnostic');
requireMissing(modal, 'reload', 'background unavailable modal');
requireMissing(modal, 'refresh', 'background unavailable modal');

requireMissing(worker, 'runtimeDiagnostics', 'MediaPipe worker instrumentation');
requireMissing(worker, 'reportClientDiagnostic', 'MediaPipe worker diagnostics channel');

process.stdout.write('[background-runtime-diagnostics-contract] PASS\n');
