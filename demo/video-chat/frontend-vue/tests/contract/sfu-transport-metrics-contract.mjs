import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-transport-metrics-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

try {
  const framePayload = readFrontend('src/lib/sfu/framePayload.ts');
  requireContains(framePayload, 'projected_binary_envelope_overhead_bytes', 'frame payload binary overhead metric');
  requireContains(framePayload, 'legacy_base64_overhead_bytes', 'frame payload legacy base64 overhead metric');
  requireContains(framePayload, 'binary_continuation_state', 'frame payload binary continuation state metric');
  requireContains(framePayload, 'SFU_BINARY_CONTINUATION_THRESHOLD_BYTES', 'frame payload binary continuation threshold');
  requireContains(framePayload, 'application_media_chunking: false', 'frame payload active media path disables application chunking');
  requireContains(framePayload, 'transport_frame_kind', 'frame payload transport frame kind metric');
  requireContains(framePayload, 'roi_area_ratio', 'frame payload ROI area metric');
  requireContains(framePayload, 'selection_tile_ratio', 'frame payload selected tile ratio metric');
  requireContains(framePayload, 'selection_mask_guided', 'frame payload matte-guided metric');
  requireContains(framePayload, 'outgoing_video_quality_profile', 'frame payload outgoing profile metric');
  requireContains(framePayload, 'draw_image_ms', 'frame payload draw timing metric');
  requireContains(framePayload, 'readback_ms', 'frame payload readback timing metric');
  requireContains(framePayload, 'encode_ms', 'frame payload encode timing metric');
  requireContains(framePayload, 'budget_max_encoded_bytes_per_frame', 'frame payload encoded byte budget metric');
  requireContains(framePayload, 'budget_max_wire_bytes_per_second', 'frame payload wire byte budget metric');
  requireContains(framePayload, 'budget_max_queue_age_ms', 'frame payload queue age budget metric');
  requireContains(framePayload, 'budget_max_buffered_bytes', 'frame payload buffered bytes budget metric');
  requireContains(framePayload, 'transportMetrics: normalizeTransportMetrics', 'binary envelope preserves stage transport metrics');

  const sfuClient = readFrontend('src/lib/sfu/sfuClient.ts');
  requireContains(sfuClient, 'SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS', 'sfu client transport sample cooldown');
  requireContains(sfuClient, "'sfu_frame_transport_sample'", 'sfu client sampled transport diagnostic');
  requireContains(sfuClient, 'wire_overhead_bytes', 'sfu client wire overhead metric');
  requireContains(sfuClient, "transport_path: 'binary_envelope'", 'sfu client binary transport path metric');
  requireContains(sfuClient, 'binaryContinuationRequired', 'sfu client records binary continuation state');
  requireContains(sfuClient, 'binary_continuation_threshold_bytes: SFU_BINARY_CONTINUATION_THRESHOLD_BYTES', 'sfu client exact binary continuation threshold metric');
  assert.ok(
    !sfuClient.includes('this.sendChunkedFramePayload(prepared.payload, prepared.chunkField'),
    'sfu client must not route outbound media through legacy chunked JSON before binary envelope send',
  );
  requireContains(sfuClient, 'stage: String(details.stage ||', 'sfu client persists exact send stage on failure');
  requireContains(sfuClient, 'source: String(details.source ||', 'sfu client persists exact send source on failure');
  requireContains(sfuClient, 'metrics.send_drain_ms = drain.waitedMs', 'sfu client records send-drain timing');
  requireContains(sfuClient, 'sfu_queue_age_budget_exceeded', 'sfu client enforces queue-age budget before send');
  requireContains(sfuClient, 'sfu_buffer_budget_exceeded', 'sfu client enforces websocket buffered budget before critical pressure');

  const sfuTransport = readFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const publisherBackpressureController = readFrontend('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
  requireContains(sfuPublisherControl, '[KingRT] SFU frame send failed at exact transport stage', 'workspace exact-stage send failure log');
  requireContains(sfuPublisherControl, 'transport_path: failureTransportPath', 'workspace failed frame send diagnostic includes transport path');
  requireContains(sfuPublisherControl, 'binary_continuation_state: String(details?.binaryContinuationState', 'workspace failed frame send diagnostic includes binary continuation state');
  requireContains(sfuPublisherControl, 'stage: failureStage', 'workspace failed frame send diagnostic includes exact stage');
  requireContains(sfuPublisherControl, 'source: failureSource', 'workspace failed frame send diagnostic includes exact source');

  const publisherPipeline = readFrontend('src/domain/realtime/local/publisherPipeline.js');
  requireContains(publisherPipeline, 'draw_image_ms: drawImageMs', 'publisher records DOM draw timing');
  requireContains(publisherPipeline, 'readback_ms: readbackMs', 'publisher records canvas readback timing');
  requireContains(publisherPipeline, 'encode_ms: encodeMs', 'publisher records WLVC encode timing');
  requireContains(publisherPipeline, 'videoProfile.maxEncodedBytesPerFrame', 'publisher enforces profile encoded byte budget');

  const frameDecode = readFrontend('src/domain/realtime/sfu/frameDecode.js');
  requireContains(frameDecode, 'sfu_receiver_render_sample', 'receiver records render latency sample');
  requireContains(frameDecode, 'receiver_render_latency_ms', 'receiver render sample includes render latency');

  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  requireContains(backendStore, 'function videochat_sfu_transport_metric_fields', 'backend transport metric helper');
  requireContains(backendStore, 'transport_frame_kind', 'backend transport frame kind metric');
  requireContains(backendStore, 'legacy_base64_overhead_bytes', 'backend legacy base64 overhead metric');
  requireContains(backendStore, 'binary_continuation_state', 'backend binary continuation state metric');
  requireContains(backendStore, 'sfu_frame_binary_send_sample', 'backend sampled binary send diagnostic');
  requireContains(backendStore, 'selection_tile_ratio', 'backend selected tile ratio metric');
  requireContains(backendStore, 'selection_mask_guided', 'backend matte-guided metric');
  requireContains(backendStore, 'videochat_sfu_extract_stage_transport_metadata', 'backend normalizes stage transport metadata');
  requireContains(backendStore, 'king_receive_latency_ms', 'backend preserves King receive latency metric');
  requireContains(backendStore, 'subscriber_send_latency_ms', 'backend preserves subscriber send latency metric');
  assert.ok(!backendStore.includes('CREATE TABLE IF NOT EXISTS sfu_frames'), 'backend must not persist SFU media frames in SQLite');
  assert.ok(!backendStore.includes('INSERT INTO sfu_frames'), 'backend must not insert SFU media frames into SQLite');

  const backendGateway = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  requireContains(backendGateway, 'stampKingReceiveMetrics', 'gateway stamps King receive latency per frame');
  requireContains(backendGateway, 'king_fanout_latency_ms', 'gateway records fanout latency per frame');

  process.stdout.write('[sfu-transport-metrics-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
