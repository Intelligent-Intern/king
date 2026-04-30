import { markRaw } from 'vue';
import { noteSfuRemoteVideoFrameStable } from './videoConnectionStatus';
import {
  markRemoteFrameRendered,
  shouldDecodeRemoteFrame,
  shouldRenderRemoteFrame,
} from './remoteRenderScheduler';
import { shouldRequestSfuFullKeyframeForReason } from './recoveryReasons';

export const PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8';

function positiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function normalizeChunkType(value) {
  return String(value || '').trim().toLowerCase() === 'keyframe' ? 'key' : 'delta';
}

function isBrowserKeyframe(frame) {
  return String(frame?.type || '').trim().toLowerCase() === 'keyframe';
}

function normalizeVideoLayer(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail';
  if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary';
  return '';
}

function browserFrameVideoLayer(frame) {
  return normalizeVideoLayer(frame?.videoLayer || frame?.video_layer) || 'primary';
}

function browserFrameDecoderKey(frame) {
  return [
    String(frame?.trackId || '').trim() || 'default',
    browserFrameVideoLayer(frame),
  ].join(':');
}

function browserFrameDimension(frame, fieldName, fallback) {
  return positiveInteger(
    frame?.[fieldName]
      || frame?.[`_${fieldName}`]
      || frame?.[fieldName.replace(/[A-Z]/g, (match) => `_${match.toLowerCase()}`)],
    fallback,
  );
}

export function isProtectedBrowserEncodedVideoFrame(frame) {
  return String(frame?.codecId || frame?.codec_id || '').trim().toLowerCase() === PROTECTED_BROWSER_VIDEO_CODEC_ID;
}

function ensureBrowserVideoDecoderState(peer) {
  if (!peer || typeof peer !== 'object') return null;
  if (!peer.browserVideoDecoderByLayer || typeof peer.browserVideoDecoderByLayer !== 'object') {
    peer.browserVideoDecoderByLayer = {};
  }
  return peer.browserVideoDecoderByLayer;
}

function closeBrowserDecoderState(decoderState) {
  if (!decoderState || typeof decoderState !== 'object') return;
  decoderState.pendingFrames = [];
  try {
    decoderState.decoder?.close?.();
  } catch {
    // The decoder may already be closed after a browser error.
  }
}

function isBrowserDecoderClosed(decoder) {
  return String(decoder?.state || '').trim().toLowerCase() === 'closed';
}

function isBrowserDecoderConfigured(decoder) {
  return String(decoder?.state || '').trim().toLowerCase() === 'configured';
}

function discardBrowserDecoderState(peer, frame, decoderState = null) {
  if (!peer || typeof peer !== 'object') return;
  const states = peer.browserVideoDecoderByLayer && typeof peer.browserVideoDecoderByLayer === 'object'
    ? peer.browserVideoDecoderByLayer
    : {};
  const decoderKey = browserFrameDecoderKey(frame);
  const state = decoderState || states[decoderKey] || null;
  const decoder = state?.decoder || null;
  if (state) closeBrowserDecoderState(state);
  if (!state || states[decoderKey] === state || states[decoderKey]?.decoder === decoder) {
    delete states[decoderKey];
  }
  if (peer.browserVideoDecoder === decoder) {
    peer.browserVideoDecoder = null;
    peer.browserVideoDecoderCodec = '';
    peer.browserVideoDecoderWidth = 0;
    peer.browserVideoDecoderHeight = 0;
  }
}

