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
  requireContains(framePayload, 'queued_age_ms', 'frame payload preserves measured outbound queue age');
  requireContains(framePayload, 'budget_max_buffered_bytes', 'frame payload buffered bytes budget metric');
  requireContains(framePayload, 'transportMetrics: normalizeTransportMetrics', 'binary envelope preserves stage transport metrics');

  const sfuClient = readFrontend('src/lib/sfu/sfuClient.ts');
  requireContains(sfuClient, 'SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS', 'sfu client transport sample cooldown');
  requireContains(sfuClient, "'sfu_frame_transport_sample'", 'sfu client sampled transport diagnostic');
  requireContains(sfuClient, 'wire_overhead_bytes', 'sfu client wire overhead metric');
  requireContains(sfuClient, "transport_path: 'binary_envelope'", 'sfu client binary transport path metric');
  requireContains(sfuClient, 'binaryContinuationRequired', 'sfu client records binary continuation state');
  requireContains(sfuClient, 'binary_continuation_threshold_bytes: SFU_BINARY_CONTINUATION_THRESHOLD_BYTES', 'sfu client exact binary continuation threshold metric');
  requireContains(sfuClient, 'const applicationMediaChunking = payload.application_media_chunking !== false', 'sfu client gates legacy chunk pressure on active application chunking');
  requireContains(sfuClient, 'const binaryEnvelopePressure = !applicationMediaChunking', 'sfu client separates binary envelope pressure from legacy chunk pressure');
  requireContains(sfuClient, 'wirePayloadBytes >= SFU_FRAME_CHUNK_BACKPRESSURE_BYTES', 'sfu client requires real binary envelope wire pressure before warning');
  requireContains(sfuClient, 'const bufferedPressure = bufferedAmount >= SFU_FRAME_CHUNK_BACKPRESSURE_BYTES', 'sfu client keeps real websocket buffered pressure warnings');
  requireContains(sfuClient, '!legacyChunkPressure && !binaryEnvelopePressure && !bufferedPressure', 'sfu client suppresses false binary pressure warnings');
  assert.ok(
    !sfuClient.includes('this.sendChunkedFramePayload(prepared.payload, prepared.chunkField'),
    'sfu client must not route outbound media through legacy chunked JSON before binary envelope send',
  );
  const sendFailureDetails = readFrontend('src/lib/sfu/sendFailureDetails.ts');
  requireContains(sendFailureDetails, 'stage: String(details.stage ||', 'sfu client persists exact send stage on failure');
  requireContains(sendFailureDetails, 'source: String(details.source ||', 'sfu client persists exact send source on failure');
  requireContains(sendFailureDetails, 'retryAfterMs: Math.max(0, Number(details.retryAfterMs || 0))', 'sfu client persists retry-after pacing on failure');
  requireContains(sfuClient, 'metrics.send_drain_ms = drain.waitedMs', 'sfu client records send-drain timing');
  requireContains(sfuClient, 'metrics.queued_age_ms = postDrainQueueAgeMs', 'sfu client stamps post-drain queue age before binary encode');
  requireContains(sfuClient, 'prepared.metrics = {\n      ...prepared.metrics,\n      ...metrics,', 'sfu client writes fresh queue age into binary envelope metadata before encode');
  requireContains(sfuClient, 'sfu_queue_age_budget_exceeded', 'sfu client enforces queue-age budget before send');
  requireContains(sfuClient, 'sfu_buffer_budget_exceeded', 'sfu client enforces websocket buffered budget before critical pressure');

  const sfuTransport = readFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const publisherBackpressureController = readFrontend('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
  const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
  requireContains(sfuPublisherControl, "eventType: 'sfu_frame_send_failed'", 'workspace exact-stage send failure backend diagnostic');
  requireContains(sfuPublisherControl, 'transport_path: failureTransportPath', 'workspace failed frame send diagnostic includes transport path');
  requireContains(sfuPublisherControl, 'binary_continuation_state: String(details?.binaryContinuationState', 'workspace failed frame send diagnostic includes binary continuation state');
  requireContains(sfuPublisherControl, 'stage: failureStage', 'workspace failed frame send diagnostic includes exact stage');
  requireContains(sfuPublisherControl, 'source: failureSource', 'workspace failed frame send diagnostic includes exact source');

  const publisherPipeline = readFrontend('src/domain/realtime/local/publisherPipeline.js');
  const publisherFrameTrace = readFrontend('src/domain/realtime/local/publisherFrameTrace.js');
  const publisherPathMetrics = `${publisherPipeline}\n${publisherFrameTrace}`;
  requireContains(publisherPathMetrics, 'draw_image_ms: drawImageMs', 'publisher records DOM draw timing');
  requireContains(publisherPathMetrics, 'readback_ms: readbackMs', 'publisher records canvas readback timing');
  requireContains(publisherPathMetrics, 'encode_ms: encodeMs', 'publisher records WLVC encode timing');
  requireContains(publisherPipeline, 'videoProfile.maxEncodedBytesPerFrame', 'publisher enforces profile encoded byte budget');

  const frameDecode = readFrontend('src/domain/realtime/sfu/frameDecode.js');
  requireContains(frameDecode, 'sfu_receiver_render_sample', 'receiver records render latency sample');
  requireContains(frameDecode, 'receiver_render_latency_ms', 'receiver render sample includes render latency');

  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  const backendFrameBuffer = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_frame_buffer.php');
  requireContains(backendStore, 'function videochat_sfu_transport_metric_fields', 'backend transport metric helper');
  requireContains(backendStore, 'transport_frame_kind', 'backend transport frame kind metric');
  requireContains(backendStore, 'legacy_base64_overhead_bytes', 'backend legacy base64 overhead metric');
  requireContains(backendStore, 'binary_continuation_state', 'backend binary continuation state metric');
  requireContains(backendStore, 'sfu_frame_binary_send_sample', 'backend sampled binary send diagnostic');
  requireContains(backendStore, 'selection_tile_ratio', 'backend selected tile ratio metric');
  requireContains(backendStore, 'selection_mask_guided', 'backend matte-guided metric');
  requireContains(backendStore, 'videochat_sfu_extract_stage_transport_metadata', 'backend normalizes stage transport metadata');
  requireContains(backendStore, "'queued_age_ms' => ['queued_age_ms', 'queuedAgeMs']", 'backend preserves client-measured queue age metric');
  requireContains(backendStore, 'king_receive_latency_ms', 'backend preserves King receive latency metric');
  requireContains(backendStore, 'subscriber_send_latency_ms', 'backend preserves subscriber send latency metric');
  requireContains(backendStore, 'CREATE TABLE IF NOT EXISTS sfu_frames', 'backend bounded SQLite frame buffer table');
  requireContains(backendStore, "require_once __DIR__ . '/realtime_sfu_frame_buffer.php';", 'backend SFU store delegates bounded frame-buffer runtime');
  requireContains(backendFrameBuffer, 'INSERT INTO sfu_frames', 'backend bounded SQLite frame buffer insert');
  requireContains(backendFrameBuffer, 'sqlite_buffer_age_ms', 'backend reports SQLite frame-buffer age metric');
  requireContains(backendFrameBuffer, 'sfu_frame_buffer_age_biased_eviction', 'backend reports frame-buffer eviction pressure');

  const backendGateway = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  requireContains(backendGateway, 'stampKingReceiveMetrics', 'gateway stamps King receive latency per frame');
  requireContains(backendGateway, 'king_fanout_latency_ms', 'gateway records fanout latency per frame');
  requireContains(backendGateway, 'videochat_sfu_insert_frame', 'gateway writes frames to bounded SQLite buffer');

  const backendSubscriberBudget = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');
  requireContains(backendSubscriberBudget, 'function videochat_sfu_frame_trusted_ingress_age_ms', 'backend uses client-measured ingress age instead of wall-clock latency for stale drops');
  requireContains(backendSubscriberBudget, "'trusted_ingress_age_ms' => $trustedIngressAgeMs", 'backend logs trusted ingress age');
  requireContains(backendSubscriberBudget, "'clock_sensitive_receive_latency_ms' => $receiveLatencyMs", 'backend keeps wall-clock latency as diagnostic only');
  requireContains(backendSubscriberBudget, "'queue_age_ms' => $trustedIngressAgeMs", 'publisher pressure reports trusted queue age, not clock-skewed latency');

  process.stdout.write('[sfu-transport-metrics-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
