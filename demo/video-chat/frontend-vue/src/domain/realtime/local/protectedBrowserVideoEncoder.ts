import { measureProtectedSfuFrameBudget } from '../media/protectedFrameBudget';
import {
  closePublisherVideoFrame,
  createPublisherVideoFrameSourceReader,
  PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
} from './publisherVideoFrameSource.ts';
import {
  highResolutionNowMs,
  markPublisherFrameTraceStage,
  publisherFrameTraceMetrics,
  roundedStageMs,
} from './publisherFrameTrace.ts';
import { resolveProfileReadbackIntervalMs } from './videoFrameSizing.ts';
import {
  createBrowserVideoFrameScaler,
  videoFrameSourceDimensions,
} from './browserVideoFrameScaler.ts';
import {
  browserEncoderConfigKey,
  buildBrowserEncoderConfig,
  closeBrowserEncoder,
  resolveBrowserEncoderFrameSize,
  resolveSupportedBrowserEncoderConfig,
  shouldScaleBrowserFrame,
} from './browserVideoEncoderConfig.ts';

export const PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8';
export const PROTECTED_BROWSER_VIDEO_RUNTIME_ID = 'wlvc_sfu';
export const PROTECTED_BROWSER_VIDEO_SOURCE_BACKEND = 'video_frame_webcodecs_vp8';
export const PROTECTED_BROWSER_VIDEO_READBACK_METHOD = 'video_frame_webcodecs_direct';

function functionRef(value) {
  return typeof value === 'function' ? value : null;
}

function positiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function normalizeVideoLayer(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail';
  if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary';
  return '';
}

function normalizedFrameKind(value) {
  return String(value || '').trim().toLowerCase() === 'key' ? 'keyframe' : 'delta';
}

function browserEncoderLifecycleCloseError(error) {
  const name = String(error?.name || '').trim().toLowerCase();
  const message = String(error?.message || error || '').trim().toLowerCase();
  return (
    (name === 'aborterror' && message.includes('close'))
    || message.includes('aborted due to close')
    || message.includes("cannot call 'encode' on a closed codec")
    || message.includes('cannot call encode on a closed codec')
    || message.includes('closed codec')
  );
}

function normalizeProtectedBrowserVideoEncoderCloseReason(reason) {
  const normalized = String(reason || '').trim().toLowerCase();
  if (normalized.includes('profile')) return 'protected_browser_video_profile_switch_close';
  if (normalized.includes('reconnect') || normalized.includes('transport')) return 'protected_browser_video_transport_reconnect_close';
  if (normalized.includes('background') || normalized.includes('pause')) return 'protected_browser_video_background_pause_close';
  return normalized || 'protected_browser_video_encoder_closed';
}

function copyEncodedChunkToArrayBuffer(chunk) {
  const byteLength = positiveInteger(chunk?.byteLength, 0);
  if (byteLength <= 0 || typeof chunk?.copyTo !== 'function') return null;
  const bytes = new Uint8Array(byteLength);
  chunk.copyTo(bytes);
  return bytes.buffer;
}

export function detectProtectedBrowserVideoEncoderCapabilities({
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
} = {}) {
  const VideoEncoderCtor = functionRef(globalScope.VideoEncoder);
  const VideoDecoderCtor = functionRef(globalScope.VideoDecoder);
  const EncodedVideoChunkCtor = functionRef(globalScope.EncodedVideoChunk);
  const MediaStreamTrackProcessorCtor = functionRef(globalScope.MediaStreamTrackProcessor);
  const VideoFrameCtor = functionRef(globalScope.VideoFrame);
  return {
    supportsVideoEncoder: Boolean(VideoEncoderCtor),
    supportsVideoEncoderConfigProbe: typeof VideoEncoderCtor?.isConfigSupported === 'function',
    supportsVideoDecoder: Boolean(VideoDecoderCtor),
    supportsEncodedVideoChunk: Boolean(EncodedVideoChunkCtor),
    supportsMediaStreamTrackProcessor: Boolean(MediaStreamTrackProcessorCtor),
    supportsVideoFrame: Boolean(VideoFrameCtor),
    supportsVideoFrameClose: typeof VideoFrameCtor?.prototype?.close === 'function',
    preferredCodec: PROTECTED_BROWSER_VIDEO_CODEC_ID,
  };
}

export function protectedBrowserVideoCapabilityDiagnosticPayload(capabilities) {
  const detected = capabilities && typeof capabilities === 'object'
    ? capabilities
    : detectProtectedBrowserVideoEncoderCapabilities();
  return {
    browser_encoder_codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
    supports_video_encoder: Boolean(detected.supportsVideoEncoder),
    supports_video_encoder_config_probe: Boolean(detected.supportsVideoEncoderConfigProbe),
    supports_video_decoder: Boolean(detected.supportsVideoDecoder),
    supports_encoded_video_chunk: Boolean(detected.supportsEncodedVideoChunk),
    supports_media_stream_track_processor: Boolean(detected.supportsMediaStreamTrackProcessor),
    supports_video_frame: Boolean(detected.supportsVideoFrame),
    supports_video_frame_close: Boolean(detected.supportsVideoFrameClose),
  };
}

function canAttemptProtectedBrowserVideoEncoder(capabilities) {
  return Boolean(
    capabilities.supportsVideoEncoder
      && capabilities.supportsVideoDecoder
      && capabilities.supportsEncodedVideoChunk
      && capabilities.supportsMediaStreamTrackProcessor
      && capabilities.supportsVideoFrame
      && capabilities.supportsVideoFrameClose,
  );
}

