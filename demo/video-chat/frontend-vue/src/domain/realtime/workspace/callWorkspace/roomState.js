import { computed } from 'vue';

export function createCallWorkspaceRoomStateHelpers(context) {
  const {
    callbacks,
    refs,
    state,
  } = context;

  const {
    apiRequest,
    applyActivitySnapshot,
    applyCallLayoutPayload,
    clearAdmissionGate,
    hideLobbyJoinToast,
    mergeLiveMediaPeerIntoRoster,
    normalizeCallRole,
    normalizeLobbyEntry,
    normalizeParticipantRow,
    normalizeRole,
    normalizeRoomId,
    notifyLobbyJoinRequests,
    participantSnapshotSignature,
    pruneParticipantActivity,
    refreshUsersDirectoryPresentation,
    roleRank,
    callRoleRank,
    syncControlStateToPeers,
    syncMediaSecurityWithParticipants,
    syncModerationStateToPeers,
  } = callbacks;

  const {
    activeCallId,
    activeRoomId,
    callParticipantRoles,
    connectedParticipantUsersRef,
    currentUserConnectedAt,
    currentUserId,
    desiredRoomId,
    hasRealtimeRoomSync,
    isSocketOnline,
    loadedCallId,
    lobbyActionState,
    lobbyAdmitted,
    lobbyNotificationState,
    lobbyQueue,
    nativePeerConnectionsRef,
    participantsRaw,
    peerControlStateByUserId,
    pendingAdmissionJoinRoomId,
    remotePeersRef,
    serverRoomId,
    sessionState,
    viewerCallRole,
    viewerCanManageOwnerRole,
    viewerCanModerateCall,
    viewerEffectiveCallRole,
  } = refs;

  function ensureRoomBuckets(roomId) {
    const normalizedRoomId = normalizeRoomId(roomId);
    if (!Array.isArray(refs.chatByRoom[normalizedRoomId])) {
      refs.chatByRoom[normalizedRoomId] = [];
    }
    if (!refs.typingByRoom[normalizedRoomId] || typeof refs.typingByRoom[normalizedRoomId] !== 'object') {
      refs.typingByRoom[normalizedRoomId] = {};
    }
  }

  function applyParticipantsSnapshot(rows) {
    const nextRows = Array.isArray(rows) ? rows : [];
    const nextSignature = participantSnapshotSignature(nextRows);
    if (nextSignature === state.getParticipantsRawSignature()) {
      return false;
    }
    state.setParticipantsRawSignature(nextSignature);
    participantsRaw.value = nextRows;
    return true;
  }

  function removeParticipantFromSnapshot(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
      return false;
    }
    const nextRows = participantsRaw.value.filter((row) => {
      const normalized = normalizeParticipantRow(row);
      return normalized.userId !== normalizedUserId;
    });
    if (nextRows.length === participantsRaw.value.length) {
      return false;
    }
    return applyParticipantsSnapshot(nextRows);
  }

  function resetCallParticipantRoles() {
    for (const key of Object.keys(callParticipantRoles)) {
      delete callParticipantRoles[key];
    }
  }

  function currentUserParticipantRow() {
    const userId = currentUserId.value;
    if (!Number.isInteger(userId) || userId <= 0) return null;

    const displayName = String(sessionState.displayName || sessionState.email || '').trim() || 'You';
    const callRole = normalizeCallRole(viewerEffectiveCallRole.value || callParticipantRoles[userId] || viewerCallRole.value || 'participant');
    return {
      userId,
      displayName,
      role: normalizeRole(sessionState.role),
      callRole,
      connectedAt: currentUserConnectedAt,
      connections: 1,
    };
  }

  const participantUsers = computed(() => {
    const aggregate = new Map();

    for (const row of participantsRaw.value) {
      const normalized = normalizeParticipantRow(row);
      if (normalized.userId <= 0) continue;

      const existing = aggregate.get(normalized.userId);
      if (!existing) {
        aggregate.set(normalized.userId, {
          userId: normalized.userId,
          displayName: normalized.displayName,
          role: normalized.role,
          callRole: normalized.callRole,
          connectedAt: normalized.connectedAt,
          connections: normalized.hasConnection ? 1 : 0,
        });
        continue;
      }

      if (normalized.hasConnection) {
        existing.connections += 1;
      }
      if (roleRank(normalized.role) < roleRank(existing.role)) {
        existing.role = normalized.role;
      }
      if (normalized.displayName.length > existing.displayName.length) {
        existing.displayName = normalized.displayName;
      }
      if (callRoleRank(normalized.callRole) < callRoleRank(existing.callRole)) {
        existing.callRole = normalized.callRole;
      }
    }

    for (const peer of remotePeersRef.value.values()) {
      mergeLiveMediaPeerIntoRoster(aggregate, peer, {
        currentUserId: currentUserId.value,
        callParticipantRoles,
        source: 'sfu',
      });
    }

    for (const peer of nativePeerConnectionsRef.value.values()) {
      mergeLiveMediaPeerIntoRoster(aggregate, peer, {
        currentUserId: currentUserId.value,
        callParticipantRoles,
        source: 'native',
      });
    }

    const currentUser = currentUserParticipantRow();
    if (currentUser) {
      const existing = aggregate.get(currentUser.userId);
      if (existing) {
        existing.displayName = currentUser.displayName;
        existing.role = currentUser.role;
        existing.callRole = currentUser.callRole;
        existing.connections = Math.max(1, Number(existing.connections || 0));
      } else {
        aggregate.set(currentUser.userId, currentUser);
      }
    }

    return Array.from(aggregate.values()).sort((left, right) => {
      const roleCmp = roleRank(left.role) - roleRank(right.role);
      if (roleCmp !== 0) return roleCmp;
      const nameCmp = left.displayName.localeCompare(right.displayName, 'en', { sensitivity: 'base' });
      if (nameCmp !== 0) return nameCmp;
      return left.userId - right.userId;
    });
  });

  function hasConnectedParticipantEvidence(row) {
    if (Number(row?.connections || 0) > 0) return true;
    return String(row?.connectedAt || row?.connected_at || '').trim() !== '';
  }

  const connectedParticipantUsers = computed(() => (
    participantUsers.value.filter((row) => hasConnectedParticipantEvidence(row))
  ));
  connectedParticipantUsersRef.value = connectedParticipantUsers;

  const isAloneInCall = computed(() => {
    const participants = connectedParticipantUsers.value;
    if (participants.length !== 1) return false;
    return Number(participants[0]?.userId || 0) === currentUserId.value;
  });

  function applyViewerContext(viewerPayload) {
    const viewer = viewerPayload && typeof viewerPayload === 'object' ? viewerPayload : {};
    const nextCallId = String(viewerPayload?.call_id || viewerPayload?.callId || '').trim();
    if (nextCallId !== activeCallId.value) {
      activeCallId.value = nextCallId;
      loadedCallId.value = '';
      resetCallParticipantRoles();
      viewerCallRole.value = 'participant';
      viewerEffectiveCallRole.value = 'participant';
      viewerCanModerateCall.value = false;
      viewerCanManageOwnerRole.value = false;
      if (nextCallId !== '') {
        void loadActiveCallDetails(true);
      }
    }

    viewerCallRole.value = normalizeCallRole(viewer.call_role || viewer.callRole || 'participant');
    viewerEffectiveCallRole.value = normalizeCallRole(
      viewer.effective_call_role
      || viewer.effectiveCallRole
      || viewer.call_role
      || viewer.callRole
      || 'participant'
    );
    viewerCanModerateCall.value = Boolean(viewer.can_moderate ?? viewer.canModerate ?? false);
    viewerCanManageOwnerRole.value = Boolean(
      viewer.can_manage_owner
      ?? viewer.canManageOwner
      ?? viewer.can_manage_call_owner
      ?? viewer.canManageCallOwner
      ?? false
    );
  }

  function applyCallDetails(callPayload) {
    const call = callPayload && typeof callPayload === 'object' ? callPayload : {};
    const callId = String(call.id || '').trim();
    if (callId !== '') {
      activeCallId.value = callId;
      loadedCallId.value = callId;
    }

    resetCallParticipantRoles();
    const internal = Array.isArray(call?.participants?.internal) ? call.participants.internal : [];
    for (const participant of internal) {
      const userId = Number(participant?.user_id || 0);
      if (!Number.isInteger(userId) || userId <= 0) continue;
      callParticipantRoles[userId] = normalizeCallRole(participant?.call_role || participant?.callRole || 'participant');
    }

    const isAdmin = normalizeRole(sessionState.role) === 'admin';
    if (callParticipantRoles[currentUserId.value]) {
      const currentCallRole = normalizeCallRole(callParticipantRoles[currentUserId.value]);
      viewerCallRole.value = currentCallRole;
      viewerEffectiveCallRole.value = isAdmin ? 'owner' : currentCallRole;
      viewerCanModerateCall.value = isAdmin || currentCallRole === 'owner' || currentCallRole === 'moderator';
      viewerCanManageOwnerRole.value = isAdmin || currentCallRole === 'owner';
      return;
    }
    const ownerUserId = Number(call?.owner?.user_id || 0);
    if (Number.isInteger(ownerUserId) && ownerUserId > 0 && ownerUserId === currentUserId.value) {
      viewerCallRole.value = 'owner';
      viewerEffectiveCallRole.value = 'owner';
      viewerCanModerateCall.value = true;
      viewerCanManageOwnerRole.value = true;
    } else if (isAdmin) {
      viewerCallRole.value = 'participant';
      viewerEffectiveCallRole.value = 'owner';
      viewerCanModerateCall.value = true;
      viewerCanManageOwnerRole.value = true;
    } else {
      viewerCallRole.value = 'participant';
      viewerEffectiveCallRole.value = 'participant';
      viewerCanModerateCall.value = false;
      viewerCanManageOwnerRole.value = false;
    }
  }

  async function loadActiveCallDetails(force = false) {
    const callId = String(activeCallId.value || '').trim();
    if (callId === '') {
      loadedCallId.value = '';
      resetCallParticipantRoles();
      viewerCallRole.value = 'participant';
      viewerEffectiveCallRole.value = 'participant';
      viewerCanModerateCall.value = false;
      viewerCanManageOwnerRole.value = false;
      return;
    }
    if (!force && loadedCallId.value === callId) return;

    try {
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}`);
      applyCallDetails(payload?.call || null);
    } catch {
      loadedCallId.value = '';
    }
  }

  function applyLobbySnapshot(payload) {
    const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
    const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted.map(normalizeLobbyEntry) : [];
    const admittedCurrentUser = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId.value);

    if (roomId !== activeRoomId.value) {
      if (
        admittedCurrentUser
        && roomId === desiredRoomId.value
        && pendingAdmissionJoinRoomId.value !== roomId
      ) {
        pendingAdmissionJoinRoomId.value = roomId;
        if (!refs.sendRoomJoin(roomId)) {
          pendingAdmissionJoinRoomId.value = '';
        }
      }
      return;
    }

    const previousQueuedUserIds = new Set(
      lobbyQueue.value
        .map((entry) => Number(entry?.user_id || 0))
        .filter((userId) => Number.isInteger(userId) && userId > 0)
    );
    const nextQueueRows = Array.isArray(payload?.queue) ? payload.queue.map(normalizeLobbyEntry) : [];
    lobbyQueue.value = nextQueueRows;
    lobbyAdmitted.value = admittedRows;

    const addedQueueRows = nextQueueRows.filter((entry) => {
      const userId = Number(entry?.user_id || 0);
      if (!Number.isInteger(userId) || userId <= 0) return false;
      if (userId === currentUserId.value) return false;
      return !previousQueuedUserIds.has(userId);
    });
    if (lobbyNotificationState.hasSnapshot) {
      notifyLobbyJoinRequests(addedQueueRows);
    }
    lobbyNotificationState.hasSnapshot = true;

    for (const key of Object.keys(lobbyActionState)) {
      if (key.startsWith('allow:') || key.startsWith('remove:')) {
        delete lobbyActionState[key];
      }
    }
    refreshUsersDirectoryPresentation();
  }

  function applyRoomSnapshot(payload) {
    hasRealtimeRoomSync.value = true;
    const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || desiredRoomId.value);
    const previousRoomId = normalizeRoomId(serverRoomId.value || roomId);
    if (previousRoomId !== roomId) {
      lobbyNotificationState.hasSnapshot = false;
      hideLobbyJoinToast();
      state.setParticipantsRawSignature('');
    }
    serverRoomId.value = roomId;
    if (pendingAdmissionJoinRoomId.value === roomId) {
      pendingAdmissionJoinRoomId.value = '';
    }
    if (roomId === desiredRoomId.value) {
      clearAdmissionGate(roomId);
    }
    ensureRoomBuckets(roomId);
    applyViewerContext(payload?.viewer || null);

    const participantsChanged = applyParticipantsSnapshot(payload?.participants);
    if (payload?.layout && typeof payload.layout === 'object') {
      applyCallLayoutPayload(payload.layout);
    }
    if (Array.isArray(payload?.activity)) {
      applyActivitySnapshot(payload.activity);
    }

    const presentUserIds = new Set();
    for (const row of connectedParticipantUsers.value) {
      presentUserIds.add(row.userId);
    }
    pruneParticipantActivity(presentUserIds);
    for (const userId of Object.keys(peerControlStateByUserId)) {
      if (!presentUserIds.has(Number(userId))) {
        delete peerControlStateByUserId[userId];
      }
    }
    if (participantsChanged) {
      refreshUsersDirectoryPresentation();
    }
    if (isSocketOnline.value) {
      void syncControlStateToPeers();
      void syncModerationStateToPeers();
      if (participantsChanged) {
        void syncMediaSecurityWithParticipants();
      }
    }
  }

  return {
    applyCallDetails,
    applyLobbySnapshot,
    applyParticipantsSnapshot,
    applyRoomSnapshot,
    applyViewerContext,
    connectedParticipantUsers,
    currentUserParticipantRow,
    ensureRoomBuckets,
    isAloneInCall,
    loadActiveCallDetails,
    participantUsers,
    removeParticipantFromSnapshot,
    resetCallParticipantRoles,
  };
}
