import { wlvcDecodeFrame } from '../../../support/wlvcFrame.ts';
import {
  SFU_WLVC_FRAME_HEIGHT,
  SFU_WLVC_FRAME_QUALITY,
  SFU_WLVC_FRAME_WIDTH,
} from '../workspace/config';

export const WLVC_LIVE_ABI_VERSION = 2;
export const WLVC_LIVE_FRAME_VERSION = 2;
export const WLVC_JS_ASSET_VERSION = 'wlvc-js-abi-v2';
export const WLVC_WASM_ABI_VERSION = 2;
export const WLVC_ABI_MISMATCH_ERROR = 'wlvc_abi_version_mismatch';

export function normalizePositiveInteger(value, fallback) {
  const number = Number(value || 0);
  return Number.isInteger(number) && number > 0 ? number : fallback;
}

export function normalizeSfuFrameType(value, fallback = 'delta') {
  const normalized = String(value ?? '').trim();
  if (normalized === 'keyframe' || (normalized !== '' && Number(value) === 0)) return 'keyframe';
  if (normalized === 'delta' || (normalized !== '' && Number(value) === 1)) return 'delta';
  return String(fallback || '').trim() === 'keyframe' ? 'keyframe' : 'delta';
}

export function readWlvcFrameMetadata(frameData, fallback = {}) {
  const fallbackWidth = normalizePositiveInteger(fallback.width, SFU_WLVC_FRAME_WIDTH);
  const fallbackHeight = normalizePositiveInteger(fallback.height, SFU_WLVC_FRAME_HEIGHT);
  const fallbackQuality = normalizePositiveInteger(fallback.quality, SFU_WLVC_FRAME_QUALITY);
  const fallbackType = normalizeSfuFrameType(fallback.type || fallback.frameType, 'delta');
  const abi = validateLiveWlvcFrameAbi(frameData);
  if (!abi.ok) {
    return {
      width: fallbackWidth,
      height: fallbackHeight,
      quality: fallbackQuality,
      type: fallbackType,
      decodeOk: false,
      abiOk: false,
      errorCode: abi.errorCode,
      wlvcFrameVersion: abi.frameVersion,
      wlvcAbiVersion: WLVC_LIVE_ABI_VERSION,
      wlvcJsAssetVersion: WLVC_JS_ASSET_VERSION,
      wlvcWasmAbiVersion: WLVC_WASM_ABI_VERSION,
    };
  }
  const decoded = wlvcDecodeFrame(frameData);
  if (!decoded?.ok || !decoded.frame) {
    return {
      width: fallbackWidth,
      height: fallbackHeight,
      quality: fallbackQuality,
      type: fallbackType,
      decodeOk: false,
      abiOk: true,
      errorCode: String(decoded?.error_code || 'metadata_unavailable'),
      wlvcFrameVersion: abi.frameVersion,
      wlvcAbiVersion: WLVC_LIVE_ABI_VERSION,
      wlvcJsAssetVersion: WLVC_JS_ASSET_VERSION,
      wlvcWasmAbiVersion: WLVC_WASM_ABI_VERSION,
    };
  }

  return {
    width: normalizePositiveInteger(decoded.frame.width, fallbackWidth),
    height: normalizePositiveInteger(decoded.frame.height, fallbackHeight),
    quality: normalizePositiveInteger(decoded.frame.quality, fallbackQuality),
    type: normalizeSfuFrameType(decoded.frame.frame_type, fallbackType),
    decodeOk: true,
    abiOk: true,
    errorCode: '',
    wlvcFrameVersion: abi.frameVersion,
    wlvcAbiVersion: WLVC_LIVE_ABI_VERSION,
    wlvcJsAssetVersion: WLVC_JS_ASSET_VERSION,
    wlvcWasmAbiVersion: WLVC_WASM_ABI_VERSION,
  };
}

export function sfuFrameTypeFromWlvcData(frameData, fallback = 'delta') {
  const metadata = readWlvcFrameMetadata(frameData, { type: fallback });
  assertLiveWlvcFrameMetadata(metadata);
  return metadata.type;
}

export function buildSfuFrameDescriptor(frameData, timestamp, metadata, fallbackType = 'delta') {
  assertLiveWlvcFrameMetadata(metadata);
  return {
    data: frameData,
    timestamp,
    width: normalizePositiveInteger(metadata?.width, SFU_WLVC_FRAME_WIDTH),
    height: normalizePositiveInteger(metadata?.height, SFU_WLVC_FRAME_HEIGHT),
    type: normalizeSfuFrameType(metadata?.type, fallbackType),
  };
}

export function assertLiveWlvcFrameMetadata(metadata) {
  if (metadata?.abiOk === false || metadata?.errorCode === WLVC_ABI_MISMATCH_ERROR) {
    throw new Error(WLVC_ABI_MISMATCH_ERROR);
  }
  return true;
}

export function validateLiveWlvcFrameAbi(frameData) {
  const bytes = toUint8ArrayOrNull(frameData);
  if (!bytes || bytes.byteLength < 5) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion: 0 };
  }
  if (bytes[0] !== 0x57 || bytes[1] !== 0x4c || bytes[2] !== 0x56 || bytes[3] !== 0x43) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion: 0 };
  }
  const frameVersion = Number(bytes[4] || 0);
  if (frameVersion !== WLVC_LIVE_FRAME_VERSION) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion };
  }
  if (bytes.byteLength < 33) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion };
  }
  const waveletType = Number(bytes[28] || 0);
  const colorSpace = Number(bytes[29] || 0);
  const entropyCoding = Number(bytes[30] || 0);
  const reserved = Number(bytes[32] || 0);
  if (waveletType < 0 || waveletType > 2 || colorSpace < 0 || colorSpace > 1 || entropyCoding < 0 || entropyCoding > 2) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion };
  }
  if (reserved < 0 || reserved > 255) {
    return { ok: false, errorCode: WLVC_ABI_MISMATCH_ERROR, frameVersion };
  }
  return { ok: true, errorCode: '', frameVersion };
}

function toUint8ArrayOrNull(value) {
  if (value instanceof Uint8Array) return value;
  if (value instanceof ArrayBuffer) return new Uint8Array(value);
  if (ArrayBuffer.isView(value)) return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  return null;
}
