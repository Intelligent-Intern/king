import { createLocalPublisherPipelineHelpers } from './publisherPipeline';
import { buildDisplayMediaOptions, hasGetDisplayMedia, normalizeDisplayMediaError } from './screenShareCapture';
import {
  SCREEN_SHARE_MEDIA_SOURCE,
  SCREEN_SHARE_TRACK_LABEL,
} from '../screenShareIdentity.js';

const SCREEN_SHARE_CONNECT_TIMEOUT_MS = 10_000;
const SCREEN_SHARE_CAPTURE_MAX_WIDTH = 960;
const SCREEN_SHARE_CAPTURE_MAX_HEIGHT = 540;
const SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE = 6;
const SCREEN_SHARE_FRAME_MAX_WIDTH = 960;
const SCREEN_SHARE_FRAME_MAX_HEIGHT = 540;
const SCREEN_SHARE_ENCODE_INTERVAL_MS = 250;
const SCREEN_SHARE_FRAME_QUALITY = 32;
const SCREEN_SHARE_KEYFRAME_INTERVAL = 24;
const SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES = 900 * 1024;
const SCREEN_SHARE_MAX_KEYFRAME_BYTES = 1280 * 1024;
const SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND = 1200 * 1024;
const SCREEN_SHARE_MAX_BUFFERED_BYTES = 1024 * 1024;
const SCREEN_SHARE_MAX_QUEUE_AGE_MS = 220;
const SCREEN_SHARE_MAX_ENCODE_MS = 70;
const SCREEN_SHARE_MAX_DRAW_IMAGE_MS = 24;
const SCREEN_SHARE_MAX_READBACK_MS = 34;
const SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO = 0.94;
const SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS = 1300;
const SCREEN_SHARE_RECONNECT_MAX_ATTEMPTS = 5;
const SCREEN_SHARE_RECONNECT_BASE_DELAY_MS = 750;
const SCREEN_SHARE_RECONNECT_MAX_DELAY_MS = 5000;

function mutableRef(value = null) {
  return { value };
}

function createPublisherState() {
  return {
    backgroundBaselineCaptured: false,
    backgroundRuntimeToken: 0,
    localPublisherTeardownInProgress: false,
    localTrackRecoveryAttempts: 0,
    localTrackRecoveryTimer: null,
    localTracksPublishedToSfu: false,
    wlvcEncodeFailureCount: 0,
    wlvcEncodeFirstFailureAtMs: 0,
    wlvcEncodeInFlight: false,
    wlvcEncodeLastErrorLogAtMs: 0,
    wlvcEncodeWarmupUntilMs: 0,
  };
}

function stopStreamTracks(stream) {
  if (typeof MediaStream === 'undefined') return;
  if (!(stream instanceof MediaStream)) return;
  for (const track of stream.getTracks()) {
    try {
      track.stop();
    } catch {
      // best-effort capture cleanup
    }
  }
}

function clearVideoElement(videoRef) {
  const video = videoRef?.value;
  if (typeof HTMLVideoElement !== 'undefined' && video instanceof HTMLVideoElement) {
    try {
      video.pause();
    } catch {
      // ignore stale preview cleanup failures
    }
    video.srcObject = null;
    video.remove();
  }
  if (videoRef) videoRef.value = null;
}

function screenShareDiagnosticsPayload(refs, extra = {}) {
  return {
    media_runtime_path: refs.mediaRuntimePath.value,
    room_id: refs.activeRoomId.value,
    call_id: refs.activeSocketCallId.value,
    ...extra,
  };
}

function positiveNumber(value, fallback = 0) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : fallback;
}

function cappedPositiveNumber(value, fallback, max) {
  const normalized = positiveNumber(value, fallback);
  return Math.max(1, Math.min(max, normalized));
}

