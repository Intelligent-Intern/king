import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-browser-ws-send-drain-contract] FAIL: ${message}`);
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
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const sfuTypes = read('src/lib/sfu/sfuTypes.ts');

  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES = 192 * 1024', 'client has an explicit low-water send resume target');
  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 160', 'client does not hide media behind a 500ms browser drain wait');
  requireContains(sfuClient, 'resolveSendDrainTargetBytes(metrics)', 'client derives drain target from the active profile budget');
  requireContains(sfuClient, 'this.waitForSendBufferDrain(drainTargetBufferedBytes, SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS)', 'client waits only to the budgeted low-water target');
  requireContains(sfuClient, 'sfu_projected_buffer_budget_exceeded', 'client drops frames that would refill bufferedAmount above budget');
  requireContains(sfuClient, 'projected_buffered_after_send_bytes', 'client reports projected websocket buffer pressure before send');
  requireContains(sfuClient, 'send_drain_target_buffered_bytes', 'client records low-water drain target');
  requireContains(sfuClient, 'send_drain_max_wait_ms', 'client records bounded drain wait budget');
  requireContains(sfuClient, 'private send(msg: object): boolean', 'control messages keep a direct send path');
  requireContains(sfuClient, 'this.ws.send(JSON.stringify(msg))', 'control messages bypass the media frame queue');
  requireContains(sfuTypes, 'sendDrainTargetBytes', 'transport samples expose drain target');
  requireContains(sfuTypes, 'sendDrainMaxWaitMs', 'transport samples expose drain wait budget');
  assert.equal(
    sfuClient.includes('SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 500'),
    false,
    'browser drain pacing must not retain the old 500ms wait',
  );

  process.stdout.write('[sfu-browser-ws-send-drain-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
