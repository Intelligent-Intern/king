export const CALL_LAYOUT_MODES = ['grid', 'main_mini', 'main_only'] as const;
export const CALL_LAYOUT_STRATEGIES = ['manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active'] as const;

export type CallLayoutMode = typeof CALL_LAYOUT_MODES[number];
export type CallLayoutStrategy = typeof CALL_LAYOUT_STRATEGIES[number];

export interface CallLayoutSelectionInput {
  main_user_id?: number | string;
  mainUserId?: number | string;
  visible_user_ids?: unknown[];
  visibleUserIds?: unknown[];
  mini_user_ids?: unknown[];
  miniUserIds?: unknown[];
  pinned_user_ids?: unknown[];
  pinnedUserIds?: unknown[];
}

export interface CallLayoutStateInput {
  call_id?: string;
  callId?: string;
  room_id?: string;
  roomId?: string;
  mode?: string;
  strategy?: string;
  automation_paused?: boolean;
  automationPaused?: boolean;
  pinned_user_ids?: unknown[];
  pinnedUserIds?: unknown[];
  selected_user_ids?: unknown[];
  selectedUserIds?: unknown[];
  main_user_id?: number | string;
  mainUserId?: number | string;
  selection?: CallLayoutSelectionInput;
  updated_at?: string;
  updatedAt?: string;
}

export interface NormalizedCallLayoutState {
  callId: string;
  roomId: string;
  mode: CallLayoutMode;
  strategy: CallLayoutStrategy;
  automationPaused: boolean;
  pinnedUserIds: number[];
  selectedUserIds: number[];
  mainUserId: number;
  selection: {
    mainUserId: number;
    visibleUserIds: number[];
    miniUserIds: number[];
    pinnedUserIds: number[];
  };
  updatedAt: string;
}

export interface CallLayoutParticipantInput {
  id?: number | string;
  userId?: number | string;
  user_id?: number | string;
  displayName?: string;
  display_name?: string;
  role?: string;
  callRole?: string;
  call_role?: string;
  user?: {
    id?: number | string;
    display_name?: string;
    role?: string;
    call_role?: string;
  };
  [key: string]: unknown;
}

export interface CallLayoutParticipant extends CallLayoutParticipantInput {
  userId: number;
  displayName: string;
  role: string;
  callRole: string;
}

export interface CallActivityEntry {
  score2s?: number | string;
  score_2s?: number | string;
  score?: number | string;
  lastActiveMs?: number | string;
  updated_at_ms?: number | string;
  weight?: number | string;
}

export interface SelectCallLayoutParticipantsOptions {
  participants?: CallLayoutParticipantInput[];
  currentUserId?: number | string;
  pinnedUsers?: Record<string, boolean>;
  activityByUserId?: Map<number, CallActivityEntry> | Record<string, CallActivityEntry>;
  layoutState?: CallLayoutStateInput;
  nowMs?: number;
}

export interface SelectedCallLayoutParticipants {
  mode: CallLayoutMode;
  strategy: CallLayoutStrategy;
  automationPaused: boolean;
  mainUserId: number;
  visibleUserIds: number[];
  pinnedUserIds: number[];
  visibleParticipants: CallLayoutParticipant[];
  miniParticipants: CallLayoutParticipant[];
  gridParticipants: CallLayoutParticipant[];
}

export function normalizeCallLayoutMode(value: unknown, fallback: CallLayoutMode = 'main_mini'): CallLayoutMode {
  const normalized = String(value || '').trim().toLowerCase();
  return (CALL_LAYOUT_MODES as readonly string[]).includes(normalized) ? normalized as CallLayoutMode : fallback;
}

export function normalizeCallLayoutStrategy(value: unknown, fallback: CallLayoutStrategy = 'manual_pinned'): CallLayoutStrategy {
  const normalized = String(value || '').trim().toLowerCase();
  return (CALL_LAYOUT_STRATEGIES as readonly string[]).includes(normalized) ? normalized as CallLayoutStrategy : fallback;
}

function normalizeIdList(value: unknown): number[] {
  const ids = Array.isArray(value) ? value : [];
  const seen = new Set<number>();
  const normalized: number[] = [];
  for (const rawId of ids) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;
    seen.add(id);
    normalized.push(id);
  }
  return normalized;
}

export function normalizeCallLayoutState(value: CallLayoutStateInput = {}): NormalizedCallLayoutState {
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

function activityScoreForUser(
  activityByUserId: SelectCallLayoutParticipantsOptions['activityByUserId'],
  userId: number,
  nowMs = Date.now(),
): number {
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

function normalizeParticipant(row: CallLayoutParticipantInput): CallLayoutParticipant | null {
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

function sortByActivity(
  participants: CallLayoutParticipant[],
  activityByUserId: SelectCallLayoutParticipantsOptions['activityByUserId'],
  nowMs: number,
): CallLayoutParticipant[] {
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
}: SelectCallLayoutParticipantsOptions = {}): SelectedCallLayoutParticipants {
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
  const explicitMiniUserIds = layout.selection.miniUserIds.filter((id) => byUserId.has(id));
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
    if (explicitMiniUserIds.length <= 0) {
      mainUserId = clippedVisibleIds.find((id) => id !== Number(currentUserId)) || mainUserId;
    }
  }

  const visibleParticipants = clippedVisibleIds.map((id) => byUserId.get(id)).filter(Boolean);
  const explicitMiniVisibleIds = explicitMiniUserIds
    .filter((id) => clippedVisibleIds.includes(id) && id !== mainUserId);
  const fallbackMiniUserIds = clippedVisibleIds.filter((id) => id !== mainUserId);
  const miniUserIds = mode === 'main_mini'
    ? (explicitMiniUserIds.length > 0
      ? (explicitMiniVisibleIds.length > 0 ? explicitMiniVisibleIds : fallbackMiniUserIds)
      : fallbackMiniUserIds)
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
