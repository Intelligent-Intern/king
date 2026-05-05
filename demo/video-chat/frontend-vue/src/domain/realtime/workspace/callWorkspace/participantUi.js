import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

import { createParticipantActivityState } from './participantActivityState';
import { createCallWorkspaceModerationSync } from './moderationSync';
import { createVideoFullscreenToggle } from './videoFullscreenToggle';
import { isScreenShareMediaSource, isScreenShareUserId, screenShareUserIdForOwner } from '../../screenShareIdentity.js';

export function createCallWorkspaceParticipantUiHelpers(context) {
  const {
    activeReactions,
    activeRoomId,
    activeTab,
    admissionGateState,
    aloneIdlePrompt,
    apiRequest,
    callLayoutState,
    callParticipantRoles,
    canModerate,
    chatAttachmentDrafts,
    chatByRoom,
    chatDraft,
    chatEmojiTrayOpen,
    chatSending,
    chatUnreadByRoom,
    compactMiniStripPlacement,
    connectedParticipantUsers,
    controlState,
    currentUserId,
    fullscreenVideoUserId,
    hangupCall,
    isAloneInCall,
    isCompactLayoutViewport,
    isCompactMiniStripAbove,
    isSocketOnline,
    layoutStrategyOptionsFor,
    lobbyActionState,
    lobbyListRef,
    lobbyListViewport,
    lobbyNotificationState,
    lobbyPage,
    lobbyQueue,
    localReactionEchoes,
    mediaRenderVersion,
    moderationActionState,
    mutedUsers,
    nextTick: nextTickOverride,
    normalizeCallLayoutMode,
    normalizeCallLayoutState,
    normalizeCallRole,
    normalizeOptionalRoomId,
    normalizeRole,
    normalizeRoomId,
    normalizeUsersDirectoryOrder,
    normalizeUsersDirectoryStatus,
    parseUsersDirectoryQuery,
    participantActivityByUserId,
    participantActivityWeight,
    participantHasRenderableMedia,
    participantUsers,
    peerControlStateByUserId,
    pinnedUsers,
    publishLocalActivitySample,
    queuedReactionEmojis,
    reconfigureLocalTracksFromSelectedDevices,
    renderCallVideoLayout,
    replaceNumericArray,
    requestRoomSnapshot,
    remotePeersRef,
    rightSidebarCollapsed,
    sendSocketFrame,
    selectCallLayoutParticipants,
    setLocalScreenShareEnabled,
    showLobbyTab,
    typingByRoom,
    usersDirectoryLoading,
    usersDirectoryPagination,
    usersDirectoryRows,
    usersListRef,
    usersListViewport,
    usersPage,
    usersSearch,
    usersSourceMode,
    viewerEffectiveCallRole,
    workspaceError,
    workspaceNotice,
    workspaceSidebarState,
    CALL_LAYOUT_MODES,
    CALL_LAYOUT_STRATEGIES,
    CALL_STATE_SIGNAL_TYPES,
    LOBBY_PAGE_SIZE,
    LOCAL_REACTION_ECHO_TTL_MS,
    MEDIA_SECURITY_SIGNAL_TYPES,
    MODERATION_SYNC_FLUSH_INTERVAL_MS,
    PARTICIPANT_ACTIVITY_WINDOW_MS,
    REACTION_CLIENT_BATCH_SIZE,
    REACTION_CLIENT_DIRECT_PER_WINDOW,
    REACTION_CLIENT_FLUSH_INTERVAL_MS,
    REACTION_CLIENT_MAX_QUEUE,
    REACTION_CLIENT_WINDOW_MS,
    ROSTER_VIRTUAL_OVERSCAN,
    ROSTER_VIRTUAL_ROW_HEIGHT,
    USERS_PAGE_SIZE,
    VISIBLE_PARTICIPANTS_LIMIT,
    ALONE_IDLE_ACTIVITY_EVENTS,
    ALONE_IDLE_COUNTDOWN_MS,
    ALONE_IDLE_POLL_MS,
    ALONE_IDLE_PROMPT_AFTER_MS,
    ALONE_IDLE_TICK_MS,
    layoutModeOptionsFor,
    REMOTE_VIDEO_FREEZE_THRESHOLD_MS,
    REMOTE_VIDEO_STALL_THRESHOLD_MS,
  } = context;

  void nextTickOverride;

  let reactionId = 0;
  let reactionQueueTimer = null;
  let reactionWindowStartedMs = 0;
  let reactionSentInWindow = 0;
  let reactionBatchCounter = 0;
  let lobbyToastTimer = null;
  let aloneIdleWatchTimer = null;
  let aloneIdleCountdownTimer = null;
  let aloneIdleLastActiveMs = Date.now();
  const layoutAutomationTick = ref(0);
  const layoutSelectionState = {
    activeSpeakerUserId: 0,
    topActivityEnteredAtMsByUserId: {},
  };
  const autoPinnedScreenShareUserIds = new Set();
  let layoutAutomationTimer = null;

const {
  activityLabelForUser,
  applyActivitySnapshot,
  applyParticipantActivityPayload,
  markParticipantActivity,
  participantActivityScore,
  pruneParticipantActivity,
} = createParticipantActivityState({
  participantActivityByUserId,
  participantActivityWeight,
  participantActivityWindowMs: PARTICIPANT_ACTIVITY_WINDOW_MS,
});

function applyCallLayoutPayload(payload) {
  const normalized = normalizeCallLayoutState(payload);
  callLayoutState.call_id = normalized.callId;
  callLayoutState.room_id = normalized.roomId;
  callLayoutState.mode = normalized.mode;
  callLayoutState.strategy = normalized.strategy;
  callLayoutState.automation_paused = normalized.automationPaused;
  callLayoutState.main_user_id = Number.isInteger(normalized.mainUserId) ? normalized.mainUserId : 0;
  callLayoutState.updated_at = normalized.updatedAt;
  replaceNumericArray(callLayoutState.pinned_user_ids, normalized.pinnedUserIds);
  replaceNumericArray(callLayoutState.selected_user_ids, normalized.selectedUserIds);
  callLayoutState.selection.main_user_id = Number.isInteger(normalized.selection.mainUserId) ? normalized.selection.mainUserId : 0;
  replaceNumericArray(callLayoutState.selection.visible_user_ids, normalized.selection.visibleUserIds);
  replaceNumericArray(callLayoutState.selection.mini_user_ids, normalized.selection.miniUserIds);
  replaceNumericArray(callLayoutState.selection.pinned_user_ids, normalized.selection.pinnedUserIds);

  nextTick(() => renderCallVideoLayout());
}

function participantVisibilityScore(row, nowMs = Date.now()) {
  const userId = Number(row?.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0) return 0;
  const pinnedBoost = pinnedUsers[userId] === true ? 1000 : 0;
  const localBoost = userId === currentUserId.value ? 30 : 0;
  const callRole = normalizeCallRole(row?.callRole || 'participant');
  const roleBoost = callRole === 'owner' ? 14 : (callRole === 'moderator' ? 8 : 0);
  const peerState = userId === currentUserId.value
    ? controlState
    : (peerControlStateByUserId[userId] && typeof peerControlStateByUserId[userId] === 'object'
      ? peerControlStateByUserId[userId]
      : null);
  const raisedHandBoost = Boolean(peerState?.handRaised) ? 10 : 0;
  return pinnedBoost + localBoost + roleBoost + raisedHandBoost + participantActivityScore(userId, nowMs);
}

const normalizedCallLayout = computed(() => normalizeCallLayoutState(callLayoutState));
const currentLayoutMode = computed(() => normalizeCallLayoutMode(normalizedCallLayout.value.mode));
const activeScreenShareUserIds = computed(() => {
  const seen = new Set();
  const userIds = [];
  for (const row of connectedParticipantUsers.value) {
    const screenShareUserId = screenShareUserIdFromParticipant(row);
    if (screenShareUserId <= 0 || seen.has(screenShareUserId)) continue;
    seen.add(screenShareUserId);
    userIds.push(screenShareUserId);
  }
  return userIds;
});
  if (typeof window !== 'undefined') {
    layoutAutomationTimer = window.setInterval(() => {
      const layout = normalizedCallLayout.value;
      if (layout.automationPaused || layout.strategy === 'manual_pinned') return;
      layoutAutomationTick.value += 1;
    }, 1000);
  }
  onBeforeUnmount(() => {
    if (layoutAutomationTimer !== null && typeof window !== 'undefined') {
      window.clearInterval(layoutAutomationTimer);
    }
  });
watch(activeScreenShareUserIds, (screenShareUserIds, previousScreenShareUserIds = []) => {
  const currentIds = new Set(screenShareUserIds);
  for (const previousUserId of previousScreenShareUserIds) {
    if (!currentIds.has(previousUserId)) {
      autoPinnedScreenShareUserIds.delete(previousUserId);
    }
  }
  const nextScreenShareUserId = screenShareUserIds.find((userId) => !autoPinnedScreenShareUserIds.has(userId));
  if (nextScreenShareUserId > 0) {
    pinScreenShareParticipant(nextScreenShareUserId);
  }
});
const layoutSelection = computed(() => selectCallLayoutParticipants({
  tick: layoutAutomationTick.value,
  participants: connectedParticipantUsers.value,
  currentUserId: currentUserId.value,
  pinnedUsers,
  activityByUserId: participantActivityByUserId,
  layoutState: normalizedCallLayout.value,
  selectionState: layoutSelectionState,
  nowMs: Date.now(),
}));
const stripParticipants = computed(() => layoutSelection.value.visibleParticipants.slice(0, VISIBLE_PARTICIPANTS_LIMIT));
const primaryVideoUserId = computed(() => {
  const selectedId = Number(layoutSelection.value.mainUserId || 0);
  return Number.isInteger(selectedId) && selectedId > 0 ? selectedId : currentUserId.value;
});
const miniVideoParticipants = computed(() => {
  if (currentLayoutMode.value !== 'main_mini') return [];
  return layoutSelection.value.miniParticipants;
});
const gridVideoParticipants = computed(() => (
  currentLayoutMode.value === 'grid' ? layoutSelection.value.gridParticipants : []
));
const showMiniParticipantStrip = computed(() => (
  currentLayoutMode.value === 'main_mini'
  && connectedParticipantUsers.value.length > 1
));

function remotePeerForParticipant(userId) {
  if (mediaRenderVersion && typeof mediaRenderVersion === 'object') {
    mediaRenderVersion.value;
  }
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return null;
  const peers = remotePeersRef?.value instanceof Map ? remotePeersRef.value : new Map();
  for (const peer of peers.values()) {
    if (!peer || typeof peer !== 'object') continue;
    if (Number(peer.userId || 0) === normalizedUserId) return peer;
  }
  return null;
}

function participantMediaStatus(userId) {
  if (mediaRenderVersion && typeof mediaRenderVersion === 'object') {
    mediaRenderVersion.value;
  }
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) {
    return { show: false, state: 'local', label: '' };
  }

  const hasRenderable = typeof participantHasRenderableMedia === 'function'
    ? participantHasRenderableMedia(normalizedUserId)
    : false;
  const peer = remotePeerForParticipant(normalizedUserId);
  if (!peer) {
    return { show: true, state: 'connecting', label: 'Connecting media' };
  }

  const nowMs = Date.now();
  const state = String(peer.mediaConnectionState || '').trim().toLowerCase();
  const message = String(peer.mediaConnectionMessage || '').trim();
  if (state === 'recovering') {
    return { show: true, state: 'recovering', label: message || 'Reconnecting video' };
  }

  const frameCount = Number(peer.frameCount || 0);
  if (!hasRenderable || frameCount <= 0) {
    const createdAtMs = Number(peer.createdAtMs || 0);
    const ageMs = createdAtMs > 0 ? Math.max(0, nowMs - createdAtMs) : 0;
    if (ageMs >= REMOTE_VIDEO_STALL_THRESHOLD_MS) {
      return { show: true, state: 'recovering', label: 'Reconnecting video' };
    }
    return { show: true, state: 'connecting', label: message || 'Connecting media' };
  }

  const lastFrameAtMs = Number(peer.lastFrameAtMs || 0);
  if (lastFrameAtMs > 0 && (nowMs - lastFrameAtMs) >= Math.max(REMOTE_VIDEO_FREEZE_THRESHOLD_MS * 3, REMOTE_VIDEO_STALL_THRESHOLD_MS * 2)) {
    return { show: true, state: 'recovering', label: 'Reconnecting video' };
  }

  return { show: false, state: 'live', label: '' };
}

