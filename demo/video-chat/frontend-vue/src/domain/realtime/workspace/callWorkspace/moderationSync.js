export function createCallWorkspaceModerationSync({
  activeRoomId,
  connectedParticipantUsers,
  currentUserId,
  isSocketOnline,
  mutedUsers,
  pinnedUsers,
  sendSocketFrame,
  rowActionKey,
  MODERATION_SYNC_FLUSH_INTERVAL_MS,
}) {
  let moderationSyncTimer = null;
  const moderationSyncQueue = {};

  function clearModerationSyncTimer() {
    if (moderationSyncTimer === null) return;
    clearTimeout(moderationSyncTimer);
    moderationSyncTimer = null;
  }

  function consumeQueuedModerationSyncEntries() {
    const queuedEntries = Object.values(moderationSyncQueue);
    for (const key of Object.keys(moderationSyncQueue)) {
      delete moderationSyncQueue[key];
    }
    return queuedEntries;
  }

  function buildModerationStatePayloadFromQueue() {
    const moderatedState = {};
    const queuedEntries = consumeQueuedModerationSyncEntries();
    for (const entry of queuedEntries) {
      const action = String(entry?.action || '').trim().toLowerCase();
      const userId = Number(entry?.userId || 0);
      if (!Number.isInteger(userId) || userId <= 0) continue;
      if (action !== 'mute' && action !== 'pin') continue;

      const key = rowActionKey(action, userId);
      moderatedState[key] = {
        updatedAt: Number(entry?.updatedAt || Date.now()),
        pending: false,
        muted: action === 'mute' ? Boolean(entry?.state) : undefined,
        pinned: action === 'pin' ? Boolean(entry?.state) : undefined,
      };
    }
    return moderatedState;
  }

  function buildFullModerationStatePayload() {
    const moderatedState = {};

    for (const [rawUserId, muted] of Object.entries(mutedUsers)) {
      const userId = Number(rawUserId);
      if (!Number.isInteger(userId) || userId <= 0) continue;
      if (muted !== true) continue;
      moderatedState[rowActionKey('mute', userId)] = {
        updatedAt: Date.now(),
        pending: false,
        muted: true,
      };
    }

    for (const [rawUserId, pinned] of Object.entries(pinnedUsers)) {
      const userId = Number(rawUserId);
      if (!Number.isInteger(userId) || userId <= 0) continue;
      if (pinned !== true) continue;
      moderatedState[rowActionKey('pin', userId)] = {
        updatedAt: Date.now(),
        pending: false,
        pinned: true,
      };
    }

    return moderatedState;
  }

  function syncModerationStateToPeers() {
    return syncModerationStateToPeersWithPayload(buildFullModerationStatePayload());
  }

  function syncModerationStateToPeersWithPayload(moderatedUsers) {
    const normalizedPayload = moderatedUsers && typeof moderatedUsers === 'object' ? moderatedUsers : {};
    const payloadKeys = Object.keys(normalizedPayload);
    if (payloadKeys.length === 0) return 0;

    const peerIds = connectedParticipantUsers.value
      .map((row) => row.userId)
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);
    if (peerIds.length <= 0) return 0;

    let sentCount = 0;
    for (const targetUserId of peerIds) {
      const sent = sendSocketFrame({
        type: 'call/moderation-state',
        target_user_id: targetUserId,
        payload: {
          kind: 'workspace-moderation-state',
          actor_user_id: currentUserId.value,
          room_id: activeRoomId.value,
          moderated_users: normalizedPayload,
        },
      });
      if (sent) sentCount += 1;
    }

    return sentCount;
  }

  function flushQueuedModerationSync() {
    clearModerationSyncTimer();
    if (!isSocketOnline.value) {
      consumeQueuedModerationSyncEntries();
      return 0;
    }

    const moderatedUsers = buildModerationStatePayloadFromQueue();
    if (Object.keys(moderatedUsers).length === 0) return 0;
    return syncModerationStateToPeersWithPayload(moderatedUsers);
  }

  function queueModerationSync(action, userId) {
    const normalizedAction = String(action || '').trim().toLowerCase();
    const normalizedUserId = Number(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    if (normalizedAction !== 'mute' && normalizedAction !== 'pin') return;

    const nextState = normalizedAction === 'mute'
      ? (mutedUsers[normalizedUserId] === true)
      : (pinnedUsers[normalizedUserId] === true);

    const key = rowActionKey(normalizedAction, normalizedUserId);
    moderationSyncQueue[key] = {
      action: normalizedAction,
      userId: normalizedUserId,
      state: nextState,
      updatedAt: Date.now(),
    };

    if (moderationSyncTimer !== null) return;
    moderationSyncTimer = setTimeout(() => {
      moderationSyncTimer = null;
      flushQueuedModerationSync();
    }, MODERATION_SYNC_FLUSH_INTERVAL_MS);
  }

  return {
    clearModerationSyncTimer,
    consumeQueuedModerationSyncEntries,
    flushQueuedModerationSync,
    queueModerationSync,
    syncModerationStateToPeers,
    syncModerationStateToPeersWithPayload,
  };
}
