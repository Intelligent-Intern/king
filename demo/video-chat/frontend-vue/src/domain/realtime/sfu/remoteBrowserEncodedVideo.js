import { markRaw } from 'vue';
import { noteSfuRemoteVideoFrameStable } from './videoConnectionStatus';
import {
  markRemoteFrameRendered,
  shouldDecodeRemoteFrame,
  shouldRenderRemoteFrame,
} from './remoteRenderScheduler';

export const PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8';

function positiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function normalizeChunkType(value) {
  return String(value || '').trim().toLowerCase() === 'keyframe' ? 'key' : 'delta';
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

function createBrowserVideoDecoder(peer, frame, {
  captureClientDiagnosticError,
  globalScope,
  renderBrowserVideoFrame,
}) {
  const VideoDecoderCtor = globalScope.VideoDecoder;
  if (typeof VideoDecoderCtor !== 'function') return null;

  const width = browserFrameDimension(frame, 'frameWidth', positiveInteger(peer?.frameWidth, 640));
  const height = browserFrameDimension(frame, 'frameHeight', positiveInteger(peer?.frameHeight, 360));
  let decoder = null;
  decoder = new VideoDecoderCtor({
    output(videoFrame) {
      try {
        renderBrowserVideoFrame(peer, videoFrame, frame);
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
        frame_width: width,
        frame_height: height,
      }, {
        code: 'sfu_browser_decoder_error',
      });
      peer.needsKeyframe = true;
    },
  });
  decoder.configure({
    codec: 'vp8',
    codedWidth: width,
    codedHeight: height,
    hardwareAcceleration: 'prefer-hardware',
  });
  peer.browserVideoDecoder = markRaw(decoder);
  peer.browserVideoDecoderCodec = PROTECTED_BROWSER_VIDEO_CODEC_ID;
  peer.browserVideoDecoderWidth = width;
  peer.browserVideoDecoderHeight = height;
  return decoder;
}

function ensureBrowserVideoDecoder(peer, frame, options) {
  if (!peer || typeof peer !== 'object') return null;
  const width = browserFrameDimension(frame, 'frameWidth', positiveInteger(peer.frameWidth, 640));
  const height = browserFrameDimension(frame, 'frameHeight', positiveInteger(peer.frameHeight, 360));
  if (
    peer.browserVideoDecoder
      && peer.browserVideoDecoderCodec === PROTECTED_BROWSER_VIDEO_CODEC_ID
      && Number(peer.browserVideoDecoderWidth || 0) === width
      && Number(peer.browserVideoDecoderHeight || 0) === height
  ) {
    return peer.browserVideoDecoder;
  }
  try {
    peer.browserVideoDecoder?.close?.();
  } catch {
    // A stale decoder can be replaced on the next keyframe.
  }
  return createBrowserVideoDecoder(peer, frame, options);
}

export function createRemoteBrowserEncodedVideoRenderer({
  captureClientDiagnostic,
  captureClientDiagnosticError,
  currentUserId,
  markRemotePeerRenderable,
  bumpMediaRenderVersion,
  mediaRuntimePathRef,
  requestRemoteSfuLayerPreference,
  renderCallVideoLayout,
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
}) {
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
    const decoder = ensureBrowserVideoDecoder(peer, frame, {
      captureClientDiagnosticError,
      globalScope,
      renderBrowserVideoFrame,
    });
    if (!decoder) return true;
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
      decoder.decode(chunk);
    } catch (error) {
      peer.needsKeyframe = true;
      try {
        decoder.reset?.();
      } catch {
        // next keyframe will recreate decoder state if reset is unavailable
      }
      captureClientDiagnosticError('sfu_browser_decode_frame_failed', error, {
        codec_id: PROTECTED_BROWSER_VIDEO_CODEC_ID,
        publisher_id: String(frame?.publisherId || ''),
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: String(frame?.trackId || ''),
        frame_type: String(frame?.type || ''),
        frame_timestamp: Number(frame?.timestamp || 0),
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
