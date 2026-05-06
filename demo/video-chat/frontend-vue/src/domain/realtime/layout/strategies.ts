import { isScreenShareMediaSource, isScreenShareUserId } from '../screenShareIdentity.js';
import { compareLocalizedStrings } from '../../../support/localeCollation.js';

export const CALL_LAYOUT_MODES = ['grid', 'main_mini', 'main_only'];
export const CALL_LAYOUT_STRATEGIES = ['manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active'];
export const ACTIVE_SPEAKER_MIN_SPEAKING_MS = 2000;
export const ACTIVE_SPEAKER_RELEASE_PAUSE_MS = 2000;
export const ACTIVITY_TOP_POOL_LIMIT = 20;
export const ACTIVITY_TOP_POOL_MIN_TENURE_MS = 20_000;
export const ROUND_ROBIN_REFRESH_MS = 30_000;

export function normalizeCallLayoutMode(value, fallback = 'main_mini') {
  const normalized = String(value || '').trim().toLowerCase();
  return CALL_LAYOUT_MODES.includes(normalized) ? normalized : fallback;
}

export function normalizeCallLayoutStrategy(value, fallback = 'manual_pinned') {
  const normalized = String(value || '').trim().toLowerCase();
  return CALL_LAYOUT_STRATEGIES.includes(normalized) ? normalized : fallback;
}

function normalizeIdList(value) {
  const ids = Array.isArray(value) ? value : [];
  const seen = new Set();
  const normalized = [];
  for (const rawId of ids) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;
    seen.add(id);
    normalized.push(id);
  }
  return normalized;
}

export function normalizeCallLayoutState(value = {}) {
  const raw = value && typeof value === 'object' ? value : {};
  const selection = raw.selection && typeof raw.selection === 'object' ? raw.selection : {};
  return {
    callId: String(raw.call_id || raw.callId || '').trim(),
    roomId: String(raw.room_id || raw.roomId || '').trim(),
    mode: normalizeCallLayoutMode(raw.mode),
    strategy: normalizeCallLayoutStrategy(raw.strategy),
    automationPaused: Boolean(raw.automation_paused ?? raw.automationPaused ?? false),
    pinnedUserIds: normalizeIdList(raw.pinned_user_ids || raw.pinnedUserIds || selection.pinned_user_ids || selection.pinnedUserIds),
    selectedUserIds: normalizeIdList(raw.selected_user_ids || raw.selectedUserIds || selection.visible_user_ids || selection.visibleUserIds),
    mainUserId: Number(raw.main_user_id || raw.mainUserId || selection.main_user_id || selection.mainUserId || 0),
    selection: {
      mainUserId: Number(selection.main_user_id || selection.mainUserId || raw.main_user_id || raw.mainUserId || 0),
      visibleUserIds: normalizeIdList(selection.visible_user_ids || selection.visibleUserIds),
      miniUserIds: normalizeIdList(selection.mini_user_ids || selection.miniUserIds),
      pinnedUserIds: normalizeIdList(selection.pinned_user_ids || selection.pinnedUserIds || raw.pinned_user_ids || raw.pinnedUserIds),
    },
    updatedAt: String(raw.updated_at || raw.updatedAt || '').trim(),
  };
}

function activityEntryForUser(activityByUserId, userId) {
  return activityByUserId instanceof Map
    ? activityByUserId.get(userId)
    : (activityByUserId && typeof activityByUserId === 'object' ? activityByUserId[userId] : null);
}

function activityNumber(entry, keys, fallback = 0) {
  const keyList = Array.isArray(keys) ? keys : [keys];
  for (const key of keyList) {
    const value = Number(entry?.[key]);
    if (Number.isFinite(value)) return value;
  }
  return fallback;
}

