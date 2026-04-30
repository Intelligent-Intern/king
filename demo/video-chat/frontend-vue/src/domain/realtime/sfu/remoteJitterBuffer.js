export const REMOTE_SFU_JITTER_BUFFER_HOLD_MS = 90;
export const REMOTE_SFU_JITTER_BUFFER_MAX_FRAMES = 8;
export const REMOTE_SFU_JITTER_BUFFER_MAX_GAP = 3;

function normalizePositiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

export function remoteJitterTrackKey(frame) {
  return String(frame?.trackId || '').trim() || 'default';
}

export function ensureRemoteJitterBufferState(peer, trackKey) {
  if (!peer || typeof peer !== 'object') return null;
  if (!peer.remoteJitterBufferByTrack || typeof peer.remoteJitterBufferByTrack !== 'object') {
    peer.remoteJitterBufferByTrack = {};
  }
  const normalizedTrackKey = String(trackKey || '').trim() || 'default';
  if (!peer.remoteJitterBufferByTrack[normalizedTrackKey] || typeof peer.remoteJitterBufferByTrack[normalizedTrackKey] !== 'object') {
    peer.remoteJitterBufferByTrack[normalizedTrackKey] = {
      framesBySequence: new Map(),
      timer: null,
    };
  }
  return peer.remoteJitterBufferByTrack[normalizedTrackKey];
}

function remoteFrameSequence(frame) {
  return normalizePositiveInteger(frame?.frameSequence, 0);
}

function isRemoteKeyframe(frame) {
  return String(frame?.type || '').trim().toLowerCase() === 'keyframe'
    || String(frame?.type || '').trim().toLowerCase() === 'key';
}

export function shouldBufferRemoteFrameForJitter(peer, frame, nowMs = Date.now()) {
  if (!peer || typeof peer !== 'object' || isRemoteKeyframe(frame)) {
    return { buffer: false, reason: 'not_bufferable' };
  }
  const sequence = remoteFrameSequence(frame);
  if (sequence <= 0) return { buffer: false, reason: 'missing_sequence' };
  const trackKey = remoteJitterTrackKey(frame);
  const lastSequence = normalizePositiveInteger(peer.lastSfuFrameSequenceByTrack?.[trackKey], 0);
  if (lastSequence <= 0 || sequence <= (lastSequence + 1)) {
    return { buffer: false, reason: 'already_in_order', lastSequence, sequence };
  }
  const missingFrameCount = sequence - lastSequence - 1;
  if (missingFrameCount > REMOTE_SFU_JITTER_BUFFER_MAX_GAP) {
    return { buffer: false, reason: 'gap_too_large', lastSequence, sequence, missingFrameCount };
  }
  return {
    buffer: true,
    reason: 'small_sequence_gap',
    trackKey,
    sequence,
    lastSequence,
    missingFrameCount,
    releaseAtMs: Math.max(0, Number(nowMs || 0)) + REMOTE_SFU_JITTER_BUFFER_HOLD_MS,
  };
}

export function bufferRemoteFrameForJitter(peer, frame, decision, nowMs = Date.now()) {
  const trackKey = String(decision?.trackKey || remoteJitterTrackKey(frame));
  const sequence = normalizePositiveInteger(decision?.sequence || frame?.frameSequence, 0);
  if (sequence <= 0) return false;
  const state = ensureRemoteJitterBufferState(peer, trackKey);
  if (!state) return false;
  state.framesBySequence.set(sequence, {
    frame,
    heldAtMs: Math.max(0, Number(nowMs || 0)),
    releaseAtMs: Math.max(0, Number(decision?.releaseAtMs || 0)),
  });
  const sortedSequences = Array.from(state.framesBySequence.keys()).sort((a, b) => a - b);
  while (sortedSequences.length > REMOTE_SFU_JITTER_BUFFER_MAX_FRAMES) {
    const evictSequence = sortedSequences.pop();
    if (evictSequence === undefined) break;
    state.framesBySequence.delete(evictSequence);
  }
  return true;
}

export function popNextRemoteJitterFrame(peer, trackKey) {
  const state = ensureRemoteJitterBufferState(peer, trackKey);
  if (!state) return null;
  const lastSequence = normalizePositiveInteger(peer.lastSfuFrameSequenceByTrack?.[trackKey], 0);
  const nextSequence = lastSequence + 1;
  const entry = state.framesBySequence.get(nextSequence);
  if (!entry) return null;
  state.framesBySequence.delete(nextSequence);
  return entry.frame || null;
}

export function popExpiredRemoteJitterFrame(peer, trackKey, nowMs = Date.now()) {
  const state = ensureRemoteJitterBufferState(peer, trackKey);
  if (!state) return null;
  const now = Math.max(0, Number(nowMs || 0));
  const expired = Array.from(state.framesBySequence.entries())
    .filter(([, entry]) => now >= Math.max(0, Number(entry?.releaseAtMs || 0)))
    .sort((a, b) => a[0] - b[0]);
  if (expired.length < 1) return null;
  const [sequence, entry] = expired[0];
  state.framesBySequence.delete(sequence);
  return entry?.frame || null;
}

export function remoteJitterBufferSize(peer, trackKey) {
  const state = ensureRemoteJitterBufferState(peer, trackKey);
  return state ? state.framesBySequence.size : 0;
}
