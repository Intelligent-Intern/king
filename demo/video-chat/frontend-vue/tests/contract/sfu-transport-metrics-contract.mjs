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
  requireContains(framePayload, 'transport_frame_kind', 'frame payload transport frame kind metric');
  requireContains(framePayload, 'roi_area_ratio', 'frame payload ROI area metric');
  requireContains(framePayload, 'selection_tile_ratio', 'frame payload selected tile ratio metric');
  requireContains(framePayload, 'selection_mask_guided', 'frame payload matte-guided metric');

  const sfuClient = readFrontend('src/lib/sfu/sfuClient.ts');
  requireContains(sfuClient, 'SFU_FRAME_TRANSPORT_SAMPLE_COOLDOWN_MS', 'sfu client transport sample cooldown');
  requireContains(sfuClient, "'sfu_frame_transport_sample'", 'sfu client sampled transport diagnostic');
  requireContains(sfuClient, 'wire_overhead_bytes', 'sfu client wire overhead metric');
  requireContains(sfuClient, "transport_path: 'legacy_chunked_json'", 'sfu client chunked legacy transport path metric');
  requireContains(sfuClient, "transport_path: 'binary_envelope'", 'sfu client binary transport path metric');

  const backendStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  requireContains(backendStore, 'function videochat_sfu_transport_metric_fields', 'backend transport metric helper');
  requireContains(backendStore, 'transport_frame_kind', 'backend transport frame kind metric');
  requireContains(backendStore, 'legacy_base64_overhead_bytes', 'backend legacy base64 overhead metric');
  requireContains(backendStore, 'selection_tile_ratio', 'backend selected tile ratio metric');
  requireContains(backendStore, 'selection_mask_guided', 'backend matte-guided metric');
  requireContains(backendStore, 'sfu_frame_broker_replay_binary', 'backend binary replay log remains instrumented');

  process.stdout.write('[sfu-transport-metrics-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