function activityScoreForUser(activityByUserId, userId, nowMs = Date.now()) {
  const entry = activityEntryForUser(activityByUserId, userId);
  if (!entry || typeof entry !== 'object') return 0;

  const rollingScore = rollingActivityScore(entry);
  if (rollingScore > 0) return rollingScore;
  if (Number.isFinite(Number(entry.score))) return Number(entry.score);

  const lastActiveMs = Number(entry.lastActiveMs || entry.updated_at_ms || 0);
  if (!Number.isFinite(lastActiveMs) || lastActiveMs <= 0) return 0;
  const ageMs = Math.max(0, nowMs - lastActiveMs);
  if (ageMs >= 15000) return 0;
  const weight = Number.isFinite(Number(entry.weight)) ? Number(entry.weight) : 0.5;
  return (1 - (ageMs / 15000)) * Math.max(0.25, Math.min(1, weight)) * 100;
}

function normalizedScore(value) {
  const score = Number(value);
  if (!Number.isFinite(score)) return null;
  return Math.max(0, Math.min(100, score));
}

export function rollingActivityScore(entry) {
  if (!entry || typeof entry !== 'object') return 0;
  const score2s = normalizedScore(entry.topkScore2s ?? entry.topk_score_2s ?? entry.score2s ?? entry.score_2s);
  const score5s = normalizedScore(entry.topkScore5s ?? entry.topk_score_5s ?? entry.score5s ?? entry.score_5s);
  const score15s = normalizedScore(entry.topkScore15s ?? entry.topk_score_15s ?? entry.score15s ?? entry.score_15s);
  const score = normalizedScore(entry.score);
  if (score2s === null && score5s === null && score15s === null) return score ?? 0;

  let weightedTotal = 0;
  let weightTotal = 0;
  const add = (value, weight) => {
    if (value === null) return;
    weightedTotal += value * weight;
    weightTotal += weight;
  };
  add(score2s, 0.5);
  add(score5s, 0.3);
  add(score15s, 0.2);
  return weightTotal > 0 ? weightedTotal / weightTotal : 0;
}

function activityDeltaCountForUser(activityByUserId, userId, nowMs = Date.now()) {
  const entry = activityEntryForUser(activityByUserId, userId);
  if (!entry || typeof entry !== 'object') return 0;
  const count = activityNumber(entry, ['activityDeltaCount', 'deltaCount', 'delta_count', 'activity_delta_count'], 0);
  if (count > 0) return count;
  return activityScoreForUser(activityByUserId, userId, nowMs) / 100;
}

function normalizeParticipant(row) {
  const userId = Number(row?.userId || row?.user_id || row?.id || row?.user?.id || 0);
  if (!Number.isInteger(userId) || userId <= 0) return null;
  return {
    ...row,
    userId,
    displayName: String(row?.displayName || row?.display_name || row?.user?.display_name || `User ${userId}`).trim() || `User ${userId}`,
    role: String(row?.role || row?.user?.role || 'user').trim().toLowerCase() || 'user',
    callRole: String(row?.callRole || row?.call_role || row?.user?.call_role || 'participant').trim().toLowerCase() || 'participant',
  };
}

function compareParticipantFallback(left, right) {
  const screenShareDiff = Number(isScreenShareParticipant(right)) - Number(isScreenShareParticipant(left));
  if (screenShareDiff !== 0) return screenShareDiff;
  const roleRank = (role) => (role === 'admin' ? 0 : 1);
  const roleDiff = roleRank(left.role) - roleRank(right.role);
  if (roleDiff !== 0) return roleDiff;
  const nameDiff = compareLocalizedStrings(left.displayName, right.displayName);
  if (nameDiff !== 0) return nameDiff;
  return left.userId - right.userId;
}

function isScreenShareParticipant(row) {
  return isScreenShareUserId(row?.userId) || isScreenShareMediaSource(row?.mediaSource || row?.media_source);
}

function sortByActivity(participants, activityByUserId, nowMs) {
  return [...participants].sort((left, right) => {
    const scoreDiff = activityScoreForUser(activityByUserId, right.userId, nowMs) - activityScoreForUser(activityByUserId, left.userId, nowMs);
    if (scoreDiff !== 0) return scoreDiff;
    return compareParticipantFallback(left, right);
  });
}