function screenShareProfileFrom(baseProfile = {}) {
  const baseId = String(baseProfile?.id || '').trim().toLowerCase();
  const profileId = baseId === 'rescue' ? 'rescue' : 'realtime';
  const captureWidth = cappedPositiveNumber(
    baseProfile.captureWidth,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
  );
  const captureHeight = cappedPositiveNumber(
    baseProfile.captureHeight,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
  );
  const frameWidth = cappedPositiveNumber(
    baseProfile.frameWidth || captureWidth,
    SCREEN_SHARE_FRAME_MAX_WIDTH,
    SCREEN_SHARE_FRAME_MAX_WIDTH,
  );
  const frameHeight = cappedPositiveNumber(
    baseProfile.frameHeight || captureHeight,
    SCREEN_SHARE_FRAME_MAX_HEIGHT,
    SCREEN_SHARE_FRAME_MAX_HEIGHT,
  );
  const captureFrameRate = cappedPositiveNumber(
    baseProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  const encodeIntervalMs = Math.max(
    SCREEN_SHARE_ENCODE_INTERVAL_MS,
    positiveNumber(baseProfile.encodeIntervalMs || baseProfile.readbackIntervalMs, SCREEN_SHARE_ENCODE_INTERVAL_MS),
  );

  return {
    ...baseProfile,
    id: profileId,
    label: 'Screen share',
    captureWidth,
    captureHeight,
    captureFrameRate,
    frameWidth,
    frameHeight,
    frameQuality: Math.min(
      SCREEN_SHARE_FRAME_QUALITY,
      positiveNumber(baseProfile.frameQuality, SCREEN_SHARE_FRAME_QUALITY),
    ),
    keyFrameInterval: Math.max(
      SCREEN_SHARE_KEYFRAME_INTERVAL,
      positiveNumber(baseProfile.keyFrameInterval, SCREEN_SHARE_KEYFRAME_INTERVAL),
    ),
    encodeIntervalMs,
    readbackIntervalMs: encodeIntervalMs,
    readbackFrameRate: Number((1000 / encodeIntervalMs).toFixed(3)),
    maxEncodedBytesPerFrame: Math.min(
      SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES,
      positiveNumber(baseProfile.maxEncodedBytesPerFrame, SCREEN_SHARE_MAX_ENCODED_FRAME_BYTES),
    ),
    maxKeyframeBytesPerFrame: Math.min(
      SCREEN_SHARE_MAX_KEYFRAME_BYTES,
      positiveNumber(baseProfile.maxKeyframeBytesPerFrame, SCREEN_SHARE_MAX_KEYFRAME_BYTES),
    ),
    maxWireBytesPerSecond: Math.min(
      SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND,
      positiveNumber(baseProfile.maxWireBytesPerSecond, SCREEN_SHARE_MAX_WIRE_BYTES_PER_SECOND),
    ),
    maxEncodeMs: Math.min(
      SCREEN_SHARE_MAX_ENCODE_MS,
      positiveNumber(baseProfile.maxEncodeMs, SCREEN_SHARE_MAX_ENCODE_MS),
    ),
    maxDrawImageMs: Math.min(
      SCREEN_SHARE_MAX_DRAW_IMAGE_MS,
      positiveNumber(baseProfile.maxDrawImageMs, SCREEN_SHARE_MAX_DRAW_IMAGE_MS),
    ),
    maxReadbackMs: Math.min(
      SCREEN_SHARE_MAX_READBACK_MS,
      positiveNumber(baseProfile.maxReadbackMs, SCREEN_SHARE_MAX_READBACK_MS),
    ),
    maxQueueAgeMs: Math.min(
      SCREEN_SHARE_MAX_QUEUE_AGE_MS,
      positiveNumber(baseProfile.maxQueueAgeMs, SCREEN_SHARE_MAX_QUEUE_AGE_MS),
    ),
    maxBufferedBytes: Math.min(
      SCREEN_SHARE_MAX_BUFFERED_BYTES,
      positiveNumber(baseProfile.maxBufferedBytes, SCREEN_SHARE_MAX_BUFFERED_BYTES),
    ),
    payloadSoftLimitRatio: Math.min(
      SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO,
      positiveNumber(baseProfile.payloadSoftLimitRatio, SCREEN_SHARE_PAYLOAD_SOFT_LIMIT_RATIO),
    ),
    minKeyframeRetryMs: Math.max(
      SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS,
      positiveNumber(baseProfile.minKeyframeRetryMs, SCREEN_SHARE_MIN_KEYFRAME_RETRY_MS),
    ),
    expectedRecovery: 'hold_screen_share_until_socket_low_water',
  };
}

function screenShareDisplayMediaVideoOptions(videoProfile = {}) {
  const captureFrameRate = cappedPositiveNumber(
    videoProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  return {
    cursor: 'always',
    frameRate: { ideal: captureFrameRate, max: captureFrameRate },
  };
}

function screenShareTrackConstraints(videoProfile = {}) {
  const captureWidth = cappedPositiveNumber(
    videoProfile.captureWidth,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
    SCREEN_SHARE_CAPTURE_MAX_WIDTH,
  );
  const captureHeight = cappedPositiveNumber(
    videoProfile.captureHeight,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
    SCREEN_SHARE_CAPTURE_MAX_HEIGHT,
  );
  const captureFrameRate = cappedPositiveNumber(
    videoProfile.captureFrameRate,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
    SCREEN_SHARE_CAPTURE_MAX_FRAME_RATE,
  );
  return {
    width: { ideal: captureWidth, max: captureWidth },
    height: { ideal: captureHeight, max: captureHeight },
    frameRate: { ideal: captureFrameRate, max: captureFrameRate },
  };
}

async function applyScreenShareTrackConstraints(videoTrack, videoProfile = {}, callbacks = {}) {
  if (!videoTrack || typeof videoTrack.applyConstraints !== 'function') return false;
  const constraints = screenShareTrackConstraints(videoProfile);
  try {
    await videoTrack.applyConstraints(constraints);
    callbacks.captureClientDiagnostic?.({
      category: 'media',
      level: 'info',
      eventType: 'local_screen_share_capture_constraints_applied',
      code: 'local_screen_share_capture_constraints_applied',
      message: 'Screen sharing capture constraints were capped for stable SFU transport.',
      payload: {
        publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
        requested_capture_width: constraints.width.max,
        requested_capture_height: constraints.height.max,
        requested_capture_frame_rate: constraints.frameRate.max,
        outgoing_video_quality_profile: String(videoProfile.id || ''),
      },
    });
    return true;
  } catch (error) {
    callbacks.captureClientDiagnosticError?.('local_screen_share_capture_constraints_failed', error, {
      publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
      requested_capture_width: constraints.width.max,
      requested_capture_height: constraints.height.max,
      requested_capture_frame_rate: constraints.frameRate.max,
      outgoing_video_quality_profile: String(videoProfile.id || ''),
    }, {
      code: 'local_screen_share_capture_constraints_failed',
    });
    return false;
  }
}

async function acquireScreenShareStream(videoProfile = {}) {
  if (!hasGetDisplayMedia()) {
    throw normalizeDisplayMediaError({
      name: 'NotSupportedError',
      message: 'Screen sharing is not supported in this browser.',
    });
  }

  try {
    return await navigator.mediaDevices.getDisplayMedia(buildDisplayMediaOptions({
      audio: false,
      video: screenShareDisplayMediaVideoOptions(videoProfile),
      selfBrowserSurface: 'exclude',
      surfaceSwitching: 'include',
    }));
  } catch (error) {
    throw normalizeDisplayMediaError(error);
  }
}

export function createScreenShareParticipantPublisher({
  callbacks,
  constants,
  refs,
}) {
  const screenRefs = {
    encodeIntervalRef: mutableRef(null),
    localFilteredStreamRef: mutableRef(null),
    localRawStreamRef: mutableRef(null),
    localStreamRef: mutableRef(null),
    localTracksRef: mutableRef([]),
    localVideoElement: mutableRef(null),
    sfuClientRef: mutableRef(null),
    videoEncoderRef: mutableRef(null),
    videoPatchEncoderHeight: mutableRef(0),
    videoPatchEncoderQuality: mutableRef(0),
    videoPatchEncoderRef: mutableRef(null),
    videoPatchEncoderWidth: mutableRef(0),
  };
  const publisherState = createPublisherState();
  const noBackgroundBaselineCollector = { reset() {} };
  const noBackgroundFilterController = {
    dispose() {},
    getCurrentMatteMaskSnapshot() { return null; },
  };

  let activeStream = null;
  let activeVideoTrack = null;
  let endedHandler = null;
  let startStopInFlight = false;
  let stopRequested = false;
  let reconnectTimer = null;
  let reconnectInFlight = false;
  let reconnectAttempts = 0;
  const currentScreenShareSfuVideoProfile = () => screenShareProfileFrom(
    callbacks.currentSfuVideoProfile?.() || {},
  );

  const pipeline = createLocalPublisherPipelineHelpers({
    backgroundBaselineCollector: noBackgroundBaselineCollector,
    backgroundFilterController: noBackgroundFilterController,
    callbacks: {
      additionalPublisherFrameMetrics: () => ({
        publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
      }),
      applyCallOutputPreferences: callbacks.applyCallOutputPreferences,
      canProtectCurrentSfuTargets: callbacks.canProtectCurrentSfuTargets,
      captureClientDiagnostic: callbacks.captureClientDiagnostic,
      currentSfuVideoProfile: currentScreenShareSfuVideoProfile,
      ensureMediaSecuritySession: callbacks.ensureMediaSecuritySession,
      getSfuClientBufferedAmount: () => screenRefs.sfuClientRef.value?.getBufferedAmount?.() || 0,
      handleWlvcEncodeBackpressure: callbacks.handleWlvcEncodeBackpressure,
      handleWlvcFrameSendFailure: callbacks.handleWlvcFrameSendFailure,
      handleWlvcFramePayloadPressure: callbacks.handleWlvcFramePayloadPressure,
      handleWlvcRuntimeEncodeError: callbacks.handleWlvcRuntimeEncodeError,
      hintMediaSecuritySync: callbacks.hintMediaSecuritySync,
      isSfuClientOpen: () => screenRefs.sfuClientRef.value?.isOpen?.() === true,
      isWlvcRuntimePath: callbacks.isWlvcRuntimePath,
      maybeFallbackToNativeRuntime: callbacks.maybeFallbackToNativeRuntime,
      mediaDebugLog: callbacks.mediaDebugLog,
      mountLocalPreview: false,
      noteWlvcSourceReadbackSuccess: callbacks.noteWlvcSourceReadbackSuccess,
      reconfigureLocalTracksFromSelectedDevices: async () => false,
      renderCallVideoLayout: callbacks.renderCallVideoLayout,
      resetBackgroundRuntimeMetrics: () => {},
      resetWlvcBackpressureCounters: callbacks.resetWlvcBackpressureCounters,
      resetWlvcFrameSendFailureCounters: callbacks.resetWlvcFrameSendFailureCounters,
      resolveWlvcEncodeIntervalMs: callbacks.resolveWlvcEncodeIntervalMs,
      shouldDelayWlvcFrameForBackpressure: callbacks.shouldDelayWlvcFrameForBackpressure,
      shouldSendTransportOnlySfuFrame: callbacks.shouldSendTransportOnlySfuFrame,
      shouldThrottleWlvcEncodeLoop: callbacks.shouldThrottleWlvcEncodeLoop,
      stopActivityMonitor: () => {},
      stopSfuTrackAnnounceTimer: () => {},
    },
    captureClientDiagnosticError: callbacks.captureClientDiagnosticError,
    constants,
    refs: {
      currentUserId: () => refs.sessionState.userId,
      encodeIntervalRef: screenRefs.encodeIntervalRef,
      localFilteredStreamRef: screenRefs.localFilteredStreamRef,
      localRawStreamRef: screenRefs.localRawStreamRef,
      localStreamRef: screenRefs.localStreamRef,
      localTracksRef: screenRefs.localTracksRef,
      localVideoElement: screenRefs.localVideoElement,
      mediaRuntimeCapabilitiesRef: refs.mediaRuntimeCapabilities,
      mediaRuntimePathRef: refs.mediaRuntimePath,
      sfuClientRef: screenRefs.sfuClientRef,
      sfuTransportState: refs.sfuTransportState,
      videoEncoderRef: screenRefs.videoEncoderRef,
      videoPatchEncoderHeight: screenRefs.videoPatchEncoderHeight,
      videoPatchEncoderQuality: screenRefs.videoPatchEncoderQuality,
      videoPatchEncoderRef: screenRefs.videoPatchEncoderRef,
      videoPatchEncoderWidth: screenRefs.videoPatchEncoderWidth,
    },
    state: publisherState,
  });

  function isActive() {
    if (typeof MediaStream === 'undefined') return false;
    return activeStream instanceof MediaStream
      && activeVideoTrack
      && String(activeVideoTrack.readyState || '').toLowerCase() !== 'ended';
  }

  function detachEndedHandler() {
    if (activeVideoTrack && endedHandler) {
      activeVideoTrack.removeEventListener('ended', endedHandler);
    }
    endedHandler = null;
  }

  function attachEndedHandler() {
    detachEndedHandler();
    if (!activeVideoTrack) return;
    endedHandler = () => {
      void stop('ended');
    };
    activeVideoTrack.addEventListener('ended', endedHandler, { once: true });
  }

  function clearReconnectTimer() {
    if (reconnectTimer === null) return;
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }

  function resetReconnectState() {
    clearReconnectTimer();
    reconnectInFlight = false;
    reconnectAttempts = 0;
  }

  function resetScreenRefs() {
    screenRefs.localRawStreamRef.value = null;
    screenRefs.localFilteredStreamRef.value = null;
    screenRefs.localStreamRef.value = null;
    screenRefs.localTracksRef.value = [];
    clearVideoElement(screenRefs.localVideoElement);
  }

  function reconnectDelayMsForAttempt(attempt) {
    const exponent = Math.max(0, Number(attempt || 0));
    return Math.min(
      SCREEN_SHARE_RECONNECT_MAX_DELAY_MS,
      SCREEN_SHARE_RECONNECT_BASE_DELAY_MS * Math.max(1, 2 ** exponent),
    );
  }

  function scheduleScreenSfuReconnect(reason = 'sfu_disconnected') {
    if (stopRequested || !isActive()) return false;
    if (reconnectTimer !== null || reconnectInFlight) return true;
    if (reconnectAttempts >= SCREEN_SHARE_RECONNECT_MAX_ATTEMPTS) {
      void stop('disconnected');
      return false;
    }

    const attempt = reconnectAttempts + 1;
    reconnectAttempts = attempt;
    const delayMs = reconnectDelayMsForAttempt(attempt - 1);
    callbacks.captureClientDiagnostic?.({
      category: 'media',
      level: 'warning',
      eventType: 'local_screen_share_sfu_reconnect_scheduled',
      code: 'local_screen_share_sfu_reconnect_scheduled',
      message: 'Screen sharing media routing disconnected; reconnecting the screen publisher without stopping capture.',
      payload: screenShareDiagnosticsPayload(refs, {
        reason: String(reason || 'sfu_disconnected'),
        attempt,
        max_attempts: SCREEN_SHARE_RECONNECT_MAX_ATTEMPTS,
        retry_delay_ms: delayMs,
        publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
      }),
      immediate: attempt <= 2,
    });

    reconnectTimer = setTimeout(async () => {
      reconnectTimer = null;
      if (stopRequested || !isActive()) return;
      reconnectInFlight = true;
      try {
        await waitForScreenSfuConnected();
        reconnectAttempts = 0;
        callbacks.requestWlvcFullFrameKeyframe?.('screen_share_sfu_reconnected', {
          requested_action: 'force_full_keyframe',
          request_full_keyframe: true,
          publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
        });
        if (activeVideoTrack) {
          await pipeline.startEncodingPipeline(activeVideoTrack);
        }
        callbacks.captureClientDiagnostic?.({
          category: 'media',
          level: 'info',
          eventType: 'local_screen_share_sfu_reconnected',
          code: 'local_screen_share_sfu_reconnected',
          message: 'Screen sharing media routing reconnected without stopping capture.',
          payload: screenShareDiagnosticsPayload(refs, {
            attempt,
            publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
          }),
        });
      } catch (error) {
        callbacks.captureClientDiagnosticError?.('local_screen_share_sfu_reconnect_failed', error, screenShareDiagnosticsPayload(refs, {
          attempt,
          max_attempts: SCREEN_SHARE_RECONNECT_MAX_ATTEMPTS,
          publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
        }), {
          code: 'local_screen_share_sfu_reconnect_failed',
          immediate: attempt <= 2,
        });
        reconnectInFlight = false;
        scheduleScreenSfuReconnect('reconnect_failed');
      } finally {
        reconnectInFlight = false;
      }
    }, delayMs);
    return true;
  }

  async function ensureLocalScreenSharePreviewVideo(videoTrack) {
    if (typeof document === 'undefined' || typeof HTMLVideoElement === 'undefined') {
      return null;
    }
    let video = screenRefs.localVideoElement.value;
    if (!(video instanceof HTMLVideoElement)) {
      video = document.createElement('video');
      screenRefs.localVideoElement.value = video;
    }
    video.dataset.callScreenSharePreview = '1';
    delete video.dataset.callLocalPreview;
    video.muted = true;
    video.playsInline = true;
    video.autoplay = true;
    video.srcObject = new MediaStream([videoTrack]);
    try {
      await video.play();
    } catch {
      // keep the local screen tile available even if autoplay is delayed
    }
    return video;
  }

  function buildScreenSfuClient(resolveConnected, rejectConnected) {
    let connected = false;
    let client = null;
    client = new refs.SFUClient({
      onTracks: () => {},
      onUnpublished: () => {},
      onPublisherLeft: () => {},
      onConnected: () => {
        connected = true;
        if (activeVideoTrack) {
          screenRefs.sfuClientRef.value?.publishTracks?.([{
            id: activeVideoTrack.id,
            kind: 'video',
            label: SCREEN_SHARE_TRACK_LABEL,
          }]);
        }
        resolveConnected();
      },
      onDisconnect: () => {
        if (screenRefs.sfuClientRef.value === client) {
          screenRefs.sfuClientRef.value = null;
        }
        if (!connected && !stopRequested) {
          rejectConnected(normalizeDisplayMediaError({
            name: 'NetworkError',
            message: 'Screen sharing could not connect to media routing.',
          }));
          return;
        }
        if (!stopRequested && isActive()) {
          scheduleScreenSfuReconnect('sfu_socket_disconnected');
        }
      },
      onEncodedFrame: () => {},
      onPublisherPressure: (details = {}) => {
        const requestedAction = String(details?.requested_action || details?.requestedAction || '').trim().toLowerCase();
        if (
          requestedAction === 'force_full_keyframe'
          || Boolean(details?.request_full_keyframe || details?.requestFullKeyframe)
        ) {
          callbacks.requestWlvcFullFrameKeyframe?.(String(details?.reason || 'screen_share_recovery_request'), details);
        }
        callbacks.handleWlvcFrameSendFailure?.(
          screenRefs.sfuClientRef.value?.getBufferedAmount?.() || 0,
          String(details?.trackId || details?.track_id || activeVideoTrack?.id || ''),
          String(details?.reason || 'screen_share_publisher_pressure'),
          details,
        );
      },
    }, { autoSubscribe: false });
    return client;
  }

  async function waitForScreenSfuConnected() {
    let timeoutId = null;
    try {
      await new Promise((resolve, reject) => {
        timeoutId = setTimeout(() => {
          try {
            screenRefs.sfuClientRef.value?.leave?.();
          } catch {
            // timeout cleanup is best effort before the retry path opens a new socket
          }
          screenRefs.sfuClientRef.value = null;
          reject(normalizeDisplayMediaError({
            name: 'TimeoutError',
            message: 'Screen sharing media routing timed out.',
          }));
        }, SCREEN_SHARE_CONNECT_TIMEOUT_MS);

        screenRefs.sfuClientRef.value = buildScreenSfuClient(resolve, reject);
        screenRefs.sfuClientRef.value.connect(
          {
            userId: String(refs.sessionState.userId || ''),
            token: String(refs.sessionState.sessionToken || ''),
            name: String(refs.sessionState.displayName || refs.sessionState.email || 'User'),
          },
          refs.activeRoomId.value,
          refs.activeSocketCallId.value,
        );
      });
    } finally {
      if (timeoutId !== null) clearTimeout(timeoutId);
    }
  }

  async function start() {
    if (isActive()) return true;
    if (startStopInFlight) {
      throw normalizeDisplayMediaError({
        name: 'InvalidStateError',
        message: 'Screen sharing is already switching.',
      });
    }

    startStopInFlight = true;
    stopRequested = false;
    resetReconnectState();
    let nextStream = null;
    try {
      const screenShareVideoProfile = currentScreenShareSfuVideoProfile();
      nextStream = await acquireScreenShareStream(screenShareVideoProfile);
      const videoTrack = nextStream.getVideoTracks()[0] || null;
      if (!videoTrack) {
        throw normalizeDisplayMediaError({
          name: 'NotFoundError',
          message: 'No screen sharing video track was selected.',
        });
      }

      activeStream = nextStream;
      activeVideoTrack = videoTrack;
      try {
        videoTrack.contentHint = 'detail';
      } catch {
        // content hints are advisory and not supported uniformly across browsers
      }
      await applyScreenShareTrackConstraints(videoTrack, screenShareVideoProfile, callbacks);
      screenRefs.localRawStreamRef.value = nextStream;
      screenRefs.localFilteredStreamRef.value = nextStream;
      screenRefs.localStreamRef.value = nextStream;
      screenRefs.localTracksRef.value = [videoTrack];

      if (!refs.SFUClient || typeof refs.SFUClient !== 'function') {
        throw normalizeDisplayMediaError({
          name: 'NotSupportedError',
          message: 'Screen sharing media routing is not available.',
        });
      }
      if (!callbacks.isWlvcRuntimePath?.()) {
        throw normalizeDisplayMediaError({
          name: 'NotSupportedError',
          message: 'Screen sharing needs the SFU media runtime.',
        });
      }
      if (String(refs.activeSocketCallId.value || '').trim() === '') {
        throw normalizeDisplayMediaError({
          name: 'InvalidStateError',
          message: 'Join the call before starting screen sharing.',
        });
      }

      const previewVideo = await ensureLocalScreenSharePreviewVideo(videoTrack);
      callbacks.registerLocalScreenSharePeer?.({
        stream: nextStream,
        videoElement: previewVideo,
        videoTrack,
      });
      await waitForScreenSfuConnected();
      await pipeline.startEncodingPipeline(videoTrack);
      callbacks.registerLocalScreenSharePeer?.({
        stream: nextStream,
        videoElement: screenRefs.localVideoElement.value,
        videoTrack,
      });
      attachEndedHandler();
      callbacks.captureClientDiagnostic?.({
        category: 'media',
        level: 'info',
        eventType: 'local_screen_share_participant_started',
        code: 'local_screen_share_participant_started',
        message: 'Local screen sharing joined the call as a separate media participant.',
        payload: screenShareDiagnosticsPayload(refs, {
          track_id: videoTrack.id,
          outgoing_video_quality_profile: String(screenShareVideoProfile.id || ''),
          requested_capture_width: Number(screenShareVideoProfile.captureWidth || 0),
          requested_capture_height: Number(screenShareVideoProfile.captureHeight || 0),
          requested_capture_frame_rate: Number(screenShareVideoProfile.captureFrameRate || 0),
          requested_readback_interval_ms: Number(screenShareVideoProfile.readbackIntervalMs || 0),
          publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
        }),
      });
      return true;
    } catch (error) {
      await stop('failed');
      if (typeof MediaStream !== 'undefined' && nextStream instanceof MediaStream) stopStreamTracks(nextStream);
      callbacks.captureClientDiagnosticError?.('local_screen_share_participant_failed', error, screenShareDiagnosticsPayload(refs), {
        code: 'local_screen_share_participant_failed',
        immediate: true,
      });
      throw error;
    } finally {
      startStopInFlight = false;
    }
  }

  async function stop(reason = 'stopped') {
    stopRequested = true;
    resetReconnectState();
    detachEndedHandler();
    pipeline.stopLocalEncodingPipeline();
    const videoTrackId = String(activeVideoTrack?.id || '');
    const client = screenRefs.sfuClientRef.value;
    if (client && videoTrackId !== '') {
      try {
        client.unpublishTrack(videoTrackId);
      } catch {
        // best-effort unpublish before leaving the screen publisher socket
      }
    }
    if (client) {
      try {
        client.leave();
      } catch {
        // close cleanup is best-effort
      }
    }
    screenRefs.sfuClientRef.value = null;
    stopStreamTracks(activeStream);
    activeStream = null;
    activeVideoTrack = null;
    callbacks.unregisterLocalScreenSharePeer?.({ reason });
    resetScreenRefs();
    if (reason !== 'failed') {
      callbacks.captureClientDiagnostic?.({
        category: 'media',
        level: 'info',
        eventType: 'local_screen_share_participant_stopped',
        code: 'local_screen_share_participant_stopped',
        message: 'Local screen sharing left the call media roster.',
        payload: screenShareDiagnosticsPayload(refs, {
          reason: String(reason || 'stopped'),
          publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
        }),
      });
    }
    if (reason === 'ended' || reason === 'disconnected') {
      callbacks.onScreenShareStopped?.(reason);
    }
    stopRequested = false;
    return true;
  }

  return {
    isActive,
    start,
    stop,
  };
}
