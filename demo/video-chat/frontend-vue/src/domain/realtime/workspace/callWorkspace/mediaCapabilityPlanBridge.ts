import {
  buildClientCapabilitiesFrame,
  buildClientCapabilitiesV1,
} from '../../media/clientCapabilities.ts';
import {
  mediaSessionPlanAllowsLocalPublication,
  mediaSessionPlanDiagnosticPayload,
  normalizeMediaSessionPlanFromSnapshot,
  normalizeMediaSessionPlanV1,
} from '../../media/mediaSessionPlan.ts';

function refValue(value: any): any {
  return value && typeof value === 'object' && 'value' in value ? value.value : value;
}

function stringValue(value: any, fallback = ''): string {
  const text = String(refValue(value) ?? '').trim();
  return text === '' ? fallback : text.slice(0, 128);
}

function firstStringValue(...values: any[]): string {
  for (const value of values) {
    const text = stringValue(value);
    if (text !== '') return text;
  }
  return '';
}

function snapshotHasMediaSessionPlan(snapshot: any): boolean {
  return Boolean(
    snapshot
      && typeof snapshot === 'object'
      && (
        (snapshot.media_session_plan && typeof snapshot.media_session_plan === 'object')
        || (snapshot.mediaSessionPlan && typeof snapshot.mediaSessionPlan === 'object')
      ),
  );
}

export function hasSnapshotMediaSessionPlan(snapshot: any): boolean {
  return snapshotHasMediaSessionPlan(snapshot);
}

function payloadType(payload: Record<string, any> = {}): string {
  return stringValue(payload.type).toLowerCase();
}

function isAdmittedWebsocketJoinPayload(payload: Record<string, any> = {}): boolean {
  const type = payloadType(payload);
  if (type !== 'system/welcome') return false;
  const admission = payload.admission && typeof payload.admission === 'object' ? payload.admission : {};
  return admission.requires_admission !== true;
}

