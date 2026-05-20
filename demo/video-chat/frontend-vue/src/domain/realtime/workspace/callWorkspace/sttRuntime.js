import { onBeforeUnmount, reactive, ref, watch } from 'vue';

import {
  CallWorkspaceSttUploader,
  normalizeSttRuntimeConfig,
} from './sttUploader.js';

export const WORKSPACE_STT_CONTROL_KIND = 'workspace-stt-control-state';

function firstString(...values) {
  for (const value of values) {
    const text = String(value || '').trim();
    if (text !== '') return text;
  }
  return '';
}

function nextChunkId() {
  const uuid = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `stt:${uuid}`;
}

export function createCallWorkspaceSttRuntime(context) {
  const {
    callbacks,
    refs,
  } = context;
  const {
    apiRequest,
    appendChatMessage,
    buildApiRequestError,
    fetchBackend,
    mediaDebugLog,
    normalizeCallRole,
    normalizeRole,
    requestHeaders,
    sendSocketFrame,
  } = callbacks;
  const {
    activeCallId,
    activeRoomId,
    callParticipantRoles,
    canModerate,
    connectedParticipantUsers,
    currentUserId,
    isSocketOnline,
    localStreamRef,
    sessionState,
    workspaceSidebarState,
  } = refs;

  const callSttState = reactive({
    enabled: false,
    pending: false,
    status: '',
    diagnostic: '',
  });
  const sttRuntimeConfig = ref({
    active: false,
    endpoint: '',
    controlEndpoint: '',
  });
  let configSequence = 0;

  const sttUploader = new CallWorkspaceSttUploader({
    uploadChunk: uploadSttMicChunk,
    onTranscript: (message) => appendChatMessage(message),
    onDiagnostic: applySttDiagnostic,
    fallbackMessage: {
      userId: Number(currentUserId.value || 0) || 0,
      displayName: firstString(sessionState.displayName, 'Unknown user'),
      role: firstString(sessionState.role, 'user'),
      roomId: activeRoomId.value,
    },
  });

  function syncSidebarSttState() {
    const sidebarStt = workspaceSidebarState?.stt;
    if (!sidebarStt || typeof sidebarStt !== 'object') return;
    sidebarStt.enabled = Boolean(callSttState.enabled);
    sidebarStt.backendReady = Boolean(
      sttRuntimeConfig.value.active
      && sttRuntimeConfig.value.endpoint
      && sttRuntimeConfig.value.controlEndpoint
    );
    sidebarStt.canManage = Boolean(canModerate.value);
    sidebarStt.pending = Boolean(callSttState.pending);
    sidebarStt.status = callSttState.status;
    sidebarStt.diagnostic = callSttState.diagnostic;
    sidebarStt.toggle = setCallSttEnabledFromSidebar;
  }

  function applySttDiagnostic(event) {
    const code = String(event?.code || '').trim();
    const message = String(event?.message || '').trim();
    if (message !== '') {
      callSttState.diagnostic = message;
    }
    if (code === 'transcript_accepted') {
      callSttState.status = 'Transcript accepted.';
    } else if (code === 'transcript_empty') {
      callSttState.status = 'Listening; silence or empty transcript skipped.';
    } else if (code === 'upload_failed') {
      callSttState.status = 'STT upload failed.';
    } else if (code === 'recorder_unsupported') {
      callSttState.status = 'STT recorder unsupported.';
    }
    mediaDebugLog?.('[CallSTT]', code, message, event?.details || {});
    syncSidebarSttState();
  }

  async function refreshSttRuntimeConfig() {
    const sequence = ++configSequence;
    const callId = String(activeCallId.value || '').trim();
    if (callId === '') {
      sttRuntimeConfig.value = { active: false, endpoint: '', controlEndpoint: '' };
      callSttState.enabled = false;
      syncSidebarSttState();
      reconcileSttUploader();
      return;
    }

    try {
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/stt`);
      if (sequence !== configSequence) return;
      const sttState = payload?.result?.stt && typeof payload.result.stt === 'object' ? payload.result.stt : {};
      const runtimeConfig = sttState.runtime_config && typeof sttState.runtime_config === 'object'
        ? sttState.runtime_config
        : {};
      sttRuntimeConfig.value = {
        ...normalizeSttRuntimeConfig({ calls: { stt: runtimeConfig } }, { callId, roomId: activeRoomId.value }),
        active: Boolean(runtimeConfig.enabled),
        endpoint: `/api/calls/${encodeURIComponent(callId)}/stt/chunks`,
        controlEndpoint: `/api/calls/${encodeURIComponent(callId)}/stt`,
      };
      callSttState.enabled = Boolean(sttState.enabled);
      callSttState.status = callSttState.enabled
        ? 'Speech transcription enabled for this call.'
        : 'Speech transcription is available and currently off.';
    } catch (error) {
      if (sequence !== configSequence) return;
      sttRuntimeConfig.value = { active: false, endpoint: '', controlEndpoint: '' };
      callSttState.enabled = false;
      callSttState.status = '';
      callSttState.diagnostic = error instanceof Error ? error.message : 'Could not load STT state.';
    } finally {
      syncSidebarSttState();
      reconcileSttUploader();
    }
  }

  async function uploadSttMicChunk(blob, stats) {
    const config = sttRuntimeConfig.value;
    if (!callSttState.enabled) throw new Error('STT is disabled.');
    const endpoint = String(config.endpoint || '').trim();
    if (endpoint === '') throw new Error('STT upload endpoint is not configured.');

    const { response } = await fetchBackend(endpoint, {
      method: 'POST',
      headers: {
        ...requestHeaders(false),
        'content-type': String(blob.type || '').trim() || 'application/octet-stream',
        'x-call-room-id': activeRoomId.value,
        'x-call-stt-max-rms': String(Number(stats?.maxRms || 0)),
        'x-call-stt-chunk-id': nextChunkId(),
      },
      body: blob,
      serialize: false,
      timeoutMs: 20_000,
    });
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (!response.ok) {
      if (typeof buildApiRequestError === 'function') {
        throw buildApiRequestError(payload, `STT upload failed (${response.status}).`, response.status);
      }
      throw new Error(`STT upload failed (${response.status}).`);
    }
    return payload || {};
  }

  function isAuthorizedSttController(sender) {
    const senderUserId = Number(sender?.user_id || 0);
    if (!Number.isInteger(senderUserId) || senderUserId <= 0) return false;
    if (normalizeRole(sender?.role) === 'admin') return true;
    const callRole = normalizeCallRole(callParticipantRoles?.[senderUserId] || '');
    return callRole === 'owner' || callRole === 'moderator';
  }

  function applySttControlState(enabled, source = 'remote') {
    callSttState.enabled = Boolean(enabled);
    callSttState.status = callSttState.enabled
      ? `Speech transcription enabled${source === 'local' ? '' : ' for this call'}.`
      : `Speech transcription disabled${source === 'local' ? '' : ' for this call'}.`;
    callSttState.diagnostic = '';
    syncSidebarSttState();
    reconcileSttUploader();
  }

  function syncControlStateToPeers() {
    const peerIds = connectedParticipantUsers.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);
    let sentCount = 0;
    for (const targetUserId of peerIds) {
      const sent = sendSocketFrame({
        type: 'call/control-state',
        target_user_id: targetUserId,
        payload: {
          kind: WORKSPACE_STT_CONTROL_KIND,
          actor_user_id: currentUserId.value,
          room_id: activeRoomId.value,
          enabled: Boolean(callSttState.enabled),
        },
      });
      if (sent) sentCount += 1;
    }
    return sentCount;
  }

  async function setCallSttEnabledFromSidebar(enabled) {
    if (!canModerate.value || callSttState.pending) return;
    const controlEndpoint = String(sttRuntimeConfig.value.controlEndpoint || '').trim();
    if (!sttRuntimeConfig.value.active || controlEndpoint === '') {
      callSttState.diagnostic = 'STT backend control endpoint is not configured.';
      syncSidebarSttState();
      return;
    }

    callSttState.pending = true;
    callSttState.diagnostic = '';
    syncSidebarSttState();
    try {
      const payload = await apiRequest(controlEndpoint, {
        method: 'PATCH',
        body: {
          enabled: Boolean(enabled),
          call_id: activeCallId.value,
          room_id: activeRoomId.value,
        },
      });
      const accepted = Boolean(payload?.result?.stt?.enabled ?? enabled);
      applySttControlState(accepted, 'local');
      syncControlStateToPeers();
    } catch (error) {
      callSttState.diagnostic = error instanceof Error ? error.message : 'Could not update STT state.';
    } finally {
      callSttState.pending = false;
      syncSidebarSttState();
    }
  }

  function reconcileSttUploader() {
    const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
    const config = sttRuntimeConfig.value;
    if (!callSttState.enabled || !config.active || !config.endpoint || !(stream instanceof MediaStream)) {
      sttUploader.stop();
      return;
    }
    sttUploader.options.fallbackMessage = {
      userId: Number(currentUserId.value || 0) || 0,
      displayName: firstString(sessionState.displayName, 'Unknown user'),
      role: firstString(sessionState.role, 'user'),
      roomId: activeRoomId.value,
    };
    if (!sttUploader.started) {
      sttUploader.start(stream, config);
    }
  }

  function handleRemoteControlState(payload, sender) {
    const kind = String(payload?.kind || '').trim().toLowerCase();
    if (kind !== WORKSPACE_STT_CONTROL_KIND) return false;
    if (!isAuthorizedSttController(sender)) return true;
    applySttControlState(Boolean(payload?.enabled), 'remote');
    void refreshSttRuntimeConfig();
    return true;
  }

  watch(
    () => [String(activeCallId.value || ''), activeRoomId.value],
    () => {
      callSttState.enabled = false;
      callSttState.status = '';
      callSttState.diagnostic = '';
      sttUploader.stop();
      syncSidebarSttState();
      void refreshSttRuntimeConfig();
    },
    { immediate: true },
  );
  watch(
    () => [callSttState.enabled, sttRuntimeConfig.value.active, sttRuntimeConfig.value.endpoint, localStreamRef.value],
    () => {
      syncSidebarSttState();
      reconcileSttUploader();
    },
  );
  watch(canModerate, () => syncSidebarSttState());
  watch(isSocketOnline, (online) => {
    if (online && callSttState.enabled && canModerate.value) syncControlStateToPeers();
  });
  watch(
    () => connectedParticipantUsers.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0)
      .sort((left, right) => left - right)
      .join(','),
    () => {
      if (callSttState.enabled && canModerate.value && isSocketOnline.value) syncControlStateToPeers();
    },
  );

  onBeforeUnmount(() => {
    sttUploader.stop();
    const sidebarStt = workspaceSidebarState?.stt;
    if (sidebarStt && typeof sidebarStt === 'object') {
      sidebarStt.enabled = false;
      sidebarStt.pending = false;
      sidebarStt.canManage = false;
      sidebarStt.status = '';
      sidebarStt.diagnostic = '';
      sidebarStt.toggle = null;
    }
  });

  return {
    handleRemoteControlState,
    refreshSttRuntimeConfig,
    syncControlStateToPeers,
  };
}
