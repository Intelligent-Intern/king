export const MEDIA_SESSION_PLAN_SCHEMA_VERSION = 'king.video.media_session_plan.v1';

export const CALL_MEDIA_STATE_VALUES = Object.freeze([
  'waiting_for_capabilities',
  'waiting_for_gossip',
  'streaming_720p30',
  'throttled_50',
  'throttled_25',
  'stuck_not_sending',
  'audio_only',
  'video_unavailable',
  'blocked_capability',
  'left',
]);

export const MEDIA_SESSION_PLAN_STATE_VALUES = Object.freeze([
  'pending',
  'connecting',
  'gossip_720p30',
  'gossip_360p30',
  'gossip_360p5',
  'ready',
  'failed',
]);

const STATE_SET = new Set(CALL_MEDIA_STATE_VALUES);
const SESSION_STATE_SET = new Set(MEDIA_SESSION_PLAN_STATE_VALUES);
const GOSSIP_TRANSPORT_VALUES = new Set([
  'gossip',
  'gossip_primary',
  'gossip_primary_direct',
  'gossip_rtc_datachannel',
  'planned_gossip',
]);
const SENDING_STATE_VALUES = new Set(['streaming_720p30', 'throttled_50', 'throttled_25']);
const LOCAL_PUBLICATION_STATE_VALUES = new Set([
  'streaming_720p30',
  'throttled_50',
  'throttled_25',
  'audio_only',
]);

function stringValue(value: unknown, fallback = ''): string {
  const text = String(value ?? '').trim();
  return text === '' ? fallback : text.slice(0, 128);
}

function intValue(value: unknown, fallback = 0): number {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.max(0, Math.floor(numeric)) : fallback;
}

function planEpochValue(value: unknown): number {
  return Math.max(1, intValue(value, 1));
}

function normalizeState(value: unknown): string {
  const normalized = stringValue(value, 'waiting_for_capabilities').toLowerCase();
  return STATE_SET.has(normalized) ? normalized : 'blocked_capability';
}

function normalizeSessionState(value: unknown): string {
  const normalized = stringValue(value, 'pending').toLowerCase();
  return SESSION_STATE_SET.has(normalized) ? normalized : 'failed';
}

function normalizeTransport(value: unknown): string {
  return stringValue(value).toLowerCase();
}

function booleanValue(value: unknown): boolean {
  if (value === true || value === false) return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') return ['1', 'true', 'yes', 'on', 'available', 'supported'].includes(value.trim().toLowerCase());
  return false;
}

function normalizeSelectedPlan(input: Record<string, any> = {}) {
  return {
    plan_id: stringValue(input.plan_id ?? input.planId),
    transport: stringValue(input.transport),
    profile: stringValue(input.profile),
    codec_path: stringValue(input.codec_path ?? input.codecPath),
    width: intValue(input.width),
    height: intValue(input.height),
    fps: intValue(input.fps),
    keyframe_interval: intValue(input.keyframe_interval ?? input.keyFrameInterval ?? input.keyframe_cadence ?? input.keyframeCadence),
    render_window_ms: intValue(input.render_window_ms ?? input.renderWindowMs),
    selected_by: stringValue(input.selected_by ?? input.selectedBy),
    selection_gate: stringValue(input.selection_gate ?? input.selectionGate),
    capture_exact: booleanValue(input.capture_exact ?? input.captureExact ?? input.strict_capture ?? input.strictCapture),
    reason: stringValue(input.reason),
    session_state: normalizeSessionState(input.session_state ?? input.sessionState ?? input.plan_id ?? input.planId),
  };
}