function browserEncoderTransportMetrics({
  trace,
  videoProfile,
  config,
  videoLayer = 'primary',
  encodedPayloadBytes,
  encodedFrameType,
  encodeMs,
  maxEncodedPayloadBytes,
}) {
  const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
  const frameSize = trace?.frameSize && typeof trace.frameSize === 'object' ? trace.frameSize : {};
  return {
    ...publisherFrameTraceMetrics(trace),
    video_layer: normalizedVideoLayer,
    selected_video_layer: normalizedVideoLayer,
    outgoing_video_quality_profile: String(videoProfile?.id || ''),
    selected_video_quality_profile: String(videoProfile?.id || ''),
    active_capture_backend: PROTECTED_BROWSER_VIDEO_SOURCE_BACKEND,
    publisher_source_backend: PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
    publisher_browser_encoder_codec: PROTECTED_BROWSER_VIDEO_CODEC_ID,
    publisher_browser_encoder_layer: normalizedVideoLayer,
    publisher_browser_encoder_config_codec: String(config.codec || ''),
    publisher_browser_encoder_bitrate: positiveInteger(config.bitrate, 0),
    publisher_browser_encoder_hardware_acceleration: String(config.hardwareAcceleration || ''),
    publisher_browser_encoder_latency_mode: String(config.latencyMode || ''),
    publisher_readback_method: PROTECTED_BROWSER_VIDEO_READBACK_METHOD,
    capture_width: positiveInteger(videoProfile?.captureWidth, 0),
    capture_height: positiveInteger(videoProfile?.captureHeight, 0),
    capture_frame_rate: Number(videoProfile?.captureFrameRate || 0),
    frame_width: positiveInteger(config.width, 0),
    frame_height: positiveInteger(config.height, 0),
    profile_frame_width: positiveInteger(frameSize.profileFrameWidth, 0),
    profile_frame_height: positiveInteger(frameSize.profileFrameHeight, 0),
    source_frame_width: positiveInteger(frameSize.sourceWidth, 0),
    source_frame_height: positiveInteger(frameSize.sourceHeight, 0),
    source_crop_x: Math.max(0, Number(frameSize.sourceCropX || 0)),
    source_crop_y: Math.max(0, Number(frameSize.sourceCropY || 0)),
    source_crop_width: Math.max(0, Number(frameSize.sourceCropWidth || 0)),
    source_crop_height: Math.max(0, Number(frameSize.sourceCropHeight || 0)),
    source_aspect_ratio: Math.max(0, Number(frameSize.sourceAspectRatio || 0)),
    publisher_aspect_mode: String(frameSize.aspectMode || ''),
    publisher_framing_mode: String(frameSize.framingMode || ''),
    encoded_payload_bytes: encodedPayloadBytes,
    max_payload_bytes: maxEncodedPayloadBytes,
    budget_max_encoded_bytes_per_frame: positiveInteger(videoProfile?.maxEncodedBytesPerFrame, 0),
    budget_max_keyframe_bytes_per_frame: positiveInteger(videoProfile?.maxKeyframeBytesPerFrame, 0),
    budget_max_wire_bytes_per_second: positiveInteger(videoProfile?.maxWireBytesPerSecond, 0),
    encode_ms: encodeMs,
    frame_type: encodedFrameType,
  };
}

