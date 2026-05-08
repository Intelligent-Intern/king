import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { emitCallAppDiagnostic } from './callAppDiagnostics.js';

export const CALL_APP_IFRAME_BRIDGE_PROTOCOL = 'king.call_app.iframe.v1';
export const CALL_APP_IFRAME_OPAQUE_ORIGIN = 'null';

function normalizeLaunchResponse(payload) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const context = result.context && typeof result.context === 'object' ? result.context : {};
  return {
    token: String(result.launch_token || '').trim(),
    tokenId: String(result.launch_token_id || context?.token?.id || '').trim(),
    expiresAt: String(result.expires_at || context?.token?.expires_at || '').trim(),
    context,
  };
}

function plainStringList(value) {
  if (!Array.isArray(value)) return [];
  return value
    .map((entry) => String(entry || '').trim())
    .filter((entry, index, entries) => entry !== '' && entries.indexOf(entry) === index);
}

function plainLaunchParticipant(value, displayName = '') {
  const participant = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  const safeDisplayName = String(participant.display_name || participant.displayName || displayName || '').trim();
  return {
    subject_type: String(participant.subject_type || '').trim(),
    actor_id: String(participant.actor_id || '').trim(),
    display_name: safeDisplayName.slice(0, 80),
  };
}

function safePostMessagePayload(session, launch, options = {}) {
  const app = session?.app && typeof session.app === 'object' ? session.app : {};
  const context = launch.context && typeof launch.context === 'object' ? launch.context : {};
  const contextApp = context.app && typeof context.app === 'object' ? context.app : {};
  const capabilities = plainStringList(context.capabilities);
  const participantDisplayName = options?.participantDisplayName?.value !== undefined
    ? options.participantDisplayName.value
    : options?.participantDisplayName;
  return {
    type: 'call_app.launch',
    bridge_protocol: CALL_APP_IFRAME_BRIDGE_PROTOCOL,
    app_session_id: String(session?.id || '').trim(),
    call_id: String(session?.call_id || '').trim(),
    app_key: String(session?.app_key || '').trim(),
    app_version: String(session?.version || '').trim(),
    document_id: String(session?.document_id || '').trim(),
    launch_token: launch.token,
    launch_token_id: launch.tokenId,
    expires_at: launch.expiresAt,
    capabilities,
    launch_context: {
      bridge_protocol: CALL_APP_IFRAME_BRIDGE_PROTOCOL,
      iframe_origin_policy: 'sandbox_opaque_origin',
      expected_message_origin: CALL_APP_IFRAME_OPAQUE_ORIGIN,
      participant: plainLaunchParticipant(context.participant, participantDisplayName),
      grant_state: String(context.grant_state || '').trim(),
      app: {
        name: String(app.name || contextApp.name || '').trim(),
        category: String(app.category || contextApp.category || '').trim(),
        crdt_protocol: String(app.crdt_protocol || contextApp.crdt_protocol || '').trim(),
      },
    },
  };
}

export function createCallAppIframeBridge({
  activeSession,
  iframeRef,
  apiRequest,
  participantDisplayName,
} = {}) {
  const launch = ref(null);
  const status = ref('idle');
  const error = ref('');
  let generation = 0;

  const sessionId = computed(() => String(activeSession?.value?.id || '').trim());
  const appKey = computed(() => String(activeSession?.value?.app_key || '').trim());
  const launchState = computed(() => ({
    status: status.value,
    error: error.value,
    token_id: launch.value?.tokenId || '',
    expires_at: launch.value?.expiresAt || '',
    grant_state: String(launch.value?.context?.grant_state || '').trim().toLowerCase(),
    capabilities: Array.isArray(launch.value?.context?.capabilities) ? launch.value.context.capabilities : [],
  }));

  function resetLaunchState(nextStatus = 'idle') {
    generation += 1;
    launch.value = null;
    status.value = nextStatus;
    error.value = '';
  }

  function postLaunchToIframe() {
    const frameWindow = iframeRef?.value?.contentWindow || null;
    const session = activeSession?.value || null;
    if (!frameWindow || !session || !launch.value?.token) return false;

    frameWindow.postMessage(safePostMessagePayload(session, launch.value, { participantDisplayName }), '*');
    status.value = 'launch_sent';
    return true;
  }

  async function mintLaunchToken() {
    const currentSessionId = sessionId.value;
    const currentGeneration = generation + 1;
    generation = currentGeneration;
    launch.value = null;
    error.value = '';
    if (!currentSessionId) {
      status.value = 'idle';
      return;
    }
    if (typeof apiRequest !== 'function') {
      status.value = 'error';
      error.value = 'Call App launch API is not available.';
      emitCallAppDiagnostic('call_app_launch_token_failed', {
        session_id: currentSessionId,
        app_key: appKey.value,
        reason: 'api_request_unavailable',
      });
      return;
    }

    status.value = 'minting';
    try {
      const payload = await apiRequest(`/api/call-app-sessions/${encodeURIComponent(currentSessionId)}/launch-token`, {
        method: 'POST',
        body: {},
      });
      if (currentGeneration !== generation) return;
      const normalized = normalizeLaunchResponse(payload);
      if (!normalized.token) {
        throw new Error('Call App launch token was not returned.');
      }
      launch.value = normalized;
      status.value = 'token_ready';
      postLaunchToIframe();
    } catch (launchError) {
      if (currentGeneration !== generation) return;
      status.value = 'error';
      error.value = launchError instanceof Error ? launchError.message : 'Call App launch failed.';
      emitCallAppDiagnostic('call_app_launch_token_failed', {
        session_id: currentSessionId,
        app_key: appKey.value,
        reason: String(launchError?.responseReason || launchError?.responseDetails?.reason || 'request_failed').trim().toLowerCase(),
        response_status: Number(launchError?.responseStatus || 0) || 0,
        response_code: String(launchError?.responseCode || '').trim().toLowerCase(),
      });
    }
  }

  function handleIframeLoad() {
    postLaunchToIframe();
  }

  function handleIframeMessage(event) {
    const frameWindow = iframeRef?.value?.contentWindow || null;
    if (!frameWindow || event.source !== frameWindow) return;
    if (event.origin !== CALL_APP_IFRAME_OPAQUE_ORIGIN) return;

    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== CALL_APP_IFRAME_BRIDGE_PROTOCOL) return;
    if (String(message.app_session_id || sessionId.value || '').trim() !== sessionId.value) return;
    if (String(message.app_key || appKey.value || '').trim() !== appKey.value) return;

    if (message.type === 'call_app.ready') {
      status.value = 'ready';
      error.value = '';
    } else if (message.type === 'call_app.error') {
      status.value = 'error';
      error.value = String(message.message || 'Call App reported an error.').trim();
      emitCallAppDiagnostic('call_app_iframe_bridge_error', {
        session_id: sessionId.value,
        app_key: appKey.value,
        iframe_message_type: String(message.type || '').trim(),
        reason: 'iframe_reported_error',
        message: error.value,
      });
    }
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('message', handleIframeMessage);
  }

  watch(sessionId, () => {
    resetLaunchState(sessionId.value ? 'minting' : 'idle');
    void mintLaunchToken();
  }, { immediate: true });

  onBeforeUnmount(() => {
    resetLaunchState('idle');
    if (typeof window !== 'undefined') {
      window.removeEventListener('message', handleIframeMessage);
    }
  });

  return {
    launchState,
    handleIframeLoad,
    postLaunchToIframe,
  };
}
