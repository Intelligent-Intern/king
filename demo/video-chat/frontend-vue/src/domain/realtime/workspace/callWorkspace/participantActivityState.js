const ACTIVITY_DELTA_COUNT_WINDOW_MS = 30_000;
const ACTIVITY_SPEAKING_SIGNAL_GRACE_MS = 2500;

function nextActivityDeltaStats(existing, nowMs, countDelta = true) {
  const previousWindowStartedAtMs = Number(existing?.activityDeltaWindowStartedAtMs || 0);
  const previousCount = Number(existing?.activityDeltaCount || 0);
  const insideWindow = previousWindowStartedAtMs > 0
    && (nowMs - previousWindowStartedAtMs) <= ACTIVITY_DELTA_COUNT_WINDOW_MS;
  return {
    activityDeltaWindowStartedAtMs: insideWindow ? previousWindowStartedAtMs : nowMs,
    activityDeltaCount: countDelta ? (insideWindow ? previousCount : 0) + 1 : (insideWindow ? previousCount : 0),
    activityDeltaLastAtMs: nowMs,
  };
}

function recentSpeakingState(existing, nowMs) {
  const lastSpeakingAtMs = Number(existing?.speakingLastAtMs || existing?.lastSpeakingAtMs || 0);
  return Boolean(existing?.isSpeaking)
    && lastSpeakingAtMs > 0
    && (nowMs - lastSpeakingAtMs) <= ACTIVITY_SPEAKING_SIGNAL_GRACE_MS;
}