export async function createProtectedBrowserVideoEncoderPublisher({
  videoTrack,
  videoProfile,
  pipelineProfileId,
  constants,
  refs,
  callbacks,
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
}) {
  const capabilities = detectProtectedBrowserVideoEncoderCapabilities({ globalScope });
  const {
    canProtectCurrentSfuTargets,
    ensureMediaSecuritySession,
    getSfuClientBufferedAmount,
    handleWlvcEncodeBackpressure,
    handleWlvcFramePayloadPressure,
    handleWlvcFrameSendFailure,
    hintMediaSecuritySync,
    isSfuClientOpen,
    isWlvcRuntimePath,
    mediaDebugLog,
    resetWlvcBackpressureCounters,
    resetWlvcFrameSendFailureCounters,
    resolveWlvcEncodeIntervalMs,
    shouldDelayWlvcFrameForBackpressure,
    shouldSendTransportOnlySfuFrame,
    shouldThrottleWlvcEncodeLoop,
  } = callbacks;
  const captureClientDiagnostic = callbacks.captureClientDiagnostic || (() => {});
  const captureClientDiagnosticError = callbacks.captureClientDiagnosticError || (() => {});
  const currentSfuVideoProfile = callbacks.currentSfuVideoProfile || (() => videoProfile);
  const onProtectedBrowserEncoderFailure = callbacks.onProtectedBrowserEncoderFailure || (() => {});
  const additionalPublisherFrameMetrics = typeof callbacks.additionalPublisherFrameMetrics === 'function'
    ? callbacks.additionalPublisherFrameMetrics
    : () => ({});

  if (!canAttemptProtectedBrowserVideoEncoder(capabilities)) {
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_browser_encoder_capabilities_unavailable',
      code: 'sfu_browser_encoder_capabilities_unavailable',
      message: 'Browser WebCodecs encoder path is missing required APIs; publisher will fall back to the compatibility path.',
      payload: {
        ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
        track_id: String(videoTrack?.id || ''),
        outgoing_video_quality_profile: String(videoProfile?.id || ''),
      },
    });
    return null;
  }

  const VideoEncoderCtor = globalScope.VideoEncoder;
  let reader = null;
  try {
    reader = createPublisherVideoFrameSourceReader({
      videoTrack,
      MediaStreamTrackProcessorCtor: globalScope.MediaStreamTrackProcessor,
      readTimeoutMs: Math.max(600, resolveProfileReadbackIntervalMs(videoProfile) * 6),
    });
  } catch (error) {
    captureClientDiagnosticError('sfu_browser_encoder_source_reader_failed', error, {
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      track_id: String(videoTrack?.id || ''),
    }, {
      code: 'sfu_browser_encoder_source_reader_failed',
    });
    return null;
  }

  let closed = false;
  let encodeInFlight = false;
  let frameCount = 0;
  let thumbnailFrameCount = 0;
  let lastFrameSentDiagnosticAtMs = 0;
  let lastSecurityGateDiagnosticAtMs = 0;
  let forceNextSecurityKeyframe = false;
  let primaryKeyframeMissCount = 0;
  let primaryKeyframeMissDiagnosticAtMs = 0;
  let encoderError = null;
  let thumbnailEncoderError = null;
  let thumbnailEncoderDisabled = false;
  let config = null;
  let thumbnailConfig = null;
  let encoder = null;
  let thumbnailEncoder = null;
  let activeEncoderGeneration = 0;
  let activeEncoderCloseReason = '';
  let activeEncoderConfigKey = '';
  let thumbnailCadence = 1;
  let encoderEnabledDiagnosticSent = false;
  const encodedChunks = [];
  const thumbnailEncodedChunks = [];
  const primaryFrameScaler = createBrowserVideoFrameScaler({
    globalScope,
    errorPrefix: 'sfu_browser_primary_frame',
  });
  const thumbnailFrameScaler = createBrowserVideoFrameScaler({
    globalScope,
    errorPrefix: 'sfu_browser_thumbnail',
  });
  const disableThumbnailEncoder = (reason, error) => {
    if (thumbnailEncoderDisabled) return;
    thumbnailEncoderDisabled = true;
    thumbnailEncodedChunks.length = 0;
    thumbnailEncoderError = null;
    captureClientDiagnosticError(reason, error, {
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      frame_width: positiveInteger(thumbnailConfig?.width, 0),
      frame_height: positiveInteger(thumbnailConfig?.height, 0),
    }, {
      code: reason,
    });
  };

  const isCurrentEncoderGeneration = (generation) => (
    !closed
      && generation === activeEncoderGeneration
      && encoder
      && thumbnailEncoder
  );
  const createPrimaryEncoder = (encoderGeneration) => new VideoEncoderCtor({
    output(chunk) {
      if (encoderGeneration !== activeEncoderGeneration) return;
      const data = copyEncodedChunkToArrayBuffer(chunk);
      if (!data) return;
      encodedChunks.push({
        data,
        type: normalizedFrameKind(chunk.type),
        timestamp: Date.now(),
      });
    },
    error(error) {
      if (encoderGeneration !== activeEncoderGeneration) return;
      encoderError = error;
      captureClientDiagnosticError('sfu_browser_encoder_error', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        frame_width: positiveInteger(config?.width, 0),
        frame_height: positiveInteger(config?.height, 0),
      }, {
        code: 'sfu_browser_encoder_error',
      });
    },
  });

  const createThumbnailEncoder = (encoderGeneration) => new VideoEncoderCtor({
    output(chunk) {
      if (encoderGeneration !== activeEncoderGeneration) return;
      const data = copyEncodedChunkToArrayBuffer(chunk);
      if (!data) return;
      thumbnailEncodedChunks.push({
        data,
        type: normalizedFrameKind(chunk.type),
        timestamp: Date.now(),
      });
    },
    error(error) {
      if (encoderGeneration !== activeEncoderGeneration) return;
      thumbnailEncoderError = error;
      disableThumbnailEncoder('sfu_browser_thumbnail_encoder_error', error);
    },
  });

  const captureUnsupportedBrowserEncoderConfig = ({
    requestedPrimaryConfig,
    requestedThumbnailConfig,
    resolvedPrimaryConfig,
    resolvedThumbnailConfig,
    frameSize,
  }) => {
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_browser_encoder_unsupported',
      code: 'sfu_browser_encoder_unsupported',
      message: 'Browser WebCodecs encoder path rejected every source-oriented encoder configuration; publisher will fall back to the compatibility path.',
      payload: {
        ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
        requested_codec: requestedPrimaryConfig.codec,
        frame_width: requestedPrimaryConfig.width,
        frame_height: requestedPrimaryConfig.height,
        source_frame_width: positiveInteger(frameSize?.sourceWidth, 0),
        source_frame_height: positiveInteger(frameSize?.sourceHeight, 0),
        publisher_aspect_mode: String(frameSize?.aspectMode || ''),
        requested_bitrate: requestedPrimaryConfig.bitrate,
        requested_frame_rate: requestedPrimaryConfig.framerate,
        primary_config_supported: Boolean(resolvedPrimaryConfig),
        thumbnail_config_supported: Boolean(resolvedThumbnailConfig),
        thumbnail_frame_width: requestedThumbnailConfig.width,
        thumbnail_frame_height: requestedThumbnailConfig.height,
        thumbnail_bitrate: requestedThumbnailConfig.bitrate,
        thumbnail_frame_rate: requestedThumbnailConfig.framerate,
        outgoing_video_quality_profile: String(videoProfile?.id || ''),
      },
    });
  };

  const ensureBrowserEncodersForFrame = async (sourceFrame) => {
    const frameSize = resolveBrowserEncoderFrameSize(videoProfile, sourceFrame);
    const requestedPrimaryConfig = buildBrowserEncoderConfig(videoProfile, { videoLayer: 'primary', frameSize });
    const requestedThumbnailConfig = buildBrowserEncoderConfig(videoProfile, { videoLayer: 'thumbnail', frameSize });
    if (requestedPrimaryConfig.width <= 0 || requestedPrimaryConfig.height <= 0) {
      throw new Error('sfu_browser_encoder_source_frame_dimensions_invalid');
    }
    const nextPrimaryConfig = await resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedPrimaryConfig);
    const nextThumbnailConfig = await resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedThumbnailConfig);
    if (closed) return null;
    if (!nextPrimaryConfig || !nextThumbnailConfig) {
      captureUnsupportedBrowserEncoderConfig({
        requestedPrimaryConfig,
        requestedThumbnailConfig,
        resolvedPrimaryConfig: nextPrimaryConfig,
        resolvedThumbnailConfig: nextThumbnailConfig,
        frameSize,
      });
      throw new Error('sfu_browser_encoder_unsupported');
    }
    const nextConfigKey = [
      browserEncoderConfigKey(nextPrimaryConfig),
      browserEncoderConfigKey(nextThumbnailConfig),
      String(frameSize.aspectMode || ''),
      String(frameSize.framingMode || ''),
    ].join('|');
    if (encoder && thumbnailEncoder && activeEncoderConfigKey === nextConfigKey) {
      return {
        config,
        thumbnailConfig,
        frameSize,
        changed: false,
        encoder,
        thumbnailEncoder,
        encoderGeneration: activeEncoderGeneration,
      };
    }

    const nextEncoderGeneration = activeEncoderGeneration + 1;
    const nextEncoder = createPrimaryEncoder(nextEncoderGeneration);
    const nextThumbnailEncoder = createThumbnailEncoder(nextEncoderGeneration);
    try {
      nextEncoder.configure(nextPrimaryConfig);
      nextThumbnailEncoder.configure(nextThumbnailConfig);
    } catch (error) {
      closeBrowserEncoder(nextEncoder);
      closeBrowserEncoder(nextThumbnailEncoder);
      captureClientDiagnosticError('sfu_browser_encoder_configure_failed', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        frame_width: nextPrimaryConfig.width,
        frame_height: nextPrimaryConfig.height,
        source_frame_width: positiveInteger(frameSize?.sourceWidth, 0),
        source_frame_height: positiveInteger(frameSize?.sourceHeight, 0),
      }, {
        code: 'sfu_browser_encoder_configure_failed',
      });
      throw error;
    }

    const previousEncoder = encoder;
    const previousThumbnailEncoder = thumbnailEncoder;
    activeEncoderGeneration = nextEncoderGeneration;
    activeEncoderCloseReason = 'protected_browser_video_profile_switch_close';
    encoder = nextEncoder;
    thumbnailEncoder = nextThumbnailEncoder;
    config = nextPrimaryConfig;
    thumbnailConfig = nextThumbnailConfig;
    activeEncoderConfigKey = nextConfigKey;
    thumbnailCadence = Math.max(1, Math.round(
      Math.max(1, Number(config.framerate || 1)) / Math.max(1, Number(thumbnailConfig.framerate || 1)),
    ));
    thumbnailEncoderDisabled = false;
    thumbnailEncoderError = null;
    encoderError = null;
    encodedChunks.length = 0;
    thumbnailEncodedChunks.length = 0;
    thumbnailFrameCount = 0;
    forceNextSecurityKeyframe = true;
    closeBrowserEncoder(previousEncoder);
    closeBrowserEncoder(previousThumbnailEncoder);

    mediaDebugLog(
      '[SFU] Protected browser encoder configured',
      PROTECTED_BROWSER_VIDEO_CODEC_ID,
      `source=${positiveInteger(frameSize.sourceWidth, 0)}x${positiveInteger(frameSize.sourceHeight, 0)}`,
      `primary=${config.width}x${config.height}`,
      `thumbnail=${thumbnailConfig.width}x${thumbnailConfig.height}`,
      `aspect=${String(frameSize.aspectMode || '')}`,
    );
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: encoderEnabledDiagnosticSent
        ? 'sfu_browser_encoder_reconfigured'
        : 'sfu_browser_encoder_enabled',
      code: encoderEnabledDiagnosticSent
        ? 'sfu_browser_encoder_reconfigured'
        : 'sfu_browser_encoder_enabled',
      message: 'Protected SFU publisher configured WebCodecs from the actual source VideoFrame aspect.',
      payload: {
        ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        frame_width: config.width,
        frame_height: config.height,
        source_frame_width: positiveInteger(frameSize.sourceWidth, 0),
        source_frame_height: positiveInteger(frameSize.sourceHeight, 0),
        publisher_aspect_mode: String(frameSize.aspectMode || ''),
        publisher_framing_mode: String(frameSize.framingMode || ''),
        thumbnail_frame_width: thumbnailConfig.width,
        thumbnail_frame_height: thumbnailConfig.height,
        thumbnail_bitrate: thumbnailConfig.bitrate,
        publisher_browser_encoder_thumbnail_enabled: true,
        bitrate: config.bitrate,
        outgoing_video_quality_profile: String(videoProfile.id || ''),
      },
    });
    encoderEnabledDiagnosticSent = true;
    return {
      config,
      thumbnailConfig,
      frameSize,
      changed: true,
      encoder,
      thumbnailEncoder,
      encoderGeneration: activeEncoderGeneration,
    };
  };

  const close = async (reason = 'protected_browser_video_encoder_closed') => {
    activeEncoderCloseReason = normalizeProtectedBrowserVideoEncoderCloseReason(reason);
    activeEncoderGeneration += 1;
    closed = true;
    if (refs.encodeIntervalRef.value) {
      clearTimeout(refs.encodeIntervalRef.value);
      refs.encodeIntervalRef.value = null;
    }
    try {
      await reader.close(reason);
    } catch {
      // source reader shutdown is best-effort during publisher teardown
    }
    encodedChunks.length = 0;
    thumbnailEncodedChunks.length = 0;
    encoderError = null;
    thumbnailEncoderError = null;
    const closingEncoder = encoder;
    const closingThumbnailEncoder = thumbnailEncoder;
    encoder = null;
    thumbnailEncoder = null;
    closeBrowserEncoder(closingEncoder);
    closeBrowserEncoder(closingThumbnailEncoder);
  };

  const profileChanged = () => String(currentSfuVideoProfile()?.id || '').trim() !== String(pipelineProfileId || '');
  const currentOpenSfuClient = () => {
    const client = refs.sfuClientRef.value;
    if (!client || !isSfuClientOpen() || typeof client.sendEncodedFrame !== 'function') return null;
    return client;
  };
  const resolveActiveEncodeIntervalMs = () => resolveWlvcEncodeIntervalMs(
    resolveProfileReadbackIntervalMs(videoProfile),
    { profileId: pipelineProfileId, trackId: videoTrack.id, codecId: PROTECTED_BROWSER_VIDEO_CODEC_ID },
  );
  const remoteKeyframeRequestPending = (timestamp = Date.now()) => (
    timestamp < Number(refs.sfuTransportState?.wlvcRemoteKeyframeRequestUntilMs || 0)
  );
  const browserEncoderCompatibilityDisabledUntilMs = () => Math.max(
    0,
    Number(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityDisabledUntilMs || 0),
  );
  const browserEncoderCompatibilityFallbackActive = (timestamp = Date.now()) => (
    timestamp < browserEncoderCompatibilityDisabledUntilMs()
  );
  const scheduleNextTick = (delayMs = resolveActiveEncodeIntervalMs()) => {
    if (closed || !isWlvcRuntimePath()) {
      refs.encodeIntervalRef.value = null;
      return;
    }
    refs.encodeIntervalRef.value = setTimeout(runTick, Math.max(0, Math.round(delayMs)));
  };

  const sendChunk = async ({
    chunk,
    trace,
    timestamp,
    encodeMs,
    forceKeyframe,
    videoLayer = 'primary',
    encoderConfig = null,
    critical = true,
  }) => {
    const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
    const reportNonCriticalDrop = (reason, details = {}) => {
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'sfu_browser_thumbnail_frame_skipped',
        code: 'sfu_browser_thumbnail_frame_skipped',
        message: 'SFU thumbnail layer frame was skipped without pressuring the primary publisher layer.',
        payload: {
          reason,
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
          video_layer: normalizedVideoLayer,
          track_id: videoTrack.id,
          ...details,
        },
      });
    };
    const encodedPayloadBytes = positiveInteger(chunk?.data?.byteLength, 0);
    const actualEncodedFrameType = chunk.type === 'keyframe' ? 'keyframe' : 'delta';
    if (forceKeyframe && actualEncodedFrameType !== 'keyframe') {
      if (!critical) {
        reportNonCriticalDrop('sfu_browser_thumbnail_keyframe_required_delta_dropped', {
          encoded_payload_bytes: encodedPayloadBytes,
          frame_type: actualEncodedFrameType,
        });
        return true;
      }

      primaryKeyframeMissCount += 1;
      forceNextSecurityKeyframe = true;
      const nowMs = Date.now();
      if (
        primaryKeyframeMissCount <= 3
        || (nowMs - primaryKeyframeMissDiagnosticAtMs) >= 1000
      ) {
        primaryKeyframeMissDiagnosticAtMs = nowMs;
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_browser_keyframe_required_delta_dropped',
          code: 'sfu_browser_keyframe_required_delta_dropped',
          message: 'Browser encoder emitted a delta while SFU recovery required a keyframe; frame was dropped before transport.',
          payload: {
            codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
            video_layer: normalizedVideoLayer,
            track_id: videoTrack.id,
            encoded_payload_bytes: encodedPayloadBytes,
            frame_type: actualEncodedFrameType,
            keyframe_miss_count: primaryKeyframeMissCount,
            media_runtime_path: refs.mediaRuntimePathRef.value,
          },
          immediate: primaryKeyframeMissCount <= 3,
        });
      }
      if (primaryKeyframeMissCount >= 3) {
        const error = new Error('sfu_browser_encoder_keyframe_unavailable');
        await close('protected_browser_video_keyframe_unavailable');
        onProtectedBrowserEncoderFailure(error);
      }
      return false;
    }
    const encodedFrameType = actualEncodedFrameType;
    const maxEncodedPayloadBytes = Math.max(1, positiveInteger(
      encodedFrameType === 'keyframe'
        ? videoProfile.maxKeyframeBytesPerFrame
        : videoProfile.maxEncodedBytesPerFrame,
      constants.sfuWlvcMaxDeltaFrameBytes,
    ));
    if (encodedPayloadBytes > maxEncodedPayloadBytes) {
      if (!critical) {
        reportNonCriticalDrop('sfu_browser_thumbnail_payload_pressure', {
          encoded_payload_bytes: encodedPayloadBytes,
          max_payload_bytes: maxEncodedPayloadBytes,
          frame_type: encodedFrameType,
        });
        return true;
      }
      handleWlvcFramePayloadPressure(encodedPayloadBytes, videoTrack.id, encodedFrameType, {
        reason: 'sfu_browser_encoder_payload_pressure',
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        video_layer: normalizedVideoLayer,
        max_payload_bytes: maxEncodedPayloadBytes,
      });
      return false;
    }

    const transportMetrics = browserEncoderTransportMetrics({
      trace,
      videoProfile,
      config: encoderConfig || config || {},
      videoLayer: normalizedVideoLayer,
      encodedPayloadBytes,
      encodedFrameType,
      encodeMs,
      maxEncodedPayloadBytes,
    });
    const extraTransportMetrics = additionalPublisherFrameMetrics({
      videoTrack,
      videoProfile,
      frameType: encodedFrameType,
      trackId: videoTrack.id,
      videoLayer: normalizedVideoLayer,
    });
    const outgoingFrame = {
      publisherId: String(refs.currentUserId()),
      publisherUserId: String(refs.currentUserId()),
      trackId: videoTrack.id,
      timestamp,
      transportMetrics: {
        ...transportMetrics,
        ...(extraTransportMetrics && typeof extraTransportMetrics === 'object' ? extraTransportMetrics : {}),
      },
      data: chunk.data,
      type: encodedFrameType,
      codecId: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      runtimeId: PROTECTED_BROWSER_VIDEO_RUNTIME_ID,
      videoLayer: normalizedVideoLayer,
      protectionMode: 'transport_only',
      layoutMode: 'full_frame',
      layerId: 'full',
      cacheEpoch: 0,
    };

    if (constants.protectedMediaEnabled) {
      if (!canProtectCurrentSfuTargets()) {
        forceNextSecurityKeyframe = true;
        reportNonCriticalDrop('sfu_browser_encoder_security_gate_waiting', {
          track_id: videoTrack.id,
          video_layer: normalizedVideoLayer,
        });
        hintMediaSecuritySync('sfu_publish_security_gate_waiting_after_encode', {
          track_id: videoTrack.id,
          media_runtime_path: refs.mediaRuntimePathRef.value,
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        });
        return true;
      }
      try {
        const mediaSecurity = ensureMediaSecuritySession();
        const protectStartedAtMs = highResolutionNowMs();
        const protectedFrame = await mediaSecurity.protectFrame({
          data: chunk.data,
          runtimePath: PROTECTED_BROWSER_VIDEO_RUNTIME_ID,
          codecId: PROTECTED_BROWSER_VIDEO_CODEC_ID,
          trackKind: 'video',
          frameKind: encodedFrameType,
          trackId: videoTrack.id,
          timestamp,
        });
        markPublisherFrameTraceStage(trace, 'protected_frame_wrap', highResolutionNowMs() - protectStartedAtMs);
        const securityBudget = measureProtectedSfuFrameBudget({
          protectedFrame,
          plaintextBytes: encodedPayloadBytes,
          maxPayloadBytes: maxEncodedPayloadBytes,
        });
        outgoingFrame.transportMetrics = {
          ...outgoingFrame.transportMetrics,
          ...securityBudget.metrics,
          ...publisherFrameTraceMetrics(trace),
        };
        if (!securityBudget.ok) {
          if (!critical) {
            reportNonCriticalDrop('sfu_browser_thumbnail_protected_media_budget_pressure', {
              max_payload_bytes: maxEncodedPayloadBytes,
              ...securityBudget.metrics,
            });
            return true;
          }
          handleWlvcFramePayloadPressure(securityBudget.metrics.protected_envelope_bytes, videoTrack.id, encodedFrameType, {
            reason: 'sfu_browser_encoder_protected_media_budget_pressure',
            video_layer: normalizedVideoLayer,
            max_payload_bytes: maxEncodedPayloadBytes,
            ...securityBudget.metrics,
          });
          return false;
        }
        outgoingFrame.data = new ArrayBuffer(0);
        outgoingFrame.protectedFrame = protectedFrame.protectedFrame;
        outgoingFrame.protectionMode = 'protected';
      } catch (securityError) {
        if (!shouldSendTransportOnlySfuFrame(securityError)) {
          if (!critical) {
            reportNonCriticalDrop('sfu_browser_thumbnail_protect_frame_failed', {
              error_name: String(securityError?.name || ''),
              error_message: String(securityError?.message || ''),
            });
            return true;
          }
          throw securityError;
        }
        forceNextSecurityKeyframe = true;
        reportNonCriticalDrop(
          critical
            ? 'sfu_browser_encoder_security_gate_waiting_after_protect'
            : 'sfu_browser_thumbnail_security_gate_waiting_after_protect',
          {
            error_name: String(securityError?.name || ''),
            error_message: String(securityError?.message || ''),
            video_layer: normalizedVideoLayer,
          },
        );
        hintMediaSecuritySync('protect_browser_encoded_frame_unavailable_waiting_for_security', {
          track_id: videoTrack.id,
          media_runtime_path: refs.mediaRuntimePathRef.value,
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        });
        return true;
      }
    } else {
      markPublisherFrameTraceStage(trace, 'protected_frame_skipped', 0);
      outgoingFrame.transportMetrics = { ...outgoingFrame.transportMetrics, ...publisherFrameTraceMetrics(trace) };
    }

    const sendClient = currentOpenSfuClient();
    if (!sendClient) {
      if (!critical) {
        reportNonCriticalDrop('sfu_client_unavailable_after_browser_thumbnail_encode', {
          bufferedAmount: getSfuClientBufferedAmount(),
        });
        return true;
      }
      handleWlvcFrameSendFailure(getSfuClientBufferedAmount(), videoTrack.id, 'sfu_client_unavailable_after_browser_encode', {
        reason: 'sfu_client_unavailable_after_browser_encode',
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        bufferedAmount: getSfuClientBufferedAmount(),
      });
      return false;
    }
    const sent = await sendClient.sendEncodedFrame(outgoingFrame);
    if (sent === false) {
      const sfuSendFailureDetails = sendClient.getLastSendFailure?.() || null;
      if (!critical) {
        reportNonCriticalDrop(String(sfuSendFailureDetails?.reason || 'sfu_browser_thumbnail_frame_send_failed'), {
          ...(sfuSendFailureDetails || {}),
        });
        return true;
      }
      handleWlvcFrameSendFailure(
        getSfuClientBufferedAmount(),
        videoTrack.id,
        String(sfuSendFailureDetails?.reason || 'sfu_browser_encoded_frame_send_failed'),
        sfuSendFailureDetails,
      );
      return false;
    }
    if (critical && encodedFrameType === 'keyframe') {
      primaryKeyframeMissCount = 0;
      primaryKeyframeMissDiagnosticAtMs = 0;
    }
    resetWlvcFrameSendFailureCounters();
    if (getSfuClientBufferedAmount() < constants.sendBufferHighWaterBytes) {
      resetWlvcBackpressureCounters();
    }
    return true;
  };

  async function runTick() {
    const startedAtMs = highResolutionNowMs();
    try {
      if (closed) {
        await close('protected_browser_video_encoder_closed');
        return;
      }
      if (profileChanged()) {
        await close('protected_browser_video_profile_switch_close');
        return;
      }
      if (!isWlvcRuntimePath()) {
        await close('protected_browser_video_transport_reconnect_close');
        return;
      }
      if (browserEncoderCompatibilityFallbackActive()) {
        const error = new Error('sfu_browser_encoder_compatibility_fallback_requested');
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_browser_encoder_compatibility_fallback',
          code: 'sfu_browser_encoder_compatibility_fallback',
          message: 'Protected browser encoder switched to the WLVC compatibility publisher after a receiver reported WebCodecs decode incompatibility.',
          payload: {
            codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
            compatibility_disabled_until_ms: browserEncoderCompatibilityDisabledUntilMs(),
            compatibility_reason: String(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityReason || ''),
            compatibility_requested_by_user_id: Number(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityRequestedByUserId || 0),
            media_runtime_path: refs.mediaRuntimePathRef.value,
            outgoing_video_quality_profile: String(videoProfile?.id || ''),
            track_id: videoTrack.id,
          },
          immediate: true,
        });
        await close('protected_browser_video_compatibility_fallback_requested');
        onProtectedBrowserEncoderFailure(error);
        return;
      }
      if (encodeInFlight || !currentOpenSfuClient() || shouldThrottleWlvcEncodeLoop()) return;
      const timestamp = Date.now();
      if (constants.protectedMediaEnabled && !canProtectCurrentSfuTargets()) {
        forceNextSecurityKeyframe = true;
        if ((timestamp - lastSecurityGateDiagnosticAtMs) >= 1000) {
          lastSecurityGateDiagnosticAtMs = timestamp;
          captureClientDiagnostic({
            category: 'media',
            level: 'warning',
            eventType: 'sfu_publish_waiting_for_media_security',
            code: 'sfu_publish_waiting_for_media_security',
            message: 'SFU video publishing is paused until media-security sender keys are active for the receiver set.',
            payload: {
              codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
              track_id: videoTrack.id,
              media_runtime_path: refs.mediaRuntimePathRef.value,
            },
          });
        }
        hintMediaSecuritySync('sfu_publish_security_gate_waiting', {
          track_id: videoTrack.id,
          media_runtime_path: refs.mediaRuntimePathRef.value,
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        });
        return;
      }
      const bufferedAmount = getSfuClientBufferedAmount();
      if (shouldDelayWlvcFrameForBackpressure(bufferedAmount)) {
        handleWlvcEncodeBackpressure(bufferedAmount, videoTrack.id);
        return;
      }

      encodeInFlight = true;
      const readStartedAtMs = highResolutionNowMs();
      const result = await reader.readFrame({
        timeoutMs: Math.max(600, resolveProfileReadbackIntervalMs(videoProfile) * 6),
      });
      if (!result.ok || !result.frame) return;
      try {
        const encoderState = await ensureBrowserEncodersForFrame(result.frame);
        if (!encoderState) return;
        const activeConfig = encoderState.config;
        const activeThumbnailConfig = encoderState.thumbnailConfig;
        const activePrimaryEncoder = encoderState.encoder;
        const activeThumbnailEncoder = encoderState.thumbnailEncoder;
        const encoderGeneration = encoderState.encoderGeneration;
        const frameSize = encoderState.frameSize;
        const sourceFrameSize = videoFrameSourceDimensions(result.frame);
        const trace = {
          id: `browser_${timestamp.toString(36)}_${frameCount.toString(36)}`,
          timestamp,
          startedAtMs,
          pipelineProfileId,
          sourceBackend: PROTECTED_BROWSER_VIDEO_SOURCE_BACKEND,
          sourceTrackId: videoTrack.id,
          sourceTrackReadyState: String(videoTrack.readyState || ''),
          sourceTrackWidth: sourceFrameSize.width,
          sourceTrackHeight: sourceFrameSize.height,
          sourceTrackFrameRate: Number(videoProfile.captureFrameRate || 0),
          frameSize,
          stages: [],
          stageMetrics: {},
        };
        markPublisherFrameTraceStage(trace, 'video_frame_processor_read', highResolutionNowMs() - readStartedAtMs);
        encodedChunks.length = 0;
        thumbnailEncodedChunks.length = 0;
        const forceRemoteRecoveryKeyframe = remoteKeyframeRequestPending(timestamp);
        const forceKeyframe = encoderState.changed
          || forceNextSecurityKeyframe
          || forceRemoteRecoveryKeyframe
          || frameCount === 0
          || (frameCount % Math.max(1, positiveInteger(videoProfile.keyFrameInterval, 1))) === 0;
        const shouldEncodeThumbnail = !thumbnailEncoderDisabled && (
          thumbnailFrameCount === 0
          || forceKeyframe
          || (frameCount % thumbnailCadence) === 0
        );
        const thumbnailForceKeyframe = thumbnailFrameCount === 0 || forceKeyframe;
        const encodeStartedAtMs = highResolutionNowMs();
        let primaryFrame = null;
        let thumbnailFrame = null;
        try {
          if (!isCurrentEncoderGeneration(encoderGeneration)) return;
          if (shouldScaleBrowserFrame(sourceFrameSize, frameSize)) {
            primaryFrame = primaryFrameScaler.createScaledFrame(result.frame, {
              width: activeConfig.width,
              height: activeConfig.height,
              sourceCropX: frameSize.sourceCropX,
              sourceCropY: frameSize.sourceCropY,
              sourceCropWidth: frameSize.sourceCropWidth,
              sourceCropHeight: frameSize.sourceCropHeight,
            });
          }
          if (!isCurrentEncoderGeneration(encoderGeneration)) return;
          activePrimaryEncoder.encode(primaryFrame || result.frame, { keyFrame: forceKeyframe });
          if (shouldEncodeThumbnail) {
            try {
              if (!isCurrentEncoderGeneration(encoderGeneration)) return;
              thumbnailFrame = thumbnailFrameScaler.createScaledFrame(result.frame, {
                width: activeThumbnailConfig.width,
                height: activeThumbnailConfig.height,
                sourceCropX: frameSize.sourceCropX,
                sourceCropY: frameSize.sourceCropY,
                sourceCropWidth: frameSize.sourceCropWidth,
                sourceCropHeight: frameSize.sourceCropHeight,
              });
              if (!isCurrentEncoderGeneration(encoderGeneration)) return;
              activeThumbnailEncoder.encode(thumbnailFrame, { keyFrame: thumbnailForceKeyframe });
            } catch (thumbnailEncodeError) {
              disableThumbnailEncoder('sfu_browser_thumbnail_encode_failed', thumbnailEncodeError);
            }
          }
        } finally {
          closePublisherVideoFrame(thumbnailFrame);
          closePublisherVideoFrame(primaryFrame);
        }
        await activePrimaryEncoder.flush();
        if (!isCurrentEncoderGeneration(encoderGeneration)) return;
        if (shouldEncodeThumbnail && !thumbnailEncoderDisabled) {
          try {
            await activeThumbnailEncoder.flush();
            if (!isCurrentEncoderGeneration(encoderGeneration)) return;
          } catch (thumbnailFlushError) {
            disableThumbnailEncoder('sfu_browser_thumbnail_flush_failed', thumbnailFlushError);
          }
        }
        if (encoderError) throw encoderError;
        if (thumbnailEncoderError) {
          disableThumbnailEncoder('sfu_browser_thumbnail_encoder_error', thumbnailEncoderError);
        }
        const encodeMs = roundedStageMs(highResolutionNowMs() - encodeStartedAtMs);
        markPublisherFrameTraceStage(trace, 'browser_video_encode', encodeMs);
        frameCount += 1;
        for (const chunk of encodedChunks.splice(0)) {
          const sentPrimaryChunk = await sendChunk({
            chunk,
            trace,
            timestamp,
            encodeMs,
            forceKeyframe,
            videoLayer: 'primary',
            encoderConfig: activeConfig,
            critical: true,
          });
          if (sentPrimaryChunk && forceKeyframe) {
            forceNextSecurityKeyframe = false;
            if (forceRemoteRecoveryKeyframe && refs.sfuTransportState) {
              refs.sfuTransportState.wlvcRemoteKeyframeRequestUntilMs = 0;
            }
          }
        }
        if (shouldEncodeThumbnail && !thumbnailEncoderDisabled) {
          thumbnailFrameCount += 1;
        }
        for (const chunk of thumbnailEncodedChunks.splice(0)) {
          await sendChunk({
            chunk,
            trace,
            timestamp,
            encodeMs,
            forceKeyframe: thumbnailForceKeyframe,
            videoLayer: 'thumbnail',
            encoderConfig: activeThumbnailConfig,
            critical: false,
          });
        }
        if (timestamp - lastFrameSentDiagnosticAtMs >= 2000) {
          lastFrameSentDiagnosticAtMs = timestamp;
          captureClientDiagnostic({
            category: 'media',
            level: 'info',
            eventType: 'sfu_browser_encoder_frame_sent',
            code: 'sfu_browser_encoder_frame_sent',
            message: 'Protected SFU frame used the browser encoder path instead of RGBA/WLVC encode.',
            payload: {
              ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
              codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
              frame_width: activeConfig.width,
              frame_height: activeConfig.height,
              source_frame_width: positiveInteger(frameSize.sourceWidth, 0),
              source_frame_height: positiveInteger(frameSize.sourceHeight, 0),
              publisher_aspect_mode: String(frameSize.aspectMode || ''),
              publisher_framing_mode: String(frameSize.framingMode || ''),
              thumbnail_frame_width: activeThumbnailConfig.width,
              thumbnail_frame_height: activeThumbnailConfig.height,
              thumbnail_frame_rate: activeThumbnailConfig.framerate,
              thumbnail_cadence: thumbnailCadence,
              encode_ms: encodeMs,
              outgoing_video_quality_profile: String(videoProfile.id || ''),
            },
          });
        }
      } finally {
        closePublisherVideoFrame(result.frame);
      }
    } catch (error) {
      if (browserEncoderLifecycleCloseError(error)) {
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_browser_encoder_lifecycle_close',
          code: 'sfu_browser_encoder_lifecycle_close',
          message: 'Browser encoder closed during an expected lifecycle transition; restarting compatibility publisher without reporting a fatal encode failure.',
          payload: {
            codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
            track_id: videoTrack.id,
            media_runtime_path: refs.mediaRuntimePathRef.value,
            lifecycle_close: true,
            lifecycle_close_reason: activeEncoderCloseReason,
            encoder_generation: activeEncoderGeneration,
            error_name: String(error?.name || ''),
            error_message: String(error?.message || error || ''),
          },
        });
        await close('protected_browser_video_encoder_lifecycle_close');
        onProtectedBrowserEncoderFailure(new Error('sfu_browser_encoder_lifecycle_close'));
        return;
      }
      captureClientDiagnosticError('sfu_browser_encoder_frame_failed', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        track_id: videoTrack.id,
        media_runtime_path: refs.mediaRuntimePathRef.value,
      }, {
        code: 'sfu_browser_encoder_frame_failed',
      });
      await close('protected_browser_video_encoder_failed');
      onProtectedBrowserEncoderFailure(error);
    } finally {
      encodeInFlight = false;
      if (!closed && refs.encodeIntervalRef.value !== null) {
        const elapsedMs = Math.max(0, highResolutionNowMs() - startedAtMs);
        scheduleNextTick(resolveActiveEncodeIntervalMs() - elapsedMs);
      }
    }
  }

  mediaDebugLog(
    '[SFU] Protected browser encoder waiting for first source frame',
    PROTECTED_BROWSER_VIDEO_CODEC_ID,
    `profile=${String(videoProfile.id || '')}`,
  );
  captureClientDiagnostic({
    category: 'media',
    level: 'info',
    eventType: 'sfu_browser_encoder_waiting_source_frame',
    code: 'sfu_browser_encoder_waiting_source_frame',
    message: 'Protected SFU publisher will configure WebCodecs from the first source VideoFrame.',
    payload: {
      ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      publisher_browser_encoder_thumbnail_enabled: true,
      outgoing_video_quality_profile: String(videoProfile.id || ''),
    },
  });
  scheduleNextTick(0);
  return { close };
}