function sortByActivityDeltas(participants, activityByUserId, nowMs) {
  return [...participants].sort((left, right) => {
    const deltaDiff = activityDeltaCountForUser(activityByUserId, right.userId, nowMs) - activityDeltaCountForUser(activityByUserId, left.userId, nowMs);
    if (deltaDiff !== 0) return deltaDiff;
    const scoreDiff = activityScoreForUser(activityByUserId, right.userId, nowMs) - activityScoreForUser(activityByUserId, left.userId, nowMs);
    if (scoreDiff !== 0) return scoreDiff;
    return compareParticipantFallback(left, right);
  });
}

function speakerStateForUser(activityByUserId, userId, nowMs) {
  const entry = activityEntryForUser(activityByUserId, userId);
  if (!entry || typeof entry !== 'object') {
    return { isSpeaking: false, speakingStartedAtMs: 0, speakingLastAtMs: 0 };
  }

  const speakingLastAtMs = activityNumber(entry, ['speakingLastAtMs', 'lastSpeakingAtMs', 'last_speaking_at_ms'], 0);
  const speakingStartedAtMs = activityNumber(entry, ['speakingStartedAtMs', 'speakingSinceMs', 'speaking_since_ms'], 0);
  const fallbackUpdatedAtMs = activityNumber(entry, ['updatedAtMs', 'updated_at_ms', 'lastActiveMs'], 0);
  const lastAtMs = speakingLastAtMs > 0 ? speakingLastAtMs : fallbackUpdatedAtMs;
  const startedAtMs = speakingStartedAtMs > 0 ? speakingStartedAtMs : lastAtMs;
  const hasRecentSpeechSignal = lastAtMs > 0 && (nowMs - lastAtMs) <= ACTIVE_SPEAKER_RELEASE_PAUSE_MS;
  const isSpeaking = Boolean(entry.isSpeaking ?? entry.is_speaking ?? false) && hasRecentSpeechSignal;

  return {
    isSpeaking,
    speakingStartedAtMs: isSpeaking ? startedAtMs : 0,
    speakingLastAtMs: lastAtMs,
  };
}

function sortBySpeaker(participants, activityByUserId, nowMs) {
  return [...participants].sort((left, right) => {
    const leftState = speakerStateForUser(activityByUserId, left.userId, nowMs);
    const rightState = speakerStateForUser(activityByUserId, right.userId, nowMs);
    const speakingDiff = Number(rightState.isSpeaking) - Number(leftState.isSpeaking);
    if (speakingDiff !== 0) return speakingDiff;

    const leftEntry = activityEntryForUser(activityByUserId, left.userId);
    const rightEntry = activityEntryForUser(activityByUserId, right.userId);
    const audioDiff = activityNumber(rightEntry, ['audioLevel', 'audio_level'], 0) - activityNumber(leftEntry, ['audioLevel', 'audio_level'], 0);
    if (audioDiff !== 0) return audioDiff;

    const scoreDiff = activityScoreForUser(activityByUserId, right.userId, nowMs) - activityScoreForUser(activityByUserId, left.userId, nowMs);
    if (scoreDiff !== 0) return scoreDiff;
    return compareParticipantFallback(left, right);
  });
}

function canSpeakerTakeOver(activityByUserId, userId, nowMs) {
  const state = speakerStateForUser(activityByUserId, userId, nowMs);
  return state.isSpeaking
    && state.speakingStartedAtMs > 0
    && (nowMs - state.speakingStartedAtMs) >= ACTIVE_SPEAKER_MIN_SPEAKING_MS;
}

function currentSpeakerStillOwnsMain(activityByUserId, userId, nowMs) {
  const state = speakerStateForUser(activityByUserId, userId, nowMs);
  if (state.isSpeaking) return true;
  return state.speakingLastAtMs > 0
    && (nowMs - state.speakingLastAtMs) < ACTIVE_SPEAKER_RELEASE_PAUSE_MS;
}

