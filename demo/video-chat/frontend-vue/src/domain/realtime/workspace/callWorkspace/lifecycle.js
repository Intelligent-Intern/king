import { createSfuBackgroundTabPolicy } from './backgroundTabPolicy.js';

export function registerCallWorkspaceLifecycleHelpers({
  vue,
  callbacks,
  refs,
  state,
  constants,
}) {
  const { watch, onMounted, onBeforeUnmount, nextTick } = vue;
  const {
    applyCallInputPreferences,
    applyCallOutputPreferences,
    attachAloneIdleActivityListeners,
    clearAloneIdleWatchTimer,
    clearLobbyToastTimer,
    clearMediaSecurityHandshakeWatchdog,
    clearMediaSecurityResyncTimer,
    clearLocalTrackRecoveryTimer,
    clearModerationSyncTimer,
    clearPingTimer,
    clearReactionQueueTimer,
    clearReconnectTimer,
    clearRemoteVideoStallTimer,
    clearTypingStopTimer,
    closeSocket,
    connectSocket,
    consumeQueuedModerationSyncEntries,
    detectMediaRuntimeCapabilities,
    flushQueuedReactions,
    hideAloneIdlePrompt,
    hideLobbyJoinToast,
    initSFU,
    loadDynamicIceServers,
    markWorkspaceReconnectAfterForeground,
    publishLocalActivitySample,
    publishLocalTracks,
    reconfigureLocalBackgroundFilterOnly,
    reconfigureLocalTracksFromSelectedDevices,
    reconnectWorkspaceAfterForeground,
    refreshCallMediaDevices,
    resolveRouteCallRef,
    setActiveTab,
    setMediaRuntimePath,
    startRemoteVideoStallTimer,
    stopLocalEncodingPipeline,
    stopLocalTyping,
    stopSfuTrackAnnounceTimer,
    switchMediaRuntimePath,
    syncLobbyListViewport,
    syncUsersListViewport,
    teardownLocalPublisher,
    teardownNativePeerConnections,
    teardownSfuRemotePeers,
  } = callbacks;
  const {
    activeMessages,
    activeTab,
    callMediaPrefs,
    canModerate,
    chatListRef,
    connectionReason,
    connectionState,
    desiredRoomId,
    ensureRoomBuckets,
    isCompactLayoutViewport,
    isCompactViewport,
    isShellMobileViewport,
    isShellTabletViewport,
    isSocketOnline,
    localStreamRef,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    nativeAudioBridgeBlockDiagnosticsSent,
    nativeAudioSecurityBannerMessage,
    nativeAudioSecurityTelemetrySnapshot,
    rightSidebarCollapsed,
    routeCallRef,
    serverRoomId,
    sessionState,
    sfuClientRef,
    sfuConnected,
    shouldConnectSfu,
    usersRefreshTimer,
    typingByRoom,
    localTracksPublishedToSfuRef,
  } = refs;
  const {
    getCompactMediaQuery,
    setCompactMediaQuery,
    getConnectGeneration,
    setConnectGeneration,
    getDetachForegroundReconnect,
    setDetachForegroundReconnect,
    getDetachMediaDeviceWatcher,
    setDetachMediaDeviceWatcher,
    setManualSocketClose,
    setTypingSweepTimer,
    getTypingSweepTimer,
    setAloneIdleLastActiveMs,
  } = state;
  const {
    attachCallMediaDeviceWatcher,
    attachForegroundReconnectHandlers,
    captureClientDiagnostic,
    compactBreakpoint,
    sfuRuntimeEnabled,
    typingSweepMs,
    mediaSecuritySessionClass,
  } = constants;
  const sfuBackgroundTabPolicy = createSfuBackgroundTabPolicy({
    callbacks: {
      captureClientDiagnostic,
      publishLocalTracks,
      stopLocalEncodingPipeline,
    },
    refs: {
      callMediaPrefs,
      localStreamRef,
      localTracksPublishedToSfuRef,
      mediaRuntimePath,
      sfuClientRef,
    },
  });

  function isNativeAudioSecurityWaitingMessage(message) {
    return /waiting for the media-security handshake/i.test(String(message || ''));
  }

  function handleCompactViewportChange(event) {
    isCompactViewport.value = Boolean(event?.matches);
  }

  function resetSfuOutboundMediaForAutomaticProfileSwitch(nextValue, previousValue) {
    stopLocalEncodingPipeline?.();
    sfuClientRef.value?.resetOutboundMediaAfterProfileSwitch?.({
      fromProfile: String(previousValue || ''),
      toProfile: String(nextValue || ''),
      reason: 'automatic_profile_switch',
    });
  }

  watch(
    () => callMediaPrefs.speakerVolume,
    () => {
      applyCallOutputPreferences();
    },
    { immediate: true }
  );

  watch(
    () => callMediaPrefs.selectedSpeakerId,
    () => {
      applyCallOutputPreferences();
    }
  );

  watch(
    () => callMediaPrefs.microphoneVolume,
    () => {
      applyCallInputPreferences();
    }
  );

  watch(
    () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
    ([nextCameraId, nextMicId], [prevCameraId, prevMicId]) => {
      if (nextCameraId === prevCameraId && nextMicId === prevMicId) return;
      void reconfigureLocalTracksFromSelectedDevices();
    }
  );

  watch(
    () => callMediaPrefs.outgoingVideoQualityProfile,
    (nextValue, previousValue) => {
      if (nextValue === previousValue) return;
      resetSfuOutboundMediaForAutomaticProfileSwitch(nextValue, previousValue);
      void reconfigureLocalTracksFromSelectedDevices();
    }
  );

  watch(
    () => [
      callMediaPrefs.backgroundFilterMode,
      callMediaPrefs.backgroundBackdropMode,
      callMediaPrefs.backgroundQualityProfile,
      callMediaPrefs.backgroundBlurStrength,
      callMediaPrefs.backgroundMaskVariant,
      callMediaPrefs.backgroundBlurTransition,
      callMediaPrefs.backgroundApplyOutgoing,
      callMediaPrefs.backgroundReplacementImageUrl,
      callMediaPrefs.backgroundMaxProcessWidth,
      callMediaPrefs.backgroundMaxProcessFps,
    ],
    (nextValue, previousValue = []) => {
      if (
        nextValue[0] === previousValue[0]
        && nextValue[1] === previousValue[1]
        && nextValue[2] === previousValue[2]
        && nextValue[3] === previousValue[3]
        && nextValue[4] === previousValue[4]
        && nextValue[5] === previousValue[5]
        && nextValue[6] === previousValue[6]
        && nextValue[7] === previousValue[7]
        && nextValue[8] === previousValue[8]
        && nextValue[9] === previousValue[9]
      ) {
        return;
      }
      void reconfigureLocalBackgroundFilterOnly();
    }
  );

  watch(isCompactViewport, (nextValue) => {
    if (nextValue && isCompactLayoutViewport.value) {
      rightSidebarCollapsed.value = true;
    }
  });

  watch(isShellMobileViewport, (nextValue) => {
    if (nextValue && isCompactViewport.value) {
      rightSidebarCollapsed.value = true;
    }
  });

  watch(isShellTabletViewport, (nextValue) => {
    if (nextValue && isCompactViewport.value) {
      rightSidebarCollapsed.value = true;
    }
  });

  watch(rightSidebarCollapsed, (collapsed) => {
    if (!collapsed) {
      hideLobbyJoinToast();
    }
  });

  watch(canModerate, (enabled) => {
    if (!enabled) {
      if (activeTab.value === 'lobby') {
        setActiveTab('users');
      }
      hideLobbyJoinToast();
    }
  });

  watch(isSocketOnline, (online) => {
    if (!online) return;
    flushQueuedReactions();
    publishLocalActivitySample(true);
  });

  watch(nativeAudioSecurityBannerMessage, (message) => {
    if (message === '') return;
    const waitingForSecurity = isNativeAudioSecurityWaitingMessage(message);
    const diagnosticEventType = waitingForSecurity
      ? 'native_audio_bridge_waiting'
      : 'native_audio_bridge_blocked';
    const diagnosticLevel = waitingForSecurity ? 'warning' : 'error';
    const diagnosticKey = `${refs.activeRoomId.value}:${diagnosticEventType}:${message}`;
    if (nativeAudioBridgeBlockDiagnosticsSent.has(diagnosticKey)) return;
    nativeAudioBridgeBlockDiagnosticsSent.add(diagnosticKey);
    captureClientDiagnostic({
      category: 'media',
      level: diagnosticLevel,
      eventType: diagnosticEventType,
      code: diagnosticEventType,
      message,
      payload: {
        media_runtime_path: mediaRuntimePath.value,
        supports_native_transforms: mediaSecuritySessionClass.supportsNativeTransforms(),
        stage_b: Boolean(mediaRuntimeCapabilities.value.stageB),
        security: nativeAudioSecurityTelemetrySnapshot(),
      },
      immediate: !waitingForSecurity,
    });
  });

  watch(
    shouldConnectSfu,
    (enabled) => {
      if (!enabled) {
        if (sfuClientRef.value) {
          sfuClientRef.value.leave();
          sfuClientRef.value = null;
        }
        sfuConnected.value = false;
        localTracksPublishedToSfuRef.set(false);
        stopSfuTrackAnnounceTimer();
        teardownSfuRemotePeers();
        return;
      }

      if (String(sessionState.sessionToken || '').trim() !== '' && Number.isInteger(sessionState.userId) && sessionState.userId > 0) {
        initSFU();
      }
    }
  );

  watch(routeCallRef, (nextValue, previousValue) => {
    if (nextValue === previousValue) return;
    void resolveRouteCallRef(nextValue);
  });

  onMounted(async () => {
    startRemoteVideoStallTimer();
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      const compactMediaQuery = window.matchMedia(`(max-width: ${compactBreakpoint}px)`);
      setCompactMediaQuery(compactMediaQuery);
      isCompactViewport.value = compactMediaQuery.matches;
      if (typeof compactMediaQuery.addEventListener === 'function') {
        compactMediaQuery.addEventListener('change', handleCompactViewportChange);
      } else if (typeof compactMediaQuery.addListener === 'function') {
        compactMediaQuery.addListener(handleCompactViewportChange);
      }
    } else if (typeof window !== 'undefined') {
      isCompactViewport.value = window.innerWidth <= compactBreakpoint;
    }
    if (isCompactViewport.value && isCompactLayoutViewport.value) {
      rightSidebarCollapsed.value = true;
    }

    attachAloneIdleActivityListeners();
    setAloneIdleLastActiveMs(Date.now());
    setDetachForegroundReconnect(attachForegroundReconnectHandlers({
      onBackground: (context) => {
        markWorkspaceReconnectAfterForeground();
        sfuBackgroundTabPolicy.pauseVideoForBackground(context);
      },
      onForeground: (context) => {
        reconnectWorkspaceAfterForeground();
        void sfuBackgroundTabPolicy.resumeVideoAfterForeground(context);
      },
    }));

    setDetachMediaDeviceWatcher(attachCallMediaDeviceWatcher({ requestPermissions: true }));
    const canEnterWorkspace = await resolveRouteCallRef(routeCallRef.value);
    if (!canEnterWorkspace) {
      return;
    }
    ensureRoomBuckets(desiredRoomId.value);
    serverRoomId.value = desiredRoomId.value;
    await refreshCallMediaDevices({ requestPermissions: true });
    await loadDynamicIceServers();
    void connectSocket();

    try {
      mediaRuntimeCapabilities.value = await detectMediaRuntimeCapabilities();
      const shouldUseSfuRuntime = sfuRuntimeEnabled || !mediaRuntimeCapabilities.value.stageB;
      if (mediaRuntimeCapabilities.value.stageA && shouldUseSfuRuntime) {
        await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a');
      } else if (mediaRuntimeCapabilities.value.stageB) {
        await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b');
      } else {
        setMediaRuntimePath('unsupported', 'capability_probe_unsupported');
      }
    } catch {
      mediaRuntimeCapabilities.value = {
        checkedAt: new Date().toISOString(),
        wlvcWasm: {
          webAssembly: typeof WebAssembly === 'object',
          encoder: false,
          decoder: false,
          reason: 'probe_error',
        },
        webRtcNative: false,
        stageA: false,
        stageB: false,
        preferredPath: 'unsupported',
        reasons: ['probe_error'],
      };
      setMediaRuntimePath('unsupported', 'capability_probe_error');
    }

    await publishLocalTracks();

    if (shouldConnectSfu.value && sessionState.sessionToken && sessionState.userId) {
      initSFU();
    }

    await nextTick();
    syncUsersListViewport();
    syncLobbyListViewport();

    setTypingSweepTimer(setInterval(() => {
      const nowMs = Date.now();
      for (const roomId of Object.keys(typingByRoom)) {
        const roomMap = typingByRoom[roomId];
        if (!roomMap || typeof roomMap !== 'object') continue;
        for (const [userId, entry] of Object.entries(roomMap)) {
          if (Number(entry?.expiresAtMs || 0) <= nowMs) {
            delete roomMap[userId];
          }
        }
      }
    }, typingSweepMs));
  });

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
        refs.reconnectAttempt.value = 0;
        void connectSocket();
      }
    }
  );

  onBeforeUnmount(() => {
    clearRemoteVideoStallTimer();
    clearReactionQueueTimer();
    clearModerationSyncTimer();
    consumeQueuedModerationSyncEntries();
    const compactMediaQuery = getCompactMediaQuery();
    if (compactMediaQuery) {
      if (typeof compactMediaQuery.removeEventListener === 'function') {
        compactMediaQuery.removeEventListener('change', handleCompactViewportChange);
      } else if (typeof compactMediaQuery.removeListener === 'function') {
        compactMediaQuery.removeListener(handleCompactViewportChange);
      }
      setCompactMediaQuery(null);
    }

    const detachMediaDeviceWatcher = getDetachMediaDeviceWatcher();
    if (detachMediaDeviceWatcher) {
      detachMediaDeviceWatcher();
      setDetachMediaDeviceWatcher(null);
    }
    const detachForegroundReconnect = getDetachForegroundReconnect();
    if (typeof detachForegroundReconnect === 'function') {
      detachForegroundReconnect();
      setDetachForegroundReconnect(null);
    }
    callbacks.detachAloneIdleActivityListeners();
    setManualSocketClose(true);
    setConnectGeneration(getConnectGeneration() + 1);
    stopLocalTyping();
    clearTypingStopTimer();
    hideAloneIdlePrompt();
    clearAloneIdleWatchTimer();
    clearLobbyToastTimer();
    clearReconnectTimer();
    clearPingTimer();
    clearMediaSecurityResyncTimer();
    clearMediaSecurityHandshakeWatchdog();
    clearLocalTrackRecoveryTimer();
    stopSfuTrackAnnounceTimer();
    if (usersRefreshTimer.value !== null) {
      clearTimeout(usersRefreshTimer.value);
      usersRefreshTimer.value = null;
    }
    const typingSweepTimer = getTypingSweepTimer();
    if (typingSweepTimer !== null) {
      clearInterval(typingSweepTimer);
      setTypingSweepTimer(null);
    }
    closeSocket({ leaveRoom: true });
    teardownLocalPublisher();
    teardownNativePeerConnections();
    teardownSfuRemotePeers();
    if (sfuClientRef.value) {
      sfuClientRef.value.leave();
      sfuClientRef.value = null;
    }
    refs.backgroundFilterController.dispose();
    localStreamRef.value = null;
  });
}