function normalizeCapabilitySummary(input: Record<string, any> = {}) {
  const rawByConnectionId = input.by_connection_id ?? input.byConnectionId ?? {};
  const byConnectionId = rawByConnectionId && typeof rawByConnectionId === 'object'
    ? Object.fromEntries(Object.entries(rawByConnectionId).map(([connectionId, value]) => {
      const row = value && typeof value === 'object' ? (value as Record<string, any>) : {};
      return [stringValue(connectionId), {
        camera_720p30: booleanValue(row.camera_720p30 ?? row.camera720p30),
        microphone: booleanValue(row.microphone),
        codec_path: stringValue(row.codec_path ?? row.codecPath, 'unknown'),
        webcodecs: booleanValue(row.webcodecs ?? row.webCodecs),
        wasm: booleanValue(row.wasm),
        gpu: stringValue(row.gpu, 'unknown'),
        mobile: booleanValue(row.mobile),
        browser_family: stringValue(row.browser_family ?? row.browserFamily, 'unknown'),
        video_width: intValue(row.video_width ?? row.videoWidth),
        video_height: intValue(row.video_height ?? row.videoHeight),
        video_fps: intValue(row.video_fps ?? row.videoFps),
        backpressure_ratio: Number.isFinite(Number(row.backpressure_ratio ?? row.backpressureRatio))
          ? Math.max(0, Math.min(1, Number(row.backpressure_ratio ?? row.backpressureRatio)))
          : 0,
        queued_bytes: intValue(row.queued_bytes ?? row.queuedBytes),
        dropped_video_frames: intValue(row.dropped_video_frames ?? row.droppedVideoFrames),
      }];
    }).filter(([connectionId]) => connectionId !== ''))
    : {};

  return {
    participant_count: intValue(input.participant_count ?? input.participantCount),
    camera_720p30_count: intValue(input.camera_720p30_count ?? input.camera720p30Count),
    microphone_count: intValue(input.microphone_count ?? input.microphoneCount),
    wlvc_encoder_count: intValue(input.wlvc_encoder_count ?? input.wlvcEncoderCount),
    webcodecs_count: intValue(input.webcodecs_count ?? input.webCodecsCount),
    wasm_count: intValue(input.wasm_count ?? input.wasmCount),
    mobile_count: intValue(input.mobile_count ?? input.mobileCount),
    max_backpressure_ratio: Number.isFinite(Number(input.max_backpressure_ratio ?? input.maxBackpressureRatio))
      ? Math.max(0, Math.min(1, Number(input.max_backpressure_ratio ?? input.maxBackpressureRatio)))
      : 0,
    queued_bytes_total: intValue(input.queued_bytes_total ?? input.queuedBytesTotal),
    dropped_video_frames_total: intValue(input.dropped_video_frames_total ?? input.droppedVideoFramesTotal),
    by_connection_id: byConnectionId,
    redacted: true,
  };
}

export function isMediaSessionPlanGossipTransport(value: unknown): boolean {
  return GOSSIP_TRANSPORT_VALUES.has(normalizeTransport(value));
}

export function normalizeMediaSessionPlanV1(input: Record<string, any> = {}) {
  const participants = Array.isArray(input.participants) ? input.participants : [];
  const selectedPlan = normalizeSelectedPlan(input.selected_plan ?? input.selectedPlan ?? {});
  const sessionState = normalizeSessionState(input.session_state ?? input.sessionState ?? selectedPlan.session_state);

  return {
    schema_version: MEDIA_SESSION_PLAN_SCHEMA_VERSION,
    call_id: stringValue(input.call_id ?? input.callId),
    room_id: stringValue(input.room_id ?? input.roomId),
    plan_epoch: planEpochValue(input.plan_epoch ?? input.planEpoch),
    state_catalog: CALL_MEDIA_STATE_VALUES,
    session_state_catalog: MEDIA_SESSION_PLAN_STATE_VALUES,
    session_state: sessionState,
    selected_plan: {
      ...selectedPlan,
      session_state: normalizeSessionState(selectedPlan.session_state || sessionState),
    },
    capability_summary: normalizeCapabilitySummary(input.capability_summary ?? input.capabilitySummary ?? {}),
    participants: participants
      .filter((participant) => participant && typeof participant === 'object')
      .map((participant) => ({
        participant_session_id: stringValue(participant.participant_session_id ?? participant.participantSessionId),
        media_state: normalizeState(participant.media_state ?? participant.mediaState),
        profile: stringValue(participant.profile),
        transport: stringValue(participant.transport),
        security_policy: stringValue(participant.security_policy ?? participant.securityPolicy, 'transport_only'),
        stuck_reason: stringValue(participant.stuck_reason ?? participant.stuckReason),
      })),
  };
}

