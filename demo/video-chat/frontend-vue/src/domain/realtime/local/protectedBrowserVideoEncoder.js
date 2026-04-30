import { measureProtectedSfuFrameBudget } from '../media/protectedFrameBudget';
import {
  closePublisherVideoFrame,
  createPublisherVideoFrameSourceReader,
  PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
} from './publisherVideoFrameSource.js';
import {
  highResolutionNowMs,
  markPublisherFrameTraceStage,
  publisherFrameTraceMetrics,
  roundedStageMs,
} from './publisherFrameTrace.js';
import { resolveProfileReadbackIntervalMs } from './videoFrameSizing.js';

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

function evenInteger(value, fallback = 2) {
  const normalized = Number(value || 0);
  if (!Number.isFinite(normalized) || normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
}

function clampNumber(value, min, max) {
  return Math.min(max, Math.max(min, value));
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

function resolveThumbnailDimensions(sourceWidth, sourceHeight) {
  const normalizedWidth = positiveInteger(sourceWidth, 0);
  const normalizedHeight = positiveInteger(sourceHeight, 0);
  if (normalizedWidth <= 0 || normalizedHeight <= 0) {
    return { width: 0, height: 0 };
  }
  const longestEdge = Math.max(normalizedWidth, normalizedHeight);
  const scale = clampNumber(Math.min(0.5, 320 / longestEdge), 0.2, 0.5);
  return {
    width: evenInteger(Math.max(2, Math.round(normalizedWidth * scale)), normalizedWidth),
    height: evenInteger(Math.max(2, Math.round(normalizedHeight * scale)), normalizedHeight),
  };
}

function resolveBrowserEncoderBitrate(videoProfile, {
  videoLayer = 'primary',
  width = 0,
  height = 0,
  frameRate = 12,
} = {}) {
  const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
  const targetWidth = positiveInteger(width, 0);
  const targetHeight = positiveInteger(height, 0);
  const targetFrameRate = clampNumber(Number(frameRate || 0), 1, 30);
  const maxWireBytesPerSecond = positiveInteger(videoProfile?.maxWireBytesPerSecond, 0);
  const pixelsPerSecond = Math.max(1, targetWidth * targetHeight * targetFrameRate);
  const bitsPerPixel = normalizedVideoLayer === 'thumbnail' ? 0.12 : 0.42;
  const qualityBoundBitrate = Math.round(pixelsPerSecond * bitsPerPixel);
  const minBitrate = normalizedVideoLayer === 'thumbnail' ? 90_000 : 520_000;
  const maxBitrate = normalizedVideoLayer === 'thumbnail' ? 520_000 : 5_500_000;
  const wireBudgetBitrate = maxWireBytesPerSecond > 0
    ? Math.max(minBitrate, Math.floor(maxWireBytesPerSecond * 8 * 0.38))
    : maxBitrate;
  return clampNumber(
    Math.max(minBitrate, qualityBoundBitrate),
    minBitrate,
    Math.min(maxBitrate, wireBudgetBitrate),
  );
}

function buildVideoFrameInitFromSource(frame) {
  const init = {};
  const timestamp = Number(frame?.timestamp);
  const duration = Number(frame?.duration);
  if (Number.isFinite(timestamp) && timestamp >= 0) init.timestamp = timestamp;
  if (Number.isFinite(duration) && duration > 0) init.duration = duration;
  return init;
}

function createBrowserThumbnailFrameScaler({
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
} = {}) {
  const VideoFrameCtor = functionRef(globalScope.VideoFrame);
  const OffscreenCanvasCtor = functionRef(globalScope.OffscreenCanvas);
  const documentRef = globalScope?.document || null;
  let canvas = null;
  let context = null;

  function ensureCanvas(width, height) {
    const targetWidth = evenInteger(width, width);
    const targetHeight = evenInteger(height, height);
    if (!canvas) {
      if (typeof OffscreenCanvasCtor === 'function') {
        canvas = new OffscreenCanvasCtor(targetWidth, targetHeight);
      } else if (documentRef && typeof documentRef.createElement === 'function') {
        canvas = documentRef.createElement('canvas');
      } else {
        throw new Error('sfu_browser_thumbnail_canvas_unavailable');
      }
      context = canvas?.getContext?.('2d', {
        alpha: false,
        desynchronized: true,
      }) || null;
      if (!context || typeof context.drawImage !== 'function') {
        throw new Error('sfu_browser_thumbnail_canvas_context_unavailable');
      }
    }
    if (canvas.width !== targetWidth) canvas.width = targetWidth;
    if (canvas.height !== targetHeight) canvas.height = targetHeight;
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    return { canvas, context, width: targetWidth, height: targetHeight };
  }

  function createScaledFrame(sourceFrame, { width, height }) {
    if (typeof VideoFrameCtor !== 'function') {
      throw new Error('sfu_browser_thumbnail_video_frame_unavailable');
    }
    const targetWidth = evenInteger(width, width);
    const targetHeight = evenInteger(height, height);
    if (targetWidth <= 0 || targetHeight <= 0) {
      throw new Error('sfu_browser_thumbnail_dimensions_invalid');
    }
    const surface = ensureCanvas(targetWidth, targetHeight);
    surface.context.clearRect(0, 0, targetWidth, targetHeight);
    surface.context.drawImage(sourceFrame, 0, 0, targetWidth, targetHeight);
    return new VideoFrameCtor(surface.canvas, buildVideoFrameInitFromSource(sourceFrame));
  }

  return {
    createScaledFrame,
  };
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

function buildBrowserEncoderConfig(videoProfile, { videoLayer = 'primary' } = {}) {
  const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
  const sourceWidth = positiveInteger(videoProfile?.frameWidth || videoProfile?.captureWidth, 0);
  const sourceHeight = positiveInteger(videoProfile?.frameHeight || videoProfile?.captureHeight, 0);
  let width = sourceWidth;
  let height = sourceHeight;
  let frameRate = Math.max(1, Number(videoProfile?.captureFrameRate || videoProfile?.readbackFrameRate || 12));
  if (sourceWidth <= 0 || sourceHeight <= 0) {
    return {
      codec: 'vp8',
      width: 0,
      height: 0,
      bitrate: resolveBrowserEncoderBitrate(videoProfile, { videoLayer: normalizedVideoLayer, width: 0, height: 0, frameRate }),
      framerate: frameRate,
      latencyMode: 'realtime',
      hardwareAcceleration: 'prefer-hardware',
    };
  }
  if (normalizedVideoLayer === 'thumbnail') {
    const thumbnailDimensions = resolveThumbnailDimensions(sourceWidth, sourceHeight);
    width = thumbnailDimensions.width;
    height = thumbnailDimensions.height;
    frameRate = Math.max(4, Math.min(8, Math.floor(frameRate * 0.5)));
  }
  return {
    codec: 'vp8',
    width: evenInteger(width, sourceWidth),
    height: evenInteger(height, sourceHeight),
    bitrate: resolveBrowserEncoderBitrate(videoProfile, {
      videoLayer: normalizedVideoLayer,
      width,
      height,
      frameRate,
    }),
    framerate: frameRate,
    latencyMode: 'realtime',
    hardwareAcceleration: 'prefer-hardware',
  };
}

function browserEncoderConfigVariants(config) {
  const variants = [];
  const seen = new Set();
  const add = (candidate) => {
    const normalized = Object.fromEntries(
      Object.entries(candidate).filter(([, value]) => value !== undefined && value !== ''),
    );
    const key = JSON.stringify(normalized);
    if (seen.has(key)) return;
    seen.add(key);
    variants.push(normalized);
  };
  add(config);
  for (const hardwareAcceleration of ['prefer-software', 'no-preference', 'prefer-hardware', undefined]) {
    add({ ...config, hardwareAcceleration });
  }
  for (const hardwareAcceleration of ['prefer-software', 'no-preference', undefined]) {
    add({ ...config, latencyMode: undefined, hardwareAcceleration });
  }
  return variants;
}

async function resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, config) {
  if (typeof VideoEncoderCtor?.isConfigSupported !== 'function') return config;
  for (const candidate of browserEncoderConfigVariants(config)) {
    try {
      const result = await VideoEncoderCtor.isConfigSupported(candidate);
      if (result?.supported) return result.config || candidate;
    } catch {
      // Try the next WebCodecs configuration variant before falling back to WLVC.
    }
  }
  return null;
}

async function isBrowserEncoderConfigSupported(VideoEncoderCtor, config) {
  if (typeof VideoEncoderCtor?.isConfigSupported !== 'function') return true;
  try {
    const result = await VideoEncoderCtor.isConfigSupported(config);
    return Boolean(result?.supported);
  } catch {
    return false;
  }
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
  const requestedPrimaryConfig = buildBrowserEncoderConfig(videoProfile, { videoLayer: 'primary' });
  const requestedThumbnailConfig = buildBrowserEncoderConfig(videoProfile, { videoLayer: 'thumbnail' });
  if (requestedPrimaryConfig.width <= 0 || requestedPrimaryConfig.height <= 0) return null;
  const config = await resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedPrimaryConfig);
  const thumbnailConfig = await resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedThumbnailConfig);
  if (!config || !thumbnailConfig) {
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_browser_encoder_unsupported',
      code: 'sfu_browser_encoder_unsupported',
      message: 'Browser WebCodecs encoder path rejected every bounded encoder configuration; publisher will fall back to the compatibility path.',
      payload: {
        ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
        requested_codec: requestedPrimaryConfig.codec,
        frame_width: requestedPrimaryConfig.width,
        frame_height: requestedPrimaryConfig.height,
        requested_bitrate: requestedPrimaryConfig.bitrate,
        requested_frame_rate: requestedPrimaryConfig.framerate,
        primary_config_supported: Boolean(config),
        thumbnail_config_supported: Boolean(thumbnailConfig),
        thumbnail_frame_width: requestedThumbnailConfig.width,
        thumbnail_frame_height: requestedThumbnailConfig.height,
        thumbnail_bitrate: requestedThumbnailConfig.bitrate,
        thumbnail_frame_rate: requestedThumbnailConfig.framerate,
        outgoing_video_quality_profile: String(videoProfile?.id || ''),
      },
    });
    return null;
  }

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
  let encoderError = null;
  let thumbnailEncoderError = null;
  let thumbnailEncoderDisabled = false;
  const encodedChunks = [];
  const thumbnailEncodedChunks = [];
  const thumbnailCadence = Math.max(1, Math.round(
    Math.max(1, Number(config.framerate || 1)) / Math.max(1, Number(thumbnailConfig.framerate || 1)),
  ));
  const thumbnailFrameScaler = createBrowserThumbnailFrameScaler({ globalScope });
  const disableThumbnailEncoder = (reason, error) => {
    if (thumbnailEncoderDisabled) return;
    thumbnailEncoderDisabled = true;
    thumbnailEncodedChunks.length = 0;
    thumbnailEncoderError = null;
    captureClientDiagnosticError(reason, error, {
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      frame_width: thumbnailConfig.width,
      frame_height: thumbnailConfig.height,
    }, {
      code: reason,
    });
  };
  const encoder = new VideoEncoderCtor({
    output(chunk) {
      const data = copyEncodedChunkToArrayBuffer(chunk);
      if (!data) return;
      encodedChunks.push({
        data,
        type: normalizedFrameKind(chunk.type),
        timestamp: Date.now(),
      });
    },
    error(error) {
      encoderError = error;
      captureClientDiagnosticError('sfu_browser_encoder_error', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        frame_width: config.width,
        frame_height: config.height,
      }, {
        code: 'sfu_browser_encoder_error',
      });
    },
  });
  const thumbnailEncoder = new VideoEncoderCtor({
    output(chunk) {
      const data = copyEncodedChunkToArrayBuffer(chunk);
      if (!data) return;
      thumbnailEncodedChunks.push({
        data,
        type: normalizedFrameKind(chunk.type),
        timestamp: Date.now(),
      });
    },
    error(error) {
      thumbnailEncoderError = error;
      disableThumbnailEncoder('sfu_browser_thumbnail_encoder_error', error);
    },
  });
  try {
    encoder.configure(config);
    thumbnailEncoder.configure(thumbnailConfig);
  } catch (error) {
    await reader.close('protected_browser_video_encoder_configure_failed');
    try {
      encoder.close();
    } catch {
      // encoder construction/configuration failed before publishing started
    }
    try {
      thumbnailEncoder.close();
    } catch {
      // thumbnail encoder may already be closed after a browser error
    }
    captureClientDiagnosticError('sfu_browser_encoder_configure_failed', error, {
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      frame_width: config.width,
      frame_height: config.height,
    }, {
      code: 'sfu_browser_encoder_configure_failed',
    });
    return null;
  }

  const close = async (reason = 'protected_browser_video_encoder_closed') => {
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
    try {
      encoder.close();
    } catch {
      // encoder may already be closed after a browser error
    }
    try {
      thumbnailEncoder.close();
    } catch {
      // thumbnail encoder may already be closed after a browser error
    }
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
    encoderConfig = config,
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
    const encodedFrameType = forceKeyframe || chunk.type === 'keyframe' ? 'keyframe' : 'delta';
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
      config: encoderConfig,
      videoLayer: normalizedVideoLayer,
      encodedPayloadBytes,
      encodedFrameType,
      encodeMs,
      maxEncodedPayloadBytes,
    });
    const outgoingFrame = {
      publisherId: String(refs.currentUserId()),
      publisherUserId: String(refs.currentUserId()),
      trackId: videoTrack.id,
      timestamp,
      transportMetrics,
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

    if (constants.protectedMediaEnabled && canProtectCurrentSfuTargets()) {
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
        hintMediaSecuritySync('protect_browser_encoded_frame_unavailable', {
          track_id: videoTrack.id,
          media_runtime_path: refs.mediaRuntimePathRef.value,
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        });
        outgoingFrame.transportMetrics = { ...outgoingFrame.transportMetrics, ...publisherFrameTraceMetrics(trace) };
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
    resetWlvcFrameSendFailureCounters();
    if (getSfuClientBufferedAmount() < constants.sendBufferHighWaterBytes) {
      resetWlvcBackpressureCounters();
    }
    return true;
  };

  async function runTick() {
    const startedAtMs = highResolutionNowMs();
    try {
      if (closed || !isWlvcRuntimePath() || profileChanged()) {
        await close('protected_browser_video_profile_changed');
        return;
      }
      if (encodeInFlight || !currentOpenSfuClient() || shouldThrottleWlvcEncodeLoop()) return;
      const bufferedAmount = getSfuClientBufferedAmount();
      if (shouldDelayWlvcFrameForBackpressure(bufferedAmount)) {
        handleWlvcEncodeBackpressure(bufferedAmount, videoTrack.id);
        return;
      }

      encodeInFlight = true;
      const timestamp = Date.now();
      const readStartedAtMs = highResolutionNowMs();
      const result = await reader.readFrame({
        timeoutMs: Math.max(600, resolveProfileReadbackIntervalMs(videoProfile) * 6),
      });
      if (!result.ok || !result.frame) return;
      const trace = {
        id: `browser_${timestamp.toString(36)}_${frameCount.toString(36)}`,
        timestamp,
        startedAtMs,
        pipelineProfileId,
        sourceBackend: PROTECTED_BROWSER_VIDEO_SOURCE_BACKEND,
        sourceTrackId: videoTrack.id,
        sourceTrackReadyState: String(videoTrack.readyState || ''),
        sourceTrackWidth: config.width,
        sourceTrackHeight: config.height,
        sourceTrackFrameRate: Number(videoProfile.captureFrameRate || 0),
        stages: [],
        stageMetrics: {},
      };
      markPublisherFrameTraceStage(trace, 'video_frame_processor_read', highResolutionNowMs() - readStartedAtMs);
      encodedChunks.length = 0;
      thumbnailEncodedChunks.length = 0;
      const forceKeyframe = frameCount === 0 || (frameCount % Math.max(1, positiveInteger(videoProfile.keyFrameInterval, 1))) === 0;
      const shouldEncodeThumbnail = !thumbnailEncoderDisabled && (
        thumbnailFrameCount === 0
        || forceKeyframe
        || (frameCount % thumbnailCadence) === 0
      );
      const thumbnailForceKeyframe = thumbnailFrameCount === 0 || forceKeyframe;
      const encodeStartedAtMs = highResolutionNowMs();
      let thumbnailFrame = null;
      try {
        encoder.encode(result.frame, { keyFrame: forceKeyframe });
        if (shouldEncodeThumbnail) {
          try {
            thumbnailFrame = thumbnailFrameScaler.createScaledFrame(result.frame, {
              width: thumbnailConfig.width,
              height: thumbnailConfig.height,
            });
            thumbnailEncoder.encode(thumbnailFrame, { keyFrame: thumbnailForceKeyframe });
          } catch (thumbnailEncodeError) {
            disableThumbnailEncoder('sfu_browser_thumbnail_encode_failed', thumbnailEncodeError);
          }
        }
      } finally {
        closePublisherVideoFrame(thumbnailFrame);
        closePublisherVideoFrame(result.frame);
      }
      await encoder.flush();
      if (shouldEncodeThumbnail && !thumbnailEncoderDisabled) {
        try {
          await thumbnailEncoder.flush();
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
        await sendChunk({
          chunk,
          trace,
          timestamp,
          encodeMs,
          forceKeyframe,
          videoLayer: 'primary',
          encoderConfig: config,
          critical: true,
        });
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
          encoderConfig: thumbnailConfig,
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
            frame_width: config.width,
            frame_height: config.height,
            thumbnail_frame_width: thumbnailConfig.width,
            thumbnail_frame_height: thumbnailConfig.height,
            thumbnail_frame_rate: thumbnailConfig.framerate,
            thumbnail_cadence: thumbnailCadence,
            encode_ms: encodeMs,
            outgoing_video_quality_profile: String(videoProfile.id || ''),
          },
        });
      }
    } catch (error) {
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
    '[SFU] Protected browser encoder initialized',
    PROTECTED_BROWSER_VIDEO_CODEC_ID,
    `primary=${config.width}x${config.height}`,
    `thumbnail=${thumbnailConfig.width}x${thumbnailConfig.height}`,
  );
  captureClientDiagnostic({
    category: 'media',
    level: 'info',
    eventType: 'sfu_browser_encoder_enabled',
    code: 'sfu_browser_encoder_enabled',
    message: 'Protected SFU publisher enabled the browser encoder path.',
    payload: {
      ...protectedBrowserVideoCapabilityDiagnosticPayload(capabilities),
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      frame_width: config.width,
      frame_height: config.height,
      thumbnail_frame_width: thumbnailConfig.width,
      thumbnail_frame_height: thumbnailConfig.height,
      thumbnail_bitrate: thumbnailConfig.bitrate,
      publisher_browser_encoder_thumbnail_enabled: true,
      bitrate: config.bitrate,
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
  const disabledUntilMs = Number(gate?.disabledUntilMs || 0);
  if (Date.now() < disabledUntilMs) return null;
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
