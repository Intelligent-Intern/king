import {
  buildClientCapabilitiesFrame,
  buildClientCapabilitiesV1,
} from '../../media/clientCapabilities.ts';
import {
  findMediaSessionPlanParticipant,
  mediaSessionPlanHasGossipTransport,
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

function intValue(value: any, fallback = 0): number {
  const numeric = Number(refValue(value));
  return Number.isFinite(numeric) ? Math.max(0, Math.floor(numeric)) : fallback;
}

function planEpochValue(value: any, fallback = 1): number {
  return Math.max(1, intValue(value, fallback));
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

function payloadClientCapabilities(payload: Record<string, any> = {}) {
  const capabilities = payload.client_capabilities ?? payload.clientCapabilities ?? {};
  return capabilities && typeof capabilities === 'object' ? capabilities : {};
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

let activeMediaCapabilityPlanBridge: any = null;
let activeLocalMediaPublicationCallbacks: Record<string, any> = {
  publishLocalTracks: null,
  stopPlanBlockedLocalMedia: null,
};

export function registerMediaPlanLocalPublicationCallbacks(callbacks: Record<string, any> = {}) {
  activeLocalMediaPublicationCallbacks = {
    publishLocalTracks: typeof callbacks.publishLocalTracks === 'function' ? callbacks.publishLocalTracks : null,
    stopPlanBlockedLocalMedia: typeof callbacks.stopPlanBlockedLocalMedia === 'function'
      ? callbacks.stopPlanBlockedLocalMedia
      : null,
  };
  return () => {
    if (activeLocalMediaPublicationCallbacks.publishLocalTracks === callbacks.publishLocalTracks) {
      activeLocalMediaPublicationCallbacks = {
        publishLocalTracks: null,
        stopPlanBlockedLocalMedia: null,
      };
    }
  };
}

export function canPublishLocalMediaForActivePlan(sourcePayload: Record<string, any> = {}) {
  return activeMediaCapabilityPlanBridge?.canPublishLocalMediaForLastPlan?.(sourcePayload) === true;
}

export function activeMediaSessionPlanHasGossipTransport(sourcePayload: Record<string, any> = {}) {
  return activeMediaCapabilityPlanBridge?.mediaSessionPlanHasGossipTransportForLastPlan?.(sourcePayload) === true;
}

export function requestLocalMediaPublicationForActivePlan(
  reason = 'media_session_plan_gate',
  sourcePayload: Record<string, any> = {},
  publishLocalMediaOverride: any = null,
) {
  if (!activeMediaCapabilityPlanBridge) return Promise.resolve(false);
  return activeMediaCapabilityPlanBridge.requestLocalMediaPublicationForLastPlan(
    reason,
    sourcePayload,
    publishLocalMediaOverride,
  );
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
  const publishLocalTracks = typeof callbacks.publishLocalTracks === 'function'
    ? callbacks.publishLocalTracks
    : null;
  const stopPlanBlockedLocalMedia = typeof callbacks.stopPlanBlockedLocalMedia === 'function'
    ? callbacks.stopPlanBlockedLocalMedia
    : null;
  let admittedWebsocketJoin = false;
  let admittedWebsocketJoinKey = '';
  let capabilitySendInFlightKey = '';
  let lastCapabilitySendAttemptKey = '';
  let lastCapabilityAckStoredKey = '';
  let lastCapabilityAckPlanEpoch = 0;
  let lastMediaSessionPlan = normalizeMediaSessionPlanV1({});
  let lastMediaSessionPlanDiagnostic = mediaSessionPlanDiagnosticPayload(lastMediaSessionPlan);
  let lastCapabilityPlanGateContext = {
    callId: '',
    roomId: '',
    participantSessionId: '',
    minPlanEpoch: 1,
  };
  let pendingLocalMediaPublication = false;
  let localMediaPublicationInFlight = false;
  let localMediaPublicationStarted = false;
  let lastLocalPublicationBlockedDiagnosticKey = '';
  let lastLocalPublicationStateDiagnosticKey = '';

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

  function localMediaGateContext(sourcePayload: Record<string, any> = {}) {
    const resolvedContext = resolveClientCapabilitiesContext(refs, sourcePayload);
    return {
      ...lastCapabilityPlanGateContext,
      ...Object.fromEntries(
        Object.entries(resolvedContext)
          .filter(([, value]) => stringValue(value) !== ''),
      ),
      minPlanEpoch: Math.max(
        planEpochValue(lastCapabilityPlanGateContext.minPlanEpoch),
        planEpochValue(lastCapabilityAckPlanEpoch || 1),
      ),
    };
  }

  function localMediaGateDiagnosticPayload(context: Record<string, any>, reason = '') {
    const participant = findMediaSessionPlanParticipant(lastMediaSessionPlan, context.participantSessionId);
    const key = capabilitySendKey(context);
    return {
      schema_version: lastMediaSessionPlan.schema_version,
      call_id: context.callId,
      room_id: context.roomId,
      participant_session_id: context.participantSessionId,
      plan_epoch: lastMediaSessionPlan.plan_epoch,
      min_plan_epoch: context.minPlanEpoch,
      participant_media_state: participant?.media_state || '',
      socket_online: !('isSocketOnline' in refs) || refValue(refs.isSocketOnline) === true,
      admitted_websocket_join: admittedWebsocketJoin && admittedWebsocketJoinKey === key,
      capability_ack_stored: lastCapabilityAckStoredKey === key,
      pending_local_media_publication: pendingLocalMediaPublication,
      reason: stringValue(reason, 'media_session_plan_gate'),
    };
  }

  function captureLocalPublicationGateDiagnostic({
    context,
    eventType,
    level = 'info',
    message,
    reason,
    immediate = false,
  }: Record<string, any>) {
    const diagnosticKey = [
      eventType,
      context.callId,
      context.roomId,
      context.participantSessionId,
      context.minPlanEpoch,
      lastMediaSessionPlan.plan_epoch,
      lastCapabilityAckStoredKey,
      admittedWebsocketJoinKey,
    ].join('|');
    if (eventType === 'media_session_plan_local_publication_blocked') {
      if (lastLocalPublicationBlockedDiagnosticKey === diagnosticKey) return;
      lastLocalPublicationBlockedDiagnosticKey = diagnosticKey;
    } else if (lastLocalPublicationStateDiagnosticKey === diagnosticKey) {
      return;
    } else {
      lastLocalPublicationStateDiagnosticKey = diagnosticKey;
    }

    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      payload: localMediaGateDiagnosticPayload(context, reason),
      immediate,
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
        lastCapabilityAckStoredKey = '';
        lastCapabilityAckPlanEpoch = 0;
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

  function handleClientCapabilitiesAck(payload: Record<string, any> = {}) {
    if (payloadType(payload) !== 'client.capabilities.v1/ack') return false;
    const capabilities = payloadClientCapabilities(payload);
    const context = localMediaGateContext({
      ...payload,
      participant_session_id: capabilities.participant_session_id ?? payload.participant_session_id,
    });
    const key = capabilitySendKey(context);
    const stored = payload.ok === true && payload.stored === true;
    const ackPlanEpoch = planEpochValue(payload.plan_epoch, lastCapabilityPlanGateContext.minPlanEpoch);
    if (stored) {
      lastCapabilityAckStoredKey = key;
      lastCapabilityAckPlanEpoch = ackPlanEpoch;
      lastCapabilityPlanGateContext = {
        ...context,
        minPlanEpoch: Math.max(context.minPlanEpoch, ackPlanEpoch),
      };
    } else if (lastCapabilityAckStoredKey === key) {
      lastCapabilityAckStoredKey = '';
      lastCapabilityAckPlanEpoch = 0;
    }
    captureClientDiagnostic({
      category: 'media',
      level: stored ? 'info' : 'warning',
      eventType: stored ? 'client_capabilities_ack_stored' : 'client_capabilities_ack_failed',
      code: stored ? 'client_capabilities_ack_stored' : 'client_capabilities_ack_failed',
      message: stored
        ? 'Client media capabilities were stored by realtime.'
        : 'Client media capabilities were not stored by realtime.',
      payload: {
        schema_version: stringValue(payload.schema_version, 'king.video.client_capabilities.v1'),
        call_id: context.callId,
        room_id: context.roomId,
        participant_session_id: context.participantSessionId,
        plan_epoch: ackPlanEpoch,
        ok: payload.ok === true,
        stored,
      },
      immediate: true,
    });
    return stored;
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
    const context = localMediaGateContext(sourcePayload);
    const key = capabilitySendKey(context);
    if ('isSocketOnline' in refs && refValue(refs.isSocketOnline) !== true) return false;
    if (!admittedWebsocketJoin || admittedWebsocketJoinKey !== key) return false;
    if (lastCapabilityAckStoredKey !== key) return false;
    return mediaSessionPlanAllowsLocalPublication(lastMediaSessionPlan, {
      callId: context.callId,
      roomId: context.roomId,
      participantSessionId: context.participantSessionId,
      minPlanEpoch: context.minPlanEpoch,
    });
  }

  function mediaSessionPlanHasGossipTransportForLastPlan(sourcePayload: Record<string, any> = {}) {
    const context = localMediaGateContext(sourcePayload);
    if (stringValue(context.participantSessionId) !== '') {
      const localParticipantMatches = mediaSessionPlanHasGossipTransport(lastMediaSessionPlan, {
        participantSessionId: context.participantSessionId,
      });
      if (localParticipantMatches) return true;
    }
    return mediaSessionPlanHasGossipTransport(lastMediaSessionPlan);
  }

  async function requestLocalMediaPublicationForLastPlan(
    reason = 'media_session_plan_gate',
    sourcePayload: Record<string, any> = {},
    publishLocalMediaOverride: any = null,
  ) {
    const context = localMediaGateContext(sourcePayload);
    if (!canPublishLocalMediaForLastPlan(sourcePayload)) {
      pendingLocalMediaPublication = true;
      captureLocalPublicationGateDiagnostic({
        context,
        eventType: 'media_session_plan_local_publication_blocked',
        level: 'info',
        message: 'Local media publication is waiting for an admitted websocket join and matching media session plan.',
        reason,
        immediate: false,
      });
      return false;
    }
    const publisher = typeof publishLocalMediaOverride === 'function'
      ? publishLocalMediaOverride
      : (publishLocalTracks || activeLocalMediaPublicationCallbacks.publishLocalTracks);
    if (typeof publisher !== 'function') return false;
    if (localMediaPublicationInFlight) return false;

    localMediaPublicationInFlight = true;
    pendingLocalMediaPublication = false;
    try {
      const published = await publisher();
      localMediaPublicationStarted = published === true;
      if (published === true) {
        captureLocalPublicationGateDiagnostic({
          context,
          eventType: 'media_session_plan_local_publication_started',
          level: 'info',
          message: 'Local media publication started from the active media session plan.',
          reason,
          immediate: true,
        });
      }
      return published === true;
    } finally {
      localMediaPublicationInFlight = false;
    }
  }

  async function applyLocalMediaStateForLastPlan(reason = 'media_session_plan', sourcePayload: Record<string, any> = {}) {
    const context = localMediaGateContext(sourcePayload);
    if (canPublishLocalMediaForLastPlan(sourcePayload)) {
      return requestLocalMediaPublicationForLastPlan(reason, sourcePayload);
    }

    pendingLocalMediaPublication = true;
    const stopLocalMedia = stopPlanBlockedLocalMedia
      || activeLocalMediaPublicationCallbacks.stopPlanBlockedLocalMedia;
    if (localMediaPublicationStarted && typeof stopLocalMedia === 'function') {
      stopLocalMedia();
      localMediaPublicationStarted = false;
      captureLocalPublicationGateDiagnostic({
        context,
        eventType: 'media_session_plan_local_publication_stopped',
        level: 'warning',
        message: 'Local media publication stopped because the active media session plan no longer allows it.',
        reason,
        immediate: true,
      });
    }
    return false;
  }

  const bridgeApi = {
    applyLocalMediaStateForLastPlan,
    canPublishLocalMediaForLastPlan,
    getLastMediaSessionPlan,
    getLastMediaSessionPlanDiagnostic,
    handleClientCapabilitiesAck,
    handleRoomSnapshotMediaSessionPlan,
    mediaSessionPlanHasGossipTransportForLastPlan,
    requestLocalMediaPublicationForLastPlan,
    sendClientCapabilities,
  };
  activeMediaCapabilityPlanBridge = bridgeApi;
  return bridgeApi;
}
