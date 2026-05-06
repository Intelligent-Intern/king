import { buildOptionalCallAudioCaptureConstraints } from '../media/audioCaptureConstraints';
import { applySfuVideoProfileConstraintsToStream, reportSfuLocalCaptureSettings } from './sfuCaptureProfileConstraints';
import { isLocalMediaPermissionDeniedError, LOCAL_MEDIA_PERMISSION_DENIED_RETRY_COOLDOWN_MS } from './localMediaPermissionPolicy';
export function createLocalMediaOrchestrationHelpers({
  backgroundBaselineCollector,
  backgroundFilterController,
  callbacks,
  callMediaPrefs,
  captureClientDiagnosticError,
  constants,
  controlState,
  refs,
  state,
}) {
  const {
    clearTransientActivityPublishErrorNotice,
    captureClientDiagnostic,
    currentSfuVideoProfile,
    isWlvcRuntimePath,
    normalizeRoomId,
    refreshCallMediaDevices,
    sendSocketFrame,
    shouldMaintainNativePeerConnections,
    shouldSyncNativeLocalTracksBeforeOffer,
    syncNativePeerConnectionsWithRoster,
    syncNativePeerLocalTracks,
    sendNativeOffer,
  } = callbacks;
  const captureDiagnostic = typeof captureClientDiagnostic === 'function' ? captureClientDiagnostic : () => {};

  const localPublisherCallbacks = callbacks.localPublisher && typeof callbacks.localPublisher === 'object'
    ? callbacks.localPublisher
    : {};

  function defaultApplyControlStateToLocalTracks(tracks = []) {
    for (const track of Array.isArray(tracks) ? tracks : []) {
      const kind = String(track?.kind || '').trim().toLowerCase();
      if (kind === 'audio') {
        track.enabled = controlState.micEnabled !== false;
      } else if (kind === 'video') {
        track.enabled = controlState.cameraEnabled !== false;
      }
    }
  }

  const applyControlStateToLocalTracks = typeof localPublisherCallbacks.applyControlStateToLocalTracks === 'function'
    ? localPublisherCallbacks.applyControlStateToLocalTracks
    : defaultApplyControlStateToLocalTracks;
  const bindLocalTrackLifecycle = typeof localPublisherCallbacks.bindLocalTrackLifecycle === 'function'
    ? localPublisherCallbacks.bindLocalTrackLifecycle
    : () => {};
  const clearLocalPreviewElement = typeof localPublisherCallbacks.clearLocalPreviewElement === 'function'
    ? localPublisherCallbacks.clearLocalPreviewElement
    : () => {};
  const scheduleLocalTrackRecovery = typeof localPublisherCallbacks.scheduleLocalTrackRecovery === 'function'
    ? localPublisherCallbacks.scheduleLocalTrackRecovery
    : () => {};
  const startEncodingPipeline = typeof localPublisherCallbacks.startEncodingPipeline === 'function'
    ? localPublisherCallbacks.startEncodingPipeline
    : async () => false;
  const stopLocalEncodingPipeline = typeof localPublisherCallbacks.stopLocalEncodingPipeline === 'function'
    ? localPublisherCallbacks.stopLocalEncodingPipeline
    : () => {};
  const stopRetiredLocalStreams = typeof localPublisherCallbacks.stopRetiredLocalStreams === 'function'
    ? localPublisherCallbacks.stopRetiredLocalStreams
    : () => {};
  const unpublishSfuTracks = typeof localPublisherCallbacks.unpublishSfuTracks === 'function'
    ? localPublisherCallbacks.unpublishSfuTracks
    : () => {};
  let localMediaPermissionRetryAfterMs = 0;

  function buildLocalMediaConstraints() {
    const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
    const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
    const wantsVideo = controlState.cameraEnabled !== false;
    const wantsAudio = controlState.micEnabled !== false;
    const videoProfile = currentSfuVideoProfile();

    function profileVideoConstraints(extra = {}) {
      return {
        width: { ideal: videoProfile.captureWidth, max: videoProfile.captureWidth },
        height: { ideal: videoProfile.captureHeight, max: videoProfile.captureHeight },
        frameRate: { ideal: videoProfile.captureFrameRate, max: videoProfile.captureFrameRate },
        ...extra,
      };
    }

    if (!wantsVideo && !wantsAudio) {
      return { video: false, audio: false };
    }

    const video = !wantsVideo
      ? false
      : cameraDeviceId !== ''
        ? profileVideoConstraints({
            deviceId: { exact: cameraDeviceId },
          })
        : profileVideoConstraints();
    const audio = buildOptionalCallAudioCaptureConstraints(wantsAudio, microphoneDeviceId);

    return { video, audio };
  }

  function buildLooseLocalMediaConstraints() {
    const wantsVideo = controlState.cameraEnabled !== false;
    const wantsAudio = controlState.micEnabled !== false;
    const videoProfile = currentSfuVideoProfile();
    return {
      video: wantsVideo
        ? {
            width: { ideal: videoProfile.captureWidth, max: videoProfile.captureWidth },
            height: { ideal: videoProfile.captureHeight, max: videoProfile.captureHeight },
            frameRate: { ideal: videoProfile.captureFrameRate, max: videoProfile.captureFrameRate },
          }
        : false,
      audio: buildOptionalCallAudioCaptureConstraints(wantsAudio),
    };
  }

  function reportLocalCaptureSettings(stream, reason) {
    reportSfuLocalCaptureSettings({
      stream,
      reason,
      videoProfile: currentSfuVideoProfile(),
      captureDiagnostic,
    });
  }

  async function enforceSfuVideoCaptureProfile(stream, reason) {
    return applySfuVideoProfileConstraintsToStream({
      stream,
      reason,
      videoProfile: currentSfuVideoProfile(),
      captureDiagnostic,
      captureClientDiagnosticError,
      mediaRuntimePath: refs.mediaRuntimePathRef.value,
    });
  }

  function shouldRetryWithLooseConstraints(error) {
    const name = String(error?.name || '').trim();
    return name === 'NotFoundError'
      || name === 'OverconstrainedError'
      || name === 'NotReadableError'
      || name === 'AbortError';
  }

  function enterReceiveOnlyLocalMediaMode(reason = 'local_media_unavailable') {
    const receiveOnlyStream = new MediaStream();
    refs.localRawStreamRef.value = receiveOnlyStream;
    refs.localFilteredStreamRef.value = receiveOnlyStream;
    refs.localStreamRef.value = receiveOnlyStream;
    refs.localTracksRef.value = [];
    state.localTracksPublishedToSfu = true;
    stopLocalEncodingPipeline(); clearLocalPreviewElement();
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'local_media_receive_only_mode',
      code: 'local_media_receive_only_mode',
      message: 'Local camera or microphone capture is unavailable; the participant stays connected in receive-only mode.',
      payload: { reason: String(reason || 'local_media_unavailable'), media_runtime_path: refs.mediaRuntimePathRef.value },
    });
  }

  async function acquireLocalMediaStreamWithFallback() {
    const strictConstraints = buildLocalMediaConstraints();
    const looseConstraints = buildLooseLocalMediaConstraints();
    if (strictConstraints.video === false && strictConstraints.audio === false) {
      return new MediaStream();
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia(strictConstraints);
      await enforceSfuVideoCaptureProfile(stream, 'strict');
      return stream;
    } catch (error) {
      if (!shouldRetryWithLooseConstraints(error)) {
        throw error;
      }
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia(looseConstraints);
      await enforceSfuVideoCaptureProfile(stream, 'loose_retry');
      return stream;
    } catch {
      const fallbackConstraints = {
        video: controlState.cameraEnabled !== false,
        audio: buildOptionalCallAudioCaptureConstraints(controlState.micEnabled !== false),
      };
      if (fallbackConstraints.video !== true && fallbackConstraints.audio !== true) {
        return new MediaStream();
      }
      const stream = await navigator.mediaDevices.getUserMedia(fallbackConstraints);
      await enforceSfuVideoCaptureProfile(stream, 'boolean_fallback');
      return stream;
    }
  }

  function publishLocalTracksToSfuIfReady(options = {}) {
    const force = options?.force === true;
    if (!refs.sfuClientRef.value) return false;
    if (state.localTracksPublishedToSfu && !force) return true;
    if (!callbacks.isSfuClientOpen()) return false;
    const stream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
    if (!(stream instanceof MediaStream)) return false;

    const tracks = stream.getTracks().map((track) => ({
      id: track.id,
      kind: track.kind,
      label: track.label,
    }));

    if (tracks.length === 0) return false;
    refs.sfuClientRef.value.publishTracks(tracks);
    state.localTracksPublishedToSfu = true;
    return true;
  }

  function stopActivityMonitor() {
    if (state.activityMonitorTimer !== null) {
      clearInterval(state.activityMonitorTimer);
      state.activityMonitorTimer = null;
    }
    if (state.activityAudioContext && typeof state.activityAudioContext.close === 'function') {
      state.activityAudioContext.close().catch(() => {});
    }
    state.activityAudioContext = null;
    state.activityAudioAnalyser = null;
    state.activityAudioData = null;
    state.activityMotionCanvas = null;
    state.activityMotionContext = null;
    state.activityPreviousFrame = null;
    state.activityLastPublishMs = 0;
    state.activityLastMotionSampleMs = 0;
    state.activityLastMotionScore = 0;
  }

  function startActivityMonitor(stream) {
    stopActivityMonitor();
    if (!(stream instanceof MediaStream) || typeof window === 'undefined') return;

    const audioTrack = stream.getAudioTracks?.()[0] || null;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (audioTrack && AudioContextCtor) {
      try {
        state.activityAudioContext = new AudioContextCtor();
        const source = state.activityAudioContext.createMediaStreamSource(new MediaStream([audioTrack]));
        state.activityAudioAnalyser = state.activityAudioContext.createAnalyser();
        state.activityAudioAnalyser.fftSize = 512;
        state.activityAudioData = new Uint8Array(state.activityAudioAnalyser.fftSize);
        source.connect(state.activityAudioAnalyser);
      } catch {
        state.activityAudioContext = null;
        state.activityAudioAnalyser = null;
        state.activityAudioData = null;
      }
    }

    if (typeof document !== 'undefined') {
      state.activityMotionCanvas = document.createElement('canvas');
      state.activityMotionCanvas.width = 96;
      state.activityMotionCanvas.height = 72;
      state.activityMotionContext = state.activityMotionCanvas.getContext('2d', { willReadFrequently: true });
    }

    state.activityMonitorTimer = setInterval(() => {
      publishLocalActivitySample();
    }, 200);
  }

  function sampleLocalAudioLevel() {
    if (!state.activityAudioAnalyser || !(state.activityAudioData instanceof Uint8Array)) return 0;
    if (state.activityAudioContext?.state === 'suspended' && typeof state.activityAudioContext.resume === 'function') {
      state.activityAudioContext.resume().catch(() => {});
    }
    try {
      state.activityAudioAnalyser.getByteTimeDomainData(state.activityAudioData);
    } catch {
      return 0;
    }

    let sum = 0;
    for (const value of state.activityAudioData) {
      const centered = (value - 128) / 128;
      sum += centered * centered;
    }
    const rms = Math.sqrt(sum / Math.max(1, state.activityAudioData.length));
    return Math.max(0, Math.min(1, rms * 3.2));
  }

  function sampleLocalMotionScore(nowMs) {
    if ((nowMs - state.activityLastMotionSampleMs) < constants.activityMotionSampleMs) {
      return state.activityLastMotionScore;
    }
    state.activityLastMotionSampleMs = nowMs;

    const video = refs.localVideoElement.value;
    if (!(video instanceof HTMLVideoElement) || video.readyState < 2 || !state.activityMotionContext || !state.activityMotionCanvas) {
      return 0;
    }

    try {
      state.activityMotionContext.drawImage(video, 0, 0, state.activityMotionCanvas.width, state.activityMotionCanvas.height);
      const frame = state.activityMotionContext.getImageData(0, 0, state.activityMotionCanvas.width, state.activityMotionCanvas.height).data;
      if (!(state.activityPreviousFrame instanceof Uint8ClampedArray)) {
        state.activityPreviousFrame = new Uint8ClampedArray(frame);
        state.activityLastMotionScore = 0;
        return 0;
      }

      let diff = 0;
      for (let index = 0; index < frame.length; index += 16) {
        diff += Math.abs(frame[index] - state.activityPreviousFrame[index]);
        diff += Math.abs(frame[index + 1] - state.activityPreviousFrame[index + 1]);
        diff += Math.abs(frame[index + 2] - state.activityPreviousFrame[index + 2]);
      }
      state.activityPreviousFrame = new Uint8ClampedArray(frame);
      const samples = Math.max(1, frame.length / 16);
      state.activityLastMotionScore = Math.max(0, Math.min(1, diff / (samples * 255 * 3) * 5));
      return state.activityLastMotionScore;
    } catch {
      return state.activityLastMotionScore;
    }
  }

  function publishLocalActivitySample(force = false) {
    const nowMs = Date.now();
    if (!force && (nowMs - state.activityLastPublishMs) < constants.activityPublishIntervalMs) return;
    if (!refs.isSocketOnline.value || refs.currentUserId.value <= 0 || refs.activeSocketCallId.value === '') return;
    if (String(refs.normalizedCallLayout.value.strategy || '') === 'manual_pinned') {
      clearTransientActivityPublishErrorNotice();
      return;
    }
    const roomId = normalizeRoomId(refs.activeRoomId.value);
    if (roomId === '' || roomId === 'waiting-room' || roomId !== refs.desiredRoomId.value) return;

    const audioLevel = controlState.micEnabled ? sampleLocalAudioLevel() : 0;
    const motionScore = controlState.cameraEnabled ? sampleLocalMotionScore(nowMs) : 0;
    const speaking = audioLevel >= 0.08;
    const gesture = controlState.handRaised || motionScore >= 0.7 ? 'wave' : '';
    if (!force && audioLevel < 0.03 && motionScore < 0.04 && !gesture) return;

    state.activityLastPublishMs = nowMs;
    callbacks.markParticipantActivity(refs.currentUserId.value, speaking ? 'speaking' : (motionScore > 0.04 ? 'motion' : 'control'), nowMs);
    sendSocketFrame({
      type: 'participant/activity',
      user_id: refs.currentUserId.value,
      audio_level: Number(audioLevel.toFixed(4)),
      speaking,
      motion_score: Number(motionScore.toFixed(4)),
      gesture,
      source: 'client_observed',
    });
  }

  function applyCallOutputPreferences() {
    if (typeof document === 'undefined') return;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;
    const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
    const mediaElements = document.querySelectorAll('.workspace-call-view video, .workspace-call-view audio');

    for (const node of mediaElements) {
      if (!(node instanceof HTMLMediaElement)) continue;
      if (node.closest('#local-video-container')) continue;
      if (!node.muted) {
        node.volume = volume;
      }
      if (speakerDeviceId !== '' && typeof node.setSinkId === 'function') {
        node.setSinkId(speakerDeviceId).catch(() => {});
      }
    }
  }

  function applyCallInputPreferences() {
    const stream = refs.localRawStreamRef.value instanceof MediaStream
      ? refs.localRawStreamRef.value
      : refs.localStreamRef.value;
    if (!(stream instanceof MediaStream)) return;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints !== 'function') continue;
      track.applyConstraints({ volume }).catch(() => {});
    }
  }

  function resetBackgroundRuntimeMetrics(reason = 'idle') {
    callbacks.resetCallBackgroundRuntimeState();
    callMediaPrefs.backgroundFilterReason = reason;
  }

  function isBackgroundFilterEnabledForOutgoing() {
    const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
    return mode === 'blur' && Boolean(callMediaPrefs.backgroundApplyOutgoing);
  }

  function resolveBackgroundFilterOptions(runtimeToken) {
    const toFiniteNumber = (value, fallback) => {
      const numeric = Number(value);
      return Number.isFinite(numeric) ? numeric : fallback;
    };
    const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur' ? 'blur' : 'off';
    const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
    if (!applyOutgoing || mode !== 'blur') {
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
    const maxProcessWidth = Math.max(320, Math.min(processWidthCap, requestedProcessWidth));
    const maxProcessFps = Math.max(8, Math.min(processFpsCap, requestedProcessFps));

    return {
      mode,
      blurPx,
      detectIntervalMs,
      temporalSmoothingAlpha,
      preferFastMatte: qualityProfile !== 'quality',
      maskVariant,
      transitionGain,
      maxProcessWidth,
      maxProcessFps,
      autoDisableOnOverload: false,
      overloadFrameMs: 48,
      overloadConsecutiveFrames: 6,
      statsIntervalMs: 1000,
      onOverload: () => {
        if (runtimeToken !== state.backgroundRuntimeToken) return;
        resetBackgroundRuntimeMetrics('overload');
      },
      onStats: (stats) => {
        if (runtimeToken !== state.backgroundRuntimeToken) return;
        callMediaPrefs.backgroundFilterActive = true;
        callMediaPrefs.backgroundFilterFps = Number(stats?.fps || 0);
        callMediaPrefs.backgroundFilterDetectMs = Number(stats?.avgDetectMs || 0);
        callMediaPrefs.backgroundFilterDetectFps = Number(stats?.detectFps || 0);
        callMediaPrefs.backgroundFilterProcessMs = Number(stats?.avgProcessMs || 0);
        callMediaPrefs.backgroundFilterProcessLoad = Number(stats?.processLoad || 0);

        callMediaPrefs.backgroundBaselineSampleCount = backgroundBaselineCollector.sampleCount();
        const baseline = backgroundBaselineCollector.push(stats);
        callMediaPrefs.backgroundBaselineSampleCount = backgroundBaselineCollector.sampleCount();
        if (!baseline || state.backgroundBaselineCaptured) return;

        state.backgroundBaselineCaptured = true;
        callMediaPrefs.backgroundBaselineMedianFps = baseline.medianFps;
        callMediaPrefs.backgroundBaselineP95Fps = baseline.p95Fps;
        callMediaPrefs.backgroundBaselineMedianDetectMs = baseline.medianDetectMs;
        callMediaPrefs.backgroundBaselineP95DetectMs = baseline.p95DetectMs;
        callMediaPrefs.backgroundBaselineMedianDetectFps = baseline.medianDetectFps;
        callMediaPrefs.backgroundBaselineP95DetectFps = baseline.p95DetectFps;
        callMediaPrefs.backgroundBaselineMedianProcessMs = baseline.medianProcessMs;
        callMediaPrefs.backgroundBaselineP95ProcessMs = baseline.p95ProcessMs;
        callMediaPrefs.backgroundBaselineMedianProcessLoad = baseline.medianProcessLoad;
        callMediaPrefs.backgroundBaselineP95ProcessLoad = baseline.p95ProcessLoad;

        const gateResult = callbacks.evaluateBackgroundFilterGates({
          medianFps: baseline.medianFps,
          medianDetectMs: baseline.medianDetectMs,
          medianProcessLoad: baseline.medianProcessLoad,
        });
        callMediaPrefs.backgroundBaselineGatePass = gateResult.pass;
        callMediaPrefs.backgroundBaselineGateFpsPass = gateResult.fpsPass;
        callMediaPrefs.backgroundBaselineGateDetectPass = gateResult.detectPass;
        callMediaPrefs.backgroundBaselineGateLoadPass = gateResult.loadPass;
      },
    };
  }

  async function applyLocalBackgroundFilter(rawStream) {
    const runtimeToken = ++state.backgroundRuntimeToken;
    backgroundBaselineCollector.reset();
    state.backgroundBaselineCaptured = false;

    const options = resolveBackgroundFilterOptions(runtimeToken);
    if (options.mode !== 'blur') {
      resetBackgroundRuntimeMetrics('off');
      return rawStream;
    }

    resetBackgroundRuntimeMetrics('starting');
    const result = await backgroundFilterController.apply(rawStream, options);
    if (runtimeToken !== state.backgroundRuntimeToken || result?.stale) return rawStream;

    if (result?.active) {
      callMediaPrefs.backgroundFilterActive = true;
      callMediaPrefs.backgroundFilterReason = result.reason === 'ok_fallback' ? 'ok_fallback' : 'ok';
      callMediaPrefs.backgroundFilterBackend = String(result.backend || 'none');
    } else {
      callMediaPrefs.backgroundFilterActive = false;
      callMediaPrefs.backgroundFilterReason = String(result?.reason || 'setup_failed');
      callMediaPrefs.backgroundFilterBackend = 'none';
    }

    if (result?.stream instanceof MediaStream) {
      return result.stream;
    }
    return rawStream;
  }

  function queueLocalTrackReconfigure(mode = 'devices') {
    const normalizedMode = mode === 'filter' ? 'filter' : 'devices';
    if (state.localTrackReconfigureQueuedMode === 'devices') return;
    state.localTrackReconfigureQueuedMode = normalizedMode;
  }

  function consumeQueuedLocalTrackReconfigureMode() {
    const queuedMode = state.localTrackReconfigureQueuedMode;
    state.localTrackReconfigureQueuedMode = null;
    return queuedMode;
  }

  function nextLocalMediaCaptureGeneration() {
    const nextGeneration = Math.max(0, Number(state.localMediaCaptureGeneration || 0)) + 1;
    state.localMediaCaptureGeneration = nextGeneration;
    return nextGeneration;
  }

  function isCurrentLocalMediaCaptureGeneration(generation) {
    return Number(generation || 0) === Math.max(0, Number(state.localMediaCaptureGeneration || 0));
  }

  function discardStaleLocalMediaCapture(generation, streams = []) {
    if (isCurrentLocalMediaCaptureGeneration(generation)) return false;
    stopRetiredLocalStreams(streams, []);
    return true;
  }

  function cancelPendingLocalMediaCapture() {
    nextLocalMediaCaptureGeneration();
    state.localTrackReconfigureQueuedMode = null;
  }

  async function publishLocalTracks() {
    if (localMediaPermissionRetryAfterMs > Date.now()) {
      if (!(refs.localStreamRef.value instanceof MediaStream)) enterReceiveOnlyLocalMediaMode('permission_denied_cooldown');
      return true;
    }
    if (refs.localStreamRef.value instanceof MediaStream) {
      publishLocalTracksToSfuIfReady();
      if (isWlvcRuntimePath()) {
        const videoTrack = refs.localStreamRef.value.getVideoTracks?.()[0] || null;
        if (videoTrack) {
          if (!refs.encodeIntervalRef.value) {
            await startEncodingPipeline(videoTrack);
          }
        } else {
          stopLocalEncodingPipeline();
          clearLocalPreviewElement();
        }
      }
      return true;
    }
    if (typeof navigator === 'undefined' || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      return false;
    }

    const captureGeneration = nextLocalMediaCaptureGeneration();
    try {
      const rawStream = await acquireLocalMediaStreamWithFallback();
      if (discardStaleLocalMediaCapture(captureGeneration, [rawStream])) return false;
      refs.localRawStreamRef.value = rawStream;
      reportLocalCaptureSettings(rawStream, 'publish');

      const stream = await applyLocalBackgroundFilter(rawStream);
      if (discardStaleLocalMediaCapture(captureGeneration, [stream, rawStream])) return false;
      refs.localFilteredStreamRef.value = stream;
      refs.localStreamRef.value = stream;
      refs.localTracksRef.value = stream.getTracks();
      applyControlStateToLocalTracks(refs.localTracksRef.value);
      startActivityMonitor(stream);
      state.localTracksPublishedToSfu = false;
      state.localTrackRecoveryAttempts = 0;
      bindLocalTrackLifecycle(stream);
      publishLocalTracksToSfuIfReady();
      applyCallInputPreferences();
      applyCallOutputPreferences();
      if (shouldMaintainNativePeerConnections()) {
        syncNativePeerConnectionsWithRoster();
        for (const peer of refs.nativePeerConnectionsRef.value.values()) {
          if (!shouldSyncNativeLocalTracksBeforeOffer(peer)) continue;
          void syncNativePeerLocalTracks(peer).then(() => {
            if (peer.initiator && !peer.negotiating) {
              void sendNativeOffer(peer);
            }
          });
        }
      }

      const videoTrack = stream.getVideoTracks()[0];
      if (videoTrack) {
        await startEncodingPipeline(videoTrack);
        if (discardStaleLocalMediaCapture(captureGeneration, [stream, rawStream])) {
          stopLocalEncodingPipeline();
          clearLocalPreviewElement();
          return false;
        }
      } else {
        stopLocalEncodingPipeline();
        clearLocalPreviewElement();
      }
      return true;
    } catch (error) {
      callbacks.mediaDebugLog('[SFU] Failed to get user media:', error);
      if (isLocalMediaPermissionDeniedError(error)) {
        localMediaPermissionRetryAfterMs = Date.now() + LOCAL_MEDIA_PERMISSION_DENIED_RETRY_COOLDOWN_MS;
        enterReceiveOnlyLocalMediaMode('permission_denied');
      }
      captureClientDiagnosticError('local_user_media_failed', error, {
        media_runtime_path: refs.mediaRuntimePathRef.value,
        sfu_runtime_enabled: constants.sfuRuntimeEnabled,
      }, { code: 'local_user_media_failed', immediate: true });
      return isLocalMediaPermissionDeniedError(error);
    }
  }

  async function reconfigureLocalBackgroundFilterOnly() {
    const rawStream = refs.localRawStreamRef.value instanceof MediaStream ? refs.localRawStreamRef.value : null;
    if (!(rawStream instanceof MediaStream)) {
      return reconfigureLocalTracksFromSelectedDevices();
    }
    if (!(refs.localStreamRef.value instanceof MediaStream)) {
      return publishLocalTracks();
    }
    if (state.localTrackReconfigureInFlight) {
      queueLocalTrackReconfigure('filter');
      return false;
    }

    state.localTrackReconfigureInFlight = true;
    const captureGeneration = nextLocalMediaCaptureGeneration();
    try {
      const previousFilteredStream = refs.localFilteredStreamRef.value instanceof MediaStream ? refs.localFilteredStreamRef.value : null;
      const previousOutputStream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
      const previousTracks = Array.isArray(refs.localTracksRef.value) ? [...refs.localTracksRef.value] : [];

      const nextStream = await applyLocalBackgroundFilter(rawStream);
      if (discardStaleLocalMediaCapture(captureGeneration, [nextStream])) return false;
      if (!(nextStream instanceof MediaStream)) {
        return false;
      }

      const streamChanged = nextStream !== previousOutputStream;
      refs.localFilteredStreamRef.value = nextStream;
      refs.localStreamRef.value = nextStream;
      refs.localTracksRef.value = nextStream.getTracks();
      applyControlStateToLocalTracks(refs.localTracksRef.value);
      startActivityMonitor(nextStream);
      bindLocalTrackLifecycle(nextStream);

      if (streamChanged) {
        state.localTracksPublishedToSfu = false;
        unpublishSfuTracks(previousTracks);
        publishLocalTracksToSfuIfReady();

        if (shouldMaintainNativePeerConnections()) {
          syncNativePeerConnectionsWithRoster();
          for (const peer of refs.nativePeerConnectionsRef.value.values()) {
            if (!shouldSyncNativeLocalTracksBeforeOffer(peer)) continue;
            await syncNativePeerLocalTracks(peer);
            if (peer.initiator && !peer.negotiating) {
              void sendNativeOffer(peer);
            }
          }
        }

        const videoTrack = nextStream.getVideoTracks()[0] || null;
        if (videoTrack) {
          await startEncodingPipeline(videoTrack);
          if (discardStaleLocalMediaCapture(captureGeneration, [nextStream])) {
            stopLocalEncodingPipeline();
            clearLocalPreviewElement();
            return false;
          }
        } else {
          stopLocalEncodingPipeline();
          clearLocalPreviewElement();
        }
        stopRetiredLocalStreams([previousOutputStream, previousFilteredStream], [nextStream, rawStream]);
      }

      if (!isBackgroundFilterEnabledForOutgoing()) {
        backgroundFilterController.dispose();
      }

      applyCallInputPreferences();
      applyCallOutputPreferences();
      state.localTrackRecoveryAttempts = 0;
      return true;
    } catch {
      return false;
    } finally {
      state.localTrackReconfigureInFlight = false;
      const queuedMode = consumeQueuedLocalTrackReconfigureMode();
      if (queuedMode === 'devices') {
        void reconfigureLocalTracksFromSelectedDevices();
      } else if (queuedMode === 'filter') {
        void reconfigureLocalBackgroundFilterOnly();
      }
    }
  }

  async function reconfigureLocalTracksFromSelectedDevices() {
    if (!(refs.localStreamRef.value instanceof MediaStream)) {
      return publishLocalTracks();
    }
    if (state.localTrackReconfigureInFlight) {
      queueLocalTrackReconfigure('devices');
      return false;
    }

    state.localTrackReconfigureInFlight = true;
    const captureGeneration = nextLocalMediaCaptureGeneration();
    let nextRawStream = null;
    let nextOutputStream = null;
    try {
      const previousRawStream = refs.localRawStreamRef.value instanceof MediaStream ? refs.localRawStreamRef.value : null;
      const previousFilteredStream = refs.localFilteredStreamRef.value instanceof MediaStream ? refs.localFilteredStreamRef.value : null;
      const previousOutputStream = refs.localStreamRef.value instanceof MediaStream ? refs.localStreamRef.value : null;
      const previousTracks = Array.isArray(refs.localTracksRef.value) ? [...refs.localTracksRef.value] : [];

      nextRawStream = await acquireLocalMediaStreamWithFallback();
      if (discardStaleLocalMediaCapture(captureGeneration, [nextRawStream])) return false;
      reportLocalCaptureSettings(nextRawStream, 'reconfigure');
      nextOutputStream = await applyLocalBackgroundFilter(nextRawStream);
      if (discardStaleLocalMediaCapture(captureGeneration, [nextOutputStream, nextRawStream])) return false;
      if (!(nextOutputStream instanceof MediaStream)) {
        stopRetiredLocalStreams([nextRawStream], []);
        return false;
      }

      refs.localRawStreamRef.value = nextRawStream;
      refs.localFilteredStreamRef.value = nextOutputStream;
      refs.localStreamRef.value = nextOutputStream;
      refs.localTracksRef.value = nextOutputStream.getTracks();
      applyControlStateToLocalTracks(refs.localTracksRef.value);
      startActivityMonitor(nextOutputStream);
      bindLocalTrackLifecycle(nextOutputStream);
      state.localTracksPublishedToSfu = false;

      unpublishSfuTracks(previousTracks);
      publishLocalTracksToSfuIfReady();
      applyCallInputPreferences();
      applyCallOutputPreferences();

      if (shouldMaintainNativePeerConnections()) {
        syncNativePeerConnectionsWithRoster();
        for (const peer of refs.nativePeerConnectionsRef.value.values()) {
          if (!shouldSyncNativeLocalTracksBeforeOffer(peer)) continue;
          await syncNativePeerLocalTracks(peer);
          if (peer.initiator && !peer.negotiating) {
            void sendNativeOffer(peer);
          }
        }
      }

      const videoTrack = nextOutputStream.getVideoTracks()[0] || null;
      if (videoTrack) {
        await startEncodingPipeline(videoTrack);
        if (discardStaleLocalMediaCapture(captureGeneration, [nextOutputStream, nextRawStream])) {
          stopLocalEncodingPipeline();
          clearLocalPreviewElement();
          return false;
        }
      } else {
        stopLocalEncodingPipeline();
        clearLocalPreviewElement();
      }

      stopRetiredLocalStreams([previousOutputStream, previousRawStream, previousFilteredStream], [nextOutputStream, nextRawStream]);

      if (!isBackgroundFilterEnabledForOutgoing()) {
        backgroundFilterController.dispose();
      }

      await refreshCallMediaDevices();
      state.localTrackRecoveryAttempts = 0;
      return true;
    } catch {
      stopRetiredLocalStreams([nextOutputStream, nextRawStream], [refs.localStreamRef.value, refs.localRawStreamRef.value]);
      scheduleLocalTrackRecovery('reconfigure_failed');
      return false;
    } finally {
      state.localTrackReconfigureInFlight = false;
      const queuedMode = consumeQueuedLocalTrackReconfigureMode();
      if (queuedMode === 'devices') {
        void reconfigureLocalTracksFromSelectedDevices();
      } else if (queuedMode === 'filter') {
        void reconfigureLocalBackgroundFilterOnly();
      }
    }
  }

  return {
    acquireLocalMediaStreamWithFallback,
    applyCallInputPreferences,
    applyCallOutputPreferences,
    applyControlStateToLocalTracks,
    applyLocalBackgroundFilter,
    isBackgroundFilterEnabledForOutgoing,
    publishLocalActivitySample,
    publishLocalTracks,
    publishLocalTracksToSfuIfReady,
    cancelPendingLocalMediaCapture,
    queueLocalTrackReconfigure,
    consumeQueuedLocalTrackReconfigureMode,
    reconfigureLocalBackgroundFilterOnly,
    reconfigureLocalTracksFromSelectedDevices,
    resetBackgroundRuntimeMetrics,
    startActivityMonitor,
    stopActivityMonitor,
  };
}