function showParticipantMediaOverlay(userId) {
  return participantMediaStatus(userId).show;
}

function participantMediaStatusLabel(userId) {
  return participantMediaStatus(userId).label;
}

function participantMediaStatusState(userId) {
  return participantMediaStatus(userId).state;
}

function screenShareUserIdFromParticipant(row) {
  const userId = Number(row?.userId || row?.user_id || 0);
  if (Number.isInteger(userId) && isScreenShareUserId(userId)) return userId;
  if (!isScreenShareMediaSource(row?.mediaSource || row?.media_source)) return 0;
  const ownerUserId = Number(
    row?.screenShareOwnerUserId
    || row?.screen_share_owner_user_id
    || row?.publisherUserId
    || row?.publisher_user_id
    || 0
  );
  return screenShareUserIdForOwner(ownerUserId) || (Number.isInteger(userId) && userId > 0 ? userId : 0);
}

function replaceLocalPinsWithScreenShare(screenShareUserId) {
  const normalizedUserId = Number(screenShareUserId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  const alreadyPinnedOnly = pinnedUsers[normalizedUserId] === true && Object.keys(pinnedUsers).length === 1;
  if (alreadyPinnedOnly) {
    autoPinnedScreenShareUserIds.add(normalizedUserId);
    return false;
  }
  for (const key of Object.keys(pinnedUsers)) {
    delete pinnedUsers[key];
  }
  pinnedUsers[normalizedUserId] = true;
  autoPinnedScreenShareUserIds.add(normalizedUserId);
  markUserActionText(normalizedUserId, 'pin', 'Pinned', false);
  refreshUsersDirectoryPresentation();
  nextTick(() => renderCallVideoLayout());
  return true;
}

function pinScreenShareParticipant(screenShareUserId) {
  const normalizedUserId = Number(screenShareUserId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  if (autoPinnedScreenShareUserIds.has(normalizedUserId)) return false;
  return replaceLocalPinsWithScreenShare(normalizedUserId);
}

function forgetScreenShareAutoPin(screenShareUserId, removePin = false) {
  const normalizedUserId = Number(screenShareUserId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  autoPinnedScreenShareUserIds.delete(normalizedUserId);
  if (!removePin || pinnedUsers[normalizedUserId] !== true) return false;
  delete pinnedUsers[normalizedUserId];
  refreshUsersDirectoryPresentation();
  nextTick(() => renderCallVideoLayout());
  return true;
}

function fullscreenUserIdFromEvent(event, fallbackUserId = 0) {
  const readUserId = (node) => {
    if (!(node instanceof HTMLElement)) return 0;
    return Number(
      node.dataset?.callVideoSurfaceUserId
      || node.dataset?.userId
      || 0
    );
  };
  const path = typeof event?.composedPath === 'function' ? event.composedPath() : [];
  for (const node of path) {
    const userId = readUserId(node);
    if (Number.isInteger(userId) && userId > 0) return userId;
  }
  let node = event?.target instanceof HTMLElement ? event.target : null;
  while (node) {
    const userId = readUserId(node);
    if (Number.isInteger(userId) && userId > 0) return userId;
    node = node.parentElement;
  }
  const normalizedFallbackUserId = Number(fallbackUserId || 0);
  return Number.isInteger(normalizedFallbackUserId) && normalizedFallbackUserId > 0
    ? normalizedFallbackUserId
    : 0;
}

function toggleVideoFullscreenForEvent(fallbackUserId, event) {
  const targetUserId = fullscreenUserIdFromEvent(event, fallbackUserId);
  if (targetUserId > 0) {
    toggleVideoFullscreen(targetUserId);
  }
}

const layoutModeOptions = computed(() => layoutModeOptionsFor(CALL_LAYOUT_MODES));
const layoutStrategyOptions = computed(() => CALL_LAYOUT_STRATEGIES);
const showCompactMiniStripToggle = computed(() => (
  isCompactLayoutViewport.value
  && showMiniParticipantStrip.value
));
const compactMiniStripToggleLabel = computed(() => (
  isCompactMiniStripAbove.value
    ? 'Move mini videos below main video'
    : 'Move mini videos above main video'
));

function toggleCompactMiniStripPlacement() {
  compactMiniStripPlacement.value = isCompactMiniStripAbove.value ? 'below' : 'above';
  nextTick(() => renderCallVideoLayout());
}

function currentCallLayoutSidebarControls() {
  const controls = workspaceSidebarState?.callLayoutControls;
  return controls && typeof controls === 'object' ? controls : null;
}

function syncCallLayoutSidebarControls() {
  const controls = currentCallLayoutSidebarControls();
  if (!controls) return;

  controls.visible = true;
  controls.canModerate = canModerate.value;
  controls.currentMode = currentLayoutMode.value;
  controls.currentStrategy = normalizedCallLayout.value.strategy;
  controls.modeOptions = layoutModeOptions.value.map((option) => ({
    mode: option.mode,
    label: option.label,
    icon: option.icon,
  }));
  controls.strategyOptions = layoutStrategyOptionsFor(layoutStrategyOptions.value);
  controls.setMode = setCallLayoutMode;
  controls.setStrategy = setCallLayoutStrategy;
}

function clearCallLayoutSidebarControls() {
  const controls = currentCallLayoutSidebarControls();
  if (!controls) return;

  controls.visible = false;
  controls.canModerate = false;
  controls.currentMode = 'main_mini';
  controls.currentStrategy = 'manual_pinned';
  controls.modeOptions = [];
  controls.strategyOptions = [];
  controls.setMode = null;
  controls.setStrategy = null;
}

watch(
  () => [
    canModerate.value,
    currentLayoutMode.value,
    normalizedCallLayout.value.strategy,
    layoutModeOptions.value.map((option) => option.mode).join(','),
    layoutStrategyOptions.value.join(','),
  ],
  () => syncCallLayoutSidebarControls(),
  { immediate: true },
);

onBeforeUnmount(() => {
  clearCallLayoutSidebarControls();
});

const showLobbyRequestBadge = computed(() => (
  showLobbyTab.value
  && lobbyQueue.value.length > 0
));
const lobbyRequestBadgeText = computed(() => (
  lobbyQueue.value.length > 99 ? '99+' : String(lobbyQueue.value.length)
));
const showLobbyJoinToast = computed(() => (
  showLobbyTab.value
  && rightSidebarCollapsed.value
  && lobbyNotificationState.toastVisible
  && lobbyNotificationState.toastMessage !== ''
));
const lobbyJoinToastMessage = computed(() => lobbyNotificationState.toastMessage);
const aloneIdleCountdownLabel = computed(() => {
  const remainingMs = Math.max(0, Number(aloneIdlePrompt.remainingMs || 0));
  const totalSeconds = Math.ceil(remainingMs / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const snapshotUsersRows = computed(() => participantUsers.value.map((row) => userRowSnapshot(row)));

const filteredUsers = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return usersDirectoryRows.value;
  }

  const query = usersSearch.value.trim().toLowerCase();
  if (query === '') return snapshotUsersRows.value;

  return snapshotUsersRows.value.filter((row) => (
    String(row.displayName || '').toLowerCase().includes(query)
    || String(row.role || '').toLowerCase().includes(query)
    || String(row.userId || '').includes(query)
    || String(row.feedback || '').toLowerCase().includes(query)
  ));
});

const usersPageCount = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return Math.max(1, usersDirectoryPagination.pageCount || 1);
  }

  return Math.max(1, Math.ceil(filteredUsers.value.length / USERS_PAGE_SIZE));
});
const usersPageRows = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return usersDirectoryRows.value.map((row) => userRowSnapshot(row));
  }

  const offset = (usersPage.value - 1) * USERS_PAGE_SIZE;
  return filteredUsers.value.slice(offset, offset + USERS_PAGE_SIZE).map((row) => userRowSnapshot(row));
});

