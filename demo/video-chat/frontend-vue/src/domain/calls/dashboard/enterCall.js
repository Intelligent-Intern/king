import { nextTick, reactive, ref, watch } from 'vue';
import {
  buildWebSocketUrl,
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../../support/backendOrigin';
import {
  appendAssetVersionQuery,
  handleAssetVersionSocketClose,
  handleAssetVersionSocketPayload,
} from '../../../support/assetVersion';
import { attachForegroundReconnectHandlers } from '../../../support/foregroundReconnect';
import { t } from '../../../modules/localization/i18nRuntime.js';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
} from '../../realtime/media/preferences';
import { buildOptionalCallAudioCaptureConstraints } from '../../realtime/media/audioCaptureConstraints';
import { BackgroundFilterController } from '../../realtime/background/controller';

const ENTER_ADMISSION_RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];
const ENTER_ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS = 1500;

export function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

export function normalizeCallId(value) {
  const candidate = String(value || '').trim();
  if (candidate === '') return '';
  return /^[A-Za-z0-9._-]{1,200}$/.test(candidate) ? candidate : '';
}

export function createDashboardEnterCallController({ clearNotice, isInvitable, router, sessionState }) {
  const enterCallPreviewVideoRef = ref(null);
  const enterCallPreviewRawStreamRef = ref(null);
  const enterCallPreviewStreamRef = ref(null);
  const enterCallPreviewBackgroundController = new BackgroundFilterController();
  const enterCallState = reactive({
    open: false,
    loading: false,
    error: '',
    code: '',
    expiresAt: '',
    callId: '',
    roomId: '',
    copyNotice: '',
    previewReady: false,
    previewError: '',
    waitingForAdmission: false,
    admissionMessage: '',
  });

  let detachCallMediaWatcher = null;
  let detachForegroundReconnect = null;
  let enterAdmissionSocket = null;
  let enterAdmissionSocketGeneration = 0;
  let enterAdmissionAccepted = false;
  let enterAdmissionManuallyClosed = false;
  let enterAdmissionReconnectTimer = 0;
  let enterAdmissionReconnectAttempt = 0;
  let enterAdmissionReconnectAfterForeground = false;
  let enterAdmissionLastForegroundReconnectAt = 0;

  function resetEnterCallState() {
    enterCallState.loading = false;
    enterCallState.error = '';
    enterCallState.code = '';
    enterCallState.expiresAt = '';
    enterCallState.callId = '';
    enterCallState.roomId = '';
    enterCallState.copyNotice = '';
    enterCallState.previewReady = false;
    enterCallState.previewError = '';
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
  }

  function enterAdmissionSocketUrlForOrigin(origin) {
    const query = appendAssetVersionQuery(new URLSearchParams());
    query.set('room', normalizeRoomId(enterCallState.roomId || 'lobby'));

    const callId = normalizeCallId(enterCallState.callId);
    if (callId !== '') {
      query.set('call_id', callId);
    }

    const token = String(sessionState.sessionToken || '').trim();
    if (token !== '') {
      query.set('session', token);
    }

    return buildWebSocketUrl(origin, '/ws', query);
  }

  function clearEnterAdmissionReconnectTimer() {
    if (enterAdmissionReconnectTimer > 0 && typeof window !== 'undefined') {
      window.clearTimeout(enterAdmissionReconnectTimer);
    }
    enterAdmissionReconnectTimer = 0;
  }

  function enterAdmissionSocketIsOpen(socket = enterAdmissionSocket) {
    if (typeof WebSocket === 'undefined') return false;
    return socket instanceof WebSocket && socket.readyState === WebSocket.OPEN;
  }

  function sendEnterAdmissionFrame(payload) {
    if (!enterAdmissionSocketIsOpen()) return false;
    try {
      enterAdmissionSocket.send(JSON.stringify(payload));
      return true;
    } catch {
      return false;
    }
  }

  function retireEnterAdmissionSocket(closeReason = 'admission_reconnect') {
    const socket = enterAdmissionSocket;
    enterAdmissionSocket = null;
    if (typeof WebSocket !== 'undefined' && socket instanceof WebSocket) {
      try {
        socket.close(1000, closeReason);
      } catch {
        // ignore
      }
    }
  }

  function closeEnterAdmissionSocket({ cancel = false } = {}) {
    enterAdmissionManuallyClosed = true;
    clearEnterAdmissionReconnectTimer();

    if (cancel && enterCallState.waitingForAdmission && !enterAdmissionAccepted) {
      sendEnterAdmissionFrame({
        type: 'lobby/queue/cancel',
        room_id: normalizeRoomId(enterCallState.roomId || 'lobby'),
      });
    }

    retireEnterAdmissionSocket(cancel ? 'admission_cancelled' : 'admission_closed');
  }

  function scheduleEnterAdmissionReconnect() {
    clearEnterAdmissionReconnectTimer();
    if (enterAdmissionManuallyClosed || enterAdmissionAccepted || !enterCallState.waitingForAdmission) return;
    if (typeof window === 'undefined') return;

    enterAdmissionReconnectAttempt += 1;
    const delay = ENTER_ADMISSION_RECONNECT_DELAYS_MS[
      Math.min(enterAdmissionReconnectAttempt - 1, ENTER_ADMISSION_RECONNECT_DELAYS_MS.length - 1)
    ];
    enterCallState.admissionMessage = t('public.join.reconnecting_lobby');
    enterAdmissionReconnectTimer = window.setTimeout(() => {
      enterAdmissionReconnectTimer = 0;
      connectEnterAdmissionSocket();
    }, delay);
  }

  function markEnterAdmissionReconnectAfterForeground() {
    if (!enterCallState.waitingForAdmission || enterAdmissionAccepted || enterAdmissionManuallyClosed) return;
    enterAdmissionReconnectAfterForeground = true;
  }

  function reconnectEnterAdmissionAfterForeground() {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
    if (!enterCallState.waitingForAdmission || enterAdmissionAccepted || enterAdmissionManuallyClosed) return;
    if (!enterAdmissionReconnectAfterForeground) return;

    const now = Date.now();
    if ((now - enterAdmissionLastForegroundReconnectAt) < ENTER_ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS) return;

    enterAdmissionReconnectAfterForeground = false;
    enterAdmissionLastForegroundReconnectAt = now;
    enterAdmissionReconnectAttempt = 0;
    clearEnterAdmissionReconnectTimer();
    enterCallState.admissionMessage = t('public.join.reconnecting_lobby');
    connectEnterAdmissionSocket();
  }

  async function enterAdmittedCall() {
    const callRef = normalizeCallId(enterCallState.callId);
    if (callRef === '') {
      enterCallState.loading = false;
      enterCallState.waitingForAdmission = false;
      enterCallState.admissionMessage = '';
      enterCallState.error = t('calls.enter.missing_call_id');
      return;
    }

    enterAdmissionAccepted = true;
    closeEnterAdmissionSocket({ cancel: false });
    enterCallState.open = false;
    enterCallState.loading = false;
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    stopEnterCallPreview();
    resetEnterCallState();

    const target = router.resolve({
      name: 'call-workspace',
      params: { callRef },
      query: { entry: 'invite' },
    });
    if (typeof window !== 'undefined') {
      window.location.replace(target.href);
      return;
    }
    await router.replace(target.fullPath);
  }

  function handleEnterAdmissionLobbySnapshot(payload) {
    const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted : [];
    const currentUserId = Number(sessionState.userId || 0);
    const isAdmitted = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId);
    if (!isAdmitted) return;

    void enterAdmittedCall();
  }

  function handleEnterAdmissionWelcome(payload) {
    const admission = payload && typeof payload === 'object' ? payload.admission : null;
    const requiresAdmission = Boolean(admission?.requires_admission);
    const pendingRoomId = normalizeRoomId(admission?.pending_room_id || enterCallState.roomId || 'lobby');
    enterCallState.roomId = pendingRoomId;

    if (!requiresAdmission) {
      void enterAdmittedCall();
      return;
    }

    if (!sendEnterAdmissionFrame({ type: 'lobby/queue/join', room_id: pendingRoomId })) {
      enterCallState.loading = false;
      enterCallState.waitingForAdmission = false;
      enterCallState.admissionMessage = '';
      enterCallState.error = t('public.join.notify_owner_offline');
      return;
    }

    enterCallState.loading = false;
    enterCallState.waitingForAdmission = true;
    enterCallState.error = '';
    enterCallState.admissionMessage = t('public.join.admission_wait');
  }

  function handleEnterAdmissionSocketMessage(event) {
    let payload;
    try {
      payload = JSON.parse(String(event.data || ''));
    } catch {
      return;
    }

    if (!payload || typeof payload !== 'object') return;
    if (handleAssetVersionSocketPayload(payload)) return;
    const type = String(payload.type || '').trim().toLowerCase();
    if (type === 'system/welcome') {
      handleEnterAdmissionWelcome(payload);
      return;
    }

    if (type === 'lobby/snapshot') {
      handleEnterAdmissionLobbySnapshot(payload);
      return;
    }

    if (type === 'system/error') {
      const code = String(payload.code || '').trim().toLowerCase();
      if (code === 'lobby_command_failed') {
        enterCallState.error = t('public.join.notify_owner_failed');
        enterCallState.admissionMessage = '';
        enterCallState.waitingForAdmission = false;
        enterCallState.loading = false;
      }
    }
  }

  function connectEnterAdmissionSocketWithOriginAt(candidates, originIndex, generation) {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;

    if (originIndex >= candidates.length) {
      if (enterCallState.waitingForAdmission) {
        scheduleEnterAdmissionReconnect();
      } else {
        enterCallState.loading = false;
        enterCallState.waitingForAdmission = false;
        enterCallState.admissionMessage = '';
        enterCallState.error = t('public.join.lobby_connect_failed');
      }
      return;
    }

    const socketOrigin = candidates[originIndex];
    const wsUrl = enterAdmissionSocketUrlForOrigin(socketOrigin);
    if (!wsUrl) {
      connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
      return;
    }

    const socket = new WebSocket(wsUrl);
    enterAdmissionSocket = socket;
    let opened = false;
    let failedOver = false;

    const failOverToNextOrigin = () => {
      if (failedOver) return;
      failedOver = true;
      if (enterAdmissionSocket === socket) enterAdmissionSocket = null;
      try {
        socket.close(1000, 'admission_failover');
      } catch {
        // ignore
      }
      connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
    };

    socket.addEventListener('open', () => {
      if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) {
        try {
          socket.close(1000, 'stale_admission_socket');
        } catch {
          // ignore
        }
        return;
      }

      opened = true;
      enterAdmissionReconnectAttempt = 0;
      enterAdmissionReconnectAfterForeground = false;
      setBackendWebSocketOrigin(socketOrigin);
    });

    socket.addEventListener('message', (event) => {
      if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
      handleEnterAdmissionSocketMessage(event);
    });

    socket.addEventListener('error', () => {
      if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
      if (!opened) {
        failOverToNextOrigin();
        return;
      }
      enterCallState.admissionMessage = t('public.join.reconnecting_lobby');
    });

    socket.addEventListener('close', (event) => {
      if (generation !== enterAdmissionSocketGeneration) return;
      if (enterAdmissionSocket === socket) enterAdmissionSocket = null;
      if (enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
      if (handleAssetVersionSocketClose(event)) return;

      if (!opened) {
        failOverToNextOrigin();
        return;
      }

      scheduleEnterAdmissionReconnect();
    });
  }

  function connectEnterAdmissionSocket() {
    retireEnterAdmissionSocket('admission_reconnect');
    const candidates = resolveBackendWebSocketOriginCandidates();
    connectEnterAdmissionSocketWithOriginAt(candidates, 0, enterAdmissionSocketGeneration);
  }

  function startEnterAdmissionWait(target = null) {
    if (typeof WebSocket === 'undefined') {
      enterCallState.loading = false;
      enterCallState.error = t('public.join.realtime_lobby_unsupported');
      return false;
    }

    const normalizedTarget = target && typeof target === 'object' ? target : {};
    enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
    enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
    enterCallState.error = '';
    enterCallState.loading = true;
    enterCallState.waitingForAdmission = true;
    enterCallState.admissionMessage = t('public.join.connecting_lobby');

    closeEnterAdmissionSocket({ cancel: false });
    enterAdmissionAccepted = false;
    enterAdmissionManuallyClosed = false;
    enterAdmissionReconnectAttempt = 0;
    enterAdmissionReconnectAfterForeground = false;
    enterAdmissionSocketGeneration += 1;
    connectEnterAdmissionSocket();
    return true;
  }

  function resolvePreviewBackgroundFilterOptions() {
    const toFiniteNumber = (value, fallback) => {
      const numeric = Number(value);
      return Number.isFinite(numeric) ? numeric : fallback;
    };
    const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur'
      ? 'blur'
      : 'off';
    const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
    if (!applyOutgoing || mode !== 'blur') return { mode: 'off' };

    const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
    const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
    const baseBlurLevel = Math.max(0, Math.min(4, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
    const blurStepPx = [1, 2, 3, 4, 5];
    let blurPx = blurStepPx[baseBlurLevel] ?? 3;
    if (backdrop === 'blur9') blurPx = Math.round(blurPx * 1.35);
    blurPx = Math.max(1, Math.min(12, blurPx));

    let detectIntervalMs = 150;
    if (qualityProfile === 'quality') detectIntervalMs = 110;
    else if (qualityProfile === 'realtime') detectIntervalMs = 190;

    let temporalSmoothingAlpha = 0.28;
    if (qualityProfile === 'quality') temporalSmoothingAlpha = 0.22;
    else if (qualityProfile === 'realtime') temporalSmoothingAlpha = 0.38;

    const maskVariant = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaskVariant, 4))));
    const transitionGain = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurTransition, 10))));
    const requestedProcessWidth = Math.max(320, Math.min(1920, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessWidth, 960))));
    const requestedProcessFps = Math.max(8, Math.min(30, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessFps, 24))));
    let processWidthCap = 720;
    let processFpsCap = 15;
    if (qualityProfile === 'quality') {
      processWidthCap = 960;
      processFpsCap = 24;
    } else if (qualityProfile === 'realtime') {
      processWidthCap = 640;
      processFpsCap = 12;
    }

    return {
      mode,
      blurPx,
      detectIntervalMs,
      temporalSmoothingAlpha,
      preferFastMatte: qualityProfile !== 'quality',
      maskVariant,
      transitionGain,
      maxProcessWidth: Math.max(320, Math.min(processWidthCap, requestedProcessWidth)),
      maxProcessFps: Math.max(8, Math.min(processFpsCap, requestedProcessFps)),
      autoDisableOnOverload: false,
    };
  }

  function stopEnterCallPreview() {
    enterCallPreviewBackgroundController.dispose();

    const previewNode = enterCallPreviewVideoRef.value;
    if (previewNode instanceof HTMLVideoElement) {
      try {
        previewNode.pause();
      } catch {
        // ignore
      }
      previewNode.srcObject = null;
    }

    for (const streamRef of [enterCallPreviewRawStreamRef, enterCallPreviewStreamRef]) {
      const stream = streamRef.value;
      if (stream instanceof MediaStream) {
        for (const track of stream.getTracks()) track.stop();
      }
      streamRef.value = null;
    }
    enterCallState.previewReady = false;
  }

  function buildPreviewConstraints() {
    const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
    const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
    return {
      video: cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } },
      audio: buildOptionalCallAudioCaptureConstraints(true, microphoneDeviceId),
    };
  }

  async function startEnterCallPreview() {
    stopEnterCallPreview();
    enterCallState.previewReady = false;
    enterCallState.previewError = '';

    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
      enterCallState.previewError = 'Camera preview is not supported in this browser.';
      return;
    }

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
      enterCallPreviewRawStreamRef.value = rawStream;
      const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
      for (const track of rawStream.getAudioTracks()) {
        if (typeof track.applyConstraints === 'function') track.applyConstraints({ volume }).catch(() => {});
      }

      let previewStream = rawStream;
      const backgroundOptions = resolvePreviewBackgroundFilterOptions();
      if (backgroundOptions.mode === 'blur') {
        try {
          const result = await enterCallPreviewBackgroundController.apply(rawStream, backgroundOptions);
          if (result?.stream instanceof MediaStream) previewStream = result.stream;
        } catch {
          previewStream = rawStream;
        }
      }
      enterCallPreviewStreamRef.value = previewStream;

      await nextTick();
      const previewNode = enterCallPreviewVideoRef.value;
      if (!(previewNode instanceof HTMLVideoElement)) return;

      previewNode.muted = true;
      previewNode.srcObject = previewStream;
      await previewNode.play().catch(() => {});
      enterCallState.previewReady = true;
    } catch (error) {
      const message = error instanceof Error ? error.message : t('calls.enter.preview_failed');
      enterCallState.previewError = message || t('calls.enter.preview_failed');
    }
  }

  async function playSpeakerTestSound() {
    if (typeof window === 'undefined') return;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return;

    let context = null;
    const audio = new Audio();
    try {
      context = new AudioContextCtor();
      const destination = context.createMediaStreamDestination();
      const oscillator = context.createOscillator();
      const gainNode = context.createGain();
      const normalizedVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;

      oscillator.type = 'sine';
      oscillator.frequency.value = 880;
      gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
      oscillator.connect(gainNode);
      gainNode.connect(destination);
      audio.srcObject = destination.stream;
      audio.playsInline = true;
      audio.muted = false;
      audio.volume = 1;

      const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
      if (speakerDeviceId !== '' && typeof audio.setSinkId === 'function') {
        await audio.setSinkId(speakerDeviceId).catch(() => {});
      }

      await audio.play();
      oscillator.start();
      oscillator.stop(context.currentTime + 0.22);
      await new Promise((resolve) => setTimeout(resolve, 260));
    } catch {
      // ignore
    } finally {
      try {
        audio.pause();
      } catch {
        // ignore
      }
      audio.srcObject = null;
      if (context && typeof context.close === 'function') await context.close().catch(() => {});
    }
  }

  function closeEnterCallModal() {
    closeEnterAdmissionSocket({
      cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
    });
    enterCallState.open = false;
    resetEnterCallState();
    stopEnterCallPreview();
  }

  async function openPreviewForCallTarget({ callId, roomId }) {
    enterCallState.open = true;
    enterCallState.loading = true;
    enterCallState.error = '';
    enterCallState.code = '';
    enterCallState.expiresAt = '';
    enterCallState.callId = normalizeCallId(callId || '');
    enterCallState.roomId = normalizeRoomId(roomId || 'lobby');
    enterCallState.copyNotice = '';
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    closeEnterAdmissionSocket({ cancel: false });

    try {
      await refreshCallMediaDevices({ requestPermissions: true });
      await startEnterCallPreview();
    } finally {
      enterCallState.loading = false;
    }
  }

  async function openEnterCallModal(call) {
    if (!call || !call.id || !isInvitable(call)) return;
    clearNotice();
    await openPreviewForCallTarget({ callId: call.id, roomId: call.room_id || 'lobby' });
  }

  async function openRedeemedInvitePreview(joinTarget) {
    const normalizedTarget = joinTarget && typeof joinTarget === 'object' ? joinTarget : {};
    await openPreviewForCallTarget({
      callId: normalizedTarget.callId || '',
      roomId: normalizedTarget.roomId || 'lobby',
    });
  }

  async function copyInviteCode() {
    const code = String(enterCallState.code || '').trim();
    if (code === '') return;

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(code);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = code;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-1000px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      }

      enterCallState.copyNotice = 'Copied.';
    } catch {
      enterCallState.copyNotice = 'Copy failed.';
    }
  }

  async function openCallWorkspace(target = null) {
    const normalizedTarget = target && typeof target === 'object' ? target : {};
    enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
    enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
    startEnterAdmissionWait({
      callId: enterCallState.callId,
      roomId: enterCallState.roomId,
    });
  }

  function mountEnterCallPreview() {
    detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
    detachForegroundReconnect = attachForegroundReconnectHandlers({
      onBackground: markEnterAdmissionReconnectAfterForeground,
      onForeground: reconnectEnterAdmissionAfterForeground,
    });
  }

  function unmountEnterCallPreview() {
    if (typeof detachCallMediaWatcher === 'function') {
      detachCallMediaWatcher();
      detachCallMediaWatcher = null;
    }
    if (typeof detachForegroundReconnect === 'function') {
      detachForegroundReconnect();
      detachForegroundReconnect = null;
    }
    closeEnterAdmissionSocket({
      cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
    });
    stopEnterCallPreview();
  }

  watch(
    () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
    () => {
      if (!enterCallState.open) return;
      void startEnterCallPreview();
    },
  );

  watch(
    () => [
      callMediaPrefs.backgroundFilterMode,
      callMediaPrefs.backgroundBackdropMode,
      callMediaPrefs.backgroundQualityProfile,
      callMediaPrefs.backgroundBlurStrength,
      callMediaPrefs.backgroundApplyOutgoing,
      callMediaPrefs.backgroundMaskVariant,
      callMediaPrefs.backgroundBlurTransition,
      callMediaPrefs.backgroundMaxProcessWidth,
      callMediaPrefs.backgroundMaxProcessFps,
    ],
    () => {
      if (!enterCallState.open) return;
      void startEnterCallPreview();
    },
  );

  watch(
    () => callMediaPrefs.microphoneVolume,
    () => {
      const stream = enterCallPreviewStreamRef.value;
      if (!(stream instanceof MediaStream)) return;
      const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
      for (const track of stream.getAudioTracks()) {
        if (typeof track.applyConstraints === 'function') track.applyConstraints({ volume }).catch(() => {});
      }
    },
  );

  return {
    enterCallPreviewVideoRef,
    enterCallState,
    closeEnterCallModal,
    openEnterCallModal,
    openRedeemedInvitePreview,
    copyInviteCode,
    openCallWorkspace,
    playSpeakerTestSound,
    mountEnterCallPreview,
    unmountEnterCallPreview,
  };
}
