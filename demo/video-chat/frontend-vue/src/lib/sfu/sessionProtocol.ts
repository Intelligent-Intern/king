import type { SFUClientCallbacks, SFUTrack } from './sfuTypes'

export const SFU_SESSION_PROTOCOL = 'king_sfu_session_v1'
export const SFU_JOIN_VISIBLE_SLO_MS = 1000

const RUNTIME_PATHS = ['wlvc_sfu', 'webrtc_native']
const CODECS = ['wlvc_wasm', 'wlvc_ts', 'wlvc_unknown']
const MEDIA_TRANSPORTS = ['websocket_binary_media_fallback']
const CONTROL_TRANSPORTS = ['websocket_sfu_control']
const TRACK_KINDS = ['audio', 'video', 'screen']
const FEATURES = [
  'fast_first_frame',
  'keyframe_first',
  'delta_after_keyframe',
  'screen_as_participant',
  'protected_media',
  'binary_envelope',
]

export interface SfuSessionAcceptedDetails {
  protocolName: string
  protocolVersion: number
  joinVisibleSloMs: number
  selectedRuntimePath: string
  selectedCodecId: string
  fastFirstFrame: boolean
  raw: Record<string, unknown>
}

function numberField(value: unknown, fallback = 0): number {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : fallback
}

function stringField(value: unknown, fallback = ''): string {
  const normalized = String(value ?? '').trim()
  return normalized === '' ? fallback : normalized
}

function objectField(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {}
}

export function buildSfuSessionHelloPayload(args: {
  roomId: string
  callId?: string
  userId?: string
  name?: string
  startedAtMs?: number
}): Record<string, unknown> {
  return {
    type: 'sfu/session-hello',
    room_id: args.roomId,
    call_id: stringField(args.callId),
    user_id: stringField(args.userId),
    name: stringField(args.name),
    session_protocol: SFU_SESSION_PROTOCOL,
    protocol_versions: [1],
    client_join_started_at_ms: numberField(args.startedAtMs, Date.now()),
    join_visible_slo_ms: SFU_JOIN_VISIBLE_SLO_MS,
    runtime_paths: RUNTIME_PATHS,
    codecs: CODECS,
    control_transports: CONTROL_TRANSPORTS,
    media_transports: MEDIA_TRANSPORTS,
    track_kinds: TRACK_KINDS,
    features: FEATURES,
  }
}

export function buildSfuTrackPublishPayload(
  track: SFUTrack,
  sessionAccepted: SfuSessionAcceptedDetails | null,
): Record<string, unknown> {
  return {
    type: 'sfu/publish',
    track_id: track.id,
    kind: track.kind,
    label: track.label,
    session_protocol: SFU_SESSION_PROTOCOL,
    session_protocol_version: sessionAccepted?.protocolVersion || 1,
    capabilities: {
      expected_codecs: CODECS,
      first_frame_policy: 'keyframe_or_full_frame',
      fast_first_frame: true,
      max_first_frame_wait_ms: SFU_JOIN_VISIBLE_SLO_MS,
    },
  }
}

export function normalizeSfuSessionAcceptedMessage(msg: Record<string, unknown>): SfuSessionAcceptedDetails {
  const selected = objectField(msg.selected)
  return {
    protocolName: stringField(msg.session_protocol, SFU_SESSION_PROTOCOL),
    protocolVersion: numberField(msg.protocol_version, 1),
    joinVisibleSloMs: numberField(msg.join_visible_slo_ms, SFU_JOIN_VISIBLE_SLO_MS),
    selectedRuntimePath: stringField(selected.runtime_path, 'wlvc_sfu'),
    selectedCodecId: stringField(selected.codec_id, 'wlvc_wasm'),
    fastFirstFrame: selected.fast_first_frame !== false,
    raw: msg,
  }
}

export function buildSfuJoinLatencySample(
  stage: string,
  startedAtMs: number,
  details: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    ...details,
    stage,
    elapsed_ms: Math.max(0, Math.round(Date.now() - startedAtMs)),
    join_visible_slo_ms: SFU_JOIN_VISIBLE_SLO_MS,
  }
}

export function handleSfuSessionProtocolMessage(
  msg: Record<string, unknown>,
  args: {
    roomId: string
    startedAtMs: number
    callbacks: Pick<SFUClientCallbacks, 'onSessionAccepted' | 'onTrackAccepted' | 'onJoinLatencySample'>
  },
): { handled: boolean; accepted: SfuSessionAcceptedDetails | null } {
  const msgType = stringField(msg.type)
  if (msgType === 'sfu/session-accepted') {
    const accepted = normalizeSfuSessionAcceptedMessage(msg)
    args.callbacks.onSessionAccepted?.(accepted as unknown as Record<string, unknown>)
    args.callbacks.onJoinLatencySample?.(buildSfuJoinLatencySample('sfu_session_accepted', args.startedAtMs, {
      room_id: args.roomId,
      protocol_version: accepted.protocolVersion,
      selected_runtime_path: accepted.selectedRuntimePath,
      selected_codec_id: accepted.selectedCodecId,
      fast_first_frame: accepted.fastFirstFrame,
    }))
    return { handled: true, accepted }
  }

  if (msgType === 'sfu/track-accepted') {
    args.callbacks.onTrackAccepted?.(msg)
    return { handled: true, accepted: null }
  }

  return { handled: false, accepted: null }
}