function updateListViewportMetrics(node, viewport) {
  if (!(node instanceof HTMLElement)) return;
  viewport.scrollTop = Math.max(0, Number(node.scrollTop || 0));
  viewport.viewportHeight = Math.max(0, Number(node.clientHeight || 0));
}

function computeVirtualWindow(rows, viewport) {
  const total = Array.isArray(rows) ? rows.length : 0;
  if (total <= 0) {
    return {
      start: 0,
      end: 0,
      paddingTop: 0,
      paddingBottom: 0,
      rows: [],
    };
  }

  const viewportHeight = Math.max(
    ROSTER_VIRTUAL_ROW_HEIGHT * 3,
    Number(viewport?.viewportHeight || 0)
  );
  const contentHeight = total * ROSTER_VIRTUAL_ROW_HEIGHT;
  const maxScrollTop = Math.max(0, contentHeight - viewportHeight);
  const scrollTop = Math.max(0, Math.min(Number(viewport?.scrollTop || 0), maxScrollTop));
  const start = Math.max(0, Math.floor(scrollTop / ROSTER_VIRTUAL_ROW_HEIGHT) - ROSTER_VIRTUAL_OVERSCAN);
  const visibleCount = Math.ceil(viewportHeight / ROSTER_VIRTUAL_ROW_HEIGHT) + (ROSTER_VIRTUAL_OVERSCAN * 2);
  const end = Math.min(total, start + visibleCount);
  const paddingTop = start * ROSTER_VIRTUAL_ROW_HEIGHT;
  const paddingBottom = Math.max(0, (total - end) * ROSTER_VIRTUAL_ROW_HEIGHT);

  return {
    start,
    end,
    paddingTop,
    paddingBottom,
    rows: rows.slice(start, end),
  };
}

const usersVirtualWindow = computed(() => computeVirtualWindow(usersPageRows.value, usersListViewport));
const usersVisibleRows = computed(() => usersVirtualWindow.value.rows);

const lobbyRows = computed(() => {
  return lobbyQueue.value.map((row) => ({
    ...row,
    status: 'queued',
    sortTs: Number(row.requested_unix_ms || 0),
  })).sort((left, right) => {
    if (left.sortTs !== right.sortTs) return left.sortTs - right.sortTs;
    return String(left.display_name || '').localeCompare(String(right.display_name || ''), 'en', { sensitivity: 'base' });
  });
});

const lobbyPageCount = computed(() => Math.max(1, Math.ceil(lobbyRows.value.length / LOBBY_PAGE_SIZE)));
const lobbyPageRows = computed(() => {
  const offset = (lobbyPage.value - 1) * LOBBY_PAGE_SIZE;
  return lobbyRows.value.slice(offset, offset + LOBBY_PAGE_SIZE).map((row) => lobbyRowSnapshot(row));
});

const lobbyVirtualWindow = computed(() => computeVirtualWindow(lobbyPageRows.value, lobbyListViewport));
const lobbyVisibleRows = computed(() => lobbyVirtualWindow.value.rows);

const activeMessages = computed(() => {
  const bucket = chatByRoom[activeRoomId.value];
  return Array.isArray(bucket) ? bucket : [];
});

const hasChatPayload = computed(() => chatDraft.value.trim() !== '' || chatAttachmentDrafts.value.length > 0);
const canSubmitChatMessage = computed(() => hasChatPayload.value && !chatSending.value);
const showChatUnreadBadge = computed(() => chatUnreadByRoom[activeRoomId.value] === true);
const showChatUnreadToast = computed(() => showChatUnreadBadge.value && (rightSidebarCollapsed.value || activeTab.value !== 'chat'));

const typingUsers = computed(() => {
  const rows = typingByRoom[activeRoomId.value];
  if (!rows || typeof rows !== 'object') return [];
  const nowMs = Date.now();
  return Object.values(rows)
    .filter((entry) => Number(entry.expiresAtMs || 0) > nowMs)
    .sort((left, right) => String(left.displayName || '').localeCompare(String(right.displayName || ''), 'en', { sensitivity: 'base' }))
    .map((entry) => String(entry.displayName || '').trim())
    .filter(Boolean);
});

const participantsByUserId = computed(() => {
  const rows = new Map();
  for (const row of connectedParticipantUsers.value) {
    rows.set(row.userId, row);
  }
  return rows;
});

const lobbyEntryByUserId = computed(() => {
  const rows = new Map();
  for (const row of lobbyQueue.value) {
    rows.set(row.user_id, { ...row, status: 'queued' });
  }
  return rows;
});

