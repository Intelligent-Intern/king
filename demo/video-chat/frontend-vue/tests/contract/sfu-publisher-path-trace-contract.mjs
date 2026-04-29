import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-publisher-path-trace-contract] FAIL: ${message}`);
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
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  requireContains(publisherFrameTrace, 'publisher_frame_trace_id', 'publisher trace id metric');
  requireContains(publisherFrameTrace, 'publisher_path_trace_stages', 'publisher trace stage chain metric');
  requireContains(publisherFrameTrace, 'trace_get_user_media_frame_delivery_ms', 'getUserMedia delivery timing metric');
  requireContains(publisherFrameTrace, 'trace_video_frame_processor_read_ms', 'VideoFrame processor read timing metric');
  requireContains(publisherFrameTrace, 'trace_video_frame_canvas_draw_image_ms', 'VideoFrame canvas draw timing metric');
  requireContains(publisherFrameTrace, 'trace_video_frame_canvas_get_image_data_ms', 'VideoFrame canvas readback timing metric');
  requireContains(publisherFrameTrace, 'trace_dom_canvas_draw_image_ms', 'DOM canvas draw timing metric');
  requireContains(publisherFrameTrace, 'trace_dom_canvas_get_image_data_ms', 'DOM canvas readback timing metric');
  requireContains(publisherFrameTrace, 'trace_wlvc_encode_ms', 'WLVC encode timing metric');
  requireContains(publisherFrameTrace, 'trace_protected_frame_wrap_ms', 'protected frame wrap timing metric');
  requireContains(publisherFrameTrace, 'buildPublisherTransportStageMetrics', 'publisher transport metrics builder');

  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  requireContains(publisherPipeline, "from './publisherFrameTrace'", 'publisher pipeline imports trace helper');
  requireContains(publisherPipeline, 'createPublisherFrameTrace({', 'publisher creates a frame trace before readback');
  requireContains(publisherPipeline, "markPublisherFrameTraceStage(trace, 'get_user_media_frame_delivery'", 'publisher records getUserMedia frame delivery timing');
  requireContains(publisherPipeline, "markPublisherFrameTraceStage(trace, 'wlvc_encode'", 'publisher records WLVC encode timing');
  requireContains(publisherPipeline, "markPublisherFrameTraceStage(trace, 'protected_frame_wrap'", 'publisher records protected frame wrap timing');
  requireContains(publisherPipeline, "markPublisherFrameTraceStage(trace, 'protected_frame_skipped'", 'publisher records skipped protected wrap timing');
  requireContains(publisherPipeline, 'buildPublisherTransportStageMetrics({', 'publisher centralizes path trace metrics');

  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  requireContains(sourceReadback, "markPublisherFrameTraceStage(trace, 'video_frame_processor_read'", 'source readback records VideoFrame processor timing');
  requireContains(sourceReadback, "'video_frame_canvas_draw_image'", 'source readback records VideoFrame draw timing');
  requireContains(sourceReadback, "'video_frame_canvas_get_image_data'", 'source readback records VideoFrame canvas readback timing');
  requireContains(sourceReadback, "'dom_canvas_draw_image'", 'source readback records DOM fallback draw timing');
  requireContains(sourceReadback, "'dom_canvas_get_image_data'", 'source readback records DOM fallback readback timing');
  requireContains(sourceReadback, 'publisherFrameFailureDetails(trace', 'source readback passes trace metrics into source-readback failures');

  const framePayload = read('src/lib/sfu/framePayload.ts');
  requireContains(framePayload, 'publisher_frame_trace_id', 'frame payload preserves publisher trace id');
  requireContains(framePayload, 'publisher_path_trace_stage', 'frame payload preserves current publisher stage');
  requireContains(framePayload, 'publisher_path_trace_stages', 'frame payload preserves publisher trace stage chain');
  requireContains(framePayload, 'trace_binary_envelope_encode_ms', 'frame payload normalizes binary envelope timing');
  requireContains(framePayload, 'trace_browser_websocket_send_ms', 'frame payload normalizes browser websocket timing');

  const sfuClientTransportSample = read('src/lib/sfu/sfuClientTransportSample.ts');
  requireContains(sfuClientTransportSample, 'appendSfuPublisherTraceStage', 'sfu client trace stage helper');
  requireContains(sfuClientTransportSample, 'buildSfuFrameTransportSample', 'sfu client sample helper');
  requireContains(sfuClientTransportSample, 'binaryEnvelopeEncodeMs', 'transport sample exposes binary envelope timing');
  requireContains(sfuClientTransportSample, 'websocketSendMs', 'transport sample exposes websocket send timing');

  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  requireContains(sfuClient, "from './sfuClientTransportSample'", 'sfu client imports trace sample helper');
  requireContains(sfuClient, 'appendSfuPublisherTraceStage(', 'sfu client appends browser send-path stages');
  requireContains(sfuClient, "'binary_envelope_encode'", 'sfu client traces binary envelope encoding');
  requireContains(sfuClient, "'browser_websocket_send'", 'sfu client traces browser websocket send');
  requireContains(sfuClient, 'binary_envelope_encode_ms', 'sfu client records binary envelope timing');
  requireContains(sfuClient, 'websocket_send_ms', 'sfu client records websocket send timing');
  requireContains(sfuClient, 'buildSfuFrameTransportSample(payload, nowMs)', 'sfu client samples transport metrics through helper');

  const sendFailureDetails = read('src/lib/sfu/sendFailureDetails.ts');
  requireContains(sendFailureDetails, 'publisherFrameTraceId', 'send failures preserve publisher trace id');
  requireContains(sendFailureDetails, 'publisherPathTraceStages', 'send failures preserve publisher trace stages');

  const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  requireContains(publisherBackpressureController, 'publisher_frame_trace_id: publisherFrameTraceId', 'workspace diagnostics include publisher trace id');
  requireContains(publisherBackpressureController, 'publisher_path_trace_stages: publisherPathTraceStages', 'workspace diagnostics include publisher trace stage chain');
  requireContains(publisherBackpressureController, 'source_delivery_ms: sourceDeliveryMs', 'workspace diagnostics include source delivery timing');
  requireContains(publisherBackpressureController, 'draw_image_ms: drawImageMs', 'workspace diagnostics include draw timing');
  requireContains(publisherBackpressureController, 'readback_ms: readbackMs', 'workspace diagnostics include readback timing');
  requireContains(publisherBackpressureController, 'encode_ms: encodeMs', 'workspace diagnostics include encode timing');

  process.stdout.write('[sfu-publisher-path-trace-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
