import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-wlvc-abi-gating-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function buildLiveWlvcFrame({ version = 2, waveletType = 0, colorSpace = 0, entropyCoding = 0 } = {}) {
  const headerBytes = version >= 2 ? 33 : 28;
  const bytes = new Uint8Array(headerBytes);
  const view = new DataView(bytes.buffer);
  view.setUint32(0, 0x574c5643, false);
  view.setUint8(4, version);
  view.setUint8(5, 0);
  view.setUint8(6, 60);
  view.setUint8(7, 2);
  view.setUint16(8, 16, false);
  view.setUint16(10, 16, false);
  view.setUint32(12, 0, false);
  view.setUint32(16, 0, false);
  view.setUint32(20, 0, false);
  view.setUint16(24, 8, false);
  view.setUint16(26, 8, false);
  if (version >= 2) {
    view.setUint8(28, waveletType);
    view.setUint8(29, colorSpace);
    view.setUint8(30, entropyCoding);
    view.setUint8(31, 0);
    view.setUint8(32, 0);
  }
  return bytes.buffer;
}

async function main() {
  const server = await createServer({
    configFile: false,
    logLevel: 'silent',
    root: path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..'),
    server: { middlewareMode: true, hmr: false, watch: null },
    appType: 'custom',
  });
  try {
    const {
      WLVC_ABI_MISMATCH_ERROR,
      WLVC_JS_ASSET_VERSION,
      WLVC_LIVE_FRAME_VERSION,
      WLVC_WASM_ABI_VERSION,
      buildSfuFrameDescriptor,
      readWlvcFrameMetadata,
      sfuFrameTypeFromWlvcData,
      validateLiveWlvcFrameAbi,
    } = await server.ssrLoadModule('/src/domain/realtime/sfu/wlvcFrameMetadata.ts');
    const {
      SFU_WLVC_JS_ASSET_VERSION,
      SFU_WLVC_WASM_ABI_VERSION,
      isSfuWlvcAbiMetadataCompatible,
    } = await server.ssrLoadModule('/src/lib/sfu/framePayload.ts');

    assert.equal(WLVC_LIVE_FRAME_VERSION, 2, 'live WLVC frame version must be v2');
    assert.equal(WLVC_WASM_ABI_VERSION, 2, 'live WLVC WASM ABI must be explicit');
    assert.equal(WLVC_JS_ASSET_VERSION, 'wlvc-js-abi-v2', 'live WLVC JS asset version must be explicit');
    assert.equal(SFU_WLVC_WASM_ABI_VERSION, WLVC_WASM_ABI_VERSION, 'SFU metadata ABI version must match WLVC live gate');
    assert.equal(SFU_WLVC_JS_ASSET_VERSION, WLVC_JS_ASSET_VERSION, 'SFU metadata JS asset version must match WLVC live gate');

  const goodFrame = buildLiveWlvcFrame();
  assert.deepEqual(validateLiveWlvcFrameAbi(goodFrame), {
    ok: true,
    errorCode: '',
    frameVersion: 2,
  });
  const goodMetadata = readWlvcFrameMetadata(goodFrame);
  assert.equal(goodMetadata.decodeOk, true, 'live v2 metadata must decode');
  assert.equal(goodMetadata.abiOk, true, 'live v2 ABI must be accepted');
  assert.equal(sfuFrameTypeFromWlvcData(goodFrame), 'keyframe', 'live v2 frame type can be read before send');
  assert.equal(buildSfuFrameDescriptor(goodFrame, 1234, goodMetadata).data, goodFrame, 'live v2 descriptor can be built');

  const staleFrame = buildLiveWlvcFrame({ version: 1 });
  const staleMetadata = readWlvcFrameMetadata(staleFrame);
  assert.equal(staleMetadata.decodeOk, false, 'stale v1 live metadata must fail closed');
  assert.equal(staleMetadata.abiOk, false, 'stale v1 ABI must be rejected');
  assert.equal(staleMetadata.errorCode, WLVC_ABI_MISMATCH_ERROR, 'stale v1 error code');
  assert.throws(() => sfuFrameTypeFromWlvcData(staleFrame), new RegExp(WLVC_ABI_MISMATCH_ERROR), 'local publish path must reject stale ABI before send');
  assert.throws(() => buildSfuFrameDescriptor(staleFrame, 1234, staleMetadata), new RegExp(WLVC_ABI_MISMATCH_ERROR), 'receive path must reject stale ABI before decoder.decodeFrame');

  const newWasmOldJsFrame = buildLiveWlvcFrame({ waveletType: 9 });
  const newWasmOldJsMetadata = readWlvcFrameMetadata(newWasmOldJsFrame);
  assert.equal(newWasmOldJsMetadata.abiOk, false, 'unknown v2 ABI descriptor must fail closed');
  assert.equal(newWasmOldJsMetadata.errorCode, WLVC_ABI_MISMATCH_ERROR, 'unknown v2 ABI descriptor error code');

  assert.equal(isSfuWlvcAbiMetadataCompatible('wlvc_wasm', 'wlvc_sfu', {
    wlvc_js_asset_version: SFU_WLVC_JS_ASSET_VERSION,
    wlvc_wasm_abi_version: SFU_WLVC_WASM_ABI_VERSION,
  }), true, 'matching SFU WLVC ABI metadata must be accepted');
  assert.equal(isSfuWlvcAbiMetadataCompatible('wlvc_wasm', 'wlvc_sfu', {
    wlvc_js_asset_version: 'stale-js',
    wlvc_wasm_abi_version: SFU_WLVC_WASM_ABI_VERSION,
  }), false, 'stale JS/new WASM metadata must be rejected');
  assert.equal(isSfuWlvcAbiMetadataCompatible('wlvc_wasm', 'wlvc_sfu', {
    wlvc_js_asset_version: SFU_WLVC_JS_ASSET_VERSION,
    wlvc_wasm_abi_version: 1,
  }), false, 'new JS/stale WASM metadata must be rejected');

  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  const frontendRoot = path.resolve(__dirname, '../..');
  const frameDecodeSource = fs.readFileSync(path.resolve(frontendRoot, 'src/domain/realtime/sfu/frameDecode.ts'), 'utf8');
  const metadataSource = fs.readFileSync(path.resolve(frontendRoot, 'src/domain/realtime/sfu/wlvcFrameMetadata.ts'), 'utf8');
  const framePayloadSource = fs.readFileSync(path.resolve(frontendRoot, 'src/lib/sfu/framePayload.ts'), 'utf8');

  requireContains(frameDecodeSource, 'const frameMetadata = readWlvcFrameMetadata(frameData', 'receive path metadata read');
  requireContains(frameDecodeSource, 'const frameDescriptor = buildSfuFrameDescriptor(frameData, frame.timestamp, frameMetadata, frame.type);', 'receive path descriptor gate');
  requireContains(metadataSource, 'assertLiveWlvcFrameMetadata(metadata);', 'descriptor ABI assertion');
  requireContains(metadataSource, 'throw new Error(WLVC_ABI_MISMATCH_ERROR);', 'fail-closed ABI throw');
  requireContains(framePayloadSource, 'if (!isSfuWlvcAbiMetadataCompatible(codecId, runtimeId, transportMetrics)) return null', 'binary envelope receive ABI gate');
  requireContains(framePayloadSource, "export const SFU_WLVC_JS_ASSET_VERSION = 'wlvc-js-abi-v2'", 'SFU JS asset version diagnostic');
  requireContains(framePayloadSource, 'export const SFU_WLVC_WASM_ABI_VERSION = 2', 'SFU WASM ABI diagnostic');
  requireContains(framePayloadSource, 'return abiVersion === SFU_WLVC_WASM_ABI_VERSION && jsAssetVersion === SFU_WLVC_JS_ASSET_VERSION', 'stale JS/new WASM and new JS/stale WASM metadata gate');

  process.stdout.write('[sfu-wlvc-abi-gating-contract] PASS\n');
  } finally {
    await server.close();
  }
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