export function resetProtectedBrowserVideoDecoders(peer, frame = null) {
  if (!peer || typeof peer !== 'object') return;
  const states = peer.browserVideoDecoderByLayer && typeof peer.browserVideoDecoderByLayer === 'object'
    ? peer.browserVideoDecoderByLayer
    : {};
  const keys = frame ? [browserFrameDecoderKey(frame)] : Object.keys(states);
  for (const key of keys) {
    const decoderState = states[key];
    if (!decoderState || typeof decoderState !== 'object') continue;
    decoderState.pendingFrames = [];
    try {
      decoderState.decoder?.reset?.();
    } catch {
      // The next keyframe can rebuild this layer.
    }
  }
  if (!frame) {
    try {
      peer.browserVideoDecoder?.reset?.();
    } catch {
      // Backward-compatible alias reset is best-effort.
    }
  }
}

export function closeProtectedBrowserVideoDecoders(peer) {
  if (!peer || typeof peer !== 'object') return;
  const states = peer.browserVideoDecoderByLayer && typeof peer.browserVideoDecoderByLayer === 'object'
    ? peer.browserVideoDecoderByLayer
    : {};
  for (const decoderState of Object.values(states)) {
    closeBrowserDecoderState(decoderState);
  }
  if (peer.browserVideoDecoder && !Object.values(states).some((state) => state?.decoder === peer.browserVideoDecoder)) {
    try {
      peer.browserVideoDecoder.close();
    } catch {
      // Backward-compatible alias cleanup is best-effort.
    }
  }
  peer.browserVideoDecoderByLayer = {};
  peer.browserVideoDecoder = null;
  peer.browserVideoDecoderCodec = '';
  peer.browserVideoDecoderWidth = 0;
  peer.browserVideoDecoderHeight = 0;
}

function createBrowserVideoDecoder(peer, frame, {
  captureClientDiagnosticError,
  globalScope,
  requestProtectedBrowserDecoderRecovery,
  renderBrowserVideoFrame,
}) {
  const VideoDecoderCtor = globalScope.VideoDecoder;
  if (typeof VideoDecoderCtor !== 'function') return null;

  const width = browserFrameDimension(frame, 'frameWidth', positiveInteger(peer?.frameWidth, 640));
  const height = browserFrameDimension(frame, 'frameHeight', positiveInteger(peer?.frameHeight, 360));
  const videoLayer = browserFrameVideoLayer(frame);
  const decoderState = {
    codec: PROTECTED_BROWSER_VIDEO_CODEC_ID,
    decoder: null,
    height,
    needsKeyframe: true,
    pendingFrames: [],
    videoLayer,
    width,
  };
  let decoder = null;
  decoder = new VideoDecoderCtor({
    output(videoFrame) {
      const outputFrame = decoderState.pendingFrames.shift() || frame;
      try {
        renderBrowserVideoFrame(peer, videoFrame, outputFrame);
      } finally {
        try {
          videoFrame?.close?.();
        } catch {
          // VideoFrame release is best-effort on old browser implementations.
        }
      }
    },
    error(error) {
      captureClientDiagnosticError('sfu_browser_decoder_error', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        publisher_id: String(frame?.publisherId || ''),
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: String(frame?.trackId || ''),
        video_layer: videoLayer,
        frame_width: width,
        frame_height: height,
      }, {
        code: 'sfu_browser_decoder_error',
      });
      peer.needsKeyframe = true;
      discardBrowserDecoderState(peer, frame, decoderState);
      requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_browser_decoder_error');
    },
  });
  // VP8 carries frame dimensions in the bitstream. Supplying coded dimensions
  // for every adaptive layer makes some Chrome/WebCodecs builds reject otherwise
  // valid streams after layer switches or mobile thumbnail frames.
  decoder.configure({ codec: 'vp8' });
  decoderState.decoder = markRaw(decoder);
  return decoderState;
}

