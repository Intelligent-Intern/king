import { computed } from 'vue';
import { reportNativeAudioBridgeFailure as reportNativeAudioBridgeFailureEvent } from '../../native/audioBridgeFailureReporter';
import { mergeMediaSecurityParticipantIds } from './mediaSecurityParticipantSet';

export function createCallWorkspaceMediaSecurityRuntime({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    attachMediaSecurityNativeReceiversForPeer,
    captureClientDiagnostic,
    captureClientDiagnosticError,
    createMediaSecuritySession,
    createMediaSecurityTargetHelpers,
    ensureNativePeerConnection,
    extractDiagnosticMessage,
    mediaDebugLog,
    nativeAudioSecurityTelemetrySnapshot,
    requestRoomSnapshot,
    scheduleNativeAudioTrackRecovery,
    scheduleNativePeerAudioTrackDeadline,
    sendNativeOffer,
    sendSocketFrame,
    setNativePeerAudioBridgeState,
    shouldSyncNativeLocalTracksBeforeOffer,
    syncNativePeerLocalTracks,
    synchronizeNativePeerMediaElements,
  } = callbacks;
  const {
    mediaSecurityHandshakeTimeoutMs,
    mediaSecurityHandshakeRetryTimeoutsMs,
    mediaSecuritySfuTargetSettleMs,
    nativeFrameErrorLogCooldownMs,
    sfuRuntimeEnabled,
    MediaSecuritySession,
  } = constants;
  const {
    activeCallId,
    activeRoomId,
    activeSocketCallId,
    connectedParticipantUsers,
    currentUserId,
    isNativeWebRtcRuntimePath,
    isSocketOnline,
    isWlvcRuntimePath,
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    mediaSecuritySessionRef,
    mediaSecurityStateVersion,
    nativeAudioBridgeStatusVersion,
    nativePeerConnectionsRef,
  } = refs;

  function currentMediaSecurityRuntimePath() {
    if (isNativeWebRtcRuntimePath()) return 'webrtc_native';
    return 'wlvc_sfu';
  }

  function normalizeRemoteMediaSecurityUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (
      !Number.isInteger(normalizedUserId)
      || normalizedUserId <= 0
      || normalizedUserId === currentUserId.value
    ) {
      return 0;
    }
    return normalizedUserId;
  }

  function remoteMediaSecurityEligibleTargetIds() {
    const seen = new Set();
    const userIds = [];
    for (const userId of mediaSecurityEligibleTargetIds()) {
      const normalizedUserId = normalizeRemoteMediaSecurityUserId(userId);
      if (normalizedUserId <= 0 || seen.has(normalizedUserId)) continue;
      seen.add(normalizedUserId);
      userIds.push(normalizedUserId);
    }
    return userIds;
  }

  function remoteMediaSecurityTargetIds() {
    const seen = new Set();
    const userIds = [];
    for (const userId of mediaSecurityTargetIds()) {
      const normalizedUserId = normalizeRemoteMediaSecurityUserId(userId);
      if (normalizedUserId <= 0 || seen.has(normalizedUserId)) continue;
      seen.add(normalizedUserId);
      userIds.push(normalizedUserId);
    }
    return userIds;
  }

  function clearMediaSecurityResyncTimer() {
    if (state.mediaSecurityResyncTimer !== null) {
      clearTimeout(state.mediaSecurityResyncTimer);
      state.mediaSecurityResyncTimer = null;
    }
  }

  function clearMediaSecurityHandshakeWatchdog() {
    if (state.mediaSecurityHandshakeWatchdogTimer !== null) {
      clearInterval(state.mediaSecurityHandshakeWatchdogTimer);
      state.mediaSecurityHandshakeWatchdogTimer = null;
    }
    state.mediaSecurityHandshakeRetryingByUserId.clear();
    state.mediaSecurityHandshakeRetryCountByUserId.clear();
  }

  function mediaSecurityHandshakeRetryTimeoutMsForAttempt(retryAttempt) {
    const normalizedAttempt = Math.max(0, Number(retryAttempt || 0));
    const configuredTimeouts = Array.isArray(mediaSecurityHandshakeRetryTimeoutsMs)
      ? mediaSecurityHandshakeRetryTimeoutsMs
      : [];
    const timeoutIndex = Math.min(normalizedAttempt, Math.max(0, configuredTimeouts.length - 1));
    const configuredTimeout = Number(configuredTimeouts[timeoutIndex] || 0);
    if (Number.isFinite(configuredTimeout) && configuredTimeout > 0) return configuredTimeout;
    return Number(mediaSecurityHandshakeTimeoutMs || 6000);
  }

  async function checkMediaSecurityHandshakeTimeouts() {
    if (!isSocketOnline.value || currentUserId.value <= 0) return;
    const targetIds = remoteMediaSecurityEligibleTargetIds();
    if (targetIds.length <= 0) return;

    const session = ensureMediaSecuritySession();
    const nowMs = Date.now();
    for (const targetUserId of targetIds) {
      const normalizedTargetId = Number(targetUserId || 0);
      if (!Number.isInteger(normalizedTargetId) || normalizedTargetId <= 0) continue;
      if (state.mediaSecurityHandshakeRetryingByUserId.has(normalizedTargetId)) continue;

      const peer = session.peers instanceof Map ? session.peers.get(normalizedTargetId) : null;
      const peerState = String(peer?.state || '').trim().toLowerCase();
      if (peerState === 'active') {
        state.mediaSecurityHelloSentAtByUserId.delete(normalizedTargetId);
        state.mediaSecurityHandshakeRetryCountByUserId.delete(normalizedTargetId);
        continue;
      }

      const helloSentAt = Number(state.mediaSecurityHelloSentAtByUserId.get(normalizedTargetId) || 0);
      const retryAttempt = Number(state.mediaSecurityHandshakeRetryCountByUserId.get(normalizedTargetId) || 0);
      const retryTimeoutMs = mediaSecurityHandshakeRetryTimeoutMsForAttempt(retryAttempt);
      if (helloSentAt <= 0 || (nowMs - helloSentAt) < retryTimeoutMs) continue;

      state.mediaSecurityHandshakeRetryingByUserId.add(normalizedTargetId);
      state.mediaSecurityHandshakeRetryCountByUserId.set(normalizedTargetId, retryAttempt + 1);
      state.mediaSecurityHelloSentAtByUserId.delete(normalizedTargetId);
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'media_security_handshake_timeout',
        code: 'media_security_handshake_timeout',
        message: 'Media security handshake timed out and is being retried.',
        payload: {
          target_user_id: normalizedTargetId,
          peer_state: peerState,
          retry_attempt: retryAttempt + 1,
          retry_timeout_ms: retryTimeoutMs,
          elapsed_ms: nowMs - helloSentAt,
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });

      try {
        await sendMediaSecurityHello(normalizedTargetId, true);
        await sendMediaSecuritySenderKey(normalizedTargetId, true);
      } finally {
        state.mediaSecurityHandshakeRetryingByUserId.delete(normalizedTargetId);
      }
    }
  }

  function startMediaSecurityHandshakeWatchdog() {
    if (state.mediaSecurityHandshakeWatchdogTimer !== null) return;
    state.mediaSecurityHandshakeWatchdogTimer = setInterval(() => {
      void checkMediaSecurityHandshakeTimeouts();
    }, constants.mediaSecurityHandshakeWatchdogIntervalMs);
  }

  function scheduleMediaSecurityParticipantSync(reason = 'unspecified', forceRekey = false) {
    if (!isSocketOnline.value || currentUserId.value <= 0) return;
    if (remoteMediaSecurityEligibleTargetIds().length <= 0) return;

    state.mediaSecurityResyncForceRekey = state.mediaSecurityResyncForceRekey || Boolean(forceRekey);
    if (state.mediaSecurityResyncTimer !== null) return;

    state.mediaSecurityResyncTimer = setTimeout(() => {
      state.mediaSecurityResyncTimer = null;
      const shouldForceRekey = state.mediaSecurityResyncForceRekey;
      state.mediaSecurityResyncForceRekey = false;
      if (!isSocketOnline.value || currentUserId.value <= 0) return;
      if (remoteMediaSecurityEligibleTargetIds().length <= 0) return;
      mediaDebugLog('[MediaSecurity] scheduled participant sync', { reason, forceRekey: shouldForceRekey });
      void syncMediaSecurityWithParticipants(shouldForceRekey);
    }, 0);
  }

  function ensureMediaSecuritySession() {
    const context = {
      callId: activeSocketCallId.value || activeCallId.value,
      roomId: activeRoomId.value,
      userId: currentUserId.value,
      policy: 'preferred',
      logger: mediaDebugLog,
      onNativeFrameError: handleNativeMediaSecurityFrameError,
    };
    const existing = mediaSecuritySessionRef.value;
    const contextChanged = existing
      && (
        existing.callId !== context.callId
        || existing.roomId !== context.roomId
        || existing.userId !== context.userId
      );
    if (!existing || contextChanged) {
      state.mediaSecurityHelloSignalsSent.clear();
      state.mediaSecuritySenderKeySignalsSent.clear();
      state.mediaSecurityRecoveryLastByUserId.clear();
      state.mediaSecurityHandshakeRetryCountByUserId.clear();
      mediaSecuritySessionRef.value = createMediaSecuritySession(context);
      mediaSecurityStateVersion.value += 1;
      scheduleMediaSecurityParticipantSync('context_changed');
    } else {
      mediaSecuritySessionRef.value.updateContext(context);
    }
    return mediaSecuritySessionRef.value;
  }

  const {
    clearMediaSecuritySfuPublisherSeen,
    mediaSecurityEligibleTargetIds,
    mediaSecurityTargetIds,
    nativeAudioBridgeBlockedReason,
    nativeAudioBridgePeerStatusMessage,
    noteMediaSecuritySfuPublisherSeen,
  } = createMediaSecurityTargetHelpers({
    connectedParticipantUsers,
    currentUserId,
    isWlvcRuntimePath,
    nativePeerConnectionsRef,
    mediaRuntimeCapabilities,
    mediaSecuritySfuPublisherFirstSeenAtByUserId: state.mediaSecuritySfuPublisherFirstSeenAtByUserId,
    mediaSecuritySfuTargetSettleMs,
    sfuRuntimeEnabled,
    supportsNativeTransforms: () => MediaSecuritySession.supportsNativeTransforms(),
  });

  const nativeAudioSecurityBannerMessage = computed(() => {
    mediaSecurityStateVersion.value;
    const targetUserIds = remoteMediaSecurityTargetIds();
    if (targetUserIds.length <= 0) return '';
    const blockedReason = nativeAudioBridgeBlockedReason(targetUserIds);
    if (blockedReason !== '') return blockedReason;
    if (!shouldUseNativeAudioBridge()) return '';
    const session = mediaSecuritySessionRef.value;
    if (!session) {
      return 'Audio is waiting for the media-security handshake to become ready.';
    }
    const sessionStateValue = String(session?.state || '').trim().toLowerCase();
    if (sessionStateValue === 'blocked_capability') {
      return 'Audio is unavailable because protected media could not be initialized on this device.';
    }
    if (!session?.canProtectForTargets(targetUserIds)) {
      const blocked = targetUserIds.some((userId) => {
        const peer = session?.peers instanceof Map ? session.peers.get(userId) : null;
        return String(peer?.state || '').trim().toLowerCase() === 'blocked_capability';
      });
      if (blocked) {
        return 'Audio is muted because protected media is unavailable for at least one participant.';
      }
      return 'Audio is waiting for the media-security handshake to become ready.';
    }
    nativeAudioBridgeStatusVersion.value;
    const peerIssue = nativeAudioBridgePeerStatusMessage(targetUserIds, nativeAudioBridgeFailureMessage);
    if (peerIssue !== '') return peerIssue;
    return '';
  });

  function hintMediaSecuritySync(reason = 'unspecified', extraPayload = {}) {
    const nowMs = Date.now();
    if ((nowMs - state.mediaSecuritySyncHintLastAtMs) < 1000) return;
    state.mediaSecuritySyncHintLastAtMs = nowMs;

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'media_security_sync_hint',
      code: 'media_security_sync_hint',
      message: 'Media security sync was requested to recover SFU frame delivery.',
      payload: {
        reason: String(reason || 'unspecified'),
        target_user_ids: remoteMediaSecurityEligibleTargetIds(),
        media_runtime_path: mediaRuntimePath.value,
        ...extraPayload,
      },
    });
    void syncMediaSecurityWithParticipants();
  }

  function queueMediaSecuritySyncAfterInFlight(forceRekey = false) {
    state.mediaSecuritySyncPending = true;
    state.mediaSecuritySyncPendingForceRekey = Boolean(state.mediaSecuritySyncPendingForceRekey || forceRekey);
  }

  function canProtectCurrentSfuTargets() {
    const targetUserIds = remoteMediaSecurityEligibleTargetIds();
    if (targetUserIds.length <= 0) return true;
    return ensureMediaSecuritySession().canProtectForTargets(targetUserIds);
  }

  function canProtectCurrentNativeTargets(targetUserIds) {
    const normalizedTargetUserIds = Array.isArray(targetUserIds)
      ? targetUserIds
      : remoteMediaSecurityTargetIds();
    const remoteTargetUserIds = normalizedTargetUserIds
      .map((userId) => normalizeRemoteMediaSecurityUserId(userId))
      .filter((userId, index, userIds) => userId > 0 && userIds.indexOf(userId) === index);
    if (remoteTargetUserIds.length <= 0) return false;
    return ensureMediaSecuritySession().canProtectNativeForTargets(remoteTargetUserIds);
  }

  function shouldUseNativeAudioBridge() {
    if (!MediaSecuritySession.supportsNativeTransforms()) {
      return false;
    }
    return sfuRuntimeEnabled
      && isWlvcRuntimePath()
      && Boolean(mediaRuntimeCapabilities.value.stageB);
  }

  function shouldMaintainNativePeerConnections() {
    return isNativeWebRtcRuntimePath() || shouldUseNativeAudioBridge();
  }

  function shouldSendNativeTrackKind(kind) {
    const normalizedKind = String(kind || '').trim().toLowerCase();
    if (normalizedKind === 'audio') return shouldMaintainNativePeerConnections();
    if (normalizedKind === 'video') return isNativeWebRtcRuntimePath();
    return false;
  }

  function shouldBlockNativeRuntimeSignaling() {
    return sfuRuntimeEnabled && mediaRuntimePath.value === 'pending';
  }

  function nativeAudioBridgeFailureMessage() {
    return callbacks.defaultNativeAudioBridgeFailureMessage();
  }

  function reportNativeAudioBridgeFailure(peer, code, message, extraPayload = {}) {
    reportNativeAudioBridgeFailureEvent({
      captureClientDiagnostic,
      code,
      defaultMessage: nativeAudioBridgeFailureMessage(),
      extraPayload,
      isSocketOnline,
      mediaRuntimePath,
      message,
      nativeAudioSecurityTelemetrySnapshot,
      peer,
      setNativePeerAudioBridgeState,
      shouldUseNativeAudioBridge,
      syncMediaSecurityWithParticipants,
    });
  }

  function mediaSecurityHelloSignalKey(targetUserId, session) {
    return [
      activeRoomId.value,
      currentMediaSecurityRuntimePath(),
      Number(targetUserId || 0),
      Number(session?.epoch || 0),
      'hello',
    ].join(':');
  }

  function mediaSecuritySenderKeySignalKey(targetUserId, session) {
    return [
      activeRoomId.value,
      currentMediaSecurityRuntimePath(),
      Number(targetUserId || 0),
      Number(session?.epoch || 0),
      String(session?.senderKeyId || ''),
      'sender-key',
    ].join(':');
  }

  function incomingMediaSecurityHelloResponseKey(senderUserId, payloadBody, session) {
    const payload = payloadBody && typeof payloadBody === 'object' ? payloadBody : {};
    return [
      activeRoomId.value,
      currentMediaSecurityRuntimePath(),
      Number(senderUserId || 0),
      Number(payload.epoch || 0),
      String(payload.sender_key_id || ''),
      String(payload.device_id || ''),
      String(payload.public_key || ''),
      String(payload.hybrid_public_key || ''),
      Number(session?.epoch || 0),
      String(session?.senderKeyId || ''),
      'hello-response',
    ].join(':');
  }

  function shouldForceReplyToIncomingMediaSecurityHello(senderUserId, payloadBody, session) {
    const key = incomingMediaSecurityHelloResponseKey(senderUserId, payloadBody, session);
    if (state.mediaSecurityHelloSignalsSent.has(key)) return false;
    state.mediaSecurityHelloSignalsSent.add(key);
    return true;
  }

  function clearMediaSecuritySignalCaches() {
    state.mediaSecurityHelloSignalsSent.clear();
    state.mediaSecuritySenderKeySignalsSent.clear();
    state.mediaSecurityHelloSentAtByUserId.clear();
    state.mediaSecurityHandshakeRetryingByUserId.clear();
    state.mediaSecurityHandshakeRetryCountByUserId.clear();
  }

  async function sendMediaSecurityHello(targetUserId, force = false) {
    if (!isSocketOnline.value) return false;
    const normalizedTargetId = normalizeRemoteMediaSecurityUserId(targetUserId);
    if (normalizedTargetId <= 0) return false;
    const session = ensureMediaSecuritySession();
    const ready = await session.ensureReady();
    if (!ready) {
      mediaSecurityStateVersion.value += 1;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'media_security_hello_skipped',
        code: 'media_security_hello_not_ready',
        message: 'Media security hello could not be built because the local media-security session is not ready.',
        payload: {
          target_user_id: normalizedTargetId,
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });
      return false;
    }
    const key = mediaSecurityHelloSignalKey(normalizedTargetId, session);
    if (!force && state.mediaSecurityHelloSignalsSent.has(key)) return true;
    const signal = await session.buildHelloSignal(normalizedTargetId, currentMediaSecurityRuntimePath());
    if (!signal) {
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'media_security_hello_skipped',
        code: 'media_security_hello_skipped',
        message: 'Media security hello could not be built for the remote participant.',
        payload: {
          target_user_id: normalizedTargetId,
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });
      return false;
    }
    if (sendSocketFrame(signal)) {
      state.mediaSecurityHelloSignalsSent.add(key);
      state.mediaSecurityHelloSentAtByUserId.set(normalizedTargetId, Date.now());
      startMediaSecurityHandshakeWatchdog();
      return true;
    }
    captureClientDiagnostic({
      category: 'media',
      level: 'error',
      eventType: 'media_security_hello_send_failed',
      code: 'media_security_hello_send_failed',
      message: 'Media security hello could not be sent over the realtime websocket.',
      payload: {
        target_user_id: normalizedTargetId,
        media_runtime_path: mediaRuntimePath.value,
        security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
      },
      immediate: true,
    });
    return false;
  }

  async function sendMediaSecuritySenderKey(targetUserId, force = false) {
    if (!isSocketOnline.value) return false;
    const normalizedTargetId = normalizeRemoteMediaSecurityUserId(targetUserId);
    if (normalizedTargetId <= 0) return false;
    const session = ensureMediaSecuritySession();
    const ready = await session.ensureReady();
    if (!ready) {
      mediaSecurityStateVersion.value += 1;
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'media_security_sender_key_skipped',
        code: 'media_security_sender_key_skipped',
        message: 'Media security sender key generation is not ready yet.',
        payload: {
          target_user_id: normalizedTargetId,
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });
      return false;
    }
    const key = mediaSecuritySenderKeySignalKey(normalizedTargetId, session);
    if (!force && state.mediaSecuritySenderKeySignalsSent.has(key)) return true;
    let signal = null;
    try {
      signal = await session.buildSenderKeySignal(normalizedTargetId);
    } catch (error) {
      const errorCode = String(error?.message || '').trim().toLowerCase();
      if (errorCode === 'participant_set_mismatch') {
        const peer = session.peers instanceof Map ? session.peers.get(normalizedTargetId) : null;
        state.mediaSecurityHelloSignalsSent.delete(mediaSecurityHelloSignalKey(normalizedTargetId, session));
        state.mediaSecuritySenderKeySignalsSent.delete(key);
        state.mediaSecurityHelloSentAtByUserId.set(normalizedTargetId, Date.now());
        requestRoomSnapshot();
        startMediaSecurityHandshakeWatchdog();
        scheduleMediaSecurityParticipantSync('sender_key_participant_mismatch', true);
        await sendMediaSecurityHello(normalizedTargetId, true);
        captureClientDiagnostic({
          category: 'media',
          level: 'warning',
          eventType: 'media_security_sender_key_participant_mismatch',
          code: 'media_security_sender_key_participant_mismatch',
          message: 'Media security sender key was deferred because the participant transcript changed before key wrap completed.',
          payload: {
            target_user_id: normalizedTargetId,
            peer_state: String(peer?.state || ''),
            media_runtime_path: mediaRuntimePath.value,
            security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
          },
        });
        return false;
      }
      throw error;
    }
    if (!signal) {
      if (
        Number.isInteger(normalizedTargetId)
        && normalizedTargetId > 0
        && !state.mediaSecurityHelloSentAtByUserId.has(normalizedTargetId)
      ) {
        state.mediaSecurityHelloSentAtByUserId.set(normalizedTargetId, Date.now());
        startMediaSecurityHandshakeWatchdog();
      }
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'media_security_sender_key_not_ready',
        code: 'media_security_sender_key_not_ready',
        message: 'Media security sender key could not be built for the remote participant.',
        payload: {
          target_user_id: normalizedTargetId,
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });
      return false;
    }
    if (sendSocketFrame(signal)) {
      state.mediaSecuritySenderKeySignalsSent.add(key);
      return true;
    }
    captureClientDiagnostic({
      category: 'media',
      level: 'error',
      eventType: 'media_security_sender_key_send_failed',
      code: 'media_security_sender_key_send_failed',
      message: 'Media security sender key could not be sent over the realtime websocket.',
      payload: {
        target_user_id: normalizedTargetId,
        media_runtime_path: mediaRuntimePath.value,
        security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
      },
      immediate: true,
    });
    return false;
  }

  async function syncMediaSecurityWithParticipants(forceRekey = false) {
    if (!isSocketOnline.value || currentUserId.value <= 0) return;
    if (state.mediaSecuritySyncInFlight) {
      queueMediaSecuritySyncAfterInFlight(forceRekey);
      return;
    }
    state.mediaSecuritySyncInFlight = true;
    try {
      const session = ensureMediaSecuritySession();
      const eligibleTargetUserIds = remoteMediaSecurityEligibleTargetIds();
      if (eligibleTargetUserIds.length <= 0) {
        mediaSecurityStateVersion.value += 1;
        return;
      }
      const marked = session.markParticipantSet(eligibleTargetUserIds);
      mediaSecurityStateVersion.value += 1;
      if (forceRekey || marked.changed) {
        clearMediaSecuritySignalCaches();
        const ready = await session.ensureReady();
        if (!ready) {
          mediaSecurityStateVersion.value += 1;
          return;
        }
        await session.rotateSenderKey(forceRekey ? 'forced' : 'participant_change');
        mediaSecurityStateVersion.value += 1;
      }
      for (const targetUserId of marked.userIds) {
        await sendMediaSecurityHello(targetUserId);
        await sendMediaSecuritySenderKey(targetUserId);
      }
    } catch (error) {
      mediaDebugLog('[MediaSecurity] sync failed', error);
      captureClientDiagnosticError('media_security_sync_failed', error, {
        target_user_ids: remoteMediaSecurityTargetIds(),
        eligible_target_user_ids: remoteMediaSecurityEligibleTargetIds(),
        media_runtime_path: mediaRuntimePath.value,
      }, {
        code: 'media_security_sync_failed',
        immediate: true,
      });
    } finally {
      state.mediaSecuritySyncInFlight = false;
      if (state.mediaSecuritySyncPending) {
        const shouldForceRekey = Boolean(state.mediaSecuritySyncPendingForceRekey);
        state.mediaSecuritySyncPending = false;
        state.mediaSecuritySyncPendingForceRekey = false;
        scheduleMediaSecurityParticipantSync('pending_after_inflight', shouldForceRekey);
      }
    }
  }

  function shouldRecoverMediaSecurityFromFrameError(error) {
    const message = String(error?.message || error || '').trim().toLowerCase();
    return message.includes('wrong_key_id')
      || message.includes('wrong_epoch')
      || message.includes('participant_set_mismatch')
      || message.includes('downgrade_attempt');
  }

  function isRemoteNativeFrameError(direction, senderUserId = 0) {
    const normalizedDirection = String(direction || '').trim().toLowerCase();
    const normalizedSenderUserId = Number(senderUserId || 0);
    return normalizedDirection === 'receiver'
      && Number.isInteger(normalizedSenderUserId)
      && normalizedSenderUserId > 0
      && normalizedSenderUserId !== currentUserId.value;
  }

  function nativeSenderKeyAvailable(senderUserId = 0) {
    const normalizedSenderUserId = Number(senderUserId || 0);
    if (
      !Number.isInteger(normalizedSenderUserId)
      || normalizedSenderUserId <= 0
      || normalizedSenderUserId === currentUserId.value
    ) {
      return false;
    }
    return ensureMediaSecuritySession().canProtectNativeForTargets([normalizedSenderUserId]);
  }

  function shouldTreatNativeFrameErrorAsBootstrapDrop(direction, error, senderUserId = 0) {
    if (!isRemoteNativeFrameError(direction, senderUserId)) return false;
    const message = String(error?.message || error || '').trim().toLowerCase();
    if (message === 'malformed_protected_frame') {
      return !shouldMaintainNativePeerConnections() || !nativeSenderKeyAvailable(senderUserId);
    }
    return shouldRecoverMediaSecurityFromFrameError(error)
      && !nativeSenderKeyAvailable(senderUserId);
  }

  function shouldTreatNativeFrameErrorAsTransient(direction, error, senderUserId = 0) {
    const message = String(error?.message || error || '').trim().toLowerCase();
    return isRemoteNativeFrameError(direction, senderUserId)
      && shouldMaintainNativePeerConnections()
      && nativeSenderKeyAvailable(senderUserId)
      && message === 'malformed_protected_frame';
  }

  function shouldTreatNativeFrameErrorAsRecoverableDrop(direction, error, senderUserId = 0) {
    return isRemoteNativeFrameError(direction, senderUserId)
      && shouldRecoverMediaSecurityFromFrameError(error);
  }

  function shouldSendTransportOnlySfuFrame(error) {
    const message = String(error?.message || error || '').trim().toLowerCase();
    return message.includes('unsupported_capability')
      || message.includes('blocked_capability');
  }

  function recoverMediaSecurityForPublisher(publisherUserId) {
    const normalizedUserId = Number(publisherUserId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
    const nowMs = Date.now();
    const lastRecoveryMs = Number(state.mediaSecurityRecoveryLastByUserId.get(normalizedUserId) || 0);
    if ((nowMs - lastRecoveryMs) < 3000) return;
    state.mediaSecurityRecoveryLastByUserId.set(normalizedUserId, nowMs);

    requestRoomSnapshot();
    void (async () => {
      await sendMediaSecurityHello(normalizedUserId, true);
      await sendMediaSecuritySenderKey(normalizedUserId, true);
      await syncMediaSecurityWithParticipants();
    })();
  }

  function setNativeAudioBridgeQuarantine(userId, reason = 'malformed_protected_frame') {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    state.nativeAudioBridgeQuarantineByUserId.set(normalizedUserId, {
      reason: String(reason || 'malformed_protected_frame').trim().toLowerCase() || 'malformed_protected_frame',
      sinceMs: Date.now(),
    });
    return true;
  }

  function clearNativeAudioBridgeQuarantine(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    return state.nativeAudioBridgeQuarantineByUserId.delete(normalizedUserId);
  }

  function nativeAudioBridgeIsQuarantined(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    return state.nativeAudioBridgeQuarantineByUserId.has(normalizedUserId);
  }

  function shouldReleaseNativeAudioBridgeQuarantineForReason(reason = 'security_ready') {
    const normalizedReason = String(reason || '').trim().toLowerCase();
    return normalizedReason === 'sender_key_accepted'
      || normalizedReason === 'native_audio_track_recovery_rejoin';
  }

  function shouldBypassNativeAudioProtectionForPeer(userId) {
    if (!shouldUseNativeAudioBridge()) return false;
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) {
      return false;
    }
    return nativeAudioBridgeIsQuarantined(normalizedUserId);
  }

  async function ensureNativeAudioBridgeSecurityReady(peer, reason = 'native_audio_negotiation') {
    const targetUserId = Number(peer?.userId || 0);
    if (!shouldUseNativeAudioBridge()) return true;
    if (!Number.isInteger(targetUserId) || targetUserId <= 0 || targetUserId === currentUserId.value) return false;
    if (!isSocketOnline.value) return false;

    const session = ensureMediaSecuritySession();
    const ready = await session.ensureReady();
    mediaSecurityStateVersion.value += 1;
    if (!ready) return false;
    if (session.canProtectNativeForTargets([targetUserId])) return true;

    await sendMediaSecurityHello(targetUserId, true);
    await sendMediaSecuritySenderKey(targetUserId, true);
    await syncMediaSecurityWithParticipants();

    const secured = session.canProtectNativeForTargets([targetUserId]);
    if (!secured) {
      setNativePeerAudioBridgeState(peer, 'waiting_security', '');
      captureClientDiagnostic({
        category: 'media',
        level: 'warning',
        eventType: 'native_audio_waiting_for_media_security',
        code: 'native_audio_waiting_for_media_security',
        message: 'Native protected audio negotiation is waiting for the media-security handshake.',
        payload: {
          target_user_id: targetUserId,
          reason: String(reason || 'native_audio_negotiation'),
          media_runtime_path: mediaRuntimePath.value,
          security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
        },
      });
    }
    return secured;
  }

  function resyncNativeAudioBridgePeerAfterSecurityReady(userId, reason = 'security_ready', forceOffer = false) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return false;
    if (!shouldMaintainNativePeerConnections()) return false;
    if (shouldUseNativeAudioBridge() && !ensureMediaSecuritySession().canProtectNativeForTargets([normalizedUserId])) return false;
    if (
      nativeAudioBridgeIsQuarantined(normalizedUserId)
      && !shouldReleaseNativeAudioBridgeQuarantineForReason(reason)
    ) {
      return false;
    }
    if (nativeAudioBridgeIsQuarantined(normalizedUserId)) {
      clearNativeAudioBridgeQuarantine(normalizedUserId);
    }

    const peer = nativePeerConnectionsRef.value.get(normalizedUserId) || ensureNativePeerConnection(normalizedUserId);
    if (!peer?.pc || peer.pc.signalingState === 'closed') return false;
    attachMediaSecurityNativeReceiversForPeer(peer);
    if (!forceOffer && !shouldSyncNativeLocalTracksBeforeOffer(peer)) return true;

    void syncNativePeerLocalTracks(peer)
      .then(() => {
        attachMediaSecurityNativeReceiversForPeer(peer);
        synchronizeNativePeerMediaElements(peer);
        scheduleNativePeerAudioTrackDeadline(peer);
        if (peer.negotiating) {
          peer.needsRenegotiate = true;
          return;
        }
        if (peer.initiator || forceOffer) {
          void sendNativeOffer(peer);
        }
      })
      .catch((error) => mediaDebugLog('[WebRTC] native audio bridge resync failed', reason, error));
    return true;
  }

  function handleNativeMediaSecurityFrameError(event = {}) {
    const direction = String(event?.direction || '').trim().toLowerCase();
    const error = event?.error;
    const senderUserId = Number(event?.senderUserId || event?.sender_user_id || 0);
    const trackId = String(event?.trackId || event?.track_id || '').trim();
    const errorMessage = extractDiagnosticMessage(error, 'Native protected media frame could not be processed.');
    const code = direction === 'receiver'
      ? 'native_media_frame_decrypt_failed'
      : 'native_media_frame_encrypt_failed';
    const bootstrapFrameDrop = shouldTreatNativeFrameErrorAsBootstrapDrop(direction, error, senderUserId);
    const transientFrameDrop = shouldTreatNativeFrameErrorAsTransient(direction, error, senderUserId);
    const recoverableFrameDrop = transientFrameDrop
      || shouldTreatNativeFrameErrorAsRecoverableDrop(direction, error, senderUserId);
    const logKey = [code, direction || 'unknown', senderUserId || 0, trackId || 'n/a', errorMessage].join(':');
    const nowMs = Date.now();
    const lastLogMs = Number(state.nativeFrameErrorLastLogByKey.get(logKey) || 0);
    const shouldLog = (nowMs - lastLogMs) >= nativeFrameErrorLogCooldownMs;
    if (shouldLog) {
      state.nativeFrameErrorLastLogByKey.set(logKey, nowMs);
    }

    if (shouldLog && !bootstrapFrameDrop) {
      captureClientDiagnostic({
        category: 'media',
        level: recoverableFrameDrop ? 'warning' : 'error',
        eventType: code,
        code,
        message: errorMessage,
        payload: {
          direction,
          sender_user_id: senderUserId,
          track_id: trackId,
          media_runtime_path: mediaRuntimePath.value,
          security: nativeAudioSecurityTelemetrySnapshot(),
          recoverable_frame_drop: recoverableFrameDrop,
        },
        immediate: !recoverableFrameDrop,
      });
    }

    if (
      direction === 'receiver'
      && shouldUseNativeAudioBridge()
      && errorMessage === 'malformed_protected_frame'
      && Number.isInteger(senderUserId)
      && senderUserId > 0
      && senderUserId !== currentUserId.value
    ) {
      setNativeAudioBridgeQuarantine(senderUserId, 'malformed_protected_frame');
      const peer = nativePeerConnectionsRef.value.get(senderUserId) || null;
      if (peer) {
        setNativePeerAudioBridgeState(
          peer,
          'waiting_security',
          'Protected audio bridge paused because the remote native stream is not yet wrapped.'
        );
      }
    }

    const shouldRecoverReceiver = shouldRecoverMediaSecurityFromFrameError(error) || transientFrameDrop;
    if (direction !== 'receiver' || !shouldRecoverReceiver) return;
    if (!Number.isInteger(senderUserId) || senderUserId <= 0 || senderUserId === currentUserId.value) return;
    recoverMediaSecurityForPublisher(senderUserId);
    if (transientFrameDrop) {
      const peer = nativePeerConnectionsRef.value.get(senderUserId);
      if (peer && scheduleNativeAudioTrackRecovery(peer, 'native_media_security_malformed_frame', {
        requireMissingTrack: false,
      })) {
        return;
      }
    }
    resyncNativeAudioBridgePeerAfterSecurityReady(senderUserId, 'native_media_frame_error');
  }

  async function handleMediaSecuritySignal(type, senderUserId, payloadBody) {
    const normalizedSenderUserId = normalizeRemoteMediaSecurityUserId(senderUserId);
    if (normalizedSenderUserId <= 0) return;
    const session = ensureMediaSecuritySession();

    try {
      if (type === 'media-security/hello') {
        const marked = session.markParticipantSet(mergeMediaSecurityParticipantIds(
          session,
          remoteMediaSecurityTargetIds(),
          normalizedSenderUserId,
        ));
        if (marked.changed) {
          clearMediaSecuritySignalCaches();
          mediaSecurityStateVersion.value += 1;
          scheduleMediaSecurityParticipantSync('hello_participant_set_changed', true);
        }
        const accepted = await session.handleHelloSignal(normalizedSenderUserId, payloadBody || {});
        mediaSecurityStateVersion.value += 1;
        if (accepted) {
          const forceReply = shouldForceReplyToIncomingMediaSecurityHello(
            normalizedSenderUserId,
            payloadBody || {},
            session,
          );
          await sendMediaSecurityHello(normalizedSenderUserId, forceReply);
          await sendMediaSecuritySenderKey(normalizedSenderUserId, forceReply);
          if (remoteMediaSecurityTargetIds().includes(normalizedSenderUserId)) {
            scheduleMediaSecurityParticipantSync('hello_accepted');
          }
          resyncNativeAudioBridgePeerAfterSecurityReady(normalizedSenderUserId, 'hello_accepted');
        }
        return;
      }

      if (type === 'media-security/sender-key') {
        const accepted = await session.handleSenderKeySignal(normalizedSenderUserId, payloadBody || {});
        mediaSecurityStateVersion.value += 1;
        if (accepted) {
          state.mediaSecurityHelloSentAtByUserId.delete(normalizedSenderUserId);
          state.mediaSecurityHandshakeRetryingByUserId.delete(normalizedSenderUserId);
          state.mediaSecurityHandshakeRetryCountByUserId.delete(normalizedSenderUserId);
          resyncNativeAudioBridgePeerAfterSecurityReady(normalizedSenderUserId, 'sender_key_accepted');
        }
        if (!accepted && remoteMediaSecurityTargetIds().includes(normalizedSenderUserId)) {
          scheduleMediaSecurityParticipantSync('sender_key_pending');
        }
      }
    } catch (error) {
      mediaDebugLog('[MediaSecurity] signaling failed', error);
      const errorCode = String(error?.message || error || '').trim().toLowerCase();
      if (
        (errorCode === 'participant_set_mismatch' || errorCode === 'downgrade_attempt')
        && remoteMediaSecurityTargetIds().includes(normalizedSenderUserId)
      ) {
        const shouldForceRekeyAfterSignalFailure = true;
        if (errorCode === 'downgrade_attempt') {
          session.markPeerRemoved?.(normalizedSenderUserId);
        }
        state.mediaSecurityHelloSignalsSent.delete(mediaSecurityHelloSignalKey(normalizedSenderUserId, session));
        state.mediaSecuritySenderKeySignalsSent.delete(mediaSecuritySenderKeySignalKey(normalizedSenderUserId, session));
        state.mediaSecurityHelloSentAtByUserId.set(normalizedSenderUserId, Date.now());
        requestRoomSnapshot();
        scheduleMediaSecurityParticipantSync('signal_failed_reconnect', shouldForceRekeyAfterSignalFailure);
        startMediaSecurityHandshakeWatchdog();
      }
      captureClientDiagnosticError('media_security_signal_failed', error, {
        signal_type: type,
        sender_user_id: normalizedSenderUserId,
        media_runtime_path: mediaRuntimePath.value,
        security: session.telemetrySnapshot(currentMediaSecurityRuntimePath()),
      }, {
        code: 'media_security_signal_failed',
        immediate: true,
      });
    }
  }

  return {
    canProtectCurrentNativeTargets,
    canProtectCurrentSfuTargets,
    checkMediaSecurityHandshakeTimeouts,
    clearMediaSecurityHandshakeWatchdog,
    clearMediaSecurityResyncTimer,
    clearMediaSecuritySfuPublisherSeen,
    clearMediaSecuritySignalCaches,
    clearNativeAudioBridgeQuarantine,
    currentMediaSecurityRuntimePath,
    ensureMediaSecuritySession,
    ensureNativeAudioBridgeSecurityReady,
    handleMediaSecuritySignal,
    handleNativeMediaSecurityFrameError,
    hintMediaSecuritySync,
    mediaSecurityEligibleTargetIds,
    mediaSecurityTargetIds,
    nativeAudioBridgeFailureMessage,
    nativeAudioBridgeIsQuarantined,
    nativeAudioSecurityBannerMessage,
    noteMediaSecuritySfuPublisherSeen,
    recoverMediaSecurityForPublisher,
    reportNativeAudioBridgeFailure,
    resyncNativeAudioBridgePeerAfterSecurityReady,
    scheduleMediaSecurityParticipantSync,
    sendMediaSecurityHello,
    sendMediaSecuritySenderKey,
    shouldBlockNativeRuntimeSignaling,
    shouldBypassNativeAudioProtectionForPeer,
    shouldMaintainNativePeerConnections,
    shouldRecoverMediaSecurityFromFrameError,
    shouldSendNativeTrackKind,
    shouldSendTransportOnlySfuFrame,
    shouldTreatNativeFrameErrorAsTransient,
    shouldUseNativeAudioBridge,
    startMediaSecurityHandshakeWatchdog,
    syncMediaSecurityWithParticipants,
  };
}