export function createParticipantActivityState({
  participantActivityByUserId,
  participantActivityWeight,
  participantActivityWindowMs,
}) {
  function markParticipantActivity(userId, source = 'control', atMs = Date.now()) {
    const normalizedUserId = Number(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const nowMs = Number.isFinite(Number(atMs)) ? Math.max(0, Number(atMs)) : Date.now();
    const existing = participantActivityByUserId[normalizedUserId] || {};
    const normalizedSource = String(source || '').trim().toLowerCase();
    const localSampleSetsSpeaking = ['speaking', 'motion', 'control'].includes(normalizedSource);
    const isSpeaking = normalizedSource === 'speaking'
      ? true
      : (localSampleSetsSpeaking ? false : recentSpeakingState(existing, nowMs));
    const previousLastSpeakingAtMs = Number(existing.speakingLastAtMs || existing.lastSpeakingAtMs || 0);
    const previousSpeakingStartedAtMs = Number(existing.speakingStartedAtMs || existing.speakingSinceMs || 0);
    const speakingStartedAtMs = isSpeaking
      ? (recentSpeakingState(existing, nowMs) && previousSpeakingStartedAtMs > 0 ? previousSpeakingStartedAtMs : nowMs)
      : 0;
    const speakingLastAtMs = normalizedSource === 'speaking' ? nowMs : previousLastSpeakingAtMs;
    participantActivityByUserId[normalizedUserId] = {
      ...existing,
      lastActiveMs: nowMs,
      updatedAtMs: nowMs,
      source: normalizedSource,
      weight: participantActivityWeight(source),
      isSpeaking,
      speakingStartedAtMs,
      speakingLastAtMs,
      ...nextActivityDeltaStats(existing, nowMs, true),
    };
  }

  function pruneParticipantActivity(allowedUserIds = null) {
    const allowed = allowedUserIds instanceof Set ? allowedUserIds : null;
    const nowMs = Date.now();
    const staleAfterMs = participantActivityWindowMs * 2;
    for (const key of Object.keys(participantActivityByUserId)) {
      const userId = Number(key);
      if (!Number.isInteger(userId) || userId <= 0) {
        delete participantActivityByUserId[key];
        continue;
      }
      if (allowed && !allowed.has(userId)) {
        delete participantActivityByUserId[key];
        continue;
      }
      const entry = participantActivityByUserId[key];
      const lastActiveMs = Number(entry?.lastActiveMs || 0);
      if (!Number.isFinite(lastActiveMs) || (nowMs - lastActiveMs) > staleAfterMs) {
        delete participantActivityByUserId[key];
      }
    }
  }

  function participantActivityScore(userId, nowMs = Date.now()) {
    const normalizedUserId = Number(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return 0;
    const entry = participantActivityByUserId[normalizedUserId];
    if (!entry || typeof entry !== 'object') return 0;
    if (Number.isFinite(Number(entry.score2s))) return Number(entry.score2s);
    if (Number.isFinite(Number(entry.score_2s))) return Number(entry.score_2s);
    if (Number.isFinite(Number(entry.score))) return Number(entry.score);
    const lastActiveMs = Number(entry.lastActiveMs || 0);
    if (!Number.isFinite(lastActiveMs) || lastActiveMs <= 0) return 0;
    const ageMs = Math.max(0, nowMs - lastActiveMs);
    if (ageMs >= participantActivityWindowMs) return 0;
    const freshness = 1 - (ageMs / participantActivityWindowMs);
    const weight = Number.isFinite(Number(entry.weight)) ? Number(entry.weight) : 0.5;
    return freshness * Math.max(0.25, Math.min(1, weight)) * 100;
  }

  function activityLabelForUser(userId) {
    const normalizedUserId = Number(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return '';
    const entry = participantActivityByUserId[normalizedUserId];
    const score = participantActivityScore(normalizedUserId);
    if (Boolean(entry?.isSpeaking) || score >= 55) return 'Speaking';
    if (score >= 18) return 'Active';
    return '';
  }

  function applyParticipantActivityPayload(activity, participant = null, options = {}) {
    const normalizedUserId = Number(activity?.user_id || activity?.userId || participant?.user_id || participant?.userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const updatedAtMs = Number(activity?.updated_at_ms || activity?.updatedAtMs || Date.now());
    const normalizedUpdatedAtMs = Number.isFinite(updatedAtMs) && updatedAtMs > 0 ? updatedAtMs : Date.now();
    const existing = participantActivityByUserId[normalizedUserId] || {};
    const isSpeaking = Boolean(activity?.is_speaking ?? activity?.isSpeaking ?? false);
    const previousLastSpeakingAtMs = Number(existing.speakingLastAtMs || existing.lastSpeakingAtMs || 0);
    const previousSpeakingStartedAtMs = Number(existing.speakingStartedAtMs || existing.speakingSinceMs || 0);
    const speakingWasContinuous = Boolean(existing.isSpeaking)
      && previousLastSpeakingAtMs > 0
      && (normalizedUpdatedAtMs - previousLastSpeakingAtMs) <= ACTIVITY_SPEAKING_SIGNAL_GRACE_MS;
    const speakingStartedAtMs = isSpeaking
      ? (speakingWasContinuous && previousSpeakingStartedAtMs > 0 ? previousSpeakingStartedAtMs : normalizedUpdatedAtMs)
      : 0;
    const speakingLastAtMs = isSpeaking ? normalizedUpdatedAtMs : previousLastSpeakingAtMs;
    const deltaStats = nextActivityDeltaStats(existing, normalizedUpdatedAtMs, options.countDelta !== false);
    participantActivityByUserId[normalizedUserId] = {
      ...existing,
      lastActiveMs: normalizedUpdatedAtMs,
      updatedAtMs: normalizedUpdatedAtMs,
      source: String(activity?.source || 'server_activity').trim().toLowerCase(),
      weight: Number.isFinite(Number(activity?.score)) ? Math.max(0.25, Math.min(1, Number(activity.score) / 100)) : 0.75,
      score: Number(activity?.score || 0),
      score_2s: Number(activity?.score_2s ?? activity?.score2s ?? 0),
      score_5s: Number(activity?.score_5s ?? activity?.score5s ?? 0),
      score_15s: Number(activity?.score_15s ?? activity?.score15s ?? 0),
      audioLevel: Number(activity?.audio_level ?? activity?.audioLevel ?? 0),
      motionScore: Number(activity?.motion_score ?? activity?.motionScore ?? 0),
      isSpeaking,
      speakingStartedAtMs,
      speakingLastAtMs,
      ...deltaStats,
    };
  }

  function applyActivitySnapshot(rows) {
    if (!Array.isArray(rows)) return;
    for (const row of rows) {
      applyParticipantActivityPayload(row, null, { countDelta: false });
    }
  }

  return {
    activityLabelForUser,
    applyActivitySnapshot,
    applyParticipantActivityPayload,
    markParticipantActivity,
    participantActivityScore,
    pruneParticipantActivity,
  };
}
