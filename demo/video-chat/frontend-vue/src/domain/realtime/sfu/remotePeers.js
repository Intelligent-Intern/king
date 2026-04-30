export function createSfuRemotePeerHelpers({
  bumpMediaRenderVersion,
  captureClientDiagnosticError,
  createHybridDecoder,
  currentUserId,
  isWlvcRuntimePath,
  markRaw,
  maybeFallbackToNativeRuntime,
  mediaDebugLog,
  nextTick,
  pendingSfuRemotePeerInitializers,
  remotePeersRef,
  renderCallVideoLayout,
  sfuFrameHeight,
  sfuFrameQuality,
  sfuFrameWidth,
  teardownRemotePeer,
}) {
  function normalizeSfuPublisherId(publisherId) {
    return String(publisherId || '').trim();
  }

  function remoteDecoderRuntimeName(decoder) {
    const constructorName = String(decoder?.constructor?.name || '').trim();
    if (constructorName === 'WasmWaveletVideoDecoder') return 'wasm';
    if (constructorName === 'WaveletVideoDecoder') return 'ts';
    return constructorName !== '' ? constructorName : 'unknown';
  }

  function findSfuRemotePeerEntryByUserId(userId, excludePublisherId = '') {
    const normalizedUserId = Number(userId || 0);
    const normalizedExcludePublisherId = normalizeSfuPublisherId(excludePublisherId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
      return null;
    }

    for (const [publisherId, peer] of remotePeersRef.value.entries()) {
      if (normalizeSfuPublisherId(publisherId) === normalizedExcludePublisherId) continue;
      if (!peer || typeof peer !== 'object') continue;
      if (Number(peer?.userId || 0) !== normalizedUserId) continue;
      return {
        publisherId: normalizeSfuPublisherId(publisherId),
        peer,
      };
    }

    return null;
  }

  function findSfuRemotePeerEntryByPeer(peer, excludePublisherId = '') {
    if (!peer || typeof peer !== 'object') return null;
    const normalizedExcludePublisherId = normalizeSfuPublisherId(excludePublisherId);
    for (const [publisherId, existingPeer] of remotePeersRef.value.entries()) {
      const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
      if (normalizedPublisherId === normalizedExcludePublisherId) continue;
      if (existingPeer !== peer) continue;
      return {
        publisherId: normalizedPublisherId,
        peer,
      };
    }
    return null;
  }

  function sfuTrackSignature(tracks) {
    return sfuTrackRows(tracks)
      .map((track) => [
        String(track?.id || '').trim(),
        String(track?.kind || '').trim().toLowerCase(),
      ].join(':'))
      .filter((entry) => entry !== ':')
      .sort()
      .join('|');
  }

  function resetSfuRemotePeerMediaContinuity(peer, reason = 'remote_peer_rollover', nowMs = Date.now()) {
    if (!peer || typeof peer !== 'object') return null;
    try {
      peer.decoder?.reset?.();
    } catch {
      // the next full-frame keyframe recreates decoder state
    }
    try {
      peer.patchDecoder?.reset?.();
    } catch {
      // patch state is rebuilt after the next full-frame base
    }
    try {
      peer.browserVideoDecoder?.reset?.();
    } catch {
      // browser decoder state is rebuilt from the next keyframe
    }
    // Keep the last visible frame while waiting for the rollover keyframe.
    mediaDebugLog('[SFU] Remote peer media continuity reset', reason);
    return {
      createdAtMs: nowMs,
      frameCount: 0,
      receivedFrameCount: 0,
      lastFrameAtMs: 0,
      lastReceivedFrameAtMs: 0,
      stalledLoggedAtMs: 0,
      freezeRecoveryCount: 0,
      stallRecoveryCount: 0,
      sfuSocketRestartCount: 0,
      lastSfuSocketRestartAtMs: 0,
      nextSfuSocketRestartAllowedAtMs: 0,
      mediaConnectionState: 'connecting',
      mediaConnectionMessage: 'Connecting media',
      mediaConnectionUpdatedAtMs: nowMs,
      hasFullFrameBase: false,
      hasFullFrameBaseByTrack: {},
      acceptedSfuCacheEpochByTrack: {},
      sfuTrackRenderStateByTrack: {},
      needsKeyframe: true,
      lastDeltaBeforeKeyframeLoggedAtMs: 0,
      lastSfuFrameSequenceByTrack: {},
      lastSfuFrameTimestampByTrack: {},
      lastSfuFrameDropLoggedAtMs: 0,
      remoteJitterBufferByTrack: {},
      remoteRenderStateByTrack: {},
    };
  }

  function getSfuRemotePeerByFrameIdentity(publisherId, publisherUserId) {
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    const exactPeer = normalizedPublisherId !== ''
      ? remotePeersRef.value.get(normalizedPublisherId)
      : null;
    if (exactPeer?.decoder) {
      return {
        publisherId: normalizedPublisherId,
        peer: exactPeer,
        matchedBy: 'publisher_id',
      };
    }

    const normalizedPublisherUserId = Number(publisherUserId || 0);
    if (!Number.isInteger(normalizedPublisherUserId) || normalizedPublisherUserId <= 0) {
      return {
        publisherId: normalizedPublisherId,
        peer: exactPeer || null,
        matchedBy: 'none',
      };
    }

    const fallback = findSfuRemotePeerEntryByUserId(normalizedPublisherUserId, normalizedPublisherId);
    if (!fallback?.peer?.decoder) {
      return {
        publisherId: normalizedPublisherId,
        peer: exactPeer || null,
        matchedBy: 'none',
      };
    }

    return {
      publisherId: normalizedPublisherId,
      peer: fallback.peer,
      matchedBy: 'publisher_user_id',
    };
  }

  function promotePeerToTsDecoder(peer, createTsDecoder) {
    if (!peer || typeof peer !== 'object') return false;
    const nextDecoder = createTsDecoder({
      width: Number(peer.frameWidth || sfuFrameWidth),
      height: Number(peer.frameHeight || sfuFrameHeight),
      quality: Number(peer.frameQuality || sfuFrameQuality),
    });
    if (!nextDecoder) return false;

    try {
      peer.decoder?.destroy?.();
    } catch {
      // ignore decoder cleanup failures during runtime fallback
    }

    peer.decoder = markRaw(nextDecoder);
    peer.decoderRuntime = 'ts';
    peer.decoderFallbackApplied = true;
    return true;
  }

  function setSfuRemotePeer(publisherId, peer, previousPublisherId = '') {
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    if (normalizedPublisherId === '') return;
    const nextPeers = new Map(remotePeersRef.value);
    const normalizedPreviousPublisherId = normalizeSfuPublisherId(previousPublisherId);
    if (normalizedPreviousPublisherId !== '' && normalizedPreviousPublisherId !== normalizedPublisherId) {
      nextPeers.delete(normalizedPreviousPublisherId);
    }
    for (const [existingPublisherId, existingPeer] of nextPeers.entries()) {
      if (normalizeSfuPublisherId(existingPublisherId) === normalizedPublisherId) continue;
      if (existingPeer === peer) {
        nextPeers.delete(existingPublisherId);
      }
    }
    nextPeers.set(normalizedPublisherId, peer);
    remotePeersRef.value = nextPeers;
    bumpMediaRenderVersion();
  }

  function deleteSfuRemotePeer(publisherId) {
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    if (normalizedPublisherId === '') return false;
    if (!remotePeersRef.value.has(normalizedPublisherId)) return false;
    const nextPeers = new Map(remotePeersRef.value);
    nextPeers.delete(normalizedPublisherId);
    remotePeersRef.value = nextPeers;
    pendingSfuRemotePeerInitializers.delete(normalizedPublisherId);
    bumpMediaRenderVersion();
    return true;
  }

  function sfuTrackRows(tracks) {
    return Array.isArray(tracks) ? tracks : [];
  }

  function sfuTrackListHasVideo(tracks) {
    return sfuTrackRows(tracks).some((track) => String(track?.kind || '').trim().toLowerCase() === 'video');
  }

  async function createOrUpdateSfuRemotePeer(options = {}) {
    if (!isWlvcRuntimePath()) return null;
    const publisherId = normalizeSfuPublisherId(options.publisherId);
    const publisherUserId = Number(options.publisherUserId || 0);
    if (publisherId === '') return null;
    if (Number.isInteger(publisherUserId) && publisherUserId === currentUserId()) {
      return null;
    }

    const tracks = sfuTrackRows(options.tracks);
    const exactPeer = remotePeersRef.value.get(publisherId) || null;
    const fallbackPeerEntry = exactPeer
      ? null
      : findSfuRemotePeerEntryByUserId(publisherUserId, publisherId);
    const existingPeerEntry = exactPeer
      ? { publisherId, peer: exactPeer }
      : fallbackPeerEntry;
    const existingPeer = existingPeerEntry?.peer || null;
    if (tracks.length > 0 && !sfuTrackListHasVideo(tracks)) {
      if (existingPeer) {
        teardownRemotePeer(existingPeer);
        deleteSfuRemotePeer(existingPeerEntry?.publisherId || publisherId);
        renderCallVideoLayout();
      }
      return null;
    }
    if (existingPeer?.decoder) {
      const publisherRollover = Boolean(existingPeerEntry?.publisherId)
        && existingPeerEntry.publisherId !== publisherId;
      const existingTrackSignature = sfuTrackSignature(existingPeer.tracks);
      const nextTrackSignature = sfuTrackSignature(tracks);
      const trackRollover = existingTrackSignature !== ''
        && nextTrackSignature !== ''
        && existingTrackSignature !== nextTrackSignature;
      const continuityReset = publisherRollover || trackRollover
        ? resetSfuRemotePeerMediaContinuity(
            existingPeer,
            publisherRollover ? 'publisher_id_rollover' : 'track_set_rollover',
          )
        : null;
      const updatedPeer = {
        ...existingPeer,
        userId: Number.isInteger(publisherUserId) && publisherUserId > 0
          ? publisherUserId
          : Number(existingPeer.userId || 0),
        displayName: String(options.publisherName || existingPeer.displayName || '').trim(),
        tracks,
        createdAtMs: continuityReset ? continuityReset.createdAtMs : Number(existingPeer.createdAtMs || Date.now()),
        frameCount: continuityReset ? continuityReset.frameCount : Number(existingPeer.frameCount || 0),
        receivedFrameCount: continuityReset ? continuityReset.receivedFrameCount : Number(existingPeer.receivedFrameCount || 0),
        lastFrameAtMs: continuityReset ? continuityReset.lastFrameAtMs : Number(existingPeer.lastFrameAtMs || 0),
        lastReceivedFrameAtMs: continuityReset ? continuityReset.lastReceivedFrameAtMs : Number(existingPeer.lastReceivedFrameAtMs || 0),
        stalledLoggedAtMs: continuityReset ? continuityReset.stalledLoggedAtMs : Number(existingPeer.stalledLoggedAtMs || 0),
        freezeRecoveryCount: continuityReset ? continuityReset.freezeRecoveryCount : Number(existingPeer.freezeRecoveryCount || 0),
        stallRecoveryCount: continuityReset ? continuityReset.stallRecoveryCount : Number(existingPeer.stallRecoveryCount || 0),
        sfuSocketRestartCount: continuityReset ? continuityReset.sfuSocketRestartCount : Number(existingPeer.sfuSocketRestartCount || 0),
        lastSfuSocketRestartAtMs: continuityReset ? continuityReset.lastSfuSocketRestartAtMs : Number(existingPeer.lastSfuSocketRestartAtMs || 0),
        nextSfuSocketRestartAllowedAtMs: continuityReset ? continuityReset.nextSfuSocketRestartAllowedAtMs : Number(existingPeer.nextSfuSocketRestartAllowedAtMs || 0),
        lastSubscribeRetryAtMs: Number(existingPeer.lastSubscribeRetryAtMs || 0),
        lastSubscribeRetryReason: String(existingPeer.lastSubscribeRetryReason || ''),
        mediaConnectionState: continuityReset ? continuityReset.mediaConnectionState : (String(existingPeer.mediaConnectionState || '').trim() || (Number(existingPeer.frameCount || 0) > 0 ? 'live' : 'connecting')),
        mediaConnectionMessage: continuityReset ? continuityReset.mediaConnectionMessage : String(existingPeer.mediaConnectionMessage || ''),
        mediaConnectionUpdatedAtMs: continuityReset ? continuityReset.mediaConnectionUpdatedAtMs : Number(existingPeer.mediaConnectionUpdatedAtMs || 0),
        frameWidth: Number(existingPeer.frameWidth || sfuFrameWidth),
        frameHeight: Number(existingPeer.frameHeight || sfuFrameHeight),
        frameQuality: Number(existingPeer.frameQuality || sfuFrameQuality),
        decoderRuntime: String(existingPeer.decoderRuntime || remoteDecoderRuntimeName(existingPeer.decoder)),
        decoderFallbackApplied: Boolean(existingPeer.decoderFallbackApplied),
        patchDecoder: existingPeer.patchDecoder || null,
        patchDecoderRuntime: String(existingPeer.patchDecoderRuntime || remoteDecoderRuntimeName(existingPeer.patchDecoder)),
        patchDecoderWidth: Number(existingPeer.patchDecoderWidth || 0),
        patchDecoderHeight: Number(existingPeer.patchDecoderHeight || 0),
        patchDecoderQuality: Number(existingPeer.patchDecoderQuality || 0),
        browserVideoDecoder: existingPeer.browserVideoDecoder || null,
        browserVideoDecoderCodec: String(existingPeer.browserVideoDecoderCodec || ''),
        browserVideoDecoderWidth: Number(existingPeer.browserVideoDecoderWidth || 0),
        browserVideoDecoderHeight: Number(existingPeer.browserVideoDecoderHeight || 0),
        hasFullFrameBase: continuityReset ? continuityReset.hasFullFrameBase : Boolean(existingPeer.hasFullFrameBase),
        hasFullFrameBaseByTrack: continuityReset ? continuityReset.hasFullFrameBaseByTrack : (existingPeer.hasFullFrameBaseByTrack && typeof existingPeer.hasFullFrameBaseByTrack === 'object'
          ? { ...existingPeer.hasFullFrameBaseByTrack }
          : {}),
        acceptedSfuCacheEpochByTrack: continuityReset ? continuityReset.acceptedSfuCacheEpochByTrack : (existingPeer.acceptedSfuCacheEpochByTrack && typeof existingPeer.acceptedSfuCacheEpochByTrack === 'object'
          ? { ...existingPeer.acceptedSfuCacheEpochByTrack }
          : {}),
        sfuTrackRenderStateByTrack: continuityReset ? continuityReset.sfuTrackRenderStateByTrack : (existingPeer.sfuTrackRenderStateByTrack && typeof existingPeer.sfuTrackRenderStateByTrack === 'object'
          ? { ...existingPeer.sfuTrackRenderStateByTrack }
          : {}),
        needsKeyframe: continuityReset ? continuityReset.needsKeyframe : Boolean(existingPeer.needsKeyframe),
        lastDeltaBeforeKeyframeLoggedAtMs: continuityReset ? continuityReset.lastDeltaBeforeKeyframeLoggedAtMs : Number(existingPeer.lastDeltaBeforeKeyframeLoggedAtMs || 0),
        lastSfuFrameSequenceByTrack: continuityReset ? continuityReset.lastSfuFrameSequenceByTrack : (existingPeer.lastSfuFrameSequenceByTrack && typeof existingPeer.lastSfuFrameSequenceByTrack === 'object'
          ? { ...existingPeer.lastSfuFrameSequenceByTrack }
          : {}),
        lastSfuFrameTimestampByTrack: continuityReset ? continuityReset.lastSfuFrameTimestampByTrack : (existingPeer.lastSfuFrameTimestampByTrack && typeof existingPeer.lastSfuFrameTimestampByTrack === 'object'
          ? { ...existingPeer.lastSfuFrameTimestampByTrack }
          : {}),
        lastSfuFrameDropLoggedAtMs: continuityReset ? continuityReset.lastSfuFrameDropLoggedAtMs : Number(existingPeer.lastSfuFrameDropLoggedAtMs || 0),
        remoteJitterBufferByTrack: continuityReset ? continuityReset.remoteJitterBufferByTrack : {},
        remoteRenderStateByTrack: continuityReset ? continuityReset.remoteRenderStateByTrack : (existingPeer.remoteRenderStateByTrack && typeof existingPeer.remoteRenderStateByTrack === 'object'
          ? { ...existingPeer.remoteRenderStateByTrack }
          : {}),
      };
      if (updatedPeer.decodedCanvas instanceof HTMLElement) {
        updatedPeer.decodedCanvas.dataset.publisherId = publisherId;
        if (Number.isInteger(updatedPeer.userId) && updatedPeer.userId > 0) {
          updatedPeer.decodedCanvas.dataset.userId = String(updatedPeer.userId);
        }
      }
      setSfuRemotePeer(publisherId, updatedPeer, existingPeerEntry?.publisherId || '');
      await nextTick();
      renderCallVideoLayout();
      return updatedPeer;
    }

    let decoder = null;
    if (isWlvcRuntimePath()) {
      try {
        decoder = await createHybridDecoder({
          width: sfuFrameWidth,
          height: sfuFrameHeight,
          quality: sfuFrameQuality,
        });
        if (decoder) {
          decoder = markRaw(decoder);
          mediaDebugLog('[SFU] Remote decoder initialized for publisher', publisherId, decoder?.constructor?.name || 'unknown_decoder');
        }
      } catch (error) {
        mediaDebugLog('[SFU] Remote decoder init failed for publisher', publisherId, error);
        captureClientDiagnosticError('sfu_remote_decoder_init_failed', error, {
          publisher_id: publisherId,
          publisher_user_id: publisherUserId,
          track_count: tracks.length,
        }, {
          code: 'sfu_remote_decoder_init_failed',
          immediate: true,
        });
      }
    }

    if (!decoder) {
      void maybeFallbackToNativeRuntime('wlvc_decoder_unavailable');
      return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = sfuFrameWidth;
    canvas.height = sfuFrameHeight;
    canvas.className = 'remote-video';
    canvas.dataset.publisherId = publisherId;
    if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
      canvas.dataset.userId = String(publisherUserId);
    }

    if (existingPeer) {
      teardownRemotePeer(existingPeer);
    }

    const peer = {
      userId: Number.isInteger(publisherUserId) && publisherUserId > 0 ? publisherUserId : 0,
      displayName: String(options.publisherName || '').trim(),
      pc: null,
      video: null,
      tracks,
      stream: null,
      decoder,
      decoderRuntime: remoteDecoderRuntimeName(decoder),
      decoderFallbackApplied: false,
      patchDecoder: null,
      patchDecoderRuntime: '',
      patchDecoderWidth: 0,
      patchDecoderHeight: 0,
      patchDecoderQuality: 0,
      browserVideoDecoder: null,
      browserVideoDecoderCodec: '',
      browserVideoDecoderWidth: 0,
      browserVideoDecoderHeight: 0,
      hasFullFrameBase: false,
      hasFullFrameBaseByTrack: {},
      acceptedSfuCacheEpochByTrack: {},
      sfuTrackRenderStateByTrack: {},
      decodedCanvas: canvas,
      createdAtMs: Date.now(),
      frameCount: 0,
      receivedFrameCount: 0,
      lastFrameAtMs: 0,
      lastReceivedFrameAtMs: 0,
      stalledLoggedAtMs: 0,
      lastSubscribeRetryAtMs: 0,
      lastSubscribeRetryReason: '',
      sfuSocketRestartCount: 0,
      lastSfuSocketRestartAtMs: 0,
      nextSfuSocketRestartAllowedAtMs: 0,
      frameWidth: sfuFrameWidth,
      frameHeight: sfuFrameHeight,
      frameQuality: sfuFrameQuality,
      freezeRecoveryCount: 0,
      stallRecoveryCount: 0,
      mediaConnectionState: 'connecting',
      mediaConnectionMessage: 'Connecting media',
      mediaConnectionUpdatedAtMs: Date.now(),
      needsKeyframe: true,
      lastDeltaBeforeKeyframeLoggedAtMs: 0,
      lastSfuFrameSequenceByTrack: {},
      lastSfuFrameTimestampByTrack: {},
      lastSfuFrameDropLoggedAtMs: 0,
      remoteJitterBufferByTrack: {},
      remoteRenderStateByTrack: {},
    };
    setSfuRemotePeer(publisherId, peer);

    await nextTick();
    renderCallVideoLayout();
    mediaDebugLog('[SFU] Subscribed to publisher', publisherId, 'with', tracks.length, 'tracks');
    return peer;
  }

  function ensureSfuRemotePeerForFrame(frame) {
    const publisherId = normalizeSfuPublisherId(frame?.publisherId);
    if (publisherId === '') return null;
    const fallbackPeer = getSfuRemotePeerByFrameIdentity(publisherId, frame?.publisherUserId);
    const existingPeer = fallbackPeer?.peer || remotePeersRef.value.get(publisherId);
    if (existingPeer?.decoder) return Promise.resolve(existingPeer);
    const pending = pendingSfuRemotePeerInitializers.get(publisherId);
    if (pending) return pending;

    const trackId = String(frame?.trackId || '').trim();
    const init = createOrUpdateSfuRemotePeer({
      publisherId,
      publisherUserId: frame?.publisherUserId,
      publisherName: '',
      tracks: trackId === '' ? [] : [{ id: trackId, kind: 'video', label: 'Remote video' }],
    })
      .catch((error) => {
        mediaDebugLog('[SFU] Could not create peer from frame', publisherId, error);
        captureClientDiagnosticError('sfu_remote_peer_create_failed', error, {
          publisher_id: publisherId,
          publisher_user_id: frame?.publisherUserId,
          track_id: trackId,
        }, {
          code: 'sfu_remote_peer_create_failed',
          immediate: true,
        });
        return null;
      })
      .finally(() => {
        pendingSfuRemotePeerInitializers.delete(publisherId);
      });
    pendingSfuRemotePeerInitializers.set(publisherId, init);
    return init;
  }

  function updateSfuRemotePeerUserId(publisherId, peer, publisherUserId, options = {}) {
    if (!peer || typeof peer !== 'object') return peer;
    const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
    const resolvedPreviousPublisherId = normalizeSfuPublisherId(options.previousPublisherId)
      || findSfuRemotePeerEntryByPeer(peer, normalizedPublisherId)?.publisherId
      || '';
    const publisherRollover = resolvedPreviousPublisherId !== '' && resolvedPreviousPublisherId !== normalizedPublisherId;
    const shouldResetContinuity = publisherRollover || options.resetContinuity === true;
    const normalizedUserId = Number(publisherUserId || 0);
    if (!shouldResetContinuity && (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0)) return peer;
    if (!shouldResetContinuity && Number(peer?.userId || 0) === normalizedUserId) return peer;
    const continuityReset = shouldResetContinuity
      ? resetSfuRemotePeerMediaContinuity(peer, 'publisher_id_rollover_from_frame')
      : null;
    const updatedPeer = {
      ...peer,
      userId: Number.isInteger(normalizedUserId) && normalizedUserId > 0
        ? normalizedUserId
        : Number(peer?.userId || 0),
      ...(continuityReset || {}),
    };
    if (updatedPeer.decodedCanvas instanceof HTMLElement) {
      updatedPeer.decodedCanvas.dataset.publisherId = normalizedPublisherId;
      if (Number.isInteger(updatedPeer.userId) && updatedPeer.userId > 0) {
        updatedPeer.decodedCanvas.dataset.userId = String(updatedPeer.userId);
      }
    }
    setSfuRemotePeer(normalizedPublisherId, updatedPeer, resolvedPreviousPublisherId);
    return updatedPeer;
  }

  return {
    createOrUpdateSfuRemotePeer,
    deleteSfuRemotePeer,
    ensureSfuRemotePeerForFrame,
    findSfuRemotePeerEntryByUserId,
    getSfuRemotePeerByFrameIdentity,
    normalizeSfuPublisherId,
    promotePeerToTsDecoder,
    remoteDecoderRuntimeName,
    setSfuRemotePeer,
    sfuTrackListHasVideo,
    sfuTrackRows,
    updateSfuRemotePeerUserId,
  };
}
