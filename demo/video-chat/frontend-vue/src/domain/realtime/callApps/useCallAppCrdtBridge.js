import { onBeforeUnmount } from 'vue';
import { CALL_APP_IFRAME_BRIDGE_PROTOCOL, CALL_APP_IFRAME_OPAQUE_ORIGIN } from './useCallAppIframeBridge.js';
import {
  callAppDiagnosticElapsedMs,
  callAppDiagnosticNow,
  emitCallAppDiagnostic,
  emitCallAppResponseDiagnostics,
} from './callAppDiagnostics.js';
import {
  CALL_APP_PRESENCE_SIGNAL_TYPE,
  CALL_APP_PRESENCE_WINDOW_EVENT,
  createCallAppPresenceSignalPayload,
  normalizeCallAppPresenceDisplayName,
  normalizeCallAppPresenceParticipantRows,
  normalizeCallAppPresencePayload,
  normalizeCallAppPresencePayloadType,
  normalizeRemoteCallAppPresenceSignal,
} from './callAppPresenceRelay.js';

function postToIframe(frameWindow, session, type, payload = {}) {
  if (!frameWindow || !session) return;
  frameWindow.postMessage({
    type,
    bridge_protocol: CALL_APP_IFRAME_BRIDGE_PROTOCOL,
    app_session_id: String(session?.id || '').trim(),
    app_key: String(session?.app_key || '').trim(),
    ...payload,
  }, '*');
}

function requestId(message) {
  return String(message?.request_id || message?.requestId || '').trim();
}

function safeClock(value) {
  const parsed = Number(value || 0);
  if (!Number.isFinite(parsed) || parsed < 0) return 0;
  return Math.floor(parsed);
}

function unrefValue(value) {
  if (value && typeof value === 'object' && 'value' in value) return value.value;
  if (typeof value === 'function') return value();
  return value;
}

