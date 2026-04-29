import { markRaw } from 'vue';
import { createHybridDecoder } from '../../../lib/wasm/wasm-codec';
import {
  buildSfuFrameDescriptor,
  normalizePositiveInteger,
  readWlvcFrameMetadata,
} from './wlvcFrameMetadata';
import { noteSfuRemoteVideoFrameStable } from './videoConnectionStatus';
import { createSfuReceiverFeedback } from './receiverFeedback';
import { createRemoteBrowserEncodedVideoRenderer, isProtectedBrowserEncodedVideoFrame } from './remoteBrowserEncodedVideo';
import {
  clearCanvas,
  putImageDataOntoCanvas,
  resizeCanvas,
  resizeCanvasPreservingFrame,
} from './remoteCanvas';
import {
  markRemoteFrameRendered,
  shouldRenderRemoteFrame,
} from './remoteRenderScheduler';

export function createSfuFrameDecodeHelpers({
  captureClientDiagnostic,
  captureClientDiagnosticError,
  currentUserId,
  ensureMediaSecuritySession,
  ensureSfuRemotePeerForFrame,
  getSfuRemotePeerByFrameIdentity,
  isWlvcRuntimePath,
  markParticipantActivity,
  markRemotePeerRenderable,
  bumpMediaRenderVersion,
  mediaDebugLog,
  mediaRuntimePathRef,
  normalizeSfuPublisherId,
  promotePeerToTsDecoder,
  recoverMediaSecurityForPublisher,
  remoteDecoderRuntimeName,
  remoteFrameActivityMarkIntervalMs,
  remoteFrameActivityLastByUserId,
  remoteSfuFrameDropLogCooldownMs,
  remoteSfuFrameStaleTtlMs,
  remoteVideoKeyframeWaitLogCooldownMs,
  renderCallVideoLayout,
  remotePeersRef,
  sendMediaSecurityHello,
  sendRemoteSfuVideoQualityPressure,
  sfuFrameHeight,
  sfuFrameQuality,
  sfuFrameWidth,
  shouldRecoverMediaSecurityFromFrameError,
  updateSfuRemotePeerUserId,
}) {
  function normalizeSfuFrameNumber(value, fallback = 0) {
    const normalized = Number(value);
    if (!Number.isFinite(normalized)) return fallback;
    return Math.floor(normalized);
  }

  const receiverFeedback = createSfuReceiverFeedback({
    currentUserId,
    mediaRuntimePathRef,
    sendRemoteSfuVideoQualityPressure,
  });
  const remoteBrowserEncodedVideo = createRemoteBrowserEncodedVideoRenderer({
    captureClientDiagnostic,
    captureClientDiagnosticError,
    currentUserId,
    markRemotePeerRenderable,
    bumpMediaRenderVersion,
    mediaRuntimePathRef,
    renderCallVideoLayout,
    requestRemoteSfuLayerPreference: receiverFeedback.maybeSendReceiverLayerPreference,
  });

  function sfuFrameTrackStateKey(frame) {
    return String(frame?.trackId || '').trim() || 'default';
  }

  function markRemoteFrameActivity(publisherUserId) {
    const normalizedUserId = Number(publisherUserId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const nowMs = Date.now();
    const lastMarkedMs = Number(remoteFrameActivityLastByUserId.get(normalizedUserId) || 0);
    if ((nowMs - lastMarkedMs) < remoteFrameActivityMarkIntervalMs) return;
    remoteFrameActivityLastByUserId.set(normalizedUserId, nowMs);
    markParticipantActivity(normalizedUserId, 'media_frame', nowMs);
  }

  async function ensureSfuRemotePeerDecoderForFrame(publisherId, peer, metadata) {
    if (!peer || typeof peer !== 'object') return false;
    const nextWidth = normalizePositiveInteger(metadata?.width, Number(peer.frameWidth || sfuFrameWidth));
    const nextHeight = normalizePositiveInteger(metadata?.height, Number(peer.frameHeight || sfuFrameHeight));
    const nextQuality = normalizePositiveInteger(metadata?.quality, Number(peer.frameQuality || sfuFrameQuality));
    const currentWidth = normalizePositiveInteger(peer.frameWidth, sfuFrameWidth);
    const currentHeight = normalizePositiveInteger(peer.frameHeight, sfuFrameHeight);
    const currentQuality = normalizePositiveInteger(peer.frameQuality, sfuFrameQuality);

    if (peer.decoder && currentWidth === nextWidth && currentHeight === nextHeight && currentQuality === nextQuality) {
      return true;
    }

    let nextDecoder = null;
    try {
      nextDecoder = await createHybridDecoder({
        width: nextWidth,
        height: nextHeight,
        quality: nextQuality,
      });
    } catch (error) {
      captureClientDiagnosticError('sfu_remote_decoder_reconfigure_failed', error, {
        publisher_id: publisherId,
        frame_width: nextWidth,
        frame_height: nextHeight,
        frame_quality: nextQuality,
      }, {
        code: 'sfu_remote_decoder_reconfigure_failed',
      });
      return false;
    }

    if (!nextDecoder) return false;

    try {
      peer.decoder?.destroy?.();
    } catch {
      // ignore stale decoder cleanup failures during size switches
    }

    peer.decoder = markRaw(nextDecoder);
    peer.decoderRuntime = remoteDecoderRuntimeName(nextDecoder);
    peer.decoderFallbackApplied = false;
    peer.frameWidth = nextWidth;
    peer.frameHeight = nextHeight;
    peer.frameQuality = nextQuality;
    peer.needsKeyframe = true;
    resizeCanvasPreservingFrame(peer.decodedCanvas, nextWidth, nextHeight);
    mediaDebugLog('[SFU] Remote decoder reconfigured', publisherId, `${nextWidth}x${nextHeight}`, `q=${nextQuality}`);
    return true;
  }

  async function ensureSfuRemotePatchDecoderForFrame(publisherId, peer, metadata) {
    if (!peer || typeof peer !== 'object') return false;
    const nextWidth = normalizePositiveInteger(metadata?.width, 0);
    const nextHeight = normalizePositiveInteger(metadata?.height, 0);
    const nextQuality = normalizePositiveInteger(metadata?.quality, sfuFrameQuality);
    if (nextWidth < 1 || nextHeight < 1) return false;

    if (
      peer.patchDecoder
      && Number(peer.patchDecoderWidth || 0) === nextWidth
      && Number(peer.patchDecoderHeight || 0) === nextHeight
      && Number(peer.patchDecoderQuality || 0) === nextQuality
    ) {
      return true;
    }

    let nextDecoder = null;
    try {
      nextDecoder = await createHybridDecoder({
        width: nextWidth,
        height: nextHeight,
        quality: nextQuality,
      });
    } catch (error) {
      captureClientDiagnosticError('sfu_remote_patch_decoder_reconfigure_failed', error, {
        publisher_id: publisherId,
        patch_width: nextWidth,
        patch_height: nextHeight,
        patch_quality: nextQuality,
      }, {
        code: 'sfu_remote_patch_decoder_reconfigure_failed',
      });
      return false;
    }

    if (!nextDecoder) return false;

    try {
      peer.patchDecoder?.destroy?.();
    } catch {
      // ignore stale decoder cleanup failures during patch size switches
    }

    peer.patchDecoder = markRaw(nextDecoder);
    peer.patchDecoderRuntime = remoteDecoderRuntimeName(nextDecoder);
    peer.patchDecoderWidth = nextWidth;
    peer.patchDecoderHeight = nextHeight;
    peer.patchDecoderQuality = nextQuality;
    mediaDebugLog('[SFU] Remote patch decoder reconfigured', publisherId, `${nextWidth}x${nextHeight}`, `q=${nextQuality}`);
    return true;
  }

  function ensureRemoteSfuTrackCacheState(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (!peer.hasFullFrameBaseByTrack || typeof peer.hasFullFrameBaseByTrack !== 'object') {
      peer.hasFullFrameBaseByTrack = {};
    }
    if (!peer.acceptedSfuCacheEpochByTrack || typeof peer.acceptedSfuCacheEpochByTrack !== 'object') {
      peer.acceptedSfuCacheEpochByTrack = {};
    }
    if (!peer.sfuTrackRenderStateByTrack || typeof peer.sfuTrackRenderStateByTrack !== 'object') {
      peer.sfuTrackRenderStateByTrack = {};
    }
  }

  function ensureRemoteSfuTrackRenderLayers(peer, trackKey, width = 0, height = 0) {
    ensureRemoteSfuTrackCacheState(peer);
    const normalizedTrackKey = String(trackKey || '').trim() || 'default';
    let state = peer.sfuTrackRenderStateByTrack[normalizedTrackKey];
    if (!state || typeof state !== 'object') {
      state = {
        width: 0,
        height: 0,
        fullFrameCanvas: document.createElement('canvas'),
        backgroundCanvas: document.createElement('canvas'),
        foregroundCanvas: document.createElement('canvas'),
        foregroundLayerActive: false,
      };
      peer.sfuTrackRenderStateByTrack[normalizedTrackKey] = state;
    }
    const nextWidth = Math.max(0, Math.floor(Number(width || state.width || 0)));
    const nextHeight = Math.max(0, Math.floor(Number(height || state.height || 0)));
    if (nextWidth > 0 && nextHeight > 0 && (state.width !== nextWidth || state.height !== nextHeight)) {
      state.width = nextWidth;
      state.height = nextHeight;
      resizeCanvas(state.fullFrameCanvas, nextWidth, nextHeight);
      resizeCanvas(state.backgroundCanvas, nextWidth, nextHeight);
      resizeCanvas(state.foregroundCanvas, nextWidth, nextHeight);
      state.foregroundLayerActive = false;
    }
    return state;
  }

  function composeRemoteSfuTrackLayers(peer, trackKey) {
    if (!peer || typeof peer !== 'object') return false;
    const canvas = peer.decodedCanvas;
    if (!(canvas instanceof HTMLCanvasElement)) return false;
    ensureRemoteSfuTrackCacheState(peer);
    const state = peer.sfuTrackRenderStateByTrack[String(trackKey || '').trim() || 'default'];
    if (!state || !(state.backgroundCanvas instanceof HTMLCanvasElement) || !(state.foregroundCanvas instanceof HTMLCanvasElement)) {
      return false;
    }
    if (state.width < 1 || state.height < 1) return false;
    resizeCanvas(canvas, state.width, state.height);
    const ctx = canvas.getContext('2d');
    if (!ctx) return false;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(state.backgroundCanvas, 0, 0);
    if (state.foregroundLayerActive) {
      ctx.drawImage(state.foregroundCanvas, 0, 0);
    }
    return true;
  }

  function syncRemoteSfuPeerBaseFlag(peer) {
    if (!peer || typeof peer !== 'object') return;
    ensureRemoteSfuTrackCacheState(peer);
    peer.hasFullFrameBase = Object.values(peer.hasFullFrameBaseByTrack).some((value) => value === true);
  }

  function renderDecodedSfuFrame(peer, decoded, frame = null) {
    if (!peer || !decoded?.data) return false;
    const renderedAtMs = Date.now();
    const previousConnectionState = String(peer.mediaConnectionState || '');
    const previousConnectionMessage = String(peer.mediaConnectionMessage || '');
    const canvas = peer.decodedCanvas;
    if (!(canvas instanceof HTMLCanvasElement)) return false;
    const ctx = canvas.getContext('2d');
    if (!ctx) return false;
    const renderDecision = shouldRenderRemoteFrame(peer, frame, renderedAtMs);
    if (!renderDecision.render) {
      if (
        (renderedAtMs - Number(peer.lastSfuRenderSkipTelemetryAtMs || 0)) >= 2000
      ) {
        peer.lastSfuRenderSkipTelemetryAtMs = renderedAtMs;
        captureClientDiagnostic({
          category: 'media',
          level: 'info',
          eventType: 'sfu_receiver_render_scheduled_skip',
          code: 'sfu_receiver_render_scheduled_skip',
          message: 'Remote SFU frame render was skipped by the surface-aware receiver scheduler.',
          payload: {
            publisher_id: String(frame?.publisherId || ''),
            publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
            track_id: String(frame?.trackId || ''),
            frame_type: String(frame?.type || ''),
            frame_sequence: normalizeSfuFrameNumber(frame?.frameSequence),
            frame_timestamp: normalizeSfuFrameNumber(frame?.timestamp),
            render_skip_reason: String(renderDecision.reason || ''),
            render_surface_role: String(renderDecision.role || ''),
            render_elapsed_ms: Math.max(0, Number(renderDecision.elapsedMs || 0)),
            render_min_interval_ms: Math.max(0, Number(renderDecision.minIntervalMs || 0)),
            media_runtime_path: mediaRuntimePathRef.value,
          },
        });
      }
      return true;
    }
    const imageData = new ImageData(decoded.data, decoded.width, decoded.height);
    const trackKey = sfuFrameTrackStateKey(frame);
    ensureRemoteSfuTrackCacheState(peer);
    const layoutMode = String(frame?.layoutMode || 'full_frame').trim().toLowerCase();
    const existingTrackRenderState = peer.sfuTrackRenderStateByTrack[trackKey] || null;
    const targetWidth = layoutMode === 'full_frame'
      ? decoded.width
      : Number(existingTrackRenderState?.width || canvas.width || peer.frameWidth || sfuFrameWidth);
    const targetHeight = layoutMode === 'full_frame'
      ? decoded.height
      : Number(existingTrackRenderState?.height || canvas.height || peer.frameHeight || sfuFrameHeight);
    const trackRenderState = ensureRemoteSfuTrackRenderLayers(peer, trackKey, targetWidth, targetHeight);
    let rendered = false;
    if (layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot') {
      if (
        trackRenderState.width < 1
        || trackRenderState.height < 1
        || !peer.hasFullFrameBaseByTrack[trackKey]
      ) {
        return false;
      }
      const offsetX = Math.max(0, Math.min(
        trackRenderState.width - decoded.width,
        Math.round(Number(frame?.roiNormX || 0) * trackRenderState.width)
      ));
      const offsetY = Math.max(0, Math.min(
        trackRenderState.height - decoded.height,
        Math.round(Number(frame?.roiNormY || 0) * trackRenderState.height)
      ));
      if (layoutMode === 'background_snapshot') {
        trackRenderState.foregroundLayerActive = false;
        putImageDataOntoCanvas(trackRenderState.backgroundCanvas, imageData, offsetX, offsetY);
      } else {
        clearCanvas(trackRenderState.foregroundCanvas);
        putImageDataOntoCanvas(trackRenderState.foregroundCanvas, imageData, offsetX, offsetY);
        trackRenderState.foregroundLayerActive = true;
      }
      rendered = composeRemoteSfuTrackLayers(peer, trackKey);
    } else {
      resizeCanvas(canvas, decoded.width, decoded.height);
      ctx.putImageData(imageData, 0, 0);
      putImageDataOntoCanvas(trackRenderState.fullFrameCanvas, imageData, 0, 0);
      putImageDataOntoCanvas(trackRenderState.backgroundCanvas, imageData, 0, 0);
      clearCanvas(trackRenderState.foregroundCanvas);
      trackRenderState.foregroundLayerActive = false;
      peer.hasFullFrameBaseByTrack[trackKey] = true;
      peer.acceptedSfuCacheEpochByTrack[trackKey] = Math.max(0, normalizeSfuFrameNumber(frame?.cacheEpoch));
      syncRemoteSfuPeerBaseFlag(peer);
      composeRemoteSfuTrackLayers(peer, trackKey);
      rendered = true;
    }
    if (!rendered) return false;
    markRemoteFrameRendered(peer, frame, renderedAtMs);
    receiverFeedback.maybeSendReceiverLayerPreference(peer, frame?.publisherId, frame, renderDecision.role, {
      codec_id: String(frame?.codecId || frame?.codec_id || 'wlvc'),
    });
    peer.frameCount = Number(peer.frameCount || 0) + 1;
    peer.lastFrameAtMs = renderedAtMs;
    peer.stalledLoggedAtMs = 0;
    peer.freezeRecoveryCount = 0;
    peer.stallRecoveryCount = 0;
    peer.mediaConnectionState = 'live';
    peer.mediaConnectionMessage = '';
    peer.mediaConnectionUpdatedAtMs = renderedAtMs;
    if (!(canvas.parentElement instanceof HTMLElement)) {
      renderCallVideoLayout();
    }
    const senderSentAtMs = normalizeSfuFrameNumber(frame?.senderSentAtMs);
    const receiverRenderLatencyMs = senderSentAtMs > 0
      ? Math.max(0, renderedAtMs - senderSentAtMs)
      : 0;
    if (
      receiverRenderLatencyMs > 0
      && (renderedAtMs - Number(peer.lastSfuRenderTelemetryAtMs || 0)) >= 2000
    ) {
      peer.lastSfuRenderTelemetryAtMs = renderedAtMs;
      captureClientDiagnostic({
        category: 'media',
        level: 'info',
        eventType: 'sfu_receiver_render_sample',
        code: 'sfu_receiver_render_sample',
        message: 'Sampled SFU receiver render latency for the active media path.',
        payload: {
          publisher_id: String(frame?.publisherId || ''),
          publisher_user_id: String(frame?.publisherUserId || ''),
          track_id: String(frame?.trackId || ''),
          frame_sequence: normalizeSfuFrameNumber(frame?.frameSequence),
          outgoing_video_quality_profile: String(frame?.outgoingVideoQualityProfile || ''),
          receiver_render_latency_ms: receiverRenderLatencyMs,
          king_receive_latency_ms: Math.max(0, Number(frame?.kingReceiveLatencyMs || 0)),
          king_fanout_latency_ms: Math.max(0, Number(frame?.kingFanoutLatencyMs || 0)),
          subscriber_send_latency_ms: Math.max(0, Number(frame?.subscriberSendLatencyMs || 0)),
          media_runtime_path: mediaRuntimePathRef.value,
        },
      });
      receiverFeedback.maybeSendReceiverRenderLagFeedback(peer, frame?.publisherId, frame, receiverRenderLatencyMs, {
        outgoing_video_quality_profile: String(frame?.outgoingVideoQualityProfile || ''),
      });
    }
    markRemotePeerRenderable(peer);
    if (
      (previousConnectionState !== 'live' || previousConnectionMessage !== '')
      && typeof bumpMediaRenderVersion === 'function'
    ) {
      bumpMediaRenderVersion();
    }
    noteSfuRemoteVideoFrameStable(peer, frame, {
      currentUserId: currentUserId(),
      mediaRuntimePath: mediaRuntimePathRef.value,
    });
    return true;
  }

  function logDroppedRemoteSfuFrame(peer, publisherId, frame, reason, extraPayload = {}, immediate = false) {
    const nowMs = Date.now();
    if (!immediate && (nowMs - Number(peer?.lastSfuFrameDropLoggedAtMs || 0)) < remoteSfuFrameDropLogCooldownMs) {
      return;
    }
    if (peer && typeof peer === 'object') {
      peer.lastSfuFrameDropLoggedAtMs = nowMs;
    }
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_remote_frame_dropped',
      code: 'sfu_remote_frame_dropped',
      message: 'Remote SFU frame was dropped by transport continuity checks before decode.',
      payload: {
        publisher_id: publisherId,
        publisher_user_id: Number(frame?.publisherUserId || peer?.userId || 0),
        track_id: frame?.trackId,
        frame_id: String(frame?.frameId || ''),
        frame_type: String(frame?.type || ''),
        frame_timestamp: normalizeSfuFrameNumber(frame?.timestamp),
        frame_sequence: normalizeSfuFrameNumber(frame?.frameSequence),
        protocol_version: normalizeSfuFrameNumber(frame?.protocolVersion, 1),
        payload_chars: normalizeSfuFrameNumber(frame?.payloadChars),
        chunk_count: normalizeSfuFrameNumber(frame?.chunkCount, 1),
        sender_sent_at_ms: normalizeSfuFrameNumber(frame?.senderSentAtMs),
        layout_mode: String(frame?.layoutMode || 'full_frame'),
        layer_id: String(frame?.layerId || 'full'),
        cache_epoch: Math.max(0, normalizeSfuFrameNumber(frame?.cacheEpoch)),
        drop_reason: reason,
        ...extraPayload,
      },
      immediate,
    });
  }

  function invalidateRemoteSfuTrackCache(peer, trackKey, reason = 'unknown') {
    if (!peer || typeof peer !== 'object') return;
    ensureRemoteSfuTrackCacheState(peer);
    peer.hasFullFrameBaseByTrack[trackKey] = false;
    peer.acceptedSfuCacheEpochByTrack[trackKey] = 0;
    const renderState = peer.sfuTrackRenderStateByTrack[String(trackKey || '').trim() || 'default'];
    if (renderState && typeof renderState === 'object') {
      clearCanvas(renderState.fullFrameCanvas);
      clearCanvas(renderState.backgroundCanvas);
      clearCanvas(renderState.foregroundCanvas);
      renderState.foregroundLayerActive = false;
    }
    syncRemoteSfuPeerBaseFlag(peer);
    peer.needsKeyframe = true;
    try {
      peer.decoder?.reset?.();
    } catch {
      // a future keyframe can still rebuild the full-frame decoder state
    }
    try {
      peer.patchDecoder?.reset?.();
    } catch {
      // patch decoder state is also rebuilt from the next clean base
    }
    try {
      peer.browserVideoDecoder?.reset?.();
    } catch {
      // browser decoder state is rebuilt from the next clean keyframe
    }
    if (peer.decodedCanvas instanceof HTMLCanvasElement) {
      clearCanvas(peer.decodedCanvas);
    }
    mediaDebugLog('[SFU] Remote tile/layer cache invalidated', trackKey, reason);
  }

  function resetRemoteSfuDecoderAfterSequenceGap(peer, frame, reason = 'sequence_gap') {
    if (!peer || typeof peer !== 'object') return;
    const layoutMode = String(frame?.layoutMode || 'full_frame').trim().toLowerCase();
    peer.needsKeyframe = true;
    if (layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot') {
      try {
        peer.patchDecoder?.reset?.();
      } catch {
        // the next clean patch keyframe can rebuild patch-decoder state
      }
    } else {
      try {
        peer.decoder?.reset?.();
      } catch {
        // the next full-frame keyframe can rebuild full-frame decoder state
      }
      try {
        peer.browserVideoDecoder?.reset?.();
      } catch {
        // the next browser keyframe can rebuild WebCodecs decoder state
      }
    }
    mediaDebugLog('[SFU] Remote decoder reset after sequence gap without clearing render cache', reason);
  }

  function invalidateRemoteSfuTrackAfterProtectedDecryptFailure(peer, frame, reason = 'unknown') {
    const trackKey = sfuFrameTrackStateKey(frame);
    invalidateRemoteSfuTrackCache(peer, trackKey, `protected_frame_decrypt_failed:${reason}`);
  }

  function shouldDropRemoteSfuFrameForCacheEpoch(peer, publisherId, frame) {
    if (!peer || typeof peer !== 'object') return false;
    ensureRemoteSfuTrackCacheState(peer);

    const trackKey = sfuFrameTrackStateKey(frame);
    const layoutMode = String(frame?.layoutMode || 'full_frame').trim().toLowerCase();
    const frameType = String(frame?.type || '').trim().toLowerCase() === 'keyframe' ? 'keyframe' : 'delta';
    const cacheEpoch = Math.max(0, normalizeSfuFrameNumber(frame?.cacheEpoch));
    const hasBaseForTrack = peer.hasFullFrameBaseByTrack[trackKey] === true;
    const acceptedCacheEpoch = Math.max(0, normalizeSfuFrameNumber(peer.acceptedSfuCacheEpochByTrack[trackKey]));

    if (layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot') {
      if (!hasBaseForTrack || acceptedCacheEpoch < 1) {
        invalidateRemoteSfuTrackCache(peer, trackKey, 'missing_full_frame_base');
        logDroppedRemoteSfuFrame(peer, publisherId, frame, 'missing_full_frame_base', {
          accepted_cache_epoch: acceptedCacheEpoch,
          frame_cache_epoch: cacheEpoch,
        }, true);
        return true;
      }
      if (cacheEpoch < 1 || cacheEpoch !== acceptedCacheEpoch) {
        invalidateRemoteSfuTrackCache(peer, trackKey, 'cache_epoch_mismatch');
        logDroppedRemoteSfuFrame(peer, publisherId, frame, 'cache_epoch_mismatch', {
          accepted_cache_epoch: acceptedCacheEpoch,
          frame_cache_epoch: cacheEpoch,
        }, true);
        return true;
      }
      return false;
    }

    if (frameType === 'keyframe') {
      if (cacheEpoch > 0 && cacheEpoch !== acceptedCacheEpoch) {
        invalidateRemoteSfuTrackCache(peer, trackKey, 'full_frame_cache_epoch_rollover');
      }
      return false;
    }

    if (hasBaseForTrack && acceptedCacheEpoch > 0 && cacheEpoch > 0 && cacheEpoch !== acceptedCacheEpoch) {
      invalidateRemoteSfuTrackCache(peer, trackKey, 'full_frame_delta_cache_epoch_mismatch');
      logDroppedRemoteSfuFrame(peer, publisherId, frame, 'full_frame_delta_cache_epoch_mismatch', {
        accepted_cache_epoch: acceptedCacheEpoch,
        frame_cache_epoch: cacheEpoch,
      }, true);
      return true;
    }

    return false;
  }

  function shouldDropRemoteSfuFrameForContinuity(publisherId, peer, frame) {
    if (!peer || typeof peer !== 'object') return false;
    const trackKey = sfuFrameTrackStateKey(frame);
    ensureRemoteSfuTrackCacheState(peer);
    if (!peer.lastSfuFrameSequenceByTrack || typeof peer.lastSfuFrameSequenceByTrack !== 'object') {
      peer.lastSfuFrameSequenceByTrack = {};
    }
    if (!peer.lastSfuFrameTimestampByTrack || typeof peer.lastSfuFrameTimestampByTrack !== 'object') {
      peer.lastSfuFrameTimestampByTrack = {};
    }

    const nowMs = Date.now();
    const frameType = String(frame?.type || '').trim().toLowerCase() === 'keyframe' ? 'keyframe' : 'delta';
    const frameSequence = Math.max(0, normalizeSfuFrameNumber(frame?.frameSequence));
    const frameTimestamp = Math.max(0, normalizeSfuFrameNumber(frame?.timestamp));
    const senderSentAtMs = Math.max(0, normalizeSfuFrameNumber(frame?.senderSentAtMs));
    const ageReferenceMs = senderSentAtMs > 0 ? senderSentAtMs : frameTimestamp;
    if (ageReferenceMs > 0 && (nowMs - ageReferenceMs) > remoteSfuFrameStaleTtlMs) {
      invalidateRemoteSfuTrackCache(peer, trackKey, 'stale_frame_ttl');
      logDroppedRemoteSfuFrame(peer, publisherId, frame, 'stale_frame_ttl', {
        frame_age_ms: nowMs - ageReferenceMs,
        stale_ttl_ms: remoteSfuFrameStaleTtlMs,
      });
      return true;
    }

    if (frameSequence > 0) {
      const lastSequence = Math.max(0, normalizeSfuFrameNumber(peer.lastSfuFrameSequenceByTrack[trackKey]));
      if (lastSequence > 0 && frameSequence <= lastSequence) {
        logDroppedRemoteSfuFrame(peer, publisherId, frame, 'duplicate_or_reordered_sequence', {
          last_frame_sequence: lastSequence,
        });
        return true;
      }
      if (lastSequence > 0 && frameSequence > (lastSequence + 1)) {
        const missingFrameCount = frameSequence - lastSequence - 1;
        if (frameType !== 'keyframe') {
          resetRemoteSfuDecoderAfterSequenceGap(peer, frame, 'sequence_gap_delta');
          logDroppedRemoteSfuFrame(peer, publisherId, frame, 'sequence_gap_delta', {
            last_frame_sequence: lastSequence,
            missing_frame_count: missingFrameCount,
          }, true);
          receiverFeedback.maybeSendReceiverSequenceGapFeedback(peer, publisherId, frame, missingFrameCount, {
            last_frame_sequence: lastSequence,
            drop_reason: 'sequence_gap_delta',
          });
          peer.lastSfuFrameSequenceByTrack[trackKey] = frameSequence;
          if (frameTimestamp > 0) {
            peer.lastSfuFrameTimestampByTrack[trackKey] = frameTimestamp;
          }
          return true;
        }
        logDroppedRemoteSfuFrame(peer, publisherId, frame, 'sequence_gap_keyframe', {
          last_frame_sequence: lastSequence,
          missing_frame_count: missingFrameCount,
        });
        receiverFeedback.maybeSendReceiverSequenceGapFeedback(peer, publisherId, frame, missingFrameCount, {
          last_frame_sequence: lastSequence,
          drop_reason: 'sequence_gap_keyframe',
        });
      }
      peer.lastSfuFrameSequenceByTrack[trackKey] = frameSequence;
      if (frameTimestamp > 0) {
        peer.lastSfuFrameTimestampByTrack[trackKey] = frameTimestamp;
      }
      return shouldDropRemoteSfuFrameForCacheEpoch(peer, publisherId, frame);
    }

    if (frameTimestamp > 0) {
      const lastTimestamp = Math.max(0, normalizeSfuFrameNumber(peer.lastSfuFrameTimestampByTrack[trackKey]));
      if (lastTimestamp > 0 && frameTimestamp < lastTimestamp) {
        logDroppedRemoteSfuFrame(peer, publisherId, frame, 'reordered_timestamp', {
          last_frame_timestamp: lastTimestamp,
        });
        return true;
      }
      peer.lastSfuFrameTimestampByTrack[trackKey] = frameTimestamp;
    }

    return shouldDropRemoteSfuFrameForCacheEpoch(peer, publisherId, frame);
  }

  async function decodeSfuFrameForPeer(publisherId, peer, frame) {
    if (!peer || (!peer.decoder && !isProtectedBrowserEncodedVideoFrame(frame))) return;
    peer.receivedFrameCount = Number(peer.receivedFrameCount || 0) + 1;
    peer.lastReceivedFrameAtMs = Date.now();
    const publisherUserId = Number(frame?.publisherUserId || peer?.userId || 0);
    if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
      markRemoteFrameActivity(publisherUserId);
    }
    if (shouldDropRemoteSfuFrameForContinuity(publisherId, peer, frame)) {
      return;
    }

    let frameData = frame.data;
    if (frame?.protectedFrame) {
      try {
        frameData = await ensureMediaSecuritySession().decryptProtectedFrameEnvelope({
          protectedFrame: frame.protectedFrame,
          publisherUserId,
          runtimePath: 'wlvc_sfu',
          codecId: frame.codecId,
          trackId: frame.trackId,
          timestamp: frame.timestamp,
        });
      } catch (error) {
        const errorCode = String(error?.message || '').trim() || 'unknown';
        mediaDebugLog('[MediaSecurity] protected SFU frame dropped', error);
        captureClientDiagnosticError('sfu_protected_frame_decrypt_failed', error, {
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_id: frame?.trackId,
          frame_type: frame?.type,
          frame_timestamp: frame?.timestamp,
          keyframe_required_after_recovery: true,
          media_runtime_path: mediaRuntimePathRef.value,
        }, {
          code: 'sfu_protected_frame_decrypt_failed',
        });
        invalidateRemoteSfuTrackAfterProtectedDecryptFailure(peer, frame, errorCode);
        if (shouldRecoverMediaSecurityFromFrameError(error)) {
          recoverMediaSecurityForPublisher(publisherUserId);
        }
        return;
      }
    } else if (frame?.protected && typeof frame.protected === 'object') {
      try {
        frameData = await ensureMediaSecuritySession().decryptFrame({
          data: frame.data,
          protected: frame.protected,
          publisherUserId,
          runtimePath: 'wlvc_sfu',
          codecId: frame.codecId,
          trackId: frame.trackId,
          timestamp: frame.timestamp,
      });
      } catch (error) {
        const errorCode = String(error?.message || '').trim() || 'unknown';
        mediaDebugLog('[MediaSecurity] protected SFU frame dropped', error);
        captureClientDiagnosticError('sfu_protected_frame_decrypt_failed', error, {
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_id: frame?.trackId,
          frame_type: frame?.type,
          frame_timestamp: frame?.timestamp,
          keyframe_required_after_recovery: true,
          media_runtime_path: mediaRuntimePathRef.value,
        }, {
          code: 'sfu_protected_frame_decrypt_failed',
        });
        invalidateRemoteSfuTrackAfterProtectedDecryptFailure(peer, frame, errorCode);
        if (shouldRecoverMediaSecurityFromFrameError(error)) {
          recoverMediaSecurityForPublisher(publisherUserId);
        }
        return;
      }
    }

    if (isProtectedBrowserEncodedVideoFrame(frame)) {
      await remoteBrowserEncodedVideo.decodeProtectedBrowserEncodedVideoFrame(peer, frame, frameData);
      return;
    }

    const frameMetadata = readWlvcFrameMetadata(frameData, {
      width: peer.frameWidth || sfuFrameWidth,
      height: peer.frameHeight || sfuFrameHeight,
      quality: peer.frameQuality || sfuFrameQuality,
      type: frame.type,
    });
    const layoutMode = String(frame?.layoutMode || 'full_frame').trim().toLowerCase();
    const isSelectiveTileFrame = layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot';
    if (peer.needsKeyframe && frameMetadata.type !== 'keyframe') {
      const nowMs = Date.now();
      if (
        !peer.lastDeltaBeforeKeyframeLoggedAtMs
        || (nowMs - Number(peer.lastDeltaBeforeKeyframeLoggedAtMs || 0)) >= remoteVideoKeyframeWaitLogCooldownMs
      ) {
        peer.lastDeltaBeforeKeyframeLoggedAtMs = nowMs;
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_delta_before_keyframe_dropped',
          code: 'sfu_delta_before_keyframe_dropped',
          message: 'Remote SFU delta frame was dropped while waiting for a keyframe after decoder reset or subscription.',
          payload: {
            publisher_id: publisherId,
            publisher_user_id: publisherUserId,
            track_id: frame?.trackId,
            frame_timestamp: frame?.timestamp,
            received_frame_count: Number(peer.receivedFrameCount || 0),
            frame_count: Number(peer.frameCount || 0),
            frame_width: frameMetadata.width,
            frame_height: frameMetadata.height,
            frame_quality: frameMetadata.quality,
            frame_metadata_ok: Boolean(frameMetadata.decodeOk),
            frame_metadata_error: String(frameMetadata.errorCode || ''),
            decoder_runtime: String(peer.decoderRuntime || remoteDecoderRuntimeName(peer.decoder)),
          },
        });
      }
      return;
    }
    const decoderReady = isSelectiveTileFrame
      ? await ensureSfuRemotePatchDecoderForFrame(publisherId, peer, frameMetadata)
      : await ensureSfuRemotePeerDecoderForFrame(publisherId, peer, frameMetadata);
    if (!decoderReady) {
      peer.needsKeyframe = true;
      return;
    }
    const activeDecoder = isSelectiveTileFrame ? peer.patchDecoder : peer.decoder;
    if (frameMetadata.type === 'keyframe' && peer.needsKeyframe && !isSelectiveTileFrame) {
      try {
        activeDecoder?.reset?.();
      } catch {
        // keyframe decode below can still recover via the normal diagnostic path.
      }
    }
    const frameDescriptor = buildSfuFrameDescriptor(frameData, frame.timestamp, frameMetadata, frame.type);

    try {
      let decoded = activeDecoder.decodeFrame(frameDescriptor);
      const decodedHasPixels = decoded && decoded.data && Number(decoded.data.length || 0) > 0;
      if (!decodedHasPixels && !isSelectiveTileFrame && !peer.decoderFallbackApplied && String(peer.decoderRuntime || '') === 'wasm') {
        if (promotePeerToTsDecoder(peer)) {
          decoded = peer.decoder.decodeFrame(frameDescriptor);
        }
      }

      if (decoded && decoded.data) {
        if (renderDecodedSfuFrame(peer, decoded, frame) && frameMetadata.type === 'keyframe' && !isSelectiveTileFrame) {
          peer.needsKeyframe = false;
        }
      } else {
        peer.needsKeyframe = true;
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'sfu_decode_frame_empty',
          code: 'sfu_decode_frame_empty',
          message: 'SFU decoder returned no renderable pixels for a received frame.',
          payload: {
            publisher_id: publisherId,
            publisher_user_id: publisherUserId,
            track_id: frame?.trackId,
            frame_type: frame?.type,
            frame_timestamp: frame?.timestamp,
            received_frame_count: Number(peer.receivedFrameCount || 0),
            frame_count: Number(peer.frameCount || 0),
            frame_width: frameMetadata.width,
            frame_height: frameMetadata.height,
            frame_quality: frameMetadata.quality,
            frame_metadata_ok: Boolean(frameMetadata.decodeOk),
            frame_metadata_error: String(frameMetadata.errorCode || ''),
            decoder_runtime: String(
              isSelectiveTileFrame
                ? (peer.patchDecoderRuntime || remoteDecoderRuntimeName(peer.patchDecoder))
                : (peer.decoderRuntime || remoteDecoderRuntimeName(peer.decoder))
            ),
            decoder_fallback_applied: Boolean(!isSelectiveTileFrame && peer.decoderFallbackApplied),
          },
        });
      }
    } catch (error) {
      if (!isSelectiveTileFrame && !peer.decoderFallbackApplied && String(peer.decoderRuntime || '') === 'wasm' && promotePeerToTsDecoder(peer)) {
        try {
          const decoded = peer.decoder.decodeFrame(frameDescriptor);
          if (decoded && decoded.data && renderDecodedSfuFrame(peer, decoded, frame)) {
            if (frameMetadata.type === 'keyframe' && !isSelectiveTileFrame) {
              peer.needsKeyframe = false;
            }
            return;
          }
        } catch {
          // fall through to the existing diagnostic path
        }
      }
      mediaDebugLog('[SFU] Decode error:', error);
      peer.needsKeyframe = true;
      try {
        activeDecoder?.reset?.();
      } catch {
        // the next keyframe will recreate clean state if the decoder supports it.
      }
      captureClientDiagnosticError('sfu_decode_frame_failed', error, {
        publisher_id: publisherId,
        publisher_user_id: publisherUserId,
        track_id: frame?.trackId,
        frame_type: frame?.type,
        frame_timestamp: frame?.timestamp,
        frame_count: Number(peer.frameCount || 0),
        received_frame_count: Number(peer.receivedFrameCount || 0),
        frame_width: frameMetadata.width,
        frame_height: frameMetadata.height,
        frame_quality: frameMetadata.quality,
        frame_metadata_ok: Boolean(frameMetadata.decodeOk),
        frame_metadata_error: String(frameMetadata.errorCode || ''),
        decoder_runtime: String(
          isSelectiveTileFrame
            ? (peer.patchDecoderRuntime || remoteDecoderRuntimeName(peer.patchDecoder))
            : (peer.decoderRuntime || remoteDecoderRuntimeName(peer.decoder))
        ),
        decoder_fallback_applied: Boolean(!isSelectiveTileFrame && peer.decoderFallbackApplied),
      }, {
        code: 'sfu_decode_frame_failed',
      });
    }
  }

  function handleSFUEncodedFrame(frame) {
    if (!isWlvcRuntimePath()) return;
    const publisherId = normalizeSfuPublisherId(frame?.publisherId);
    if (publisherId === '') return;
    let peerLookup = getSfuRemotePeerByFrameIdentity(publisherId, frame?.publisherUserId);
    let peer = peerLookup?.peer || null;
    if (peerLookup?.matchedBy === 'publisher_user_id') {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'sfu_publisher_id_alias_applied',
        code: 'sfu_publisher_id_alias_applied',
        message: 'SFU frame used a publisher id that did not match the known track publisher key, so the client matched by user id.',
        payload: {
          frame_publisher_id: publisherId,
          resolved_publisher_id: String(peerLookup.publisherId || ''),
          publisher_user_id: Number(frame?.publisherUserId || 0),
        },
      });
    }
    peer = updateSfuRemotePeerUserId(peerLookup?.publisherId || publisherId, peer, frame?.publisherUserId);
    const publisherUserId = Number(frame?.publisherUserId || peer?.userId || 0);
    if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
      markRemoteFrameActivity(publisherUserId);
      if (frame?.protectedFrame && Number(peer?.frameCount || 0) <= 0) {
        void sendMediaSecurityHello(publisherUserId);
      }
    }
    if (!peer || (!peer.decoder && !isProtectedBrowserEncodedVideoFrame(frame))) {
      const init = ensureSfuRemotePeerForFrame(frame);
      if (init) {
        void init.then((createdPeer) => {
          const nextPeer = updateSfuRemotePeerUserId(
            publisherId,
            createdPeer || remotePeersRef.value.get(publisherId),
            frame?.publisherUserId
          );
          void decodeSfuFrameForPeer(publisherId, nextPeer, frame);
        });
      }
      return;
    }

    void decodeSfuFrameForPeer(publisherId, peer, frame);
  }

  return {
    handleSFUEncodedFrame,
  };
}
