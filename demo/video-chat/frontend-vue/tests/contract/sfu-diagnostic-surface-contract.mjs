import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-diagnostic-surface-contract] FAIL: ${message}`);
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

try {
  const packageJson = read('package.json');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.ts');
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const sfuTypes = read('src/lib/sfu/sfuTypes.ts');
  const transportSample = read('src/lib/sfu/sfuClientTransportSample.ts');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.ts');
  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
  const diagnosticsSurface = read('src/domain/realtime/workspace/callWorkspace/publisherDiagnosticsSurface.ts');

  requireContains(packageJson, 'sfu-diagnostic-surface-contract.mjs', 'SFU contract suite includes diagnostic surface proof');

  requireContains(publisherFrameTrace, 'active_capture_backend', 'transport metrics expose active capture backend alias');
  requireContains(publisherFrameTrace, 'selected_video_quality_profile', 'transport metrics expose selected profile alias');
  requireContains(publisherFrameTrace, 'source_frame_width', 'transport metrics expose source frame width');
  requireContains(publisherFrameTrace, 'source_frame_height', 'transport metrics expose source frame height');
  requireContains(publisherFrameTrace, 'source_frame_rate', 'transport metrics expose source frame FPS');
  requireContains(publisherFrameTrace, 'source_readback_ms', 'transport metrics expose source readback timing alias');
  requireContains(publisherFrameTrace, 'source_readback_budget_ms', 'transport metrics expose source readback budget alias');

  requireContains(framePayload, 'active_capture_backend', 'binary envelope preserves active capture backend diagnostics');
  requireContains(framePayload, 'selected_video_quality_profile', 'binary envelope preserves selected profile diagnostics');
  requireContains(framePayload, 'source_frame_width', 'binary envelope preserves source frame width diagnostics');
  requireContains(framePayload, 'source_frame_rate', 'binary envelope preserves source frame FPS diagnostics');
  requireContains(framePayload, 'source_readback_ms', 'binary envelope preserves readback timing diagnostics');
  requireContains(framePayload, 'dropped_source_frame_count', 'binary envelope preserves dropped source frame diagnostics');
  requireContains(framePayload, 'automatic_quality_transition_count', 'binary envelope preserves quality transition counters when present');

  requireContains(sfuTypes, 'activeCaptureBackend: string', 'transport sample type exposes active capture backend');
  requireContains(sfuTypes, 'selectedVideoQualityProfile: string', 'transport sample type exposes selected profile');
  requireContains(sfuTypes, 'sourceFrameRate: number', 'transport sample type exposes source FPS');
  requireContains(sfuTypes, 'sourceReadbackMs: number', 'transport sample type exposes readback timing');
  requireContains(sfuTypes, 'droppedSourceFrameCount: number', 'transport sample type exposes dropped source frame count');
  requireContains(sfuTypes, 'automaticQualityTransitionCount: number', 'transport sample type exposes quality transition count');

  requireContains(transportSample, 'activeCaptureBackend: String(payload.active_capture_backend || payload.publisher_source_backend || \'\')', 'transport sample maps active capture backend alias');
  requireContains(transportSample, 'selectedVideoQualityProfile: String(payload.selected_video_quality_profile || payload.outgoing_video_quality_profile || \'\')', 'transport sample maps selected profile alias');
  requireContains(transportSample, 'sourceReadbackMs: Math.max(0, Number(payload.source_readback_ms || payload.readback_ms || 0))', 'transport sample maps readback timing alias');

  requireContains(sfuTransport, 'wlvcDroppedSourceFrameCount', 'transport state tracks dropped source frames');
  requireContains(sfuTransport, 'sfuAutomaticQualityTransitionCount', 'transport state tracks automatic quality transitions');
  requireContains(runtimeSwitching, 'publisherQualityTransitionDiagnosticSurface', 'runtime switching emits clear quality transition diagnostics');
  requireContains(runtimeSwitching, 'sfuAutomaticQualityTransitionCount', 'runtime switching increments quality transition count');
  requireContains(publisherBackpressureController, 'wlvcDroppedSourceFrameCount', 'source readback pressure increments dropped source frame count');
  requireContains(publisherBackpressureController, 'publisherDroppedSourceFrameDiagnosticSurface', 'source readback pressure emits clear dropped source diagnostics');
  requireContains(diagnosticsSurface, 'active_capture_backend', 'diagnostic surface helper emits active capture backend');
  requireContains(diagnosticsSurface, 'dropped_source_frame_count', 'diagnostic surface helper emits dropped source frame count');
  requireContains(diagnosticsSurface, 'automatic_quality_transition_count', 'quality transition payload includes transition count');
  requireContains(diagnosticsSurface, 'automatic_quality_transition_direction', 'quality transition payload includes direction');
  requireContains(diagnosticsSurface, 'automatic_quality_from_profile', 'quality transition payload includes source profile');
  requireContains(diagnosticsSurface, 'automatic_quality_to_profile', 'quality transition payload includes target profile');

  const {
    publisherCaptureDiagnosticSurface,
    publisherDroppedSourceFrameDiagnosticSurface,
    publisherQualityTransitionDiagnosticSurface,
  } = await import('../../src/domain/realtime/workspace/callWorkspace/publisherDiagnosticsSurface.ts');

  assert.deepEqual(publisherCaptureDiagnosticSurface({
    publisher_source_backend: 'video_frame_copy_to_rgba',
    outgoing_video_quality_profile: 'balanced',
    source_track_width: 1280,
    source_track_height: 720,
    source_track_frame_rate: 27,
    readback_ms: 2.5,
    readback_budget_ms: 7,
  }), {
    active_capture_backend: 'video_frame_copy_to_rgba',
    selected_video_quality_profile: 'balanced',
    source_frame_width: 1280,
    source_frame_height: 720,
    source_frame_rate: 27,
    source_draw_image_ms: 0,
    source_draw_image_budget_ms: 0,
    source_readback_ms: 2.5,
    source_readback_budget_ms: 7,
  });

  assert.equal(
    publisherDroppedSourceFrameDiagnosticSurface({ droppedSourceFrameCount: 3 }).dropped_source_frame_count,
    3,
  );
  assert.deepEqual(publisherQualityTransitionDiagnosticSurface({
    transitionCount: 4,
    direction: 'down',
    fromProfile: 'quality',
    toProfile: 'balanced',
  }), {
    automatic_quality_transition_count: 4,
    automatic_quality_transition_direction: 'down',
    automatic_quality_from_profile: 'quality',
    automatic_quality_to_profile: 'balanced',
    selected_video_quality_profile: 'balanced',
  });

  process.stdout.write('[sfu-diagnostic-surface-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
