export const WLVC_MAGIC_U32_BE = 0x574c5643;
export const WLVC_HEADER_BYTES = 28;

const WLVC_FRAME_TYPE_KEYFRAME = 0;
const WLVC_FRAME_TYPE_DELTA = 1;

const QUALITY_MIN = 1;
const QUALITY_MAX = 100;
const DWT_LEVELS_MIN = 1;
const DWT_LEVELS_MAX = 8;
const DIMENSION_MIN = 1;
const DIMENSION_MAX = 8192;
const CHANNEL_MAX_BYTES = 16 * 1024 * 1024;
const PAYLOAD_MAX_BYTES = 48 * 1024 * 1024;

function isIntegerInRange(value, min, max) {
  return Number.isInteger(value) && value >= min && value <= max;
}

function toUint8Array(value) {
  if (value instanceof Uint8Array) {
    return value;
  }
  if (value instanceof ArrayBuffer) {
    return new Uint8Array(value);
  }
  if (ArrayBuffer.isView(value)) {
    return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  }
  if (typeof value === 'string') {
    return hexToBytes(value);
  }
  throw new TypeError('wlvc_payload_type_invalid');
}

function hexToBytes(hex) {
  const normalized = String(hex).trim().toLowerCase();
  if (normalized === '') {
    return new Uint8Array(0);
  }
  if (normalized.length % 2 !== 0 || /[^0-9a-f]/.test(normalized)) {
    throw new TypeError('wlvc_hex_invalid');
  }
  const out = new Uint8Array(normalized.length / 2);
  for (let i = 0; i < normalized.length; i += 2) {
    out[i / 2] = Number.parseInt(normalized.slice(i, i + 2), 16);
  }
  return out;
}

export function wlvcFrameToHex(bytes) {
  const view = toUint8Array(bytes);
  let out = '';
  for (let i = 0; i < view.length; i += 1) {
    out += view[i].toString(16).padStart(2, '0');
  }
  return out;
}

export function wlvcHexToBytes(hex) {
  return hexToBytes(hex);
}

export function wlvcEncodeFrame(input) {
  try {
    const version = Number(input?.version);
    const frameType = Number(input?.frame_type);
    const quality = Number(input?.quality);
    const dwtLevels = Number(input?.dwt_levels);
    const width = Number(input?.width);
    const height = Number(input?.height);

    if (!isIntegerInRange(version, 1, 255)) {
      return { ok: false, error_code: 'version_invalid' };
    }
    if (![WLVC_FRAME_TYPE_KEYFRAME, WLVC_FRAME_TYPE_DELTA].includes(frameType)) {
      return { ok: false, error_code: 'frame_type_invalid' };
    }
    if (!isIntegerInRange(quality, QUALITY_MIN, QUALITY_MAX)) {
      return { ok: false, error_code: 'quality_invalid' };
    }
    if (!isIntegerInRange(dwtLevels, DWT_LEVELS_MIN, DWT_LEVELS_MAX)) {
      return { ok: false, error_code: 'dwt_levels_invalid' };
    }
    if (!isIntegerInRange(width, DIMENSION_MIN, DIMENSION_MAX)) {
      return { ok: false, error_code: 'width_invalid' };
    }
    if (!isIntegerInRange(height, DIMENSION_MIN, DIMENSION_MAX)) {
      return { ok: false, error_code: 'height_invalid' };
    }

    const yData = toUint8Array(input?.y_data ?? input?.y_hex ?? '');
    const uData = toUint8Array(input?.u_data ?? input?.u_hex ?? '');
    const vData = toUint8Array(input?.v_data ?? input?.v_hex ?? '');

    if (yData.byteLength > CHANNEL_MAX_BYTES || uData.byteLength > CHANNEL_MAX_BYTES || vData.byteLength > CHANNEL_MAX_BYTES) {
      return { ok: false, error_code: 'channel_too_large' };
    }

    const payloadLength = yData.byteLength + uData.byteLength + vData.byteLength;
    if (payloadLength > PAYLOAD_MAX_BYTES) {
      return { ok: false, error_code: 'payload_too_large' };
    }

    const defaultUvWidth = Math.ceil(width / 2);
    const defaultUvHeight = Math.ceil(height / 2);
    const uvWidth = Number(input?.uv_width ?? defaultUvWidth);
    const uvHeight = Number(input?.uv_height ?? defaultUvHeight);

    if (!isIntegerInRange(uvWidth, DIMENSION_MIN, DIMENSION_MAX)) {
      return { ok: false, error_code: 'uv_width_invalid' };
    }
    if (!isIntegerInRange(uvHeight, DIMENSION_MIN, DIMENSION_MAX)) {
      return { ok: false, error_code: 'uv_height_invalid' };
    }

    const out = new Uint8Array(WLVC_HEADER_BYTES + payloadLength);
    const view = new DataView(out.buffer);

    view.setUint32(0, WLVC_MAGIC_U32_BE, false);
    view.setUint8(4, version);
    view.setUint8(5, frameType);
    view.setUint8(6, quality);
    view.setUint8(7, dwtLevels);
    view.setUint16(8, width, false);
    view.setUint16(10, height, false);
    view.setUint32(12, yData.byteLength, false);
    view.setUint32(16, uData.byteLength, false);
    view.setUint32(20, vData.byteLength, false);
    view.setUint16(24, uvWidth, false);
    view.setUint16(26, uvHeight, false);

    let cursor = WLVC_HEADER_BYTES;
    out.set(yData, cursor);
    cursor += yData.byteLength;
    out.set(uData, cursor);
    cursor += uData.byteLength;
    out.set(vData, cursor);

    return {
      ok: true,
      bytes: out,
      frame: {
        version,
        frame_type: frameType,
        quality,
        dwt_levels: dwtLevels,
        width,
        height,
        uv_width: uvWidth,
        uv_height: uvHeight,
        y_length: yData.byteLength,
        u_length: uData.byteLength,
        v_length: vData.byteLength,
        payload_length: payloadLength,
      },
    };
  } catch (error) {
    return {
      ok: false,
      error_code: error instanceof Error && error.message !== '' ? error.message : 'encode_exception',
    };
  }
}