export async function maybeStartProtectedBrowserVideoEncoderPublisher({
  videoTrack,
  videoProfile,
  pipelineProfileId,
  constants,
  refs,
  callbacks,
  captureClientDiagnostic,
  captureClientDiagnosticError,
  currentSfuVideoProfile,
  restartPublisher,
  gate,
}) {
  const nowMs = Date.now();
  const compatibilityDisabledUntilMs = Math.max(
    0,
    Number(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityDisabledUntilMs || 0),
  );
  if (nowMs < compatibilityDisabledUntilMs) {
    if (gate && typeof gate === 'object') {
      const lastDiagnosticAtMs = Number(gate.lastCompatibilityFallbackDiagnosticAtMs || 0);
      if (nowMs - lastDiagnosticAtMs >= 2000) {
        gate.lastCompatibilityFallbackDiagnosticAtMs = nowMs;
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_browser_encoder_compatibility_fallback',
          code: 'sfu_browser_encoder_compatibility_fallback',
          message: 'Protected browser encoder is disabled while a connected receiver requires the WLVC compatibility codec.',
          payload: {
            codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
            compatibility_disabled_until_ms: compatibilityDisabledUntilMs,
            compatibility_reason: String(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityReason || ''),
            compatibility_requested_by_user_id: Number(refs.sfuTransportState?.sfuBrowserEncoderCompatibilityRequestedByUserId || 0),
            outgoing_video_quality_profile: String(videoProfile?.id || ''),
            track_id: String(videoTrack?.id || ''),
          },
        });
      }
    }
    return null;
  }
  const disabledUntilMs = Number(gate?.disabledUntilMs || 0);
  if (nowMs < disabledUntilMs) return null;
  return createProtectedBrowserVideoEncoderPublisher({
    videoTrack,
    videoProfile,
    pipelineProfileId,
    constants,
    refs,
    callbacks: {
      ...callbacks,
      captureClientDiagnostic,
      captureClientDiagnosticError,
      currentSfuVideoProfile,
      onProtectedBrowserEncoderFailure: (error) => {
        if (gate && typeof gate === 'object') {
          gate.disabledUntilMs = Date.now() + 30_000;
        }
        if (String(videoTrack?.readyState || '').toLowerCase() === 'live') {
          setTimeout(() => restartPublisher?.(videoTrack, error), 0);
        }
      },
    },
  });
}
