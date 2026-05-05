export function createCallWorkspaceOrchestrationHelpers({
  vue,
  callbacks,
  refs,
  state,
}) {
  const { watch, nextTick } = vue;
  const {
    clearAloneIdleWatchTimer,
    closeSocket,
    connectSocket,
    ensureAloneIdleWatchTimer,
    hideAloneIdlePrompt,
    refreshUsersDirectoryPresentation,
    renderCallVideoLayout,
    requestRoomSnapshot,
    resetLobbyListScroll,
    resetUsersListScroll,
    sendRoomJoin,
    setNotice,
    syncLobbyListViewport,
    syncUsersListViewport,
    syncMediaSecurityWithParticipants,
    syncNativePeerConnectionsWithRoster,
    teardownLocalPublisher,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
    resyncNativeAudioBridgePeerAfterSecurityReady,
    scheduleMediaSecurityParticipantSync,
  } = callbacks;
  const {
    activeCallId,
    activeMessages,
    activeRoomId,
    activeSocketCallId,
    chatAttachmentDragActive,
    chatAttachmentDrafts,
    chatAttachmentError,
    chatListRef,
    connectedParticipantUsers,
    connectionReason,
    connectionState,
    controlState,
    currentLayoutMode,
    currentUserId,
    desiredRoomId,
    filteredUsers,
    gridVideoParticipants,
    isAloneInCall,
    isSocketOnline,
    lobbyPage,
    lobbyPageCount,
    lobbyPageRows,
    lobbyRows,
    mediaSecurityTargetIds,
    miniVideoParticipants,
    primaryVideoUserId,
    reactionTrayOpen,
    reconnectAttempt,
    route,
    router,
    sessionState,
    shouldMaintainNativePeerConnections,
    shouldUseNativeAudioBridge,
    usersPage,
    usersPageCount,
    usersPageRows,
  } = refs;
  const {
    normalizeRole,
    setAloneIdleLastActiveMs,
    setManualSocketClose,
  } = state;

  function hangupCall() {
    controlState.handRaised = false;
    controlState.cameraEnabled = true;
    controlState.micEnabled = true;
    controlState.screenEnabled = false;
    reactionTrayOpen.value = false;
    refreshUsersDirectoryPresentation();
    teardownLocalPublisher();
    teardownNativePeerConnections();
    teardownSfuRemotePeers();

    const peerIds = connectedParticipantUsers.value
      .map((participant) => participant.userId)
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);

    for (const targetUserId of peerIds) {
      refs.sendSocketFrame({
        type: 'call/hangup',
        target_user_id: targetUserId,
        payload: {
          reason: 'local_hangup',
          room_id: activeRoomId.value,
          actor_user_id: currentUserId.value,
        },
      });
    }

    const callEntryMode = String(route.query.entry || '').trim().toLowerCase();
    if (callEntryMode === 'invite' && refs.isGuestSession()) {
      if (String(route.name || '') !== 'call-goodbye') {
        void router.push({ name: 'call-goodbye' });
      }
      return;
    }

    const overviewRouteName = normalizeRole(sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
    if (String(route.name || '') !== overviewRouteName) {
      void router.push({ name: overviewRouteName });
    }
  }

  watch(desiredRoomId, (nextRoomId, previousRoomId) => {
    refs.ensureRoomBuckets(nextRoomId);
    refs.hasRealtimeRoomSync.value = false;
    usersPage.value = 1;
    lobbyPage.value = 1;
    if (nextRoomId === previousRoomId) return;
    chatAttachmentDrafts.value = [];
    chatAttachmentError.value = '';
    chatAttachmentDragActive.value = false;
    teardownNativePeerConnections();
    teardownSfuRemotePeers();
    if (isSocketOnline.value) {
      if (!sendRoomJoin(nextRoomId)) {
        setNotice(`Could not join room ${nextRoomId} while websocket is offline.`, 'error');
      } else {
        requestRoomSnapshot();
        refreshUsersDirectoryPresentation();
      }
    }
  });

  watch(filteredUsers, () => {
    if (usersPage.value > usersPageCount.value) {
      usersPage.value = usersPageCount.value;
    }
    if (usersPage.value < 1) usersPage.value = 1;
  });

  watch(lobbyRows, () => {
    if (lobbyPage.value > lobbyPageCount.value) {
      lobbyPage.value = lobbyPageCount.value;
    }
    if (lobbyPage.value < 1) lobbyPage.value = 1;
  });

  watch(usersPage, () => {
    nextTick(() => resetUsersListScroll());
  });

  watch(lobbyPage, () => {
    nextTick(() => resetLobbyListScroll());
  });

  watch(
    () => usersPageRows.value.length,
    () => {
      nextTick(() => syncUsersListViewport());
    }
  );

  watch(
    () => lobbyPageRows.value.length,
    () => {
      nextTick(() => syncLobbyListViewport());
    }
  );

  watch(
    () => connectedParticipantUsers.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0)
      .sort((left, right) => left - right)
      .join(','),
    () => {
      nextTick(() => renderCallVideoLayout());
      if (isSocketOnline.value) {
        void syncMediaSecurityWithParticipants();
      }
      if (!shouldMaintainNativePeerConnections()) return;
      syncNativePeerConnectionsWithRoster();
    }
  );

  watch(
    () => [
      String(activeSocketCallId.value || activeCallId.value || ''),
      activeRoomId.value,
      String(currentUserId.value || 0),
    ].join('|'),
    (nextValue, previousValue) => {
      if (nextValue === previousValue) return;
      scheduleMediaSecurityParticipantSync('context_watch');
    }
  );

  watch(
    () => [
      shouldUseNativeAudioBridge() ? '1' : '0',
      refs.currentMediaSecurityRuntimePath(),
      connectedParticipantUsers.value
        .map((row) => Number(row?.userId || 0))
        .filter((userId) => Number.isInteger(userId) && userId > 0)
        .sort((left, right) => left - right)
        .join(','),
    ].join('|'),
    (nextValue, previousValue) => {
      if (nextValue === previousValue) return;
      if (!isSocketOnline.value) return;
      if (connectedParticipantUsers.value.length <= 1) return;
      void syncMediaSecurityWithParticipants();
      if (!shouldMaintainNativePeerConnections()) return;
      syncNativePeerConnectionsWithRoster();
      for (const targetUserId of mediaSecurityTargetIds()) {
        resyncNativeAudioBridgePeerAfterSecurityReady(targetUserId, 'native_bridge_availability_changed');
      }
    }
  );

  watch(
    () => [
      currentLayoutMode.value,
      primaryVideoUserId.value,
      miniVideoParticipants.value
        .map((row) => Number(row?.userId || 0))
        .filter((userId) => Number.isInteger(userId) && userId > 0)
        .join(','),
      gridVideoParticipants.value
        .map((row) => Number(row?.userId || 0))
        .filter((userId) => Number.isInteger(userId) && userId > 0)
        .join(','),
    ].join('|'),
    () => {
      nextTick(() => renderCallVideoLayout());
    }
  );

  watch(
    isAloneInCall,
    (alone) => {
      if (alone) {
        setAloneIdleLastActiveMs(Date.now());
        hideAloneIdlePrompt();
        ensureAloneIdleWatchTimer();
        return;
      }

      hideAloneIdlePrompt();
      setAloneIdleLastActiveMs(Date.now());
      clearAloneIdleWatchTimer();
    },
    { immediate: true }
  );

  watch(
    () => activeMessages.value.length,
    async () => {
      await nextTick();
      const node = chatListRef.value;
      if (node instanceof HTMLElement) {
        node.scrollTop = node.scrollHeight;
      }
    }
  );

  watch(
    () => sessionState.sessionToken,
    (token) => {
      if (String(token || '').trim() === '') {
        setManualSocketClose(true);
        connectionState.value = 'expired';
        connectionReason.value = 'missing_session';
        closeSocket();
        return;
      }

      if (!isSocketOnline.value) {
        reconnectAttempt.value = 0;
        void connectSocket();
      }
    }
  );

  return {
    hangupCall,
  };
}