export function createCallAppCrdtBridge({
  activeSession,
  iframeRef,
  apiRequest,
  participants,
  currentUserId,
  currentUserDisplayName,
  sendSocketFrame,
} = {}) {
  async function handleBootstrap(frameWindow, session, message) {
    const startedAt = callAppDiagnosticNow();
    const params = new URLSearchParams();
    const afterClock = safeClock(message?.after_clock);
    if (afterClock > 0) params.set('after_clock', String(afterClock));
    const suffix = params.toString() ? `?${params.toString()}` : '';
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/bootstrap${suffix}`);
    const result = payload?.result || {};
    emitCallAppResponseDiagnostics(payload, { session_id: session.id, app_key: session.app_key });
    emitCallAppDiagnostic('call_app_crdt_replay_latency', {
      session_id: session.id,
      app_key: session.app_key,
      request_type: 'bootstrap',
      operation_count: Array.isArray(result.ops) ? result.ops.length : 0,
      after_clock: afterClock,
      replay_cursor: result.replay_cursor || {},
      latency_ms: callAppDiagnosticElapsedMs(startedAt),
    });
    postToIframe(frameWindow, session, 'call_app.crdt.bootstrap.response', {
      request_id: requestId(message),
      result,
    });
  }

  async function handleOpsRequest(frameWindow, session, message) {
    const startedAt = callAppDiagnosticNow();
    const params = new URLSearchParams();
    const afterClock = safeClock(message?.after_clock);
    params.set('after_clock', String(afterClock));
    if (Number.isFinite(Number(message?.limit))) params.set('limit', String(Math.floor(Number(message.limit))));
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/ops?${params.toString()}`);
    const result = payload?.result || {};
    emitCallAppResponseDiagnostics(payload, { session_id: session.id, app_key: session.app_key });
    emitCallAppDiagnostic('call_app_crdt_replay_latency', {
      session_id: session.id,
      app_key: session.app_key,
      request_type: 'ops',
      operation_count: Array.isArray(result.ops) ? result.ops.length : 0,
      after_clock: afterClock,
      replay_cursor: result.replay_cursor || {},
      latency_ms: callAppDiagnosticElapsedMs(startedAt),
    });
    postToIframe(frameWindow, session, 'call_app.crdt.ops.response', {
      request_id: requestId(message),
      result,
    });
  }

  async function handleAppend(frameWindow, session, message) {
    const startedAt = callAppDiagnosticNow();
    const operation = message?.operation || {};
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/ops`, {
      method: 'POST',
      body: {
        operation,
      },
    });
    const result = payload?.result || {};
    const state = String(result.state || '').trim().toLowerCase();
    emitCallAppResponseDiagnostics(payload, { session_id: session.id, app_key: session.app_key });
    emitCallAppDiagnostic('call_app_crdt_append_latency', {
      session_id: session.id,
      app_key: session.app_key,
      state,
      payload_type: String(operation.payload_type || result.operation?.payload_type || '').trim(),
      logical_clock: Number(result.operation?.logical_clock || 0) || 0,
      latency_ms: callAppDiagnosticElapsedMs(startedAt),
    });
    if (state === 'duplicate') {
      emitCallAppDiagnostic('call_app_crdt_duplicate_suppressed', {
        session_id: session.id,
        app_key: session.app_key,
        operation_id: String(operation.operation_id || result.operation?.operation_id || '').trim(),
        payload_type: String(operation.payload_type || result.operation?.payload_type || '').trim(),
      });
    }
    postToIframe(frameWindow, session, 'call_app.crdt.op.appended', {
      request_id: requestId(message),
      result,
    });
  }

  async function handleSnapshot(frameWindow, session, message) {
    const startedAt = callAppDiagnosticNow();
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/snapshots`, {
      method: 'POST',
      body: {},
    });
    const result = payload?.result || {};
    emitCallAppResponseDiagnostics(payload, { session_id: session.id, app_key: session.app_key });
    emitCallAppDiagnostic('call_app_crdt_snapshot_compacted', {
      session_id: session.id,
      app_key: session.app_key,
      snapshot_clock: Number(result.snapshot_clock || 0) || 0,
      compacted_through_clock: Number(result.snapshot?.compacted_through_clock || 0) || 0,
      operation_count: Number(result.snapshot?.operation_count || 0) || 0,
      latency_ms: callAppDiagnosticElapsedMs(startedAt),
    });
    postToIframe(frameWindow, session, 'call_app.crdt.snapshot.response', {
      request_id: requestId(message),
      result,
    });
  }

  function sendPresenceToPeers(session, payloadType, payload) {
    if (typeof unrefValue(sendSocketFrame) !== 'function') return 0;
    const signalPayload = createCallAppPresenceSignalPayload(session, payloadType, payload);
    if (!signalPayload) return 0;
    const targetParticipants = normalizeCallAppPresenceParticipantRows(
      unrefValue(participants),
      Number(unrefValue(currentUserId) || 0),
    );
    let sentCount = 0;
    for (const participant of targetParticipants) {
      const sent = unrefValue(sendSocketFrame)({
        type: CALL_APP_PRESENCE_SIGNAL_TYPE,
        target_user_id: participant.userId,
        payload: signalPayload,
      });
      if (sent) sentCount += 1;
    }
    if (sentCount > 0) {
      emitCallAppDiagnostic('call_app_presence_relayed', {
        session_id: session.id,
        app_key: session.app_key,
        payload_type: payloadType,
        target_count: sentCount,
      });
    }
    return sentCount;
  }

  function handlePresencePublish(frameWindow, session, message) {
    const payloadType = normalizeCallAppPresencePayloadType(message?.payload_type);
    const displayName = normalizeCallAppPresenceDisplayName(unrefValue(currentUserDisplayName));
    const payload = normalizeCallAppPresencePayload(payloadType, message?.payload || {}, {
      actorId: message?.actor_id || message?.payload?.actor_id,
      displayName,
    });
    const sentCount = payloadType !== '' && payload ? sendPresenceToPeers(session, payloadType, payload) : 0;
    postToIframe(frameWindow, session, 'call_app.presence.published', {
      request_id: requestId(message),
      result: {
        ok: payloadType !== '' && Boolean(payload),
        state: payloadType !== '' && payload ? 'accepted' : 'ignored',
        persisted: false,
        payload_type: payloadType,
        sent_count: sentCount,
      },
    });
  }

  function handleRemotePresence(event) {
    const frameWindow = iframeRef?.value?.contentWindow || null;
    const session = activeSession?.value || null;
    if (!frameWindow || !session) return;
    const signal = normalizeRemoteCallAppPresenceSignal(event?.detail?.signal || event?.detail?.payload || {});
    if (!signal) return;
    if (String(signal.app_session_id || '').trim() !== String(session.id || '').trim()) return;
    if (String(signal.app_key || '').trim() !== String(session.app_key || '').trim()) return;
    const senderDisplayName = normalizeCallAppPresenceDisplayName(
      event?.detail?.sender?.display_name || event?.detail?.sender?.displayName || ''
    );
    const payload = signal.payload && typeof signal.payload === 'object' ? { ...signal.payload } : {};
    if (senderDisplayName !== '' && !payload.display_name && !payload.label) {
      payload.display_name = senderDisplayName;
      if (signal.payload_type === 'cursor.move') payload.label = senderDisplayName;
    }
    postToIframe(frameWindow, session, 'call_app.presence.update', {
      payload_type: signal.payload_type,
      actor_id: signal.actor_id || payload.actor_id || '',
      payload,
    });
  }

  function postError(frameWindow, session, message, error) {
    const reason = String(error?.responseReason || error?.responseDetails?.reason || '').trim().toLowerCase();
    const grantState = reason === 'participant_grant_denied' ? 'denied' : '';
    emitCallAppDiagnostic('call_app_iframe_bridge_error', {
      session_id: session?.id,
      app_key: session?.app_key,
      iframe_message_type: String(message?.type || '').trim(),
      reason: reason || 'request_failed',
      response_status: Number(error?.responseStatus || 0) || 0,
      response_code: String(error?.responseCode || '').trim().toLowerCase(),
    });
    postToIframe(frameWindow, session, 'call_app.crdt.error', {
      request_id: requestId(message),
      message: error instanceof Error ? error.message : 'Call App CRDT request failed.',
      reason,
      grant_state: grantState,
      response_status: Number(error?.responseStatus || 0) || 0,
      response_code: String(error?.responseCode || '').trim().toLowerCase(),
    });
  }

  function handleMessage(event) {
    const frameWindow = iframeRef?.value?.contentWindow || null;
    const session = activeSession?.value || null;
    if (!frameWindow || !session || event.source !== frameWindow) return;
    if (event.origin !== CALL_APP_IFRAME_OPAQUE_ORIGIN) return;

    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== CALL_APP_IFRAME_BRIDGE_PROTOCOL) return;
    if (String(message.app_session_id || session.id || '').trim() !== String(session.id || '').trim()) return;
    if (String(message.app_key || session.app_key || '').trim() !== String(session.app_key || '').trim()) return;
    if (typeof apiRequest !== 'function') return;

    const type = String(message.type || '').trim();
    const run = async () => {
      if (type === 'call_app.crdt.bootstrap.request') {
        await handleBootstrap(frameWindow, session, message);
      } else if (type === 'call_app.crdt.ops.request') {
        await handleOpsRequest(frameWindow, session, message);
      } else if (type === 'call_app.crdt.op.append') {
        await handleAppend(frameWindow, session, message);
      } else if (type === 'call_app.crdt.snapshot.request') {
        await handleSnapshot(frameWindow, session, message);
      } else if (type === 'call_app.presence.publish') {
        handlePresencePublish(frameWindow, session, message);
      }
    };
    void run().catch((error) => postError(frameWindow, session, message, error));
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('message', handleMessage);
    window.addEventListener(CALL_APP_PRESENCE_WINDOW_EVENT, handleRemotePresence);
  }

  onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('message', handleMessage);
      window.removeEventListener(CALL_APP_PRESENCE_WINDOW_EVENT, handleRemotePresence);
    }
  });

  return {
    postToIframe,
  };
}