function ensureBrowserVideoDecoder(peer, frame, options) {
  if (!peer || typeof peer !== 'object') return null;
  const width = browserFrameDimension(frame, 'frameWidth', positiveInteger(peer.frameWidth, 640));
  const height = browserFrameDimension(frame, 'frameHeight', positiveInteger(peer.frameHeight, 360));
  const states = ensureBrowserVideoDecoderState(peer);
  if (!states) return null;
  const decoderKey = browserFrameDecoderKey(frame);
  const existing = states[decoderKey];
  if (
    existing?.decoder
      && isBrowserDecoderConfigured(existing.decoder)
      && existing.codec === PROTECTED_BROWSER_VIDEO_CODEC_ID
      && Number(existing.width || 0) === width
      && Number(existing.height || 0) === height
  ) {
    peer.browserVideoDecoder = existing.decoder;
    peer.browserVideoDecoderCodec = PROTECTED_BROWSER_VIDEO_CODEC_ID;
    peer.browserVideoDecoderWidth = width;
    peer.browserVideoDecoderHeight = height;
    return existing;
  }
  if (existing) {
    closeBrowserDecoderState(existing);
  }
  const next = createBrowserVideoDecoder(peer, frame, options);
  if (!next) return null;
  states[decoderKey] = next;
  peer.browserVideoDecoder = next.decoder;
  peer.browserVideoDecoderCodec = PROTECTED_BROWSER_VIDEO_CODEC_ID;
  peer.browserVideoDecoderWidth = width;
  peer.browserVideoDecoderHeight = height;
  return next;
}

