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

export function redactClientCapabilitiesV1(input: Record<string, any> = {}) {
  const media = input.media && typeof input.media === 'object' ? input.media : {};
  const runtime = input.runtime && typeof input.runtime === 'object' ? input.runtime : {};
  const constraints = input.constraints && typeof input.constraints === 'object' ? input.constraints : {};

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
    constraints: {
      video_width: positiveInt(constraints.video_width ?? constraints.videoWidth),
      video_height: positiveInt(constraints.video_height ?? constraints.videoHeight),
      video_fps: positiveInt(constraints.video_fps ?? constraints.videoFps),
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
      websocket: typeof WebSocket === 'function',
      webrtc: Boolean(runtime?.stageB || runtime?.webRtcNative),
      webassembly: Boolean(runtime?.wlvcWasm?.webAssembly),
      webcodecs: typeof VideoEncoder === 'function' && typeof VideoDecoder === 'function',
      gpu: 'available_or_unknown',
      wlvc_encoder: Boolean(runtime?.wlvcWasm?.encoder),
      wlvc_decoder: Boolean(runtime?.wlvcWasm?.decoder),
    },
    constraints,
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