function resolveActiveSpeakerMainUserId({
  rankedSpeakerIds,
  byUserId,
  activityByUserId,
  currentMainUserId,
  selectionState,
  nowMs,
}) {
  const rememberedSpeakerId = Number(selectionState?.activeSpeakerUserId || 0);
  const heldUserId = byUserId.has(rememberedSpeakerId)
    ? rememberedSpeakerId
    : (byUserId.has(currentMainUserId) ? currentMainUserId : 0);

  if (heldUserId > 0 && currentSpeakerStillOwnsMain(activityByUserId, heldUserId, nowMs)) {
    if (selectionState && typeof selectionState === 'object') selectionState.activeSpeakerUserId = heldUserId;
    return heldUserId;
  }

  const takeoverUserId = rankedSpeakerIds.find((id) => id !== heldUserId && canSpeakerTakeOver(activityByUserId, id, nowMs)) || 0;
  if (takeoverUserId > 0) {
    if (selectionState && typeof selectionState === 'object') selectionState.activeSpeakerUserId = takeoverUserId;
    return takeoverUserId;
  }

  if (heldUserId > 0) {
    if (selectionState && typeof selectionState === 'object') selectionState.activeSpeakerUserId = heldUserId;
    return heldUserId;
  }

  const fallbackUserId = rankedSpeakerIds[0] || 0;
  if (selectionState && typeof selectionState === 'object') selectionState.activeSpeakerUserId = fallbackUserId;
  return fallbackUserId;
}

function topActivityEligibleIds(rankedByActivityDeltas, selectionState, nowMs) {
  const topIds = rankedByActivityDeltas.slice(0, ACTIVITY_TOP_POOL_LIMIT);
  if (!selectionState || typeof selectionState !== 'object') return topIds;

  if (!selectionState.topActivityEnteredAtMsByUserId || typeof selectionState.topActivityEnteredAtMsByUserId !== 'object') {
    selectionState.topActivityEnteredAtMsByUserId = {};
  }

  const store = selectionState.topActivityEnteredAtMsByUserId;
  const topSet = new Set(topIds);
  for (const id of topIds) {
    if (!Number.isFinite(Number(store[id])) || Number(store[id]) <= 0) {
      store[id] = nowMs;
    }
  }
  for (const key of Object.keys(store)) {
    if (!topSet.has(Number(key))) delete store[key];
  }
  return topIds.filter((id) => (nowMs - Number(store[id] || nowMs)) >= ACTIVITY_TOP_POOL_MIN_TENURE_MS);
}

