import { createWasmDecoder, createWasmEncoder } from '../../lib/wasm/wasm-codec';

function hasWebRtcBaseSupport() {
  if (typeof window === 'undefined' || typeof navigator === 'undefined') return false;
  const hasPeerConnection = typeof window.RTCPeerConnection === 'function';
  const hasMediaDevices = Boolean(
    navigator.mediaDevices
    && typeof navigator.mediaDevices.getUserMedia === 'function'
  );
  const hasWebSocket = typeof window.WebSocket === 'function';
  return hasPeerConnection && hasMediaDevices && hasWebSocket;
}

async function probeWlvcWasm() {
  const baseSupported = typeof WebAssembly === 'object' && typeof WebAssembly.instantiate === 'function';
  if (!baseSupported) {
    return {
      webAssembly: false,
      encoder: false,
      decoder: false,
      reason: 'webassembly_unavailable',
    };
  }

  let encoder = null;
  let decoder = null;
  let encoderOk = false;
  let decoderOk = false;
  try {
    try {
      encoder = await createWasmEncoder({ width: 64, height: 64, quality: 60, keyFrameInterval: 2 });
      encoderOk = Boolean(encoder);
    } catch {
      encoderOk = false;
    }
    try {
      decoder = await createWasmDecoder({ width: 64, height: 64, quality: 60 });
      decoderOk = Boolean(decoder);
    } catch {
      decoderOk = false;
    }

    let reason = 'wlvc_wasm_probe_failed';
    if (encoderOk && decoderOk) {
      reason = 'ok';
    } else if (encoderOk && !decoderOk) {
      reason = 'wlvc_decoder_probe_failed';
    } else if (!encoderOk && decoderOk) {
      reason = 'wlvc_encoder_probe_failed';
    }

    return {
      webAssembly: true,
      encoder: encoderOk,
      decoder: decoderOk,
      reason,
    };
  } catch {
    return {
      webAssembly: true,
      encoder: false,
      decoder: false,
      reason: 'wlvc_wasm_probe_failed',
    };
  } finally {
    try {
      encoder?.destroy?.();
    } catch {
      // ignore cleanup errors
    }
    try {
      decoder?.destroy?.();
    } catch {
      // ignore cleanup errors
    }
  }
}

export async function detectMediaRuntimeCapabilities() {
  const webRtcNative = hasWebRtcBaseSupport();
  const wlvcWasm = await probeWlvcWasm();
  // Stage A requires a working WASM encoder; decoder readiness is handled
  // lazily when remote SFU tracks are subscribed.
  const stageA = Boolean(wlvcWasm.encoder);
  const stageB = Boolean(webRtcNative);

  let preferredPath = 'unsupported';
  if (stageA) {
    preferredPath = 'wlvc_wasm';
  } else if (stageB) {
    preferredPath = 'webrtc_native';
  }

  const reasons = [];
  if (!stageA) reasons.push(wlvcWasm.reason);
  if (!stageB) reasons.push('webrtc_native_unavailable');

  return {
    checkedAt: new Date().toISOString(),
    wlvcWasm,
    webRtcNative,
    stageA,
    stageB,
    preferredPath,
    reasons,
  };
}
