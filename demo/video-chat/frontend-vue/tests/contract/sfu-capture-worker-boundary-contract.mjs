import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-capture-worker-boundary-contract] FAIL: ${message}`);
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
  const workerSource = read('src/domain/realtime/local/publisherCaptureWorker.js');
  const workerClientSource = read('src/domain/realtime/local/publisherCaptureWorkerClient.js');
  const protocolSource = read('src/domain/realtime/local/publisherCaptureWorkerProtocol.js');
  const combinedBoundarySource = `${workerSource}\n${workerClientSource}\n${protocolSource}`;

  requireContains(protocolSource, 'PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES', 'shared capture worker message protocol');
  requireContains(protocolSource, 'kingrt/publisher-capture-worker/readback', 'readback message type');
  requireContains(protocolSource, 'publisherCaptureWorkerTransferListForInit', 'init transfer list helper');
  requireContains(protocolSource, 'publisherCaptureWorkerTransferListForReadback', 'readback transfer list helper');
  requireContains(workerClientSource, "new URL('./publisherCaptureWorker.js', import.meta.url)", 'module worker URL factory');
  requireContains(workerClientSource, "type: 'module'", 'module worker construction');
  requireContains(workerClientSource, 'canUsePublisherCaptureWorker', 'worker capability gate');
  requireContains(workerSource, 'resolveFramedFrameSizeFromDimensions', 'worker owns aspect-preserving and crop-aware frame sizing');
  requireContains(workerSource, 'normalizePublisherFramingTarget', 'worker normalizes cover/contain framing target');
  requireContains(workerSource, 'new OffscreenCanvas(frameWidth, frameHeight)', 'worker creates offscreen canvas when not transferred');
  requireContains(workerSource, "captureCanvas.getContext('2d'", 'worker owns 2D context');
  requireContains(workerSource, 'crop.x', 'worker owns source crop x before scaling');
  requireContains(workerSource, 'crop.width', 'worker owns source crop width before scaling');
  requireContains(workerSource, 'context.getImageData(0, 0, frameSize.frameWidth, frameSize.frameHeight)', 'worker owns RGBA readback');
  requireContains(workerSource, 'closeFrameSource(source)', 'worker closes transferred frame sources');
  requireContains(workerSource, 'imageData.data.buffer', 'worker transfers readback buffer back to main thread');
  requireContains(workerSource, 'drawImageMs', 'worker reports draw timing');
  requireContains(workerSource, 'readbackMs', 'worker reports readback timing');

  assert.equal(/\bfrom ['"]vue['"]/.test(combinedBoundarySource), false, 'capture worker boundary must not import Vue');
  assert.equal(/CallWorkspace|workspace\/callWorkspace|WorkspaceShell/.test(combinedBoundarySource), false, 'capture worker boundary must not import workspace state');

  const protocolUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherCaptureWorkerProtocol.js')).href;
  const clientUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherCaptureWorkerClient.js')).href;
  const protocol = await import(protocolUrl);
  const client = await import(clientUrl);

  const canvas = { marker: 'canvas' };
  const initMessage = protocol.buildPublisherCaptureWorkerInitMessage({ canvas, generation: 7 });
  assert.equal(initMessage.type, protocol.PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.INIT);
  assert.equal(initMessage.generation, 7);
  assert.deepEqual(protocol.publisherCaptureWorkerTransferListForInit(initMessage), [canvas]);

  const source = { marker: 'source' };
  const readbackMessage = protocol.buildPublisherCaptureWorkerReadbackMessage({
    source,
    requestId: 'req-1',
    generation: 8,
    sourceWidth: 720,
    sourceHeight: 1280,
    sourceCropX: 40,
    sourceCropY: 0,
    sourceCropWidth: 640,
    sourceCropHeight: 1280,
    framingMode: 'cover',
    targetAspectRatio: 0.5,
    profileFrameWidth: 1280,
    profileFrameHeight: 720,
    timestamp: 1234,
  });
  assert.equal(readbackMessage.type, protocol.PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK);
  assert.equal(readbackMessage.requestId, 'req-1');
  assert.equal(readbackMessage.profileFrameWidth, 1280);
  assert.equal(readbackMessage.sourceCropX, 40);
  assert.equal(readbackMessage.sourceCropWidth, 640);
  assert.equal(readbackMessage.framingMode, 'cover');
  assert.equal(readbackMessage.targetAspectRatio, 0.5);
  assert.deepEqual(protocol.publisherCaptureWorkerTransferListForReadback(readbackMessage), [source]);

  assert.equal(client.canUsePublisherCaptureWorker({
    supportsMediaStreamTrackProcessor: true,
    supportsOffscreenCanvas: true,
    supportsOffscreenCanvas2d: true,
    supportsOffscreenCanvasTransfer: true,
    supportsWorker: true,
  }), true);

  const created = [];
  class FakeWorker {
    constructor(url, options) {
      created.push({ url, options });
    }
  }
  const worker = client.createPublisherCaptureWorker({ WorkerCtor: FakeWorker, workerUrl: 'worker.js' });
  assert.ok(worker instanceof FakeWorker);
  assert.equal(created[0].url, 'worker.js');
  assert.deepEqual(created[0].options, { type: 'module', name: 'kingrt-publisher-capture-worker' });

  process.stdout.write('[sfu-capture-worker-boundary-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