function rowActionKey(action, userId) {
  return `${action}:${Number(userId)}`;
}

const {
  clearModerationSyncTimer,
  consumeQueuedModerationSyncEntries,
  flushQueuedModerationSync,
  queueModerationSync,
  syncModerationStateToPeers,
  syncModerationStateToPeersWithPayload,
} = createCallWorkspaceModerationSync({
  activeRoomId,
  connectedParticipantUsers,
  currentUserId,
  isSocketOnline,
  mutedUsers,
  sendSocketFrame,
  rowActionKey,
  MODERATION_SYNC_FLUSH_INTERVAL_MS,
});

function setRowAction(store, action, userId, text = '', pending = false) {
  store[rowActionKey(action, userId)] = {
    text: String(text || '').trim(),
    pending: Boolean(pending),
    updatedAt: Date.now(),
  };
}

function clearRowAction(store, action, userId) {
  delete store[rowActionKey(action, userId)];
}

function rowActionPending(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  for (const action of ['mute', 'pin', 'role', 'owner']) {
    const entry = moderationActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  for (const action of ['allow', 'remove']) {
    const entry = lobbyActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  return false;
}

function rowActionFeedback(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return '';
  const actions = [
    moderationActionState[rowActionKey('mute', normalizedUserId)],
    moderationActionState[rowActionKey('pin', normalizedUserId)],
    moderationActionState[rowActionKey('role', normalizedUserId)],
    moderationActionState[rowActionKey('owner', normalizedUserId)],
    lobbyActionState[rowActionKey('allow', normalizedUserId)],
    lobbyActionState[rowActionKey('remove', normalizedUserId)],
  ];
  const active = actions.find((entry) => entry && (entry.pending || String(entry.text || '').trim() !== ''));
  return active ? String(active.text || '').trim() : '';
}

function lobbyActionPending(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  for (const action of ['allow', 'remove']) {
    const entry = lobbyActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  return false;
}

function userRowSnapshot(row) {
  const participant = participantsByUserId.value.get(row.userId) || null;
  const lobbyEntry = lobbyEntryByUserId.value.get(row.userId) || null;
  const feedback = rowActionFeedback(row.userId);
  const isRoomMember = Boolean(participant);
  const mappedCallRole = normalizeCallRole(
    row.userId === currentUserId.value
      ? viewerEffectiveCallRole.value
      : (callParticipantRoles[row.userId] || participant?.callRole || row.callRole || 'participant')
  );
  const peerState = peerControlStateByUserId[row.userId] || {};
  return {
    ...row,
    callRole: mappedCallRole,
    isRoomMember,
    roomConnectionCount: Number(participant?.connections || 0),
    inLobby: Boolean(lobbyEntry),
    lobbyStatus: lobbyEntry ? String(lobbyEntry.status || 'queued') : '',
    canRemoveFromLobby: Boolean(lobbyEntry) && canModerate.value,
    canAllowFromLobby: Boolean(lobbyEntry && lobbyEntry.status === 'queued' && canModerate.value),
    feedback,
    controlBadge: describePeerControlState(row.userId),
    peerState,
  };
}

function lobbyRowSnapshot(row) {
  const feedback = rowActionFeedback(row.user_id);
  return {
    ...row,
    feedback,
  };
}

function peerControlSnapshot(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
    return {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  if (normalizedUserId === currentUserId.value) {
    return {
      handRaised: controlState.handRaised,
      cameraEnabled: controlState.cameraEnabled,
      micEnabled: controlState.micEnabled,
      screenEnabled: controlState.screenEnabled,
    };
  }

  if (!peerControlStateByUserId[normalizedUserId] || typeof peerControlStateByUserId[normalizedUserId] !== 'object') {
    peerControlStateByUserId[normalizedUserId] = {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  return peerControlStateByUserId[normalizedUserId];
}

function describePeerControlState(userId) {
  const state = peerControlSnapshot(userId);
  const badges = [];
  if (state.handRaised) badges.push('hand');
  if (!state.micEnabled) badges.push('mic off');
  if (!state.cameraEnabled) badges.push('cam off');
  if (state.screenEnabled) badges.push('screen');
  return badges.join(' · ');
}

function setNotice(message, kind = 'ok') {
  workspaceNotice.value = String(message || '').trim();
  if (kind === 'error') {
    workspaceError.value = workspaceNotice.value;
    workspaceNotice.value = '';
  } else {
    workspaceError.value = '';
  }
}

function clearTransientActivityPublishErrorNotice() {
  const currentError = String(workspaceError.value || '').trim();
  if (!/Could not publish participant activity/i.test(currentError)) return;
  if (!/database (is locked|table is locked|schema is locked|busy)/i.test(currentError)) return;
  workspaceError.value = '';
}

function setAdmissionGate(roomId, message = '') {
  const normalizedRoomId = normalizeOptionalRoomId(roomId);
  if (normalizedRoomId === '') return;
  admissionGateState.roomId = normalizedRoomId;
  admissionGateState.message = String(message || '').trim();
}

function clearAdmissionGate(roomId = '') {
  const normalizedRoomId = normalizeOptionalRoomId(roomId);
  if (normalizedRoomId !== '' && admissionGateState.roomId !== normalizedRoomId) {
    return;
  }
  admissionGateState.roomId = '';
  admissionGateState.message = '';
}

function shouldShowWorkspaceAdmissionNotice() {
  return false;
}

function normalizeSignalCommandType(type) {
  return String(type || '').trim().toLowerCase();
}

function isCallSignalType(type) {
  const normalized = normalizeSignalCommandType(type);
  return normalized === 'call/offer'
    || normalized === 'call/answer'
    || normalized === 'call/ice'
    || normalized === 'call/hangup'
    || CALL_STATE_SIGNAL_TYPES.includes(normalized)
    || MEDIA_SECURITY_SIGNAL_TYPES.includes(normalized);
}

function shouldSuppressCallAckNotice(signalType) {
  const normalizedType = normalizeSignalCommandType(signalType);
  if (MEDIA_SECURITY_SIGNAL_TYPES.includes(normalizedType)) return true;
  const normalized = normalizedType.replace('call/', '');
  return normalized === 'offer'
    || normalized === 'answer'
    || normalized === 'ice'
    || normalized === 'hangup'
    || normalized === 'control-state'
    || normalized === 'media-quality-pressure'
    || normalized === 'moderation-state';
}

function shouldSuppressExpectedSignalingError(payload) {
  const code = String(payload?.code || '').trim().toLowerCase();
  if (code !== 'signaling_publish_failed') return false;

  const details = payload && typeof payload.details === 'object' ? payload.details : {};
  const commandType = normalizeSignalCommandType(details?.type);
  if (!isCallSignalType(commandType)) return false;

  const signalingError = String(details?.error || '').trim().toLowerCase();
  return signalingError === 'target_not_in_room'
    || signalingError === 'target_delivery_failed'
    || signalingError === 'sender_not_in_room';
}

function clearErrors() {
  workspaceError.value = '';
}

function pruneLocalReactionEchoes(nowMs = Date.now()) {
  localReactionEchoes.value = localReactionEchoes.value.filter(
    (entry) => Number(entry?.expiresAtMs || 0) > nowMs
  );
}

function trackLocalReactionEcho(emoji) {
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return;
  const nowMs = Date.now();
  pruneLocalReactionEchoes(nowMs);
  localReactionEchoes.value = [
    ...localReactionEchoes.value,
    {
      emoji: normalizedEmoji,
      expiresAtMs: nowMs + LOCAL_REACTION_ECHO_TTL_MS,
    },
  ];
}

function consumeLocalReactionEcho(emoji, senderUserId) {
  if (senderUserId !== currentUserId.value) return false;
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return false;

  const nowMs = Date.now();
  pruneLocalReactionEchoes(nowMs);
  const index = localReactionEchoes.value.findIndex(
    (entry) => String(entry?.emoji || '') === normalizedEmoji
  );
  if (index < 0) return false;

  localReactionEchoes.value = localReactionEchoes.value.filter((_, rowIndex) => rowIndex !== index);
  return true;
}

function pushReaction(emoji) {
  const random = (min, max) => min + Math.random() * (max - min);
  const edgePaddingPx = 20;
  const reactionFontPx = 24;
  const reactionScaleMin = 0.85;
  const reactionScaleMax = 1.15;
  const reactionMaxWidthPx = Math.ceil(reactionFontPx * reactionScaleMax) + 8;
  const viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 720;
  const mainVideoHeight = typeof document !== 'undefined'
    ? Number(document.querySelector('.workspace-main-video')?.clientHeight || 0)
    : 0;
  const reactionLayer = typeof document !== 'undefined'
    ? document.querySelector('.workspace-reaction-flight')
    : null;
  const reactionLayerWidth = Number(reactionLayer?.clientWidth || 0);
  const reactionLayerHeight = Number(reactionLayer?.clientHeight || 0);
  const layerWidth = Math.max(reactionLayerWidth, 320);
  const layerHeight = Math.max(reactionLayerHeight, 220);
  const travelBase = Math.max(viewportHeight * 0.75, mainVideoHeight * 0.75, 280);
  const baseBottom = Math.round(random(24, 40));
  const maxTravelByTopPadding = Math.max(80, layerHeight - edgePaddingPx - baseBottom);
  const maxWaveWanted = random(14, 30);
  const leftThirdMax = Math.max(edgePaddingPx, (layerWidth / 3) - edgePaddingPx - reactionMaxWidthPx);
  let startMin = edgePaddingPx + maxWaveWanted;
  let startMax = leftThirdMax - maxWaveWanted;
  if (startMax < startMin) {
    startMin = edgePaddingPx;
    startMax = Math.max(startMin, leftThirdMax);
  }

  const startXPx = random(startMin, startMax);
  const maxWaveByBounds = Math.max(0, Math.min(
    maxWaveWanted,
    startXPx - edgePaddingPx,
    leftThirdMax - startXPx
  ));

  reactionId += 1;
  const id = `rx_${reactionId}`;
  const entry = {
    id,
    emoji,
    startXPx: Math.round(startXPx),
    delay: Math.round(Math.random() * 140),
    duration: Math.round(random(2300, 2850)),
    travelY: Math.round(Math.min(random(travelBase * 0.94, travelBase * 1.06), maxTravelByTopPadding)),
    wave: Number(maxWaveByBounds.toFixed(3)),
    phase: Math.round(random(0, 360)),
    baseBottom,
    scale: random(reactionScaleMin, reactionScaleMax).toFixed(3),
  };
  activeReactions.value = [...activeReactions.value, entry];
  window.setTimeout(() => {
    activeReactions.value = activeReactions.value.filter((row) => row.id !== id);
  }, entry.duration + entry.delay + 120);
}

function clearReactionQueueTimer() {
  if (reactionQueueTimer === null) return;
  clearTimeout(reactionQueueTimer);
  reactionQueueTimer = null;
}

function scheduleReactionQueueFlush() {
  if (reactionQueueTimer !== null) return;
  reactionQueueTimer = setTimeout(() => {
    reactionQueueTimer = null;
    flushQueuedReactions();
  }, REACTION_CLIENT_FLUSH_INTERVAL_MS);
}

function resetReactionSendWindow(nowMs) {
  reactionWindowStartedMs = nowMs;
  reactionSentInWindow = 0;
}

function refreshReactionSendWindow(nowMs) {
  if (reactionWindowStartedMs <= 0) {
    resetReactionSendWindow(nowMs);
    return;
  }
  if ((nowMs - reactionWindowStartedMs) >= REACTION_CLIENT_WINDOW_MS) {
    resetReactionSendWindow(nowMs);
  }
}

function enqueueReactionEmoji(emoji) {
  const nextQueue = [...queuedReactionEmojis.value, emoji];
  if (nextQueue.length > REACTION_CLIENT_MAX_QUEUE) {
    nextQueue.splice(0, nextQueue.length - REACTION_CLIENT_MAX_QUEUE);
  }
  queuedReactionEmojis.value = nextQueue;
}

function sendQueuedReactionFrame(emoji) {
  const clientReactionId = `rx_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  return sendSocketFrame({
    type: 'reaction/send',
    emoji,
    client_reaction_id: clientReactionId,
  });
}

function sendQueuedReactionBatchFrame(emojis) {
  reactionBatchCounter += 1;
  const clientBatchId = `rxb_${Date.now()}_${reactionBatchCounter}`;
  return sendSocketFrame({
    type: 'reaction/send_batch',
    emojis,
    client_reaction_id: clientBatchId,
  });
}

function flushQueuedReactions() {
  clearReactionQueueTimer();
  if (!isSocketOnline.value) return;

  let safety = 0;
  while (queuedReactionEmojis.value.length > 0 && safety < 512) {
    safety += 1;
    const nowMs = Date.now();
    refreshReactionSendWindow(nowMs);

    if (reactionSentInWindow < REACTION_CLIENT_DIRECT_PER_WINDOW) {
      const emoji = String(queuedReactionEmojis.value[0] || '').trim();
      if (emoji === '') {
        queuedReactionEmojis.value = queuedReactionEmojis.value.slice(1);
        continue;
      }

      const sent = sendQueuedReactionFrame(emoji);
      if (!sent) {
        scheduleReactionQueueFlush();
        return;
      }

      queuedReactionEmojis.value = queuedReactionEmojis.value.slice(1);
      reactionSentInWindow += 1;
      continue;
    }

    const batchCount = Math.min(REACTION_CLIENT_BATCH_SIZE, queuedReactionEmojis.value.length);
    if (batchCount <= 0) break;
    const batch = queuedReactionEmojis.value.slice(0, batchCount);
    const sentBatch = sendQueuedReactionBatchFrame(batch);
    if (!sentBatch) {
      scheduleReactionQueueFlush();
      return;
    }

    queuedReactionEmojis.value = queuedReactionEmojis.value.slice(batchCount);
  }

  if (queuedReactionEmojis.value.length > 0) {
    scheduleReactionQueueFlush();
  }
}

function normalizeDirectoryUser(raw) {
  const userId = Number(raw?.id || 0);
  return {
    userId: Number.isInteger(userId) && userId > 0 ? userId : 0,
    displayName: String(raw?.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(raw?.role),
    status: String(raw?.status || '').trim() || 'unknown',
    email: String(raw?.email || '').trim(),
    timeFormat: String(raw?.time_format || '24h').trim() || '24h',
    theme: String(raw?.theme || 'dark').trim() || 'dark',
    avatarPath: typeof raw?.avatar_path === 'string' && raw.avatar_path.trim() !== '' ? raw.avatar_path.trim() : null,
    createdAt: String(raw?.created_at || ''),
    updatedAt: String(raw?.updated_at || ''),
  };
}

function markUserActionText(userId, action, text, pending = false) {
  setRowAction(moderationActionState, action, userId, text, pending);
}

function markLobbyActionText(userId, action, text, pending = false) {
  setRowAction(lobbyActionState, action, userId, text, pending);
}

function clearLobbyActionText(userId, action) {
  clearRowAction(lobbyActionState, action, userId);
}

function allowLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  markLobbyActionText(normalizedUserId, 'allow', 'Allowing user…', true);
  if (!sendSocketFrame({ type: 'lobby/allow', target_user_id: normalizedUserId })) {
    clearLobbyActionText(normalizedUserId, 'allow');
    setNotice('Could not allow user while websocket is offline.', 'error');
  }
}

function removeLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  markLobbyActionText(normalizedUserId, 'remove', 'Removing user…', true);
  if (!sendSocketFrame({ type: 'lobby/remove', target_user_id: normalizedUserId })) {
    clearLobbyActionText(normalizedUserId, 'remove');
    setNotice('Could not remove user while websocket is offline.', 'error');
  }
}

function allowAllLobbyUsers() {
  if (!canModerate.value) return;
  if (!sendSocketFrame({ type: 'lobby/allow_all' })) {
    setNotice('Could not allow all while websocket is offline.', 'error');
  }
}

function updatePeerControlState(userId, patch) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  if (normalizedUserId === currentUserId.value) return;

  if (!peerControlStateByUserId[normalizedUserId] || typeof peerControlStateByUserId[normalizedUserId] !== 'object') {
    peerControlStateByUserId[normalizedUserId] = {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  peerControlStateByUserId[normalizedUserId] = {
    ...peerControlStateByUserId[normalizedUserId],
    ...patch,
  };
}

function resetPeerControlState(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
  peerControlStateByUserId[normalizedUserId] = {
    handRaised: false,
    cameraEnabled: true,
    micEnabled: true,
    screenEnabled: false,
  };
}

function applyReactionEvent(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  if (roomId !== activeRoomId.value) return;
  const senderUserId = Number(payload?.sender?.user_id || 0);
  if (Number.isInteger(senderUserId) && senderUserId > 0) {
    markParticipantActivity(senderUserId, 'reaction');
  }

  const reaction = payload && typeof payload.reaction === 'object' ? payload.reaction : null;
  if (reaction && typeof reaction === 'object') {
    const emoji = String(reaction.emoji || '').trim();
    if (emoji !== '') {
      if (consumeLocalReactionEcho(emoji, senderUserId)) {
        return;
      }
      pushReaction(emoji);
    }
  }

  const reactions = Array.isArray(payload?.reactions) ? payload.reactions : [];
  for (const row of reactions) {
    const emoji = String(row?.emoji || '').trim();
    if (emoji === '') continue;
    if (consumeLocalReactionEcho(emoji, senderUserId)) continue;
    pushReaction(emoji);
  }
}

function applyRemoteControlState(payload, sender) {
  const senderUserId = Number(sender?.user_id || 0);
  if (!Number.isInteger(senderUserId) || senderUserId <= 0) return false;
  markParticipantActivity(senderUserId, 'control');

  const kind = String(payload?.kind || '').trim().toLowerCase();
  if (kind === 'workspace-control-state') {
    const state = payload && typeof payload.state === 'object' ? payload.state : {};
    const nextScreenEnabled = Boolean(state.screenEnabled);
    updatePeerControlState(senderUserId, {
      handRaised: Boolean(state.handRaised),
      cameraEnabled: state.cameraEnabled !== false,
      micEnabled: state.micEnabled !== false,
      screenEnabled: nextScreenEnabled,
    });
    if (nextScreenEnabled) {
      pinScreenShareParticipant(screenShareUserIdForOwner(senderUserId));
    } else {
      forgetScreenShareAutoPin(screenShareUserIdForOwner(senderUserId), true);
    }
    refreshUsersDirectoryPresentation();
    return true;
  }

  if (kind === 'workspace-moderation-state') {
    const moderatedUsers = payload && typeof payload.moderated_users === 'object' ? payload.moderated_users : {};
    for (const [key, value] of Object.entries(moderatedUsers)) {
      const match = /^([a-z]+):([0-9]+)$/.exec(key);
      if (!match) continue;
      const action = String(match[1] || '');
      const subjectUserId = Number(match[2] || 0);
      if (!Number.isInteger(subjectUserId) || subjectUserId <= 0) continue;

      if (action === 'pin') {
        continue;
      }
      if (action === 'mute') {
        const nextMuted = Boolean(value?.muted);
        if (nextMuted) {
          mutedUsers[subjectUserId] = true;
        } else {
          delete mutedUsers[subjectUserId];
        }
        markUserActionText(subjectUserId, 'mute', mutedUsers[subjectUserId] ? 'Muted' : 'Unmuted', false);
      }
    }
    refreshUsersDirectoryPresentation();
    return true;
  }

  return false;
}

function syncControlStateToPeers() {
  const peerIds = connectedParticipantUsers.value
    .map((row) => row.userId)
    .filter((userId) => (
      Number.isInteger(userId)
      && userId > 0
      && userId !== currentUserId.value
      && !isScreenShareUserId(userId)
    ));

  let sentCount = 0;
  for (const targetUserId of peerIds) {
    const sent = sendSocketFrame({
      type: 'call/control-state',
      target_user_id: targetUserId,
      payload: {
        kind: 'workspace-control-state',
        actor_user_id: currentUserId.value,
        room_id: activeRoomId.value,
        state: {
          handRaised: controlState.handRaised,
          cameraEnabled: controlState.cameraEnabled,
          micEnabled: controlState.micEnabled,
          screenEnabled: controlState.screenEnabled,
        },
      },
    });
    if (sent) sentCount += 1;
  }

  return sentCount;
}

function emitReaction(emoji) {
  if (typeof emoji !== 'string') return;
  const normalizedEmoji = emoji.trim();
  if (normalizedEmoji === '') return;
  markParticipantActivity(currentUserId.value, 'reaction');
  pushReaction(normalizedEmoji);
  trackLocalReactionEcho(normalizedEmoji);
  enqueueReactionEmoji(normalizedEmoji);
  flushQueuedReactions();
}

function toggleUserMuted(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
  const nextMuted = mutedUsers[normalizedUserId] !== true;
  if (nextMuted) {
    mutedUsers[normalizedUserId] = true;
  } else {
    delete mutedUsers[normalizedUserId];
  }
  markUserActionText(normalizedUserId, 'mute', nextMuted ? 'Muted' : 'Unmuted', false);
  refreshUsersDirectoryPresentation();
  queueModerationSync('mute', normalizedUserId);
}

function togglePinned(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  const nextPinned = pinnedUsers[normalizedUserId] !== true;
  for (const key of Object.keys(pinnedUsers)) {
    delete pinnedUsers[key];
  }
  if (nextPinned) {
    pinnedUsers[normalizedUserId] = true;
  }
  markUserActionText(normalizedUserId, 'pin', nextPinned ? 'Pinned' : 'Unpinned', false);
  refreshUsersDirectoryPresentation();
  nextTick(() => renderCallVideoLayout());
}

function currentPinnedUserIds() {
  return Object.entries(pinnedUsers)
    .filter(([, pinned]) => pinned === true)
    .map(([id]) => Number(id))
    .filter((id) => Number.isInteger(id) && id > 0);
}

function sendLayoutCommand(type, payload = {}) {
  if (!canModerate.value || !isSocketOnline.value) return false;
  return sendSocketFrame({
    type,
    ...payload,
  });
}

const { closeVideoFullscreen, toggleVideoFullscreen } = createVideoFullscreenToggle({
  callLayoutState,
  fullscreenVideoUserId,
  nextTick,
  renderCallVideoLayout,
});

function setCallLayoutMode(mode) {
  const normalizedMode = normalizeCallLayoutMode(mode, currentLayoutMode.value);
  callLayoutState.mode = normalizedMode;
  if (!sendLayoutCommand('layout/mode', { mode: normalizedMode })) {
    setNotice('Could not update layout mode while websocket is offline.', 'error');
  }
  syncCallLayoutSidebarControls();
  nextTick(() => renderCallVideoLayout());
}

function setCallLayoutStrategy(strategy) {
  const normalizedStrategy = String(strategy || '').trim().toLowerCase();
  if (!CALL_LAYOUT_STRATEGIES.includes(normalizedStrategy)) return;
  callLayoutState.strategy = normalizedStrategy;
  callLayoutState.automation_paused = false;
  if (!sendLayoutCommand('layout/strategy', {
    strategy: normalizedStrategy,
    automation_paused: false,
  })) {
    setNotice('Could not update layout strategy while websocket is offline.', 'error');
  }
  syncCallLayoutSidebarControls();
  nextTick(() => renderCallVideoLayout());
}

function publishLayoutSelectionState() {
  if (!canModerate.value) return false;
  const pinnedIds = currentPinnedUserIds();
  replaceNumericArray(callLayoutState.pinned_user_ids, pinnedIds);
  callLayoutState.main_user_id = primaryVideoUserId.value;
  return sendLayoutCommand('layout/selection', {
    pinned_user_ids: pinnedIds,
    selected_user_ids: layoutSelection.value.visibleUserIds,
    main_user_id: primaryVideoUserId.value,
  });
}

function toggleHandRaised() {
  controlState.handRaised = !controlState.handRaised;
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
  publishLocalActivitySample(true);
}

function toggleCamera() {
  controlState.cameraEnabled = !controlState.cameraEnabled;
  void reconfigureLocalTracksFromSelectedDevices();
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
  publishLocalActivitySample(true);
}

function toggleMicrophone() {
  controlState.micEnabled = !controlState.micEnabled;
  void reconfigureLocalTracksFromSelectedDevices();
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
  publishLocalActivitySample(true);
}

async function toggleScreenShare() {
  const nextScreenEnabled = controlState.screenEnabled !== true;
  const localScreenShareUserId = screenShareUserIdForOwner(currentUserId.value);
  if (typeof setLocalScreenShareEnabled !== 'function') {
    controlState.screenEnabled = nextScreenEnabled;
    if (controlState.screenEnabled) {
      pinScreenShareParticipant(localScreenShareUserId);
    } else {
      forgetScreenShareAutoPin(localScreenShareUserId, true);
    }
    refreshUsersDirectoryPresentation();
    void syncControlStateToPeers();
    return;
  }

  try {
    const screenShareActive = await setLocalScreenShareEnabled(nextScreenEnabled);
    if (nextScreenEnabled && screenShareActive === true) {
      pinScreenShareParticipant(localScreenShareUserId);
    } else if (!nextScreenEnabled) {
      forgetScreenShareAutoPin(localScreenShareUserId, true);
    }
  } catch (error) {
    controlState.screenEnabled = false;
    refreshUsersDirectoryPresentation();
    void syncControlStateToPeers();
    setNotice(error?.message || 'Screen sharing failed.', 'error');
  }
}

async function refreshUsersDirectory() {
  if (usersSourceMode.value !== 'directory') return;
  if (usersDirectoryLoading.value) return;

  const directoryQuery = parseUsersDirectoryQuery(usersSearch.value);
  usersDirectoryLoading.value = true;
  try {
    const payload = await apiRequest('/api/admin/users', {
      query: {
        query: directoryQuery.query,
        status: directoryQuery.status,
        page: usersPage.value,
        page_size: USERS_PAGE_SIZE,
        order: directoryQuery.order,
      },
    });

    const rows = Array.isArray(payload?.users) ? payload.users : [];
    usersDirectoryRows.value = rows.map(normalizeDirectoryUser).map(userRowSnapshot);

    const paging = payload?.pagination || {};
    usersPage.value = Number.isInteger(paging.page) ? paging.page : usersPage.value;
    usersDirectoryPagination.page = usersPage.value;
    usersDirectoryPagination.pageSize = Number.isInteger(paging.page_size) ? paging.page_size : USERS_PAGE_SIZE;
    usersDirectoryPagination.total = Number.isInteger(paging.total) ? paging.total : rows.length;
    usersDirectoryPagination.pageCount = Number.isInteger(paging.page_count) && paging.page_count > 0 ? paging.page_count : 1;
    usersDirectoryPagination.hasPrev = Boolean(paging.has_prev);
    usersDirectoryPagination.hasNext = Boolean(paging.has_next);
    usersDirectoryPagination.returned = Number.isInteger(paging.returned) ? paging.returned : rows.length;
    usersDirectoryPagination.query = String(paging.query || directoryQuery.query || '').trim();
    usersDirectoryPagination.status = normalizeUsersDirectoryStatus(paging.status || directoryQuery.status);
    usersDirectoryPagination.order = normalizeUsersDirectoryOrder(paging.order || directoryQuery.order);
    usersDirectoryPagination.error = '';
  } catch (error) {
    usersDirectoryPagination.error = error instanceof Error ? error.message : 'Could not load user directory.';
    usersDirectoryRows.value = [];
  } finally {
    usersDirectoryLoading.value = false;
  }
}

function refreshUsersDirectoryPresentation() {
  if (usersSourceMode.value !== 'directory' || usersDirectoryRows.value.length === 0) return;
  usersDirectoryRows.value = usersDirectoryRows.value.map((row) => userRowSnapshot(row));
}

function scheduleUsersRefresh() {
  if (usersRefreshTimer.value !== null) {
    clearTimeout(usersRefreshTimer.value);
    usersRefreshTimer.value = null;
  }
  usersRefreshTimer.value = window.setTimeout(() => {
    usersRefreshTimer.value = null;
    void refreshUsersDirectory();
  }, 220);
}

function onUsersSearchInput() {
  usersPage.value = 1;
  if (usersSourceMode.value === 'directory') {
    scheduleUsersRefresh();
  }
}

function goToUsersPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1) return;
  if (normalizedPage === usersPage.value) return;
  usersPage.value = normalizedPage;
  if (usersSourceMode.value === 'directory') {
    void refreshUsersDirectory();
  }
}

function goToLobbyPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1) return;
  if (normalizedPage === lobbyPage.value) return;
  lobbyPage.value = normalizedPage;
}

function syncUsersListViewport() {
  updateListViewportMetrics(usersListRef.value, usersListViewport);
}

function syncLobbyListViewport() {
  updateListViewportMetrics(lobbyListRef.value, lobbyListViewport);
}

function resetUsersListScroll() {
  if (usersListRef.value instanceof HTMLElement) {
    usersListRef.value.scrollTop = 0;
  }
  usersListViewport.scrollTop = 0;
  syncUsersListViewport();
}

function resetLobbyListScroll() {
  if (lobbyListRef.value instanceof HTMLElement) {
    lobbyListRef.value.scrollTop = 0;
  }
  lobbyListViewport.scrollTop = 0;
  syncLobbyListViewport();
}

function onUsersListScroll(event) {
  updateListViewportMetrics(event?.target, usersListViewport);
}

function onLobbyListScroll(event) {
  updateListViewportMetrics(event?.target, lobbyListViewport);
}

function clearAloneIdleWatchTimer() {
  if (aloneIdleWatchTimer !== null) {
    clearInterval(aloneIdleWatchTimer);
    aloneIdleWatchTimer = null;
  }
}

function clearAloneIdleCountdownTimer() {
  if (aloneIdleCountdownTimer !== null) {
    clearInterval(aloneIdleCountdownTimer);
    aloneIdleCountdownTimer = null;
  }
}

function hideAloneIdlePrompt() {
  clearAloneIdleCountdownTimer();
  aloneIdlePrompt.visible = false;
  aloneIdlePrompt.deadlineMs = 0;
  aloneIdlePrompt.remainingMs = ALONE_IDLE_COUNTDOWN_MS;
}

function markAloneIdleActivity() {
  if (!isAloneInCall.value) return;
  if (aloneIdlePrompt.visible) return;
  aloneIdleLastActiveMs = Date.now();
}

function updateAloneIdleCountdown() {
  const deadlineMs = Number(aloneIdlePrompt.deadlineMs || 0);
  if (!aloneIdlePrompt.visible || deadlineMs <= 0) {
    hideAloneIdlePrompt();
    return;
  }

  const remainingMs = Math.max(0, deadlineMs - Date.now());
  aloneIdlePrompt.remainingMs = remainingMs;
  if (remainingMs > 0) return;

  hideAloneIdlePrompt();
  setNotice('Ending call due to inactivity while alone.');
  hangupCall();
}

function showAloneIdlePrompt() {
  if (!isAloneInCall.value || aloneIdlePrompt.visible) return;
  aloneIdlePrompt.visible = true;
  aloneIdlePrompt.deadlineMs = Date.now() + ALONE_IDLE_COUNTDOWN_MS;
  aloneIdlePrompt.remainingMs = ALONE_IDLE_COUNTDOWN_MS;
  clearAloneIdleCountdownTimer();
  aloneIdleCountdownTimer = setInterval(() => {
    updateAloneIdleCountdown();
  }, ALONE_IDLE_TICK_MS);
}

function evaluateAloneIdlePrompt() {
  if (!isAloneInCall.value) {
    hideAloneIdlePrompt();
    aloneIdleLastActiveMs = Date.now();
    return;
  }
  if (aloneIdlePrompt.visible) return;

  const idleMs = Math.max(0, Date.now() - aloneIdleLastActiveMs);
  if (idleMs >= ALONE_IDLE_PROMPT_AFTER_MS) {
    showAloneIdlePrompt();
  }
}

function ensureAloneIdleWatchTimer() {
  if (aloneIdleWatchTimer !== null) return;
  aloneIdleWatchTimer = setInterval(() => {
    evaluateAloneIdlePrompt();
  }, ALONE_IDLE_POLL_MS);
}

function confirmStillInCall() {
  aloneIdleLastActiveMs = Date.now();
  hideAloneIdlePrompt();
}

function handleAloneIdleActivityEvent() {
  markAloneIdleActivity();
}

function attachAloneIdleActivityListeners() {
  if (typeof window === 'undefined') return;
  for (const eventName of ALONE_IDLE_ACTIVITY_EVENTS) {
    window.addEventListener(eventName, handleAloneIdleActivityEvent, { passive: true });
  }
}

function detachAloneIdleActivityListeners() {
  if (typeof window === 'undefined') return;
  for (const eventName of ALONE_IDLE_ACTIVITY_EVENTS) {
    window.removeEventListener(eventName, handleAloneIdleActivityEvent);
  }
}

function clearLobbyToastTimer() {
  if (lobbyToastTimer !== null) {
    clearTimeout(lobbyToastTimer);
    lobbyToastTimer = null;
  }
}

function hideLobbyJoinToast() {
  clearLobbyToastTimer();
  lobbyNotificationState.toastVisible = false;
  lobbyNotificationState.toastMessage = '';
}

function buildLobbyJoinToastMessage(entries) {
  const list = Array.isArray(entries) ? entries : [];
  const labels = list
    .map((entry) => String(entry?.display_name || '').trim())
    .filter((value) => value !== '');
  if (labels.length <= 0) return 'A user requested to join.';
  if (labels.length === 1) return `${labels[0]} requested to join.`;
  if (labels.length === 2) return `${labels[0]} and ${labels[1]} requested to join.`;
  return `${labels[0]} and ${labels.length - 1} more requested to join.`;
}

function notifyLobbyJoinRequests(entries) {
  if (!canModerate.value) return;
  const list = Array.isArray(entries) ? entries : [];
  if (list.length <= 0) return;
  lobbyNotificationState.toastMessage = buildLobbyJoinToastMessage(list);
  lobbyNotificationState.toastVisible = true;
  clearLobbyToastTimer();
  lobbyToastTimer = setTimeout(() => {
    lobbyToastTimer = null;
    lobbyNotificationState.toastVisible = false;
  }, 7500);
}

function openLobbyRequestsPanel() {
  if (!showLobbyTab.value) return;
  showRightSidebar();
  setActiveTab('lobby');
  hideLobbyJoinToast();
}

function clearChatUnread(roomId = activeRoomId.value) {
  const normalizedRoomId = normalizeRoomId(roomId || activeRoomId.value);
  if (normalizedRoomId === '') return;
  delete chatUnreadByRoom[normalizedRoomId];
}

function markChatUnread(message) {
  const roomId = normalizeRoomId(message?.room_id || activeRoomId.value);
  if (roomId === '') return;
  const senderUserId = Number(message?.sender?.user_id || 0);
  if (Number.isInteger(senderUserId) && senderUserId > 0 && senderUserId === currentUserId.value) {
    return;
  }
  if (roomId === activeRoomId.value && activeTab.value === 'chat' && !rightSidebarCollapsed.value) {
    return;
  }
  chatUnreadByRoom[roomId] = true;
}

function openChatPanel() {
  showRightSidebar();
  setActiveTab('chat');
}

function setActiveTab(tab) {
  const requestedTab = ['users', 'lobby', 'chat'].includes(tab) ? tab : 'users';
  const nextTab = requestedTab === 'lobby' && !showLobbyTab.value ? 'users' : requestedTab;
  activeTab.value = nextTab;
  if (nextTab === 'users') {
    nextTick(() => syncUsersListViewport());
  } else if (nextTab === 'lobby') {
    hideLobbyJoinToast();
    nextTick(() => syncLobbyListViewport());
  } else if (nextTab === 'chat') {
    clearChatUnread();
  }
  if (nextTab !== 'chat') {
    chatEmojiTrayOpen.value = false;
  }
  if (isSocketOnline.value && (nextTab === 'users' || nextTab === 'lobby')) {
    requestRoomSnapshot();
  }
  if (nextTab === 'users' && usersSourceMode.value === 'directory') {
    void refreshUsersDirectory();
  }
}

function hideRightSidebar() {
  rightSidebarCollapsed.value = true;
  chatEmojiTrayOpen.value = false;
}

function showRightSidebar() {
  rightSidebarCollapsed.value = false;
  hideLobbyJoinToast();
  if (activeTab.value === 'chat') {
    clearChatUnread();
  }
}

function openLeftSidebarOverlay(event) {
  if (event && typeof event.stopPropagation === 'function') {
    event.stopPropagation();
  }
  const openFn = workspaceSidebarState && typeof workspaceSidebarState.showLeftSidebar === 'function'
    ? workspaceSidebarState.showLeftSidebar
    : null;
  if (!openFn) return;
  openFn();
}

  return {
    activeMessages,
    activityLabelForUser,
    allowAllLobbyUsers,
    allowLobbyUser,
    aloneIdleCountdownLabel,
    applyActivitySnapshot,
    applyCallLayoutPayload,
    applyParticipantActivityPayload,
    applyReactionEvent,
    applyRemoteControlState,
    attachAloneIdleActivityListeners,
    canSubmitChatMessage,
    clearAdmissionGate,
    clearAloneIdleCountdownTimer,
    clearAloneIdleWatchTimer,
    clearCallLayoutSidebarControls,
    clearChatUnread,
    clearErrors,
    clearLobbyToastTimer,
    clearLobbyActionText,
    clearModerationSyncTimer,
    clearReactionQueueTimer,
    clearTransientActivityPublishErrorNotice,
    compactMiniStripToggleLabel,
    confirmStillInCall,
    currentCallLayoutSidebarControls,
    currentLayoutMode,
    currentPinnedUserIds,
    describePeerControlState,
    detachAloneIdleActivityListeners,
    emitReaction,
    closeVideoFullscreen,
    ensureAloneIdleWatchTimer,
    evaluateAloneIdlePrompt,
    consumeQueuedModerationSyncEntries,
    filteredUsers,
    flushQueuedModerationSync,
    flushQueuedReactions,
    goToLobbyPage,
    goToUsersPage,
    gridVideoParticipants,
    hideAloneIdlePrompt,
    hideLobbyJoinToast,
    hideRightSidebar,
    isCallSignalType,
    layoutModeOptions,
    layoutSelection,
    layoutStrategyOptions,
    lobbyActionPending,
    lobbyEntryByUserId,
    lobbyJoinToastMessage,
    lobbyPageCount,
    lobbyPageRows,
    lobbyRequestBadgeText,
    lobbyRowSnapshot,
    lobbyRows,
    lobbyVisibleRows,
    lobbyVirtualWindow,
    markChatUnread,
    markParticipantActivity,
    miniVideoParticipants,
    normalizedCallLayout,
    notifyLobbyJoinRequests,
    onLobbyListScroll,
    onUsersListScroll,
    onUsersSearchInput,
    openChatPanel,
    openLeftSidebarOverlay,
    openLobbyRequestsPanel,
    participantActivityScore,
    participantMediaStatus,
    participantMediaStatusLabel,
    participantMediaStatusState,
    participantVisibilityScore,
    participantsByUserId,
    peerControlSnapshot,
    primaryVideoUserId,
    pruneParticipantActivity,
    publishLayoutSelectionState,
    refreshUsersDirectory,
    refreshUsersDirectoryPresentation,
    removeLobbyUser,
    resetLobbyListScroll,
    resetPeerControlState,
    resetUsersListScroll,
    rowActionFeedback,
    rowActionKey,
    rowActionPending,
    scheduleUsersRefresh,
    setActiveTab,
    setAdmissionGate,
    setCallLayoutMode,
    setCallLayoutStrategy,
    setNotice,
    shouldShowWorkspaceAdmissionNotice,
    shouldSuppressCallAckNotice,
    shouldSuppressExpectedSignalingError,
    showChatUnreadBadge,
    showChatUnreadToast,
    showCompactMiniStripToggle,
    showParticipantMediaOverlay,
    showLobbyJoinToast,
    showLobbyRequestBadge,
    showMiniParticipantStrip,
    showRightSidebar,
    snapshotUsersRows,
    stripParticipants,
    syncCallLayoutSidebarControls,
    syncControlStateToPeers,
    syncLobbyListViewport,
    syncModerationStateToPeers,
    syncModerationStateToPeersWithPayload,
    syncUsersListViewport,
    toggleCamera,
    toggleCompactMiniStripPlacement,
    toggleVideoFullscreen,
    toggleVideoFullscreenForEvent,
    toggleHandRaised,
    toggleMicrophone,
    togglePinned,
    toggleScreenShare,
    toggleUserMuted,
    typingUsers,
    updatePeerControlState,
    userRowSnapshot,
    usersPageCount,
    usersPageRows,
    usersVisibleRows,
    usersVirtualWindow,
  };
}