export function wlvcDecodeFrame(input) {
  let bytes;
  try {
    bytes = toUint8Array(input);
  } catch (error) {
    return {
      ok: false,
      error_code: error instanceof Error && error.message !== '' ? error.message : 'payload_type_invalid',
    };
  }

  if (bytes.byteLength < WLVC_HEADER_BYTES) {
    return { ok: false, error_code: 'frame_too_short' };
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const magic = view.getUint32(0, false);
  if (magic !== WLVC_MAGIC_U32_BE) {
    return { ok: false, error_code: 'magic_mismatch' };
  }

  const version = view.getUint8(4);
  if (version !== 1) {
    return { ok: false, error_code: 'version_unsupported' };
  }

  const frameType = view.getUint8(5);
  if (![WLVC_FRAME_TYPE_KEYFRAME, WLVC_FRAME_TYPE_DELTA].includes(frameType)) {
    return { ok: false, error_code: 'frame_type_invalid' };
  }

  const quality = view.getUint8(6);
  const dwtLevels = view.getUint8(7);
  const width = view.getUint16(8, false);
  const height = view.getUint16(10, false);
  const yLength = view.getUint32(12, false);
  const uLength = view.getUint32(16, false);
  const vLength = view.getUint32(20, false);
  const uvWidth = view.getUint16(24, false);
  const uvHeight = view.getUint16(26, false);

  if (!isIntegerInRange(quality, QUALITY_MIN, QUALITY_MAX)) {
    return { ok: false, error_code: 'quality_invalid' };
  }
  if (!isIntegerInRange(dwtLevels, DWT_LEVELS_MIN, DWT_LEVELS_MAX)) {
    return { ok: false, error_code: 'dwt_levels_invalid' };
  }
  if (!isIntegerInRange(width, DIMENSION_MIN, DIMENSION_MAX)) {
    return { ok: false, error_code: 'width_invalid' };
  }
  if (!isIntegerInRange(height, DIMENSION_MIN, DIMENSION_MAX)) {
    return { ok: false, error_code: 'height_invalid' };
  }
  if (!isIntegerInRange(uvWidth, DIMENSION_MIN, DIMENSION_MAX)) {
    return { ok: false, error_code: 'uv_width_invalid' };
  }
  if (!isIntegerInRange(uvHeight, DIMENSION_MIN, DIMENSION_MAX)) {
    return { ok: false, error_code: 'uv_height_invalid' };
  }

  if (yLength > CHANNEL_MAX_BYTES || uLength > CHANNEL_MAX_BYTES || vLength > CHANNEL_MAX_BYTES) {
    return { ok: false, error_code: 'channel_too_large' };
  }

  const payloadLength = yLength + uLength + vLength;
  if (payloadLength > PAYLOAD_MAX_BYTES) {
    return { ok: false, error_code: 'payload_too_large' };
  }

  if (bytes.byteLength !== WLVC_HEADER_BYTES + payloadLength) {
    return { ok: false, error_code: 'payload_length_mismatch' };
  }

  let cursor = WLVC_HEADER_BYTES;
  const yData = bytes.slice(cursor, cursor + yLength);
  cursor += yLength;
  const uData = bytes.slice(cursor, cursor + uLength);
  cursor += uLength;
  const vData = bytes.slice(cursor, cursor + vLength);

  return {
    ok: true,
    frame: {
      version,
      frame_type: frameType,
      quality,
      dwt_levels: dwtLevels,
      width,
      height,
      uv_width: uvWidth,
      uv_height: uvHeight,
      y_length: yLength,
      u_length: uLength,
      v_length: vLength,
      payload_length: payloadLength,
      total_length: bytes.byteLength,
      y_data: yData,
      u_data: uData,
      v_data: vData,
    },
  };
}
