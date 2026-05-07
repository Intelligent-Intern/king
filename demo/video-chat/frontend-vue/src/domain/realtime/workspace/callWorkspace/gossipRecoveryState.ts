const GOSSIP_RECOVERY_FRAME_CACHE_LIMIT = 64;
const GOSSIP_RECOVERY_RETRANSMIT_LIMIT = 8;
const GOSSIP_RECOVERY_REQUEST_COOLDOWN_MS = 1000;

function safeString(value) {
  return String(value || '').trim();
}

function safeSequence(value) {
  const sequence = Number(value || 0);
  return Number.isFinite(sequence) ? Math.max(0, Math.floor(sequence)) : 0;
}

function trackCacheKey(publisherId, trackId, mediaGeneration = '') {
  return [
    safeString(publisherId),
    safeString(trackId),
    safeString(mediaGeneration),
  ].join(':');
}

function cloneGossipFrame(frame) {
  return {
    ...(frame && typeof frame === 'object' ? frame : {}),
    tile_indices: Array.isArray(frame?.tile_indices) ? frame.tile_indices.slice(0, 256) : [],
  };
}

function publishedFrameEntry(cache, publisherId, trackId, mediaGeneration) {
  const key = trackCacheKey(publisherId, trackId, mediaGeneration);
  let entry = cache.get(key);
  if (!entry) {
    entry = {
      frames: new Map(),
      keyframe: null,
    };
    cache.set(key, entry);
  }
  return entry;
}

export function createGossipRecoveryState({ now = () => Date.now() } = {}) {
  const publishedFrameCache = new Map();
  const receivedSequenceByTrack = new Map();
  const requestCooldowns = new Map();

  function clear() {
    publishedFrameCache.clear();
    receivedSequenceByTrack.clear();
    requestCooldowns.clear();
  }

  function rememberPublishedFrame(frame) {
    const publisherId = safeString(frame?.publisher_id || frame?.publisherId);
    const trackId = safeString(frame?.track_id || frame?.trackId);
    const frameSequence = safeSequence(frame?.frame_sequence ?? frame?.frameSequence);
    if (publisherId === '' || trackId === '' || frameSequence <= 0) return false;

    const mediaGeneration = safeSequence(frame?.media_generation ?? frame?.mediaGeneration);
    const entry = publishedFrameEntry(publishedFrameCache, publisherId, trackId, mediaGeneration);
    const cached = cloneGossipFrame(frame);
    entry.frames.set(frameSequence, cached);
    if (safeString(frame?.frame_type || frame?.frameType).toLowerCase() === 'keyframe') {
      entry.keyframe = cached;
    }

    while (entry.frames.size > GOSSIP_RECOVERY_FRAME_CACHE_LIMIT) {
      const oldestSequence = Array.from(entry.frames.keys()).sort((left, right) => left - right)[0];
      entry.frames.delete(oldestSequence);
    }
    return true;
  }

  function recoveryRequestForReceivedFrame(frame) {
    const publisherId = safeString(frame?.publisherId || frame?.publisher_id);
    const publisherUserId = safeString(frame?.publisherUserId || frame?.publisher_user_id || publisherId);
    const trackId = safeString(frame?.trackId || frame?.track_id);
    const frameSequence = safeSequence(frame?.frameSequence ?? frame?.frame_sequence);
    const mediaGeneration = safeSequence(frame?.mediaGeneration ?? frame?.media_generation);
    const frameType = safeString(frame?.type || frame?.frame_type || frame?.frameType).toLowerCase();
    if (publisherId === '' || trackId === '' || frameSequence <= 0) return null;

    const sequenceKey = trackCacheKey(publisherId, trackId, mediaGeneration);
    const lastReceivedSequence = safeSequence(receivedSequenceByTrack.get(sequenceKey));
    let request = null;
    if (lastReceivedSequence <= 0 && frameType !== 'keyframe') {
      request = {
        request_type: 'keyframe',
        reason: 'initial_delta_without_keyframe',
        missing_from_sequence: 0,
        missing_to_sequence: 0,
      };
    } else if (lastReceivedSequence > 0 && frameSequence > lastReceivedSequence + 1 && frameType !== 'keyframe') {
      request = {
        request_type: 'missing_frame',
        reason: 'gossip_receiver_sequence_gap',
        missing_from_sequence: lastReceivedSequence + 1,
        missing_to_sequence: frameSequence - 1,
        prefer_keyframe: true,
      };
    }

    if (frameSequence > lastReceivedSequence) {
      receivedSequenceByTrack.set(sequenceKey, frameSequence);
    }
    if (!request) return null;

    return {
      ...request,
      publisher_id: publisherId,
      publisher_user_id: publisherUserId,
      track_id: trackId,
      media_generation: mediaGeneration,
      frame_sequence: frameSequence,
      last_received_sequence: lastReceivedSequence,
    };
  }

  function shouldSendRecoveryRequest(request) {
    const requestType = safeString(request?.request_type);
    const publisherId = safeString(request?.publisher_id);
    const trackId = safeString(request?.track_id);
    if (requestType === '' || publisherId === '' || trackId === '') return false;

    const cooldownKey = [
      requestType,
      publisherId,
      trackId,
      safeSequence(request?.media_generation),
      safeSequence(request?.missing_from_sequence),
      safeSequence(request?.missing_to_sequence),
    ].join(':');
    const currentTime = now();
    const lastRequestedAt = Number(requestCooldowns.get(cooldownKey) || 0);
    if ((currentTime - lastRequestedAt) < GOSSIP_RECOVERY_REQUEST_COOLDOWN_MS) return false;
    requestCooldowns.set(cooldownKey, currentTime);
    return true;
  }

  function cachedFramesForRequest(request) {
    const publisherId = safeString(request?.publisher_id);
    const trackId = safeString(request?.track_id);
    const mediaGeneration = safeSequence(request?.media_generation);
    const entry = publishedFrameCache.get(trackCacheKey(publisherId, trackId, mediaGeneration));
    if (!entry) return [];
    const fromSequence = safeSequence(request?.missing_from_sequence || request?.frame_sequence);
    const toSequence = safeSequence(request?.missing_to_sequence || fromSequence);
    if (fromSequence <= 0 || toSequence < fromSequence) return [];

    const frames = [];
    for (let sequence = fromSequence; sequence <= toSequence && frames.length < GOSSIP_RECOVERY_RETRANSMIT_LIMIT; sequence += 1) {
      const frame = entry.frames.get(sequence);
      if (frame) frames.push(cloneGossipFrame(frame));
    }
    return frames;
  }

  function cachedKeyframeForRequest(request) {
    const publisherId = safeString(request?.publisher_id);
    const trackId = safeString(request?.track_id);
    const mediaGeneration = safeSequence(request?.media_generation);
    const entry = publishedFrameCache.get(trackCacheKey(publisherId, trackId, mediaGeneration));
    return entry?.keyframe ? cloneGossipFrame(entry.keyframe) : null;
  }

  return {
    cachedFramesForRequest,
    cachedKeyframeForRequest,
    clear,
    recoveryRequestForReceivedFrame,
    rememberPublishedFrame,
    shouldSendRecoveryRequest,
  };
}
