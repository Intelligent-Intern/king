import { detectPublisherCapturePipelineCapabilities } from '../local/capturePipelineCapabilities.ts';
import { detectMediaRuntimeCapabilities } from './runtimeCapabilities.ts';
import {
  strict720p30CapabilitySupported,
  strict720p30Constraints,
} from '../workspace/callWorkspace/strictStabilityPolicy.ts';

export const CLIENT_CAPABILITIES_SCHEMA_VERSION = 'king.video.client_capabilities.v1';
export const CLIENT_CAPABILITIES_COMMAND_TYPE = 'client/capabilities.v1';

const GPU_VALUES = new Set(['available', 'unavailable', 'available_or_unknown', 'unknown']);

function booleanValue(value: unknown): boolean {
  if (value === true || value === false) return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'on', 'available', 'supported'].includes(value.trim().toLowerCase());
  }
  return false;
}

function positiveInt(value: unknown): number {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric <= 0) return 0;
  return Math.min(8192, Math.floor(numeric));
}

function boundedFloat(value: unknown, max = 10000): number {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric <= 0) return 0;
  return Math.min(max, Math.round(numeric * 1000) / 1000);
}

function safeIdentifier(value: unknown, fallback = 'unknown'): string {
  const normalized = String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 96);
  return normalized || fallback;
}

function normalizeGpu(value: unknown): string {
  const normalized = safeIdentifier(value, 'unknown');
  return GPU_VALUES.has(normalized) ? normalized : 'unknown';
}

function browserFamilyFromUserAgent(userAgent = ''): string {
  const ua = String(userAgent || '').toLowerCase();
  if (ua.includes('edg/')) return 'edge';
  if (ua.includes('firefox/')) return 'firefox';
  if (ua.includes('chrome/') || ua.includes('chromium/')) return 'chromium';
  if (ua.includes('safari/')) return 'safari';
  return 'unknown';
}

function mobileFromUserAgent(userAgent = ''): boolean {
  return /android|iphone|ipad|ipod|mobile/i.test(String(userAgent || ''));
}

function networkSnapshot(navigatorRef: any = {}) {
  const connection = navigatorRef?.connection || navigatorRef?.mozConnection || navigatorRef?.webkitConnection || {};
  return {
    effective_type: safeIdentifier(connection.effectiveType || 'unknown'),
    downlink_mbps: boundedFloat(connection.downlink || 0, 1000),
    rtt_ms: positiveInt(connection.rtt || 0),
    save_data: booleanValue(connection.saveData || false),
    backpressure: {
      ratio: 0,
      queued_bytes: 0,
      dropped_video_frames: 0,
    },
  };
}

export function redactClientCapabilitiesV1(input: Record<string, any> = {}) {
  const media = input.media && typeof input.media === 'object' ? input.media : {};
  const runtime = input.runtime && typeof input.runtime === 'object' ? input.runtime : {};
  const codec = input.codec && typeof input.codec === 'object' ? input.codec : {};
  const constraints = input.constraints && typeof input.constraints === 'object' ? input.constraints : {};
  const client = input.client && typeof input.client === 'object' ? input.client : {};
  const network = input.network && typeof input.network === 'object' ? input.network : {};
  const backpressure = network.backpressure && typeof network.backpressure === 'object' ? network.backpressure : {};

  return {
    schema_version: CLIENT_CAPABILITIES_SCHEMA_VERSION,
    participant_session_id: String(input.participant_session_id || input.participantSessionId || '').slice(0, 128),
    media: {
      camera: booleanValue(media.camera),
      camera_720p30: booleanValue(media.camera_720p30 ?? media.camera720p30),
      microphone: booleanValue(media.microphone),
      screen_share: booleanValue(media.screen_share ?? media.screenShare),
    },
    runtime: {
      websocket: booleanValue(runtime.websocket),
      webrtc: booleanValue(runtime.webrtc),
      webassembly: booleanValue(runtime.webassembly ?? runtime.webAssembly),
      webcodecs: booleanValue(runtime.webcodecs ?? runtime.webCodecs),
      gpu: normalizeGpu(runtime.gpu),
      wlvc_encoder: booleanValue(runtime.wlvc_encoder ?? runtime.wlvcEncoder),
      wlvc_decoder: booleanValue(runtime.wlvc_decoder ?? runtime.wlvcDecoder),
    },
    codec: {
      preferred_path: safeIdentifier(codec.preferred_path ?? codec.preferredPath ?? runtime.codec_path ?? runtime.codecPath ?? 'wlvc_wasm'),
      webcodecs: booleanValue(codec.webcodecs ?? codec.webCodecs ?? runtime.webcodecs ?? runtime.webCodecs),
      wasm: booleanValue(codec.wasm ?? codec.webassembly ?? runtime.webassembly ?? runtime.webAssembly),
    },
    constraints: {
      video_width: positiveInt(constraints.video_width ?? constraints.videoWidth),
      video_height: positiveInt(constraints.video_height ?? constraints.videoHeight),
      video_fps: positiveInt(constraints.video_fps ?? constraints.videoFps),
      mobile: booleanValue(constraints.mobile ?? client.mobile),
      browser_family: safeIdentifier(constraints.browser_family ?? constraints.browserFamily ?? client.browser_family ?? client.browserFamily),
    },
    network: {
      effective_type: safeIdentifier(network.effective_type ?? network.effectiveType),
      downlink_mbps: boundedFloat(network.downlink_mbps ?? network.downlinkMbps, 1000),
      rtt_ms: positiveInt(network.rtt_ms ?? network.rttMs),
      save_data: booleanValue(network.save_data ?? network.saveData),
      backpressure: {
        ratio: boundedFloat(backpressure.ratio, 1),
        queued_bytes: positiveInt(backpressure.queued_bytes ?? backpressure.queuedBytes),
        dropped_video_frames: positiveInt(backpressure.dropped_video_frames ?? backpressure.droppedVideoFrames),
      },
    },
  };
}