function stableHash(value) {
  let hash = 2166136261;
  const text = String(value);
  for (let i = 0; i < text.length; i += 1) {
    hash ^= text.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  return hash >>> 0;
}

function stableRandomIds(ids, count, nowMs, salt) {
  const limit = Math.max(0, Number(count || 0));
  if (limit <= 0) return [];
  const bucket = Math.floor(nowMs / ROUND_ROBIN_REFRESH_MS);
  return [...ids]
    .sort((left, right) => {
      const leftHash = stableHash(`${salt}:${bucket}:${left}`);
      const rightHash = stableHash(`${salt}:${bucket}:${right}`);
      return leftHash - rightHash || left - right;
    })
    .slice(0, limit);
}

export function selectCallLayoutParticipants({
  participants = [],
  currentUserId = 0,
  pinnedUsers = {},
  activityByUserId = {},
  layoutState = {},
  selectionState = null,
  nowMs = Date.now(),
} = {}) {
  const layout = normalizeCallLayoutState(layoutState);
  const rows = participants.map((row) => normalizeParticipant(row)).filter(Boolean);
  const byUserId = new Map(rows.map((row) => [row.userId, row]));
  const mainRows = rows.filter((row) => !isScreenShareParticipant(row));
  const mainByUserId = new Map(mainRows.map((row) => [row.userId, row]));
  const mode = layout.mode;
  const limit = mode === 'grid' ? 8 : (mode === 'main_only' ? 1 : 5);

  const pinnedFromMap = Object.entries(pinnedUsers || {})
    .filter(([, pinned]) => pinned === true)
    .map(([id]) => Number(id))
    .filter((id) => Number.isInteger(id) && id > 0);
  const localPinnedUserIds = pinnedFromMap.filter((id) => byUserId.has(id));
  const layoutPinnedUserIds = [...new Set([...layout.pinnedUserIds, ...layout.selection.pinnedUserIds])]
    .filter((id) => byUserId.has(id));
  const pinnedUserIds = [...new Set([...localPinnedUserIds, ...layoutPinnedUserIds])];
  const hasLocalPinnedUser = localPinnedUserIds.length > 0;
  const serverVisibleIds = layout.selection.visibleUserIds.filter((id) => byUserId.has(id));
  const explicitMiniUserIds = layout.selection.miniUserIds.filter((id) => byUserId.has(id));
  const manualSelectedIds = layout.selectedUserIds.filter((id) => byUserId.has(id));
  const rankedByActivity = sortByActivity(rows, activityByUserId, nowMs).map((row) => row.userId);
  const rankedByActivityDeltas = sortByActivityDeltas(rows, activityByUserId, nowMs).map((row) => row.userId);
  const rankedMainByActivityDeltas = sortByActivityDeltas(mainRows, activityByUserId, nowMs).map((row) => row.userId);
  const rankedMainBySpeaker = sortBySpeaker(mainRows, activityByUserId, nowMs).map((row) => row.userId);
  const screenShareUserIds = rows
    .filter((row) => isScreenShareParticipant(row))
    .map((row) => row.userId);
  const automationActive = !layout.automationPaused && layout.strategy !== 'manual_pinned';
  const automationRankedIds = layout.strategy === 'most_active_window' ? rankedByActivityDeltas : rankedByActivity;

  const visibleIds = [];
  const addVisible = (id) => {
    if (!Number.isInteger(id) || id <= 0 || !byUserId.has(id) || visibleIds.includes(id)) return;
    visibleIds.push(id);
  };

  let mainUserId = Number(layout.selection.mainUserId || layout.mainUserId || 0);
  if (hasLocalPinnedUser) {
    mainUserId = localPinnedUserIds[0];
  } else if (layoutPinnedUserIds.length > 0) {
    mainUserId = layoutPinnedUserIds[0];
  } else if (automationActive && layout.strategy === 'most_active_window') {
    mainUserId = rankedMainByActivityDeltas[0] || mainUserId;
  } else if (automationActive && ['active_speaker_main', 'round_robin_active'].includes(layout.strategy)) {
    mainUserId = resolveActiveSpeakerMainUserId({
      rankedSpeakerIds: rankedMainBySpeaker,
      byUserId: mainByUserId,
      activityByUserId,
      currentMainUserId: Number(layout.selection.mainUserId || layout.mainUserId || 0),
      selectionState,
      nowMs,
    });
  }
  if (
    !hasLocalPinnedUser
    && layoutPinnedUserIds.length <= 0
    && Number.isInteger(mainUserId)
    && mainUserId > 0
    && isScreenShareParticipant(byUserId.get(mainUserId))
  ) {
    mainUserId = 0;
  }

  pinnedUserIds.forEach(addVisible);
  if (automationActive && Number.isInteger(mainUserId) && mainUserId > 0) addVisible(mainUserId);
  screenShareUserIds.forEach(addVisible);
  serverVisibleIds.forEach(addVisible);
  manualSelectedIds.forEach(addVisible);

  if (automationActive) {
    automationRankedIds.forEach(addVisible);
  } else {
    rows.map((row) => row.userId).forEach(addVisible);
  }

  let clippedVisibleIds = visibleIds.slice(0, limit);
  if (
    Number.isInteger(mainUserId)
    && mainUserId > 0
    && byUserId.has(mainUserId)
    && !clippedVisibleIds.includes(mainUserId)
  ) {
    clippedVisibleIds = [mainUserId, ...visibleIds.filter((id) => id !== mainUserId)].slice(0, limit);
  }

  if (!Number.isInteger(mainUserId) || mainUserId <= 0 || !byUserId.has(mainUserId)) {
    const localUserId = Number(currentUserId);
    const mainVisibleIds = clippedVisibleIds.filter((id) => mainByUserId.has(id));
    const remoteMainUserId = mode === 'main_mini'
      ? mainVisibleIds.find((id) => id !== localUserId)
      : 0;
    mainUserId = remoteMainUserId
      || mainVisibleIds.find((id) => id === localUserId)
      || mainVisibleIds[0]
      || clippedVisibleIds[0]
      || localUserId
      || 0;
  }
  if (mode === 'main_mini' && !hasLocalPinnedUser && layoutPinnedUserIds.length <= 0 && mainUserId === Number(currentUserId)) {
    if (explicitMiniUserIds.length <= 0) {
      const nonLocalVisibleIds = clippedVisibleIds
        .filter((id) => id !== Number(currentUserId) && !isScreenShareParticipant(byUserId.get(id)));
      mainUserId = nonLocalVisibleIds[0] || mainUserId;
    }
  }

  const explicitMiniVisibleIds = explicitMiniUserIds
    .filter((id) => clippedVisibleIds.includes(id) && id !== mainUserId);
  const fallbackMiniUserIds = clippedVisibleIds.filter((id) => id !== mainUserId);
  let miniUserIds = [];
  if (mode === 'main_mini') {
    if (automationActive && !hasLocalPinnedUser && layoutPinnedUserIds.length <= 0 && layout.strategy === 'most_active_window') {
      const screenMiniIds = screenShareUserIds
        .filter((id) => id !== mainUserId && byUserId.has(id))
        .slice(0, Math.max(0, limit - 1));
      const eligibleTopIds = topActivityEligibleIds(rankedMainByActivityDeltas, selectionState, nowMs)
        .filter((id) => id !== mainUserId && !screenMiniIds.includes(id) && byUserId.has(id));
      miniUserIds = [
        ...screenMiniIds,
        ...stableRandomIds(eligibleTopIds, Math.max(0, limit - 1 - screenMiniIds.length), nowMs, 'most-active-window'),
      ];
      clippedVisibleIds = [mainUserId, ...miniUserIds].filter((id) => byUserId.has(id)).slice(0, limit);
    } else if (automationActive && !hasLocalPinnedUser && layoutPinnedUserIds.length <= 0 && layout.strategy === 'round_robin_active') {
      const screenMiniIds = screenShareUserIds
        .filter((id) => id !== mainUserId && byUserId.has(id))
        .slice(0, Math.max(0, limit - 1));
      const restIds = mainRows.map((row) => row.userId)
        .filter((id) => id !== mainUserId && !screenMiniIds.includes(id));
      miniUserIds = [
        ...screenMiniIds,
        ...stableRandomIds(restIds, Math.max(0, limit - 1 - screenMiniIds.length), nowMs, 'round-robin-active'),
      ];
      clippedVisibleIds = [mainUserId, ...miniUserIds].filter((id) => byUserId.has(id)).slice(0, limit);
    } else {
      miniUserIds = explicitMiniUserIds.length > 0
        ? (explicitMiniVisibleIds.length > 0 ? explicitMiniVisibleIds : fallbackMiniUserIds)
        : fallbackMiniUserIds;
    }
  }
  const finalVisibleParticipants = clippedVisibleIds.map((id) => byUserId.get(id)).filter(Boolean);
  const miniParticipants = mode === 'main_mini'
    ? miniUserIds.map((id) => byUserId.get(id)).filter(Boolean)
    : [];
  const gridParticipants = mode === 'grid' ? finalVisibleParticipants : [];

  return {
    mode,
    strategy: layout.strategy,
    automationPaused: layout.automationPaused,
    mainUserId,
    visibleUserIds: clippedVisibleIds,
    pinnedUserIds,
    visibleParticipants: finalVisibleParticipants,
    miniParticipants,
    gridParticipants,
  };
}
