import { createLocalPublisherPipelineHelpers } from './publisherPipeline';
import { buildDisplayMediaOptions, hasGetDisplayMedia, normalizeDisplayMediaError } from './screenShareCapture';
import {
  SCREEN_SHARE_MEDIA_SOURCE,
  SCREEN_SHARE_TRACK_LABEL,
} from '../screenShareIdentity.js';

const SCREEN_SHARE_CONNECT_TIMEOUT_MS = 10_000;

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

async function acquireScreenShareStream() {
  if (!hasGetDisplayMedia()) {
    throw normalizeDisplayMediaError({
      name: 'NotSupportedError',
      message: 'Screen sharing is not supported in this browser.',
    });
  }

  try {
    return await navigator.mediaDevices.getDisplayMedia(buildDisplayMediaOptions({
      audio: false,
      video: { cursor: 'always' },
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
      currentSfuVideoProfile: callbacks.currentSfuVideoProfile,
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

  function resetScreenRefs() {
    screenRefs.localRawStreamRef.value = null;
    screenRefs.localFilteredStreamRef.value = null;
    screenRefs.localStreamRef.value = null;
    screenRefs.localTracksRef.value = [];
    clearVideoElement(screenRefs.localVideoElement);
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
    return new refs.SFUClient({
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
        if (!connected && !stopRequested) {
          rejectConnected(normalizeDisplayMediaError({
            name: 'NetworkError',
            message: 'Screen sharing could not connect to media routing.',
          }));
          return;
        }
        if (!stopRequested && isActive()) {
          void stop('disconnected');
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
  }

  async function waitForScreenSfuConnected() {
    let timeoutId = null;
    try {
      await new Promise((resolve, reject) => {
        timeoutId = setTimeout(() => {
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
    let nextStream = null;
    try {
      nextStream = await acquireScreenShareStream();
      const videoTrack = nextStream.getVideoTracks()[0] || null;
      if (!videoTrack) {
        throw normalizeDisplayMediaError({
          name: 'NotFoundError',
          message: 'No screen sharing video track was selected.',
        });
      }

      activeStream = nextStream;
      activeVideoTrack = videoTrack;
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