export async function buildClientCapabilitiesV1({
  participantSessionId = '',
  runtimeCapabilities = null,
  captureCapabilities = null,
  globalScope,
  documentRef,
}: Record<string, any> = {}) {
  const scope = globalScope && typeof globalScope === 'object' ? globalScope : globalThis;
  const navigatorRef = (scope as any)?.navigator ?? (typeof navigator !== 'undefined' ? navigator : {});
  const runtime = runtimeCapabilities && typeof runtimeCapabilities === 'object'
    ? runtimeCapabilities
    : await detectMediaRuntimeCapabilities();
  const capture = captureCapabilities && typeof captureCapabilities === 'object'
    ? captureCapabilities
    : detectPublisherCapturePipelineCapabilities({ globalScope, documentRef });
  const constraints = strict720p30Constraints();
  const hasStrict720p30CapturePath = Boolean(capture?.hasWorkerCapturePath && runtime?.stageA);

  const capabilities = redactClientCapabilitiesV1({
    participant_session_id: participantSessionId,
    media: {
      camera: hasStrict720p30CapturePath,
      camera_720p30: hasStrict720p30CapturePath,
      microphone: Boolean(runtime?.stageB),
      screen_share: Boolean(runtime?.stageB),
    },
    runtime: {
      websocket: typeof (scope as any)?.WebSocket === 'function',
      webrtc: Boolean(runtime?.stageB || runtime?.webRtcNative),
      webassembly: Boolean(runtime?.wlvcWasm?.webAssembly),
      webcodecs: typeof (scope as any)?.VideoEncoder === 'function' && typeof (scope as any)?.VideoDecoder === 'function',
      gpu: 'available_or_unknown',
      wlvc_encoder: Boolean(runtime?.wlvcWasm?.encoder),
      wlvc_decoder: Boolean(runtime?.wlvcWasm?.decoder),
    },
    codec: {
      preferred_path: runtime?.preferredPath || 'unsupported',
      webcodecs: typeof (scope as any)?.VideoEncoder === 'function' && typeof (scope as any)?.VideoDecoder === 'function',
      wasm: Boolean(runtime?.wlvcWasm?.webAssembly),
    },
    constraints,
    client: {
      mobile: mobileFromUserAgent(navigatorRef?.userAgent || ''),
      browser_family: browserFamilyFromUserAgent(navigatorRef?.userAgent || ''),
    },
    network: networkSnapshot(navigatorRef),
  });

  if (!strict720p30CapabilitySupported(capabilities)) {
    capabilities.media.camera_720p30 = false;
  }
  return capabilities;
}

export function buildClientCapabilitiesFrame(payload: Record<string, any>, {
  roomId = '',
  callId = '',
  reason = 'capability_probe',
}: Record<string, string> = {}) {
  return {
    type: CLIENT_CAPABILITIES_COMMAND_TYPE,
    schema_version: CLIENT_CAPABILITIES_SCHEMA_VERSION,
    room_id: safeIdentifier(roomId, ''),
    call_id: safeIdentifier(callId, ''),
    reason: safeIdentifier(reason, 'capability_probe'),
    ...redactClientCapabilitiesV1(payload),
  };
}
