export const MEDIA_SESSION_PLAN_SCHEMA_VERSION = 'king.video.media_session_plan.v1';

export const CALL_MEDIA_STATE_VALUES = Object.freeze([
  'waiting_for_capabilities',
  'sending_720p30',
  'receive_only',
  'video_unavailable',
  'blocked_capability',
  'left',
]);

const STATE_SET = new Set(CALL_MEDIA_STATE_VALUES);

function stringValue(value: unknown, fallback = ''): string {
  const text = String(value ?? '').trim();
  return text === '' ? fallback : text.slice(0, 128);
}

function intValue(value: unknown, fallback = 0): number {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.max(0, Math.floor(numeric)) : fallback;
}

function normalizeState(value: unknown): string {
  const normalized = stringValue(value, 'waiting_for_capabilities').toLowerCase();
  return STATE_SET.has(normalized) ? normalized : 'blocked_capability';
}

export function normalizeMediaSessionPlanV1(input: Record<string, any> = {}) {
  const participants = Array.isArray(input.participants) ? input.participants : [];

  return {
    schema_version: MEDIA_SESSION_PLAN_SCHEMA_VERSION,
    call_id: stringValue(input.call_id ?? input.callId),
    room_id: stringValue(input.room_id ?? input.roomId),
    plan_epoch: intValue(input.plan_epoch ?? input.planEpoch, 1),
    participants: participants
      .filter((participant) => participant && typeof participant === 'object')
      .map((participant) => ({
        participant_session_id: stringValue(participant.participant_session_id ?? participant.participantSessionId),
        media_state: normalizeState(participant.media_state ?? participant.mediaState),
        profile: stringValue(participant.profile),
        transport: stringValue(participant.transport),
        security_policy: stringValue(participant.security_policy ?? participant.securityPolicy, 'required'),
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
