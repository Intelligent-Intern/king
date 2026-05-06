import { nextTick, reactive, ref, watch } from 'vue';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
} from '../../realtime/media/preferences';
import { buildOptionalCallAudioCaptureConstraints } from '../../realtime/media/audioCaptureConstraints';
import { BackgroundFilterController } from '../../realtime/background/controller';

export function normalizeCallAccessMode(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === 'free_for_all' ? 'free_for_all' : 'invite_only';
}

export function createEnterCallController({ apiRequest, clearNotice, isInvitable, router, sessionState }) {
  const enterCallPreviewVideoRef = ref(null);
  const enterCallPreviewRawStreamRef = ref(null);
  const enterCallPreviewStreamRef = ref(null);
  const enterCallPreviewBackgroundController = new BackgroundFilterController();
  const enterCallPreviewPipelineDebug = reactive({
    active: false,
    available: false,
    backend: 'none',
    mode: 'off',
    reactive: false,
    reason: 'idle',
    sourceActive: false,
    sourceState: 'idle',
    stages: [],
  });
  const callAccessLinkEndpointAvailable = ref(true);
  let detachCallMediaWatcher = null;
  let enterCallPreviewResizeHandler = null;

  function syncEnterCallPreviewPipelineDebug(snapshot = {}) {
    enterCallPreviewPipelineDebug.active = Boolean(snapshot?.active);
    enterCallPreviewPipelineDebug.available = Boolean(snapshot?.available);
    enterCallPreviewPipelineDebug.backend = String(snapshot?.backend || 'none');
    enterCallPreviewPipelineDebug.mode = String(snapshot?.mode || 'off');
    enterCallPreviewPipelineDebug.reactive = Boolean(snapshot?.reactive);
    enterCallPreviewPipelineDebug.reason = String(snapshot?.reason || 'idle');
    enterCallPreviewPipelineDebug.sourceActive = snapshot?.sourceActive !== false;
    enterCallPreviewPipelineDebug.sourceState = String(snapshot?.sourceState || 'idle');
    enterCallPreviewPipelineDebug.stages = Array.isArray(snapshot?.stages)
      ? snapshot.stages.map((stage) => ({
          name: String(stage?.name || ''),
          state: String(stage?.state || 'idle'),
        }))
      : [];
  }

  const stopEnterCallPreviewPipelineDebugSubscription = enterCallPreviewBackgroundController.subscribe(
    syncEnterCallPreviewPipelineDebug,
  );

  const enterCallState = reactive({
    open: false,
    loading: false,
    error: '',
    linkUrl: '',
    expiresAt: '',
    callId: '',
    roomId: '',
    callAccessMode: 'invite_only',
    targetKey: '',
    targetOptions: [],
    copyNotice: '',
    previewReady: false,
    previewError: '',
    previewAspectRatio: '16 / 9',
  });

  function resetEnterCallState() {
    enterCallState.loading = false;
    enterCallState.error = '';
    enterCallState.linkUrl = '';
    enterCallState.expiresAt = '';
    enterCallState.callId = '';
    enterCallState.roomId = '';
    enterCallState.callAccessMode = 'invite_only';
    enterCallState.targetKey = '';
    enterCallState.targetOptions = [];
    enterCallState.copyNotice = '';
    enterCallState.previewReady = false;
    enterCallState.previewError = '';
    enterCallState.previewAspectRatio = '16 / 9';
  }

  function resolvePreviewBackgroundFilterOptions() {
    const toFiniteNumber = (value, fallback) => {
      const numeric = Number(value);
      return Number.isFinite(numeric) ? numeric : fallback;
    };
    const requestedMode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
    const mode = requestedMode === 'replace'
      ? 'replace'
      : requestedMode === 'blur'
        ? 'blur'
        : 'off';
    const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
    if (!applyOutgoing || (mode !== 'blur' && mode !== 'replace')) {
      return { mode: 'off' };
    }

    const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
    const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
    const baseBlurLevel = Math.max(0, Math.min(4, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
    const blurStepPx = [1, 2, 3, 4, 5];
    let blurPx = blurStepPx[baseBlurLevel] ?? 3;
    if (backdrop === 'blur9') {
      blurPx = Math.round(blurPx * 1.35);
    }
    blurPx = Math.max(1, Math.min(12, blurPx));

    let detectIntervalMs = 1;
    if (qualityProfile === 'quality') {
      detectIntervalMs = 1;
    } else if (qualityProfile === 'realtime') {
      detectIntervalMs = 1;
    }

    let temporalSmoothingAlpha = 0.28;
    if (qualityProfile === 'quality') {
      temporalSmoothingAlpha = 0.22;
    } else if (qualityProfile === 'realtime') {
      temporalSmoothingAlpha = 0.38;
    }

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
      backgroundColor: mode === 'replace' && backdrop === 'green' ? '#16a34a' : '',
      backgroundImageUrl: mode === 'replace' ? String(callMediaPrefs.backgroundReplacementImageUrl || '').trim() : '',
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

  function updateEnterCallPreviewAspectRatio() {
    if (typeof window === 'undefined') return;
    const width = Math.max(1, Number(window.innerWidth || 0));
    const height = Math.max(1, Number(window.innerHeight || 0));
    enterCallState.previewAspectRatio = `${width} / ${height}`;
  }

  function looksLikeNotFoundError(error) {
    const message = (error instanceof Error ? error.message : String(error || '')).toLowerCase();
    return message.includes('404') || message.includes('not found');
  }

  function fallbackWorkspaceLink(callId) {
    const normalizedCallId = String(callId || '').trim();
    const joinPath = `/workspace/call/${encodeURIComponent(normalizedCallId)}`;
    const origin = typeof window !== 'undefined' ? String(window.location.origin || '').trim() : '';
    return origin !== '' ? `${origin}${joinPath}` : joinPath;
  }

  function normalizeTargetOptionsFromCall(call) {
    const options = [];
    const seen = new Set();
    const internalRows = Array.isArray(call?.participants?.internal) ? call.participants.internal : [];
    for (const row of internalRows) {
      const userId = Number(row?.user_id || 0);
      if (!Number.isInteger(userId) || userId <= 0) continue;
      const key = `user:${userId}`;
      if (seen.has(key)) continue;
      seen.add(key);
      const labelName = String(row?.display_name || row?.email || `User ${userId}`).trim();
      const labelEmail = String(row?.email || '').trim();
      options.push({ key, label: labelEmail !== '' ? `${labelName} · ${labelEmail}` : labelName });
    }
    const externalRows = Array.isArray(call?.participants?.external) ? call.participants.external : [];
    for (const row of externalRows) {
      const email = String(row?.email || '').trim().toLowerCase();
      if (email === '') continue;
      const key = `email:${email}`;
      if (seen.has(key)) continue;
      seen.add(key);
      const labelName = String(row?.display_name || email).trim();
      options.push({ key, label: `${labelName} · ${email}` });
    }

    if (options.length === 0 && Number.isInteger(sessionState.userId) && sessionState.userId > 0) {
      options.push({
        key: `user:${sessionState.userId}`,
        label: `${String(sessionState.displayName || sessionState.email || `User ${sessionState.userId}`).trim()} · ${String(sessionState.email || '').trim()}`,
      });
    }

    return options;
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

    const rawStream = enterCallPreviewRawStreamRef.value;
    if (rawStream instanceof MediaStream) {
      for (const track of rawStream.getTracks()) {
        track.stop();
      }
    }
    enterCallPreviewRawStreamRef.value = null;

    const stream = enterCallPreviewStreamRef.value;
    if (stream instanceof MediaStream) {
      for (const track of stream.getTracks()) {
        track.stop();
      }
    }
    enterCallPreviewStreamRef.value = null;
    enterCallState.previewReady = false;
  }

  function buildPreviewConstraints() {
    const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
    const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
    const video = cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } };
    const audio = buildOptionalCallAudioCaptureConstraints(true, microphoneDeviceId);
    return { video, audio };
  }

  async function startEnterCallPreview() {
    stopEnterCallPreview();
    enterCallState.previewReady = false;
    enterCallState.previewError = '';

    if (typeof navigator === 'undefined' || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      enterCallState.previewError = 'Camera preview is not supported in this browser.';
      return;
    }

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
      enterCallPreviewRawStreamRef.value = rawStream;
      const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
      for (const track of rawStream.getAudioTracks()) {
        if (typeof track.applyConstraints === 'function') {
          track.applyConstraints({ volume }).catch(() => {});
        }
      }

      let previewStream = rawStream;
      const backgroundOptions = resolvePreviewBackgroundFilterOptions();
      if (backgroundOptions.mode === 'blur' || backgroundOptions.mode === 'replace') {
        try {
          const result = await enterCallPreviewBackgroundController.apply(rawStream, backgroundOptions);
          if (result?.stream instanceof MediaStream) {
            previewStream = result.stream;
          }
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
      const message = error instanceof Error ? error.message : 'Could not start camera preview.';
      enterCallState.previewError = message || 'Could not start camera preview.';
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
      if (context && typeof context.close === 'function') {
        await context.close().catch(() => {});
      }
    }
  }

  function closeEnterCallModal() {
    enterCallState.open = false;
    resetEnterCallState();
    stopEnterCallPreview();
  }

  async function openEnterCallModal(call) {
    if (!call || !call.id || !isInvitable(call)) return;

    clearNotice();
    enterCallState.open = true;
    enterCallState.loading = true;
    enterCallState.error = '';
    enterCallState.linkUrl = '';
    enterCallState.expiresAt = '';
    enterCallState.callId = String(call.id);
    enterCallState.roomId = String(call.room_id || 'lobby');
    enterCallState.callAccessMode = normalizeCallAccessMode(call.access_mode);
    enterCallState.targetOptions = normalizeTargetOptionsFromCall(call);
    enterCallState.targetKey = enterCallState.targetOptions[0]?.key || '';
    enterCallState.copyNotice = '';
    updateEnterCallPreviewAspectRatio();

    try {
      await refreshCallMediaDevices({ requestPermissions: true });
      await startEnterCallPreview();
    } finally {
      enterCallState.loading = false;
    }
  }

  async function generateEnterCallLink() {
    const callId = String(enterCallState.callId || '').trim();
    if (callId === '') {
      enterCallState.loading = false;
      enterCallState.error = 'Missing call id.';
      return;
    }

    enterCallState.loading = true;
    enterCallState.error = '';
    enterCallState.linkUrl = '';
    enterCallState.expiresAt = '';

    if (!callAccessLinkEndpointAvailable.value) {
      enterCallState.linkUrl = fallbackWorkspaceLink(callId);
      enterCallState.loading = false;
      return;
    }

    const requestBody = {};
    const callAccessMode = normalizeCallAccessMode(enterCallState.callAccessMode);
    if (callAccessMode === 'free_for_all') {
      requestBody.link_kind = 'open';
    } else {
      requestBody.link_kind = 'personal';
      const targetKey = String(enterCallState.targetKey || '').trim();
      if (targetKey.startsWith('user:')) {
        const parsed = Number(targetKey.slice(5));
        if (Number.isInteger(parsed) && parsed > 0) requestBody.participant_user_id = parsed;
      } else if (targetKey.startsWith('email:')) {
        const email = targetKey.slice(6).trim().toLowerCase();
        if (email !== '') requestBody.participant_email = email;
      }
    }

    try {
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/access-link`, {
        method: 'POST',
        body: requestBody,
      });

      const result = payload?.result || {};
      const accessId = String(result?.access_link?.id || '').trim();
      const joinPathRaw = String(result?.join_path || '').trim();
      const joinPath = joinPathRaw !== '' ? joinPathRaw : (accessId !== '' ? `/join/${accessId}` : '');
      if (joinPath === '') throw new Error('Invite link payload is invalid.');
      const origin = typeof window !== 'undefined' ? String(window.location.origin || '').trim() : '';
      enterCallState.linkUrl = origin !== '' ? `${origin}${joinPath}` : joinPath;
      enterCallState.expiresAt = typeof result?.access_link?.expires_at === 'string' ? result.access_link.expires_at : '';
    } catch (error) {
      if (looksLikeNotFoundError(error)) {
        callAccessLinkEndpointAvailable.value = false;
        enterCallState.linkUrl = fallbackWorkspaceLink(callId);
        enterCallState.error = '';
        enterCallState.expiresAt = '';
        return;
      }
      enterCallState.error = error instanceof Error ? error.message : 'Could not create invite link.';
    } finally {
      enterCallState.loading = false;
    }
  }

  function handleEnterLinkSettingsChanged() {
    enterCallState.copyNotice = '';
    void generateEnterCallLink();
  }

  async function copyInviteCode() {
    const link = String(enterCallState.linkUrl || '').trim();
    if (link === '') return;

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(link);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = link;
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

  async function resolveWorkspaceRouteSegment(target = null) {
    const normalizedTarget = target && typeof target === 'object' ? target : {};
    const callId = String(normalizedTarget.callId || '').trim();
    if (callId !== '') return callId;
    const explicitAccessId = String(normalizedTarget.accessId || '').trim();
    if (explicitAccessId !== '') return explicitAccessId;
    const roomId = String(normalizedTarget.roomId || '').trim();
    return roomId === '' ? 'lobby' : roomId;
  }

  async function openCallWorkspace(target = null) {
    console.log('Opening call workspace with target:', target);
    const routeSegment = await resolveWorkspaceRouteSegment(target);
    console.log('Resolved workspace route segment:', routeSegment);
    closeEnterCallModal();
    await router.push(`/workspace/call/${encodeURIComponent(routeSegment)}`);
  }

  function mountEnterCallPreview() {
    detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
    updateEnterCallPreviewAspectRatio();
    enterCallPreviewResizeHandler = () => updateEnterCallPreviewAspectRatio();
    window.addEventListener('resize', enterCallPreviewResizeHandler);
    window.addEventListener('orientationchange', enterCallPreviewResizeHandler);
  }

  function unmountEnterCallPreview() {
    if (typeof enterCallPreviewResizeHandler === 'function') {
      window.removeEventListener('resize', enterCallPreviewResizeHandler);
      window.removeEventListener('orientationchange', enterCallPreviewResizeHandler);
      enterCallPreviewResizeHandler = null;
    }
    if (typeof detachCallMediaWatcher === 'function') {
      detachCallMediaWatcher();
      detachCallMediaWatcher = null;
    }
    if (typeof stopEnterCallPreviewPipelineDebugSubscription === 'function') {
      stopEnterCallPreviewPipelineDebugSubscription();
    }
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
      callMediaPrefs.backgroundReplacementImageUrl,
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
        if (typeof track.applyConstraints === 'function') {
          track.applyConstraints({ volume }).catch(() => {});
        }
      }
    },
  );

  return {
    enterCallPreviewVideoRef,
    enterCallPreviewPipelineDebug,
    enterCallState,
    callAccessLinkEndpointAvailable,
    updateEnterCallPreviewAspectRatio,
    stopEnterCallPreview,
    closeEnterCallModal,
    openEnterCallModal,
    generateEnterCallLink,
    handleEnterLinkSettingsChanged,
    copyInviteCode,
    openCallWorkspace,
    playSpeakerTestSound,
    mountEnterCallPreview,
    unmountEnterCallPreview,
  };
}