function stableJson(value: any): string {
  if (value === null || typeof value !== 'object') return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map((entry) => stableJson(entry)).join(',')}]`;
  return `{${Object.keys(value).sort().map((key) => (
    `${JSON.stringify(key)}:${stableJson(value[key])}`
  )).join(',')}}`;
}

export function resolveClientCapabilitiesContext(refs: Record<string, any> = {}, payload: Record<string, any> = {}) {
  return {
    callId: firstStringValue(
      payload.call_id,
      payload.callId,
      payload.viewer?.call_id,
      payload.call_context?.call_id,
      refs.activeSocketCallId,
      refs.activeCallId,
    ),
    roomId: firstStringValue(
      payload.room_id,
      payload.roomId,
      payload.active_room_id,
      refs.serverRoomId,
      refs.desiredRoomId,
    ),
    participantSessionId: firstStringValue(
      payload.participant_session_id,
      payload.participantSessionId,
      payload.connection_id,
      payload.call_context?.participant_session_id,
      refs.participantSessionId,
    ),
  };
}

export function createCallWorkspaceMediaCapabilityBridge({
  callbacks = {},
  refs = {},
  buildClientCapabilities = buildClientCapabilitiesV1,
  buildFrame = buildClientCapabilitiesFrame,
}: Record<string, any> = {}) {
  const captureClientDiagnostic = typeof callbacks.captureClientDiagnostic === 'function'
    ? callbacks.captureClientDiagnostic
    : () => {};
  let admittedWebsocketJoin = false;
  let admittedWebsocketJoinKey = '';
  let capabilitySendInFlightKey = '';
  let lastCapabilitySendAttemptKey = '';
  let lastMediaSessionPlan = normalizeMediaSessionPlanV1({});
  let lastMediaSessionPlanDiagnostic = mediaSessionPlanDiagnosticPayload(lastMediaSessionPlan);
  let lastCapabilityPlanGateContext = {
    callId: '',
    roomId: '',
    participantSessionId: '',
    minPlanEpoch: 1,
  };

  function capabilitySendKey(context: Record<string, string>): string {
    return [
      context.callId || 'unknown_call',
      context.roomId || 'unknown_room',
      context.participantSessionId || 'server_connection',
    ].join('|');
  }

  function capabilityChangeKey(context: Record<string, string>, frame: Record<string, any>): string {
    return [
      capabilitySendKey(context),
      stableJson({
        participant_session_id: frame.participant_session_id,
        media: frame.media,
        runtime: frame.runtime,
        constraints: frame.constraints,
      }),
    ].join('|');
  }

  function captureCapabilityDiagnostic(frame: Record<string, any>, reason: string) {
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'client_capabilities_sent',
      code: 'client_capabilities_sent',
      message: 'Client media capabilities were published to realtime.',
      payload: {
        schema_version: frame.schema_version,
        call_id: frame.call_id,
        room_id: frame.room_id,
        reason,
        media: frame.media,
        runtime: frame.runtime,
        constraints: frame.constraints,
      },
      immediate: true,
    });
  }

  async function sendClientCapabilities(reason = 'capability_probe', sourcePayload: Record<string, any> = {}) {
    if (typeof refs.sendSocketFrame !== 'function') return false;
    const context = resolveClientCapabilitiesContext(refs, sourcePayload);
    const key = capabilitySendKey(context);
    if (isAdmittedWebsocketJoinPayload(sourcePayload)) {
      if (admittedWebsocketJoinKey !== key) {
        lastCapabilitySendAttemptKey = '';
      }
      admittedWebsocketJoin = true;
      admittedWebsocketJoinKey = key;
    }
    if (!admittedWebsocketJoin) return false;
    if (capabilitySendInFlightKey === key) return false;

    capabilitySendInFlightKey = key;
    try {
      const capabilities = await buildClientCapabilities({
        participantSessionId: context.participantSessionId,
      });
      const frame = buildFrame(capabilities, {
        callId: context.callId,
        roomId: context.roomId,
        reason,
      });
      const changeKey = capabilityChangeKey(context, frame);
      if (lastCapabilitySendAttemptKey === changeKey) return false;
      lastCapabilitySendAttemptKey = changeKey;
      const sent = refs.sendSocketFrame(frame) === true;
      if (sent) {
        lastCapabilityPlanGateContext = {
          ...context,
          minPlanEpoch: lastMediaSessionPlan.plan_epoch,
        };
        captureCapabilityDiagnostic(frame, reason);
      }
      return sent;
    } catch (error) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'client_capabilities_send_failed',
        code: 'client_capabilities_send_failed',
        message: error instanceof Error ? error.message : 'Client media capability publication failed.',
        payload: {
          call_id: context.callId,
          room_id: context.roomId,
          reason,
        },
        immediate: true,
      });
      return false;
    } finally {
      if (capabilitySendInFlightKey === key) {
        capabilitySendInFlightKey = '';
      }
    }
  }

  function handleRoomSnapshotMediaSessionPlan(snapshot: Record<string, any> = {}) {
    const mediaSessionPlanPresent = snapshotHasMediaSessionPlan(snapshot);
    lastMediaSessionPlan = normalizeMediaSessionPlanFromSnapshot(snapshot);
    lastMediaSessionPlanDiagnostic = {
      ...mediaSessionPlanDiagnosticPayload(lastMediaSessionPlan),
      media_session_plan_present: mediaSessionPlanPresent,
    };
    captureClientDiagnostic({
      category: 'media',
      level: mediaSessionPlanPresent ? 'info' : 'warning',
      eventType: mediaSessionPlanPresent ? 'media_session_plan_received' : 'media_session_plan_missing',
      code: mediaSessionPlanPresent ? 'media_session_plan_received' : 'media_session_plan_missing',
      message: mediaSessionPlanPresent
        ? 'Realtime room snapshot included a normalized media session plan.'
        : 'Realtime room snapshot did not include a media session plan.',
      payload: lastMediaSessionPlanDiagnostic,
      immediate: false,
    });
    return lastMediaSessionPlan;
  }

  function getLastMediaSessionPlan() {
    return lastMediaSessionPlan;
  }

  function getLastMediaSessionPlanDiagnostic() {
    return lastMediaSessionPlanDiagnostic;
  }

  function canPublishLocalMediaForLastPlan(sourcePayload: Record<string, any> = {}) {
    const context = {
      ...lastCapabilityPlanGateContext,
      ...Object.fromEntries(
        Object.entries(resolveClientCapabilitiesContext(refs, sourcePayload))
          .filter(([, value]) => stringValue(value) !== ''),
      ),
    };
    return mediaSessionPlanAllowsLocalPublication(lastMediaSessionPlan, {
      callId: context.callId,
      roomId: context.roomId,
      participantSessionId: context.participantSessionId,
      minPlanEpoch: context.minPlanEpoch,
    });
  }

  return {
    canPublishLocalMediaForLastPlan,
    getLastMediaSessionPlan,
    getLastMediaSessionPlanDiagnostic,
    handleRoomSnapshotMediaSessionPlan,
    sendClientCapabilities,
  };
}
