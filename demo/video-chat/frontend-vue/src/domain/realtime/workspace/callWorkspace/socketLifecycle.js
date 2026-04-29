export function createCallWorkspaceSocketHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    applyCallLayoutPayload,
    applyLobbySnapshot,
    applyParticipantActivityPayload,
    applyReactionEvent,
    applyRemoteControlState,
    applyRoomSnapshot,
    applyTypingEvent,
    applyViewerContext,
    appendChatMessage,
    captureClientDiagnostic,
    clearAdmissionGate,
    clearErrors,
    clearLobbyActionText,
    clearTransientActivityPublishErrorNotice,
    closeNativePeerConnection,
    closeSocketLocal,
    downgradeSfuVideoQualityAfterEncodePressure,
    ensureRoomBuckets,
    extractErrorMessage,
    fetchBackend,
    handleAssetVersionSocketClose,
    handleAssetVersionSocketPayload,
    handleMediaSecuritySignal,
    handleNativeSignalingEvent,
    hideLobbyJoinToast,
    mediaDebugLog,
    normalizeRoomId,
    redirectInvitedRouteToJoinModal,
    refreshUsersDirectory,
    refreshUsersDirectoryPresentation,
    removeParticipantFromSnapshot,
    removeSfuRemotePeersForUserId,
    requestWlvcFullFrameKeyframe,
    requestHeaders,
    requestRoomSnapshot,
    resetPeerControlState,
    scheduleNativeOfferRetryForUserId,
    sendMediaSecuritySync,
    sendRoomJoin,
    setAdmissionGate,
    setBackendWebSocketOrigin,
    setNotice,
    syncControlStateToPeers,
    syncModerationStateToPeers,
    tryDirectJoinWithModeratorBypass,
  } = callbacks;

  const {
    callStateSignalTypes,
    mediaSecuritySignalTypes,
    reconnectDelayMs,
  } = constants;

  function removeParticipantLocallyAfterHangup(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;

    resetPeerControlState(normalizedUserId);
    closeNativePeerConnection(normalizedUserId);
    removeSfuRemotePeersForUserId(normalizedUserId);
    const participantsChanged = removeParticipantFromSnapshot(normalizedUserId);
    delete refs.participantActivityByUserId[normalizedUserId];
    delete refs.pinnedUsers[normalizedUserId];
    delete refs.mutedUsers[normalizedUserId];
    delete refs.callParticipantRoles[normalizedUserId];
    refreshUsersDirectoryPresentation();
    return participantsChanged;
  }

  function recoverExpectedSignalingPublishFailure({
    failedCommandType,
    failedTargetUserId,
    signalingError,
  }) {
    const normalizedTargetUserId = Number(failedTargetUserId || 0);
    const normalizedError = String(signalingError || '').trim().toLowerCase();
    const targetIsKnown = Number.isInteger(normalizedTargetUserId) && normalizedTargetUserId > 0;

    if (targetIsKnown && normalizedError === 'target_not_in_room') {
      removeParticipantLocallyAfterHangup(normalizedTargetUserId);
    }

    requestRoomSnapshot();
    if (mediaSecuritySignalTypes.includes(failedCommandType)) {
      setTimeout(() => {
        void sendMediaSecuritySync(true);
      }, 500);
      return;
    }

    if (targetIsKnown && normalizedError !== 'target_not_in_room') {
      scheduleNativeOfferRetryForUserId(normalizedTargetUserId, 'signaling_publish_retry');
    }
  }

  function handleMediaQualityPressure(payloadBody, sender) {
    const kind = String(payloadBody?.kind || '').trim().toLowerCase();
    if (kind !== 'sfu-video-quality-pressure') return false;

    const senderUserId = Number(sender?.user_id || 0);
    const requestedAction = String(payloadBody?.requested_action || '').trim().toLowerCase();
    const sourceReason = String(payloadBody?.reason || '').trim().toLowerCase();
    const fullKeyframeRequested = Boolean(payloadBody?.request_full_keyframe)
      || requestedAction === 'force_full_keyframe'
      || sourceReason === 'sfu_remote_video_decoder_waiting_keyframe';
    const forcedFullKeyframe = fullKeyframeRequested && typeof requestWlvcFullFrameKeyframe === 'function'
      ? requestWlvcFullFrameKeyframe(sourceReason || 'sfu_remote_quality_pressure', {
        ...payloadBody,
        senderUserId,
      })
      : false;
    const downgraded = typeof downgradeSfuVideoQualityAfterEncodePressure === 'function'
      ? downgradeSfuVideoQualityAfterEncodePressure('sfu_remote_quality_pressure')
      : false;
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_remote_quality_pressure_received',
      code: 'sfu_remote_quality_pressure_received',
      message: 'A remote receiver requested lower outgoing SFU video quality after repeated freezes.',
      payload: {
        sender_user_id: senderUserId,
        requested_action: requestedAction || 'downgrade_outgoing_video',
        source_reason: sourceReason,
        source_publisher_id: String(payloadBody?.publisher_id || '').trim(),
        full_keyframe_requested: forcedFullKeyframe,
        downgraded,
      },
      immediate: true,
    });
    return true;
  }

  function handleSignalingEvent(payload) {
    const type = String(payload?.type || '').trim().toLowerCase();
    if (!['call/offer', 'call/answer', 'call/ice', 'call/hangup', ...callStateSignalTypes, ...mediaSecuritySignalTypes].includes(type)) return;

    const sender = typeof payload.sender === 'object' ? payload.sender : {};
    const senderUserId = Number(sender.user_id || 0);
    const payloadBody = typeof payload.payload === 'object' ? payload.payload : null;
    if (mediaSecuritySignalTypes.includes(type)) {
      void handleMediaSecuritySignal(type, senderUserId, payloadBody || {});
      return;
    }

    const payloadKind = String(payloadBody?.kind || '').trim().toLowerCase();
    const hasSdpPayload = Boolean(payloadBody && typeof payloadBody.sdp === 'object');
    const hasCandidatePayload = Boolean(payloadBody && typeof payloadBody.candidate === 'object');
    const isNativeSignal = payloadKind.startsWith('webrtc_')
      || (type === 'call/offer' && hasSdpPayload)
      || (type === 'call/answer' && hasSdpPayload)
      || (type === 'call/ice' && hasCandidatePayload);

    if (type === 'call/hangup') {
      removeParticipantLocallyAfterHangup(senderUserId);
      return;
    }

    if (type === 'call/media-quality-pressure') {
      handleMediaQualityPressure(payloadBody || {}, sender);
      return;
    }

    if (isNativeSignal && Number.isInteger(senderUserId) && senderUserId > 0) {
      if (refs.shouldBlockNativeRuntimeSignaling()) {
        mediaDebugLog('[WebRTC] ignoring native signal while runtime is still pending', type);
        return;
      }
      void handleNativeSignalingEvent(type, senderUserId, payloadBody || {});
      return;
    }

    if (applyRemoteControlState(payload?.payload, sender)) {
      return;
    }

    const senderName = String(sender.display_name || `User ${senderUserId || 'unknown'}`).trim();
    setNotice(`Received ${type.replace('call/', '')} from ${senderName}.`);
  }

  function handleSocketMessage(event) {
    let payload = null;
    try {
      payload = JSON.parse(String(event.data || ''));
    } catch {
      return;
    }

    if (!payload || typeof payload !== 'object') return;
    if (handleAssetVersionSocketPayload(payload)) return;
    const type = String(payload.type || '').trim().toLowerCase();
    if (type === '') return;

    if (type === 'system/welcome') {
      refs.hasRealtimeRoomSync.value = true;
      const welcomeRoom = normalizeRoomId(payload.active_room_id || refs.desiredRoomId.value);
      refs.serverRoomId.value = welcomeRoom;
      ensureRoomBuckets(welcomeRoom);
      applyViewerContext(payload?.call_context || null);
      const admission = typeof payload.admission === 'object' ? payload.admission : null;
      const requiresAdmission = Boolean(admission?.requires_admission);
      const pendingRoomId = normalizeRoomId(admission?.pending_room_id || '');
      if (requiresAdmission && pendingRoomId !== '') {
        if (!tryDirectJoinWithModeratorBypass(pendingRoomId)) {
          setAdmissionGate(pendingRoomId);
          void redirectInvitedRouteToJoinModal({
            accessId: refs.routeCallResolve.accessId,
            callId: refs.activeCallId.value || refs.routeCallResolve.callId,
            roomId: pendingRoomId,
            call: {},
          });
          requestRoomSnapshot();
          return;
        }
      }
      clearAdmissionGate();
      requestRoomSnapshot();
      if (refs.desiredRoomId.value !== welcomeRoom) {
        void sendRoomJoin(refs.desiredRoomId.value);
      }
      return;
    }

    if (type === 'room/snapshot') {
      applyRoomSnapshot(payload);
      return;
    }

    if (type === 'room/joined' || type === 'room/left') {
      requestRoomSnapshot();
      return;
    }

    if (type === 'lobby/snapshot') {
      applyLobbySnapshot(payload);
      return;
    }

    if (type === 'reaction/event' || type === 'reaction/batch') {
      applyReactionEvent(payload);
      return;
    }

    if (type === 'chat/message') {
      appendChatMessage(payload);
      return;
    }

    if (type === 'typing/start' || type === 'typing/stop') {
      applyTypingEvent(payload);
      return;
    }

    if (type === 'participant/activity') {
      clearTransientActivityPublishErrorNotice();
      applyParticipantActivityPayload(payload?.activity, payload?.participant);
      return;
    }

    if (type === 'layout/mode' || type === 'layout/strategy' || type === 'layout/selection' || type === 'layout/state') {
      if (payload?.layout && typeof payload.layout === 'object') {
        applyCallLayoutPayload(payload.layout);
      }
      return;
    }

    if (type === 'call/ack') {
      const signalType = String(payload?.signal_type || '').replace('call/', '').trim() || 'signal';
      if (signalType === 'offer' && Number(payload?.sent_count ?? 0) === 0) {
        scheduleNativeOfferRetryForUserId(payload?.target_user_id, 'brokered_offer_unanswered');
      }
      if (!refs.shouldSuppressCallAckNotice(signalType)) {
        setNotice(`Sent ${signalType} to ${payload?.sent_count ?? 0} peer(s).`);
      }
      return;
    }

    if (type === 'chat/ack') {
      return;
    }

    if (type === 'system/error') {
      const message = String(payload?.message || 'Realtime command failed.').trim();
      const code = String(payload?.code || '').trim().toLowerCase();
      const closeReason = String(payload?.details?.close?.close_reason || payload?.details?.reason || '').trim().toLowerCase();
      const failedCommandType = String(payload?.details?.type || '').trim().toLowerCase();
      const failedTargetUserId = Number(payload?.details?.target_user_id || 0);
      if (code === 'signaling_publish_failed') {
        captureClientDiagnostic({
          category: 'realtime',
          level: 'error',
          eventType: 'realtime_signaling_publish_failed',
          code,
          message,
          payload: {
            failed_command_type: failedCommandType,
            failed_target_user_id: failedTargetUserId,
            details: payload?.details || {},
          },
          immediate: true,
        });
      }
      if (code === 'lobby_command_failed' && Number.isInteger(failedTargetUserId) && failedTargetUserId > 0) {
        if (failedCommandType === 'lobby/allow') {
          clearLobbyActionText(failedTargetUserId, 'allow');
        }
        if (failedCommandType === 'lobby/remove') {
          clearLobbyActionText(failedTargetUserId, 'remove');
        }
      }
      if (code === 'websocket_session_invalidated' || closeReason === 'session_invalidated') {
        state.manualSocketClose = true;
        refs.connectionReason.value = closeReason || 'session_invalidated';
        refs.connectionState.value = 'expired';
        closeSocketLocal();
      } else if (code === 'websocket_auth_failed' || code === 'websocket_forbidden' || closeReason === 'auth_backend_error' || closeReason === 'role_not_allowed') {
        state.manualSocketClose = true;
        refs.connectionReason.value = closeReason || code || 'blocked';
        refs.connectionState.value = 'blocked';
        closeSocketLocal();
      }
      if (code === 'reaction_publish_failed') {
        return;
      }
      if (
        code === 'lobby_command_failed'
        && (failedCommandType === 'lobby/queue/join' || failedCommandType === 'lobby/queue/request' || failedCommandType === 'lobby/queue/cancel')
        && refs.showAdmissionGate.value
      ) {
        return;
      }
      if (code === 'room_join_requires_admission' || code === 'room_join_not_allowed') {
        const pendingRoomId = normalizeRoomId(payload?.details?.pending_room_id || refs.desiredRoomId.value);
        if (!tryDirectJoinWithModeratorBypass(pendingRoomId)) {
          setAdmissionGate(pendingRoomId);
          void redirectInvitedRouteToJoinModal({
            accessId: refs.routeCallResolve.accessId,
            callId: refs.activeCallId.value || refs.routeCallResolve.callId,
            roomId: pendingRoomId,
            call: {},
          });
        }
        return;
      }
      if (refs.shouldSuppressExpectedSignalingError(payload)) {
        recoverExpectedSignalingPublishFailure({
          failedCommandType,
          failedTargetUserId,
          signalingError: payload?.details?.error,
        });
        return;
      }
      if (code === 'activity_publish_failed') {
        const activityError = String(payload?.details?.error || '').trim();
        const activityExceptionMessage = String(payload?.details?.exception_message || '').trim();
        const isTransientActivityStorageBusy = activityError === 'activity_backend_error'
          && /database (is locked|table is locked|schema is locked|busy)/i.test(activityExceptionMessage);

        captureClientDiagnostic({
          category: 'media',
          level: 'error',
          eventType: 'participant_activity_publish_failed',
          code: activityError || code,
          message,
          payload: {
            details: payload?.details || {},
          },
          immediate: true,
        });

        if (isTransientActivityStorageBusy) {
          clearTransientActivityPublishErrorNotice();
          return;
        }

        const activityReason = String(payload?.details?.reason || '').trim();
        const activityCallId = String(payload?.details?.call_id || '').trim();
        const activityRoomId = String(payload?.details?.room_id || '').trim();
        const activityExceptionClass = String(payload?.details?.exception_class || '').trim();
        const detailParts = [];
        if (activityError !== '') detailParts.push(`error=${activityError}`);
        if (activityCallId !== '') detailParts.push(`call=${activityCallId}`);
        if (activityRoomId !== '') detailParts.push(`room=${activityRoomId}`);
        if (activityExceptionClass !== '') detailParts.push(`exception=${activityExceptionClass}`);
        if (activityExceptionMessage !== '') detailParts.push(activityExceptionMessage);

        const detailedMessage = [
          message,
          activityReason && !message.includes(activityReason) ? activityReason : '',
          detailParts.length > 0 ? `[${detailParts.join(' | ')}]` : '',
        ].filter(Boolean).join(' ');
        setNotice(detailedMessage, 'error');
        return;
      }
      setNotice(message, 'error');
      return;
    }

    if (type === 'system/pong') {
      return;
    }

    handleSignalingEvent(payload);
  }

  function clearReconnectTimer() {
    if (state.reconnectTimer !== null) {
      clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }
  }

  function clearPingTimer() {
    if (state.pingTimer !== null) {
      clearInterval(state.pingTimer);
      state.pingTimer = null;
    }
  }

  function startPingLoop() {
    clearPingTimer();
    state.pingTimer = setInterval(() => {
      if (!refs.isSocketOnline.value) return;
      void refs.sendSocketFrame({ type: 'ping' });
    }, 12_000);
  }

  function closeSocket(options = {}) {
    const leaveRoom = options?.leaveRoom === true;
    clearReconnectTimer();
    clearPingTimer();
    refs.hasRealtimeRoomSync.value = false;
    hideLobbyJoinToast();
    const socket = refs.socketRef.value;
    refs.socketRef.value = null;
    if (!(socket instanceof WebSocket)) return;
    if (leaveRoom && socket.readyState === WebSocket.OPEN) {
      try {
        socket.send(JSON.stringify({ type: 'room/leave' }));
      } catch {
        // Best-effort leave.
      }
    }
    try {
      socket.close(1000, leaveRoom ? 'client_leave' : 'client_close');
    } catch {
      // ignore
    }
  }

  async function probeWorkspaceSession() {
    const token = String(refs.sessionState.sessionToken || '').trim();
    if (token === '') {
      return {
        ok: false,
        state: 'expired',
        reason: 'missing_session',
        message: 'Session is missing.',
      };
    }

    try {
      const { response } = await fetchBackend('/api/auth/session-state', {
        method: 'GET',
        headers: requestHeaders(false),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }

      const sessionProbeState = String(payload?.result?.state || '').trim().toLowerCase();
      if (response.ok && payload && payload.status === 'ok' && sessionProbeState === 'authenticated') {
        return { ok: true, state: 'online', reason: 'ready', message: '' };
      }
      if (response.ok && payload && payload.status === 'ok' && sessionProbeState === 'unauthenticated') {
        const failureReason = String(payload?.result?.reason || 'invalid_session').trim().toLowerCase();
        return { ok: false, state: 'expired', reason: failureReason, message: 'Session is no longer valid.' };
      }

      const code = String(payload?.error?.code || '').trim().toLowerCase();
      const detailReason = String(payload?.error?.details?.reason || '').trim().toLowerCase();
      const failureReason = detailReason || code || 'invalid_session';
      if (response.status === 403 || failureReason === 'role_not_allowed') {
        return { ok: false, state: 'blocked', reason: failureReason, message: extractErrorMessage(payload, 'Session is blocked by policy.') };
      }
      if (
        response.status === 401
        || response.status === 404
        || response.status === 410
        || ['missing_session', 'invalid_session', 'revoked_session', 'expired_session'].includes(failureReason)
      ) {
        return { ok: false, state: 'expired', reason: failureReason, message: extractErrorMessage(payload, 'Session is no longer valid.') };
      }
      if (response.status >= 500) {
        return { ok: false, state: 'retrying', reason: failureReason, message: extractErrorMessage(payload, 'Session validation is temporarily unavailable.') };
      }
      return { ok: false, state: 'blocked', reason: failureReason, message: extractErrorMessage(payload, 'Session is blocked.') };
    } catch (error) {
      return {
        ok: false,
        state: 'retrying',
        reason: 'network_error',
        message: error instanceof Error ? error.message : 'Session validation failed.',
      };
    }
  }

  function scheduleReconnect() {
    clearReconnectTimer();
    if (state.manualSocketClose || refs.connectionState.value === 'blocked' || refs.connectionState.value === 'expired') {
      return;
    }
    refs.reconnectAttempt.value += 1;
    refs.connectionState.value = 'retrying';
    refs.connectionReason.value = 'network_retry';

    const delay = reconnectDelayMs[Math.min(refs.reconnectAttempt.value - 1, reconnectDelayMs.length - 1)];
    state.reconnectTimer = setTimeout(() => {
      void connectSocket();
    }, delay);
  }

  async function connectSocket() {
    const generation = ++state.connectGeneration;
    const token = String(refs.sessionState.sessionToken || '').trim();
    if (token === '') {
      refs.connectionReason.value = 'missing_session';
      refs.connectionState.value = 'expired';
      return;
    }

    const previousSocket = refs.socketRef.value;
    if (previousSocket instanceof WebSocket) {
      try {
        previousSocket.close(1000, 'reconnect');
      } catch {
        // ignore
      }
      if (refs.socketRef.value === previousSocket) {
        refs.socketRef.value = null;
      }
    }

    clearReconnectTimer();
    clearPingTimer();
    state.manualSocketClose = false;
    refs.hasRealtimeRoomSync.value = false;
    refs.pendingAdmissionJoinRoomId.value = '';
    clearAdmissionGate();
    refs.lobbyNotificationState.hasSnapshot = false;
    hideLobbyJoinToast();
    refs.connectionState.value = 'retrying';
    refs.connectionReason.value = refs.reconnectAttempt.value > 0 ? 'network_retry' : 'probing_session';

    const sessionProbe = await probeWorkspaceSession();
    if (generation !== state.connectGeneration || state.manualSocketClose) {
      return;
    }
    if (!sessionProbe.ok) {
      refs.connectionReason.value = sessionProbe.reason;
      if (sessionProbe.state === 'retrying') {
        refs.workspaceNotice.value = '';
        refs.workspaceError.value = '';
      } else {
        refs.connectionState.value = sessionProbe.state;
        setNotice(sessionProbe.message, 'error');
        return;
      }
    }

    const orderedSocketOrigins = refs.resolveBackendWebSocketOriginCandidates();
    if (orderedSocketOrigins.length === 0) {
      refs.connectionState.value = 'blocked';
      refs.connectionReason.value = 'secure_transport_required';
      setNotice('Secure WebSocket transport is required. Configure HTTPS/WSS backend origins.', 'error');
      return;
    }

    const connectWithOriginAt = (originIndex) => {
      if (generation !== state.connectGeneration || state.manualSocketClose) return;
      if (originIndex >= orderedSocketOrigins.length) {
        refs.connectionState.value = 'retrying';
        refs.connectionReason.value = 'socket_unreachable';
        scheduleReconnect();
        return;
      }

      const socketOrigin = orderedSocketOrigins[originIndex] || '';
      const socketUrl = refs.socketUrlForRoom(refs.desiredRoomId.value, socketOrigin, refs.activeSocketCallId.value);
      if (!socketUrl) {
        connectWithOriginAt(originIndex + 1);
        return;
      }
      const socket = new WebSocket(socketUrl);
      if (generation !== state.connectGeneration || state.manualSocketClose) {
        try {
          socket.close(1000, 'stale_connect');
        } catch {
          // ignore
        }
        return;
      }

      refs.socketRef.value = socket;
      let opened = false;
      let failedOver = false;

      const failOverToNextOrigin = () => {
        if (failedOver) return;
        failedOver = true;
        if (refs.socketRef.value === socket) {
          refs.socketRef.value = null;
        }
        try {
          socket.close(1000, 'failover');
        } catch {
          // ignore
        }
        connectWithOriginAt(originIndex + 1);
      };

      socket.addEventListener('open', () => {
        if (generation !== state.connectGeneration || state.manualSocketClose) {
          try {
            socket.close(1000, 'stale_connect');
          } catch {
            // ignore
          }
          return;
        }

        opened = true;
        const isReconnectOpen = refs.reconnectAttempt.value > 0;
        refs.reconnectAttempt.value = 0;
        refs.connectionState.value = 'online';
        refs.connectionReason.value = 'ready';
        setBackendWebSocketOrigin(socketOrigin);
        clearErrors();
        startPingLoop();
        refs.clearMediaSecuritySignalCaches();
        refs.startMediaSecurityHandshakeWatchdog();
        console.info(
          '[KingRT] WS open - media-security signal caches cleared, full handshake will run',
          `reconnect=${isReconnectOpen ? '1' : '0'}`,
        );
        requestRoomSnapshot();
        if (refs.usersSourceMode.value === 'directory' && refs.activeTab.value === 'users') {
          void refreshUsersDirectory();
        }
        void syncControlStateToPeers();
        void syncModerationStateToPeers();
        void sendMediaSecuritySync(isReconnectOpen);
      });

      socket.addEventListener('message', handleSocketMessage);

      socket.addEventListener('error', () => {
        if (generation !== state.connectGeneration || state.manualSocketClose) return;
        if (!opened) {
          failOverToNextOrigin();
          return;
        }
        refs.connectionState.value = 'retrying';
        refs.connectionReason.value = 'socket_error';
      });

      socket.addEventListener('close', (event) => {
        if (generation !== state.connectGeneration) return;

        clearPingTimer();
        refs.clearMediaSecurityHandshakeWatchdog();
        if (refs.socketRef.value === socket) {
          refs.socketRef.value = null;
        }
        refs.hasRealtimeRoomSync.value = false;

        if (state.manualSocketClose) {
          return;
        }
        if (handleAssetVersionSocketClose(event)) {
          return;
        }

        const closeReason = String(event?.reason || '').trim().toLowerCase();
        if (closeReason === 'session_invalidated') {
          refs.connectionState.value = 'expired';
          refs.connectionReason.value = closeReason;
          state.manualSocketClose = true;
          return;
        }
        if (closeReason === 'auth_backend_error' || (event?.code === 1008 && closeReason !== '')) {
          refs.connectionState.value = 'blocked';
          refs.connectionReason.value = closeReason || 'blocked';
          state.manualSocketClose = true;
          return;
        }
        if (!opened) {
          failOverToNextOrigin();
          return;
        }

        refs.connectionState.value = 'retrying';
        refs.connectionReason.value = closeReason || 'socket_closed';
        scheduleReconnect();
      });
    };

    connectWithOriginAt(0);
  }

  return {
    clearPingTimer,
    clearReconnectTimer,
    closeSocket,
    connectSocket,
    handleSignalingEvent,
    handleSocketMessage,
    probeWorkspaceSession,
    removeParticipantLocallyAfterHangup,
    scheduleReconnect,
    startPingLoop,
  };
}