export function createRemoteBrowserEncodedVideoRenderer({
  captureClientDiagnostic,
  captureClientDiagnosticError,
  currentUserId,
  markRemotePeerRenderable,
  bumpMediaRenderVersion,
  mediaRuntimePathRef,
  requestRemoteSfuLayerPreference,
  sendRemoteSfuVideoQualityPressure,
  renderCallVideoLayout,
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
}) {
  function requestProtectedBrowserDecoderRecovery(peer, frame, reason, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return false;
    if (typeof sendRemoteSfuVideoQualityPressure !== 'function') return false;
    const normalizedReason = String(reason || '').trim().toLowerCase();
    if (!shouldRequestSfuFullKeyframeForReason(normalizedReason)) return false;
    const lastAtMs = Number(peer.lastProtectedBrowserDecoderRecoveryRequestAtMs || 0);
    if (lastAtMs > 0 && (nowMs - lastAtMs) < 1000) return false;
    const sent = sendRemoteSfuVideoQualityPressure(peer, frame?.publisherId, normalizedReason, nowMs, {
      codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      frame_sequence: positiveInteger(frame?.frameSequence || 0, 0),
      frame_timestamp: positiveInteger(frame?.timestamp || 0, 0),
      frame_type: String(frame?.type || ''),
      requested_action: 'force_full_keyframe',
      request_full_keyframe: true,
      requested_video_layer: 'primary',
      track_id: String(frame?.trackId || ''),
      video_layer: browserFrameVideoLayer(frame),
    });
    if (sent) {
      peer.lastProtectedBrowserDecoderRecoveryRequestAtMs = nowMs;
    }
    return sent;
  }

  function renderBrowserVideoFrame(peer, videoFrame, frame) {
    if (!peer || typeof peer !== 'object') return false;
    const canvas = peer.decodedCanvas;
    if (!(canvas instanceof HTMLCanvasElement)) return false;
    const renderedAtMs = Date.now();
    const renderDecision = shouldRenderRemoteFrame(peer, frame, renderedAtMs);
    if (!renderDecision.render) {
      maybeCaptureBrowserSchedulerSkip(peer, frame, renderDecision, renderedAtMs);
      return true;
    }
    const width = positiveInteger(videoFrame?.displayWidth || videoFrame?.codedWidth, browserFrameDimension(frame, 'frameWidth', canvas.width || 640));
    const height = positiveInteger(videoFrame?.displayHeight || videoFrame?.codedHeight, browserFrameDimension(frame, 'frameHeight', canvas.height || 360));
    if (width <= 0 || height <= 0) return false;
    if (canvas.width !== width) canvas.width = width;
    if (canvas.height !== height) canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) return false;
    const previousConnectionState = String(peer.mediaConnectionState || '');
    const previousConnectionMessage = String(peer.mediaConnectionMessage || '');
    ctx.drawImage(videoFrame, 0, 0, width, height);
    markRemoteFrameRendered(peer, frame, renderedAtMs);
    if (typeof requestRemoteSfuLayerPreference === 'function') {
      requestRemoteSfuLayerPreference(peer, frame?.publisherId, frame, renderDecision.role, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
      });
    }
    peer.frameWidth = width;
    peer.frameHeight = height;
    peer.frameCount = Number(peer.frameCount || 0) + 1;
    peer.lastFrameAtMs = renderedAtMs;
    peer.stalledLoggedAtMs = 0;
    peer.freezeRecoveryCount = 0;
    peer.stallRecoveryCount = 0;
    peer.mediaConnectionState = 'live';
    peer.mediaConnectionMessage = '';
    peer.mediaConnectionUpdatedAtMs = renderedAtMs;
    peer.needsKeyframe = false;
    if (!(canvas.parentElement instanceof HTMLElement)) {
      renderCallVideoLayout();
    }
    if (
      (previousConnectionState !== 'live' || previousConnectionMessage !== '')
      && typeof bumpMediaRenderVersion === 'function'
    ) {
      bumpMediaRenderVersion();
    }
    markRemotePeerRenderable(peer);
    noteSfuRemoteVideoFrameStable(peer, frame, {
      currentUserId: currentUserId(),
      mediaRuntimePath: mediaRuntimePathRef.value,
    });
    return true;
  }

  function maybeCaptureBrowserSchedulerSkip(peer, frame, decision, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return;
    if ((nowMs - Number(peer.lastSfuBrowserSchedulerSkipTelemetryAtMs || 0)) < 2000) return;
    peer.lastSfuBrowserSchedulerSkipTelemetryAtMs = nowMs;
    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_browser_decoder_scheduled_skip',
      code: 'sfu_browser_decoder_scheduled_skip',
      message: 'Browser-decoded SFU frame was skipped by the surface-aware receiver scheduler.',
      payload: {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        publisher_id: String(frame?.publisherId || ''),
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: String(frame?.trackId || ''),
        frame_type: String(frame?.type || ''),
        frame_timestamp: Number(frame?.timestamp || 0),
        frame_sequence: Number(frame?.frameSequence || 0),
        scheduler_reason: String(decision?.reason || ''),
        render_surface_role: String(decision?.role || ''),
        render_elapsed_ms: Math.max(0, Number(decision?.elapsedMs || 0)),
        render_min_interval_ms: Math.max(0, Number(decision?.minIntervalMs || 0)),
        media_runtime_path: mediaRuntimePathRef.value,
      },
    });
  }

  function captureBrowserDecoderWaitingForKeyframe(peer, frame, reason, recoverySent, nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return;
    const decoderKey = browserFrameDecoderKey(frame);
    const logKey = `browser_keyframe_wait:${decoderKey}`;
    if (peer.lastProtectedBrowserDecoderWaitingKeyframeKey === logKey
      && (nowMs - Number(peer.lastProtectedBrowserDecoderWaitingKeyframeAtMs || 0)) < 1000
    ) {
      return;
    }
    peer.lastProtectedBrowserDecoderWaitingKeyframeKey = logKey;
    peer.lastProtectedBrowserDecoderWaitingKeyframeAtMs = nowMs;
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_remote_video_decoder_waiting_keyframe',
      code: 'sfu_remote_video_decoder_waiting_keyframe',
      message: 'Remote browser-encoded SFU delta frame was dropped until a keyframe can initialize the decoder.',
      payload: {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        publisher_id: String(frame?.publisherId || ''),
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: String(frame?.trackId || ''),
        video_layer: browserFrameVideoLayer(frame),
        frame_type: String(frame?.type || ''),
        frame_sequence: positiveInteger(frame?.frameSequence || 0, 0),
        frame_timestamp: positiveInteger(frame?.timestamp || 0, 0),
        recovery_reason: String(reason || ''),
        full_keyframe_recovery_sent: Boolean(recoverySent),
        media_runtime_path: mediaRuntimePathRef.value,
      },
    });
  }

  async function decodeProtectedBrowserEncodedVideoFrame(peer, frame, frameData) {
    if (!isProtectedBrowserEncodedVideoFrame(frame)) return false;
    if (typeof globalScope.VideoDecoder !== 'function' || typeof globalScope.EncodedVideoChunk !== 'function') {
      peer.needsKeyframe = true;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_browser_decoder_unavailable',
        code: 'sfu_browser_decoder_unavailable',
        message: 'Received a browser-encoded protected SFU frame but WebCodecs decode is unavailable.',
        payload: {
          codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
          publisher_id: String(frame?.publisherId || ''),
          track_id: String(frame?.trackId || ''),
        },
        immediate: true,
      });
      return true;
    }
    const states = ensureBrowserVideoDecoderState(peer);
    const decoderKey = browserFrameDecoderKey(frame);
    const existingDecoderState = states?.[decoderKey] || null;
    const frameIsKeyframe = isBrowserKeyframe(frame);
    const decoderNeedsKeyframe = Boolean(
      peer.needsKeyframe
        || !isBrowserDecoderConfigured(existingDecoderState?.decoder)
        || existingDecoderState?.needsKeyframe
    );
    if (decoderNeedsKeyframe && !frameIsKeyframe) {
      peer.needsKeyframe = true;
      if (existingDecoderState) existingDecoderState.needsKeyframe = true;
      const recoverySent = requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_remote_video_decoder_waiting_keyframe');
      captureBrowserDecoderWaitingForKeyframe(peer, frame, 'decoder_requires_keyframe', recoverySent);
      return true;
    }
    const decoderState = ensureBrowserVideoDecoder(peer, frame, {
      captureClientDiagnosticError,
      globalScope,
      requestProtectedBrowserDecoderRecovery,
      renderBrowserVideoFrame,
    });
    const decoder = decoderState?.decoder || null;
    if (!decoder) return true;
    if (frameIsKeyframe) {
      decoderState.needsKeyframe = false;
    }
    try {
      const decodeDecision = shouldDecodeRemoteFrame(peer, frame, Number(decoder.decodeQueueSize || 0));
      if (!decodeDecision.decode) {
        maybeCaptureBrowserSchedulerSkip(peer, frame, decodeDecision, Date.now());
        return true;
      }
      const chunk = new globalScope.EncodedVideoChunk({
        type: normalizeChunkType(frame?.type),
        timestamp: Math.max(0, Number(frame?.timestamp || Date.now())) * 1000,
        data: new Uint8Array(frameData || new ArrayBuffer(0)),
      });
      decoderState.pendingFrames.push(frame);
      decoder.decode(chunk);
    } catch (error) {
      if (decoderState?.pendingFrames?.length > 0) {
        decoderState.pendingFrames.pop();
      }
      peer.needsKeyframe = true;
      discardBrowserDecoderState(peer, frame, decoderState);
      const recoverySent = requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_browser_decode_frame_failed');
      captureClientDiagnosticError('sfu_browser_decode_frame_failed', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        publisher_id: String(frame?.publisherId || ''),
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: String(frame?.trackId || ''),
        video_layer: browserFrameVideoLayer(frame),
        frame_type: String(frame?.type || ''),
        frame_timestamp: Number(frame?.timestamp || 0),
        full_keyframe_recovery_sent: Boolean(recoverySent),
      }, {
        code: 'sfu_browser_decode_frame_failed',
      });
    }
    return true;
  }

  return {
    decodeProtectedBrowserEncodedVideoFrame,
    renderBrowserVideoFrame,
  };
}
