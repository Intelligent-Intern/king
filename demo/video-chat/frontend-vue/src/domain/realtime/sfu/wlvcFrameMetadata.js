import { wlvcDecodeFrame } from '../../../support/wlvcFrame.js';
import {
  SFU_WLVC_FRAME_HEIGHT,
  SFU_WLVC_FRAME_QUALITY,
  SFU_WLVC_FRAME_WIDTH,
} from '../workspace/config';

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
  const decoded = wlvcDecodeFrame(frameData);
  if (!decoded?.ok || !decoded.frame) {
    return {
      width: fallbackWidth,
      height: fallbackHeight,
      quality: fallbackQuality,
      type: fallbackType,
      decodeOk: false,
      errorCode: String(decoded?.error_code || 'metadata_unavailable'),
    };
  }

  return {
    width: normalizePositiveInteger(decoded.frame.width, fallbackWidth),
    height: normalizePositiveInteger(decoded.frame.height, fallbackHeight),
    quality: normalizePositiveInteger(decoded.frame.quality, fallbackQuality),
    type: normalizeSfuFrameType(decoded.frame.frame_type, fallbackType),
    decodeOk: true,
    errorCode: '',
  };
}

export function sfuFrameTypeFromWlvcData(frameData, fallback = 'delta') {
  return readWlvcFrameMetadata(frameData, { type: fallback }).type;
}

export function buildSfuFrameDescriptor(frameData, timestamp, metadata, fallbackType = 'delta') {
  return {
    data: frameData,
    timestamp,
    width: normalizePositiveInteger(metadata?.width, SFU_WLVC_FRAME_WIDTH),
    height: normalizePositiveInteger(metadata?.height, SFU_WLVC_FRAME_HEIGHT),
    type: normalizeSfuFrameType(metadata?.type, fallbackType),
  };
}