export function normalizeMediaSessionPlanFromSnapshot(snapshot: Record<string, any> = {}) {
  const rawPlan = snapshot.media_session_plan ?? snapshot.mediaSessionPlan ?? {};
  return normalizeMediaSessionPlanV1(rawPlan && typeof rawPlan === 'object' ? rawPlan : {});
}

export function mediaSessionPlanDiagnosticPayload(plan: Record<string, any> = {}) {
  const normalized = normalizeMediaSessionPlanV1(plan);
  const stateCounts = Object.fromEntries(CALL_MEDIA_STATE_VALUES.map((state) => [state, 0]));
  for (const participant of normalized.participants) {
    stateCounts[participant.media_state] = (stateCounts[participant.media_state] || 0) + 1;
  }

  return {
    schema_version: normalized.schema_version,
    call_id: normalized.call_id,
    room_id: normalized.room_id,
    plan_epoch: normalized.plan_epoch,
    participant_count: normalized.participants.length,
    session_state: normalized.session_state,
    state_counts: stateCounts,
  };
}

export function findMediaSessionPlanParticipant(plan: Record<string, any> = {}, participantSessionId = '') {
  const normalized = normalizeMediaSessionPlanV1(plan);
  const sessionId = stringValue(participantSessionId);
  if (sessionId === '') return null;

  return normalized.participants.find((participant) => (
    participant.participant_session_id === sessionId
  )) || null;
}

export function mediaSessionPlanHasGossipTransport(plan: Record<string, any> = {}, {
  participantSessionId = '',
  sendingOnly = true,
}: Record<string, any> = {}) {
  const normalized = normalizeMediaSessionPlanV1(plan);
  const sessionId = stringValue(participantSessionId);
  const participants = sessionId !== ''
    ? normalized.participants.filter((participant) => participant.participant_session_id === sessionId)
    : normalized.participants;

  return participants.some((participant) => (
    isMediaSessionPlanGossipTransport(participant.transport)
    && (sendingOnly !== true || SENDING_STATE_VALUES.has(participant.media_state))
  ));
}

export function selectedMediaSessionPlanProfile(plan: Record<string, any> = {}) {
  const normalized = normalizeMediaSessionPlanV1(plan);
  const selected = normalized.selected_plan || {};
  return {
    plan_id: selected.plan_id,
    transport: selected.transport,
    profile: selected.profile,
    codec_path: selected.codec_path,
    width: selected.width,
    height: selected.height,
    fps: selected.fps,
    keyframe_interval: selected.keyframe_interval,
    render_window_ms: selected.render_window_ms,
    selected_by: selected.selected_by,
    selection_gate: selected.selection_gate,
    capture_exact: selected.capture_exact,
    reason: selected.reason,
    session_state: selected.session_state,
    plan_epoch: normalized.plan_epoch,
  };
}

export function mediaSessionPlanAllowsLocalPublication(plan: Record<string, any> = {}, {
  callId = '',
  roomId = '',
  participantSessionId = '',
  minPlanEpoch = 1,
}: Record<string, any> = {}) {
  const normalized = normalizeMediaSessionPlanV1(plan);
  const normalizedCallId = stringValue(callId);
  const normalizedRoomId = stringValue(roomId);
  if (normalizedCallId !== '' && normalized.call_id !== normalizedCallId) return false;
  if (normalizedRoomId !== '' && normalized.room_id !== normalizedRoomId) return false;
  if (normalized.plan_epoch < planEpochValue(minPlanEpoch)) return false;

  const participant = findMediaSessionPlanParticipant(normalized, participantSessionId);
  return LOCAL_PUBLICATION_STATE_VALUES.has(participant?.media_state || '');
}
