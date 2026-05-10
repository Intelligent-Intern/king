export const MEDIA_SESSION_PLAN_SCHEMA_VERSION = 'king.video.media_session_plan.v1';

export const CALL_MEDIA_STATE_VALUES = Object.freeze([
  'waiting_for_capabilities',
  'waiting_for_gossip',
  'streaming_720p30',
  'throttled_50',
  'throttled_25',
  'stuck_not_sending',
  'blocked_capability',
  'left',
]);

const STATE_SET = new Set(CALL_MEDIA_STATE_VALUES);
const GOSSIP_TRANSPORT_VALUES = new Set([
  'gossip',
  'gossip_primary',
  'gossip_primary_direct',
  'gossip_rtc_datachannel',
  'planned_gossip',
]);
const SENDING_STATE_VALUES = new Set(['streaming_720p30', 'throttled_50', 'throttled_25']);

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

function normalizeTransport(value: unknown): string {
  return stringValue(value).toLowerCase();
}

export function isMediaSessionPlanGossipTransport(value: unknown): boolean {
  return GOSSIP_TRANSPORT_VALUES.has(normalizeTransport(value));
}

export function normalizeMediaSessionPlanV1(input: Record<string, any> = {}) {
  const participants = Array.isArray(input.participants) ? input.participants : [];

  return {
    schema_version: MEDIA_SESSION_PLAN_SCHEMA_VERSION,
    call_id: stringValue(input.call_id ?? input.callId),
    room_id: stringValue(input.room_id ?? input.roomId),
    plan_epoch: planEpochValue(input.plan_epoch ?? input.planEpoch),
    state_catalog: CALL_MEDIA_STATE_VALUES,
    participants: participants
      .filter((participant) => participant && typeof participant === 'object')
      .map((participant) => ({
        participant_session_id: stringValue(participant.participant_session_id ?? participant.participantSessionId),
        media_state: normalizeState(participant.media_state ?? participant.mediaState),
        profile: stringValue(participant.profile),
        transport: stringValue(participant.transport),
        security_policy: stringValue(participant.security_policy ?? participant.securityPolicy, 'required'),
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
  return participant?.media_state === 'streaming_720p30';
}
