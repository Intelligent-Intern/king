import { onBeforeUnmount } from 'vue';
import {
  CALL_APP_IFRAME_BRIDGE_PROTOCOL,
  CALL_APP_IFRAME_OPAQUE_ORIGIN,
  sanitizeCallAppBridgePayload,
} from './useCallAppIframeBridge.js';

function postToIframe(frameWindow, session, type, payload = {}) {
  if (!frameWindow || !session) return;
  try {
    frameWindow.postMessage(sanitizeCallAppBridgePayload({
      type,
      bridge_protocol: CALL_APP_IFRAME_BRIDGE_PROTOCOL,
      app_session_id: String(session?.id || '').trim(),
      app_key: String(session?.app_key || '').trim(),
      ...payload,
    }), '*');
  } catch {
    // A bridge message must never break the parent call runtime.
  }
}

function requestId(message) {
  return String(message?.request_id || message?.requestId || '').trim();
}

function safeClock(value) {
  const parsed = Number(value || 0);
  if (!Number.isFinite(parsed) || parsed < 0) return 0;
  return Math.floor(parsed);
}

export function createCallAppCrdtBridge({ activeSession, iframeRef, apiRequest } = {}) {
  async function handleBootstrap(frameWindow, session, message) {
    const params = new URLSearchParams();
    const afterClock = safeClock(message?.after_clock);
    if (afterClock > 0) params.set('after_clock', String(afterClock));
    const suffix = params.toString() ? `?${params.toString()}` : '';
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/bootstrap${suffix}`);
    postToIframe(frameWindow, session, 'call_app.crdt.bootstrap.response', {
      request_id: requestId(message),
      result: payload?.result || {},
    });
  }

  async function handleOpsRequest(frameWindow, session, message) {
    const params = new URLSearchParams();
    params.set('after_clock', String(safeClock(message?.after_clock)));
    if (Number.isFinite(Number(message?.limit))) params.set('limit', String(Math.floor(Number(message.limit))));
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/ops?${params.toString()}`);
    postToIframe(frameWindow, session, 'call_app.crdt.ops.response', {
      request_id: requestId(message),
      result: payload?.result || {},
    });
  }

  async function handleAppend(frameWindow, session, message) {
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/ops`, {
      method: 'POST',
      body: {
        operation: message?.operation || {},
      },
    });
    postToIframe(frameWindow, session, 'call_app.crdt.op.appended', {
      request_id: requestId(message),
      result: payload?.result || {},
    });
  }

  async function handleSnapshot(frameWindow, session, message) {
    const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(session.id)}/crdt/snapshots`, {
      method: 'POST',
      body: {},
    });
    postToIframe(frameWindow, session, 'call_app.crdt.snapshot.response', {
      request_id: requestId(message),
      result: payload?.result || {},
    });
  }

  function postError(frameWindow, session, message, error) {
    postToIframe(frameWindow, session, 'call_app.crdt.error', {
      request_id: requestId(message),
      message: error instanceof Error ? error.message : 'Call App CRDT request failed.',
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
      }
    };
    void run().catch((error) => postError(frameWindow, session, message, error));
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('message', handleMessage);
  }

  onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('message', handleMessage);
    }
  });

  return {
    postToIframe,
  };
}
