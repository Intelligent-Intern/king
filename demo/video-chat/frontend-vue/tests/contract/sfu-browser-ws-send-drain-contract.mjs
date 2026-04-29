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
  const outboundFrameBudget = read('src/lib/sfu/outboundFrameBudget.ts');
  const sfuTypes = read('src/lib/sfu/sfuTypes.ts');

  requireContains(outboundFrameBudget, 'const SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES = 192 * 1024', 'client has an explicit low-water send resume target');
  requireContains(outboundFrameBudget, 'export const SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 160', 'client does not hide media behind a 500ms browser drain wait');
  requireContains(outboundFrameBudget, 'export function resolveSfuSendDrainTargetBytes(metrics', 'client derives drain target from the active profile budget');
  requireContains(sfuClient, 'this.waitForSendBufferDrain(drainTargetBufferedBytes, SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS)', 'client waits only to the budgeted low-water target');
  requireContains(sfuClient, 'resolveSfuSendDrainTargetBytes(metrics)', 'client uses the shared drain target helper');
  requireContains(sfuClient, 'sfu_projected_buffer_budget_exceeded', 'client drops frames that would refill bufferedAmount above budget');
  requireContains(sfuClient, 'projected_buffered_after_send_bytes', 'client reports projected websocket buffer pressure before send');
  requireContains(outboundFrameBudget, 'export function shouldDropProjectedSfuFrameForBufferBudget(', 'client has a shared projected websocket buffer guard');
  requireContains(outboundFrameBudget, 'projectedBufferedAfterSendBytes > bufferedBudgetBytes', 'client applies a strict projected websocket buffer budget');
  requireContains(outboundFrameBudget, 'export const SFU_FRAME_WIRE_BUDGET_WINDOW_MS = 1000', 'client enforces a rolling one-second wire budget');
  requireContains(outboundFrameBudget, 'metrics.budget_max_wire_bytes_per_second', 'wire budget follows the active quality profile budget');
  requireContains(sfuClient, 'private outboundWireBudget = new SfuOutboundWireBudget()', 'client tracks rolling outbound wire bytes per socket session');
  requireContains(sfuClient, 'sfu_wire_rate_budget_exceeded', 'client drops frames that would exceed rolling wire bytes per second');
  requireContains(sfuClient, 'this.outboundWireBudget.record(encoded.byteLength)', 'client records sent binary envelope bytes into the rolling budget');
  requireContains(sfuClient, 'this.outboundWireBudget.reset()', 'client clears wire budget on reconnect/profile reset');
  requireContains(sfuClient, 'send_drain_target_buffered_bytes', 'client records low-water drain target');
  requireContains(sfuClient, 'send_drain_max_wait_ms', 'client records bounded drain wait budget');
  requireContains(sfuClient, 'private send(msg: object): boolean', 'control messages keep a direct send path');
  requireContains(sfuClient, 'this.ws.send(JSON.stringify(msg))', 'control messages bypass the media frame queue');
  requireContains(sfuTypes, 'sendDrainTargetBytes', 'transport samples expose drain target');
  requireContains(sfuTypes, 'sendDrainMaxWaitMs', 'transport samples expose drain wait budget');
  assert.equal(
    `${sfuClient}\n${outboundFrameBudget}`.includes('SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 500'),
    false,
    'browser drain pacing must not retain the old 500ms wait',
  );
  assert.equal(
    `${sfuClient}\n${outboundFrameBudget}`.includes('Math.max(bufferedBudgetBytes, projectedWirePayloadBytes)'),
    false,
    'projected websocket buffer guard must not allow one oversized media frame above the active budget',
  );

  process.stdout.write('[sfu-browser-ws-send-drain-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
