export const CALL_LAYOUT_MODES = ['grid', 'main_mini', 'main_only'];
export const CALL_LAYOUT_STRATEGIES = ['manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active'];

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

function activityScoreForUser(activityByUserId, userId, nowMs = Date.now()) {
  const entry = activityByUserId instanceof Map
    ? activityByUserId.get(userId)
    : (activityByUserId && typeof activityByUserId === 'object' ? activityByUserId[userId] : null);
  if (!entry || typeof entry !== 'object') return 0;

  if (Number.isFinite(Number(entry.score2s))) return Number(entry.score2s);
  if (Number.isFinite(Number(entry.score_2s))) return Number(entry.score_2s);
  if (Number.isFinite(Number(entry.score))) return Number(entry.score);

  const lastActiveMs = Number(entry.lastActiveMs || entry.updated_at_ms || 0);
  if (!Number.isFinite(lastActiveMs) || lastActiveMs <= 0) return 0;
  const ageMs = Math.max(0, nowMs - lastActiveMs);
  if (ageMs >= 15000) return 0;
  const weight = Number.isFinite(Number(entry.weight)) ? Number(entry.weight) : 0.5;
  return (1 - (ageMs / 15000)) * Math.max(0.25, Math.min(1, weight)) * 100;
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

function sortByActivity(participants, activityByUserId, nowMs) {
  return [...participants].sort((left, right) => {
    const scoreDiff = activityScoreForUser(activityByUserId, right.userId, nowMs) - activityScoreForUser(activityByUserId, left.userId, nowMs);
    if (scoreDiff !== 0) return scoreDiff;
    const roleRank = (role) => (role === 'admin' ? 0 : 1);
    const roleDiff = roleRank(left.role) - roleRank(right.role);
    if (roleDiff !== 0) return roleDiff;
    const nameDiff = left.displayName.localeCompare(right.displayName, 'en', { sensitivity: 'base' });
    if (nameDiff !== 0) return nameDiff;
    return left.userId - right.userId;
  });
}

export function selectCallLayoutParticipants({
  participants = [],
  currentUserId = 0,
  pinnedUsers = {},
  activityByUserId = {},
  layoutState = {},
  nowMs = Date.now(),
} = {}) {
  const layout = normalizeCallLayoutState(layoutState);
  const rows = participants.map((row) => normalizeParticipant(row)).filter(Boolean);
  const byUserId = new Map(rows.map((row) => [row.userId, row]));
  const mode = layout.mode;
  const limit = mode === 'grid' ? 8 : (mode === 'main_only' ? 1 : 5);

  const pinnedFromMap = Object.entries(pinnedUsers || {})
    .filter(([, pinned]) => pinned === true)
    .map(([id]) => Number(id))
    .filter((id) => Number.isInteger(id) && id > 0);
  const pinnedUserIds = [...new Set([...layout.pinnedUserIds, ...layout.selection.pinnedUserIds, ...pinnedFromMap])]
    .filter((id) => byUserId.has(id));
  const serverVisibleIds = layout.selection.visibleUserIds.filter((id) => byUserId.has(id));
  const manualSelectedIds = layout.selectedUserIds.filter((id) => byUserId.has(id));
  const rankedByActivity = sortByActivity(rows, activityByUserId, nowMs).map((row) => row.userId);

  const visibleIds = [];
  const addVisible = (id) => {
    if (!Number.isInteger(id) || id <= 0 || !byUserId.has(id) || visibleIds.includes(id)) return;
    visibleIds.push(id);
  };
  pinnedUserIds.forEach(addVisible);
  serverVisibleIds.forEach(addVisible);
  manualSelectedIds.forEach(addVisible);

  if (!layout.automationPaused && layout.strategy !== 'manual_pinned') {
    rankedByActivity.forEach(addVisible);
  } else {
    rows.map((row) => row.userId).forEach(addVisible);
  }

  const clippedVisibleIds = visibleIds.slice(0, limit);
  let mainUserId = Number(layout.selection.mainUserId || layout.mainUserId || 0);
  if (pinnedUserIds.length > 0) {
    mainUserId = pinnedUserIds[0];
  } else if (!layout.automationPaused && ['active_speaker_main', 'most_active_window', 'round_robin_active'].includes(layout.strategy)) {
    mainUserId = rankedByActivity.find((id) => clippedVisibleIds.includes(id)) || mainUserId;
  }
  if (!Number.isInteger(mainUserId) || mainUserId <= 0 || !byUserId.has(mainUserId)) {
    const localUserId = Number(currentUserId);
    const remoteMainUserId = mode === 'main_mini'
      ? clippedVisibleIds.find((id) => id !== localUserId)
      : 0;
    mainUserId = remoteMainUserId || clippedVisibleIds.find((id) => id === localUserId) || clippedVisibleIds[0] || localUserId || 0;
  }
  if (mode === 'main_mini' && pinnedUserIds.length <= 0 && mainUserId === Number(currentUserId)) {
    const miniIds = (layout.selection.mini_user_ids || []).filter((id) => byUserId.has(id));
    mainUserId = miniIds.find((id) => id !== Number(currentUserId)) || clippedVisibleIds.find((id) => id !== Number(currentUserId)) || mainUserId;
  }

  const visibleParticipants = clippedVisibleIds.map((id) => byUserId.get(id)).filter(Boolean);
  const explicitMiniIds = layout.selection?.mini_user_ids || layout.selection?.miniUserIds || [];
  const miniUserIds = mode === 'main_mini'
    ? (explicitMiniIds.length > 0 
        ? explicitMiniIds.filter((id) => byUserId.has(id)) 
        : clippedVisibleIds.filter((id) => id !== mainUserId))
    : [];
  const miniParticipants = mode === 'main_mini'
    ? miniUserIds.map((id) => byUserId.get(id)).filter(Boolean)
    : [];
  const gridParticipants = mode === 'grid' ? visibleParticipants : [];

  return {
    mode,
    strategy: layout.strategy,
    automationPaused: layout.automationPaused,
    mainUserId,
    visibleUserIds: clippedVisibleIds,
    pinnedUserIds,
    visibleParticipants,
    miniParticipants,
    gridParticipants,
  };
}
