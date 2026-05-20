import {
  shouldRequestSfuCompatibilityCodecFallback,
  shouldRequestSfuFullKeyframeForReason,
} from '../../sfu/recoveryReasons';
import { isScreenShareUserId } from '../../screenShareIdentity.js';
import { CALL_APP_PRESENCE_SIGNAL_TYPE } from '../../callApps/callAppPresenceRelay.js';
import { createCallWorkspaceMediaCapabilityBridge } from './mediaCapabilityPlanBridge.ts';
import { applyGossipTopologyFromRoomStatePayload } from './roomStateTopology';
import { strictPolicyEnabled } from './strictStabilityPolicy.ts';

const CONNECT_CYCLE_TIMEOUT_MS = 5 * 60 * 1000;
const CONTROL_LANE_SECOND_CONNECT_DELAY_MS = 5 * 1000;
const CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS = 1;
const TRANSIENT_BACKFILL_REASONS = Object.freeze([
  'access_session_binding_unavailable',
  'realtime_backfill_unavailable',
  'websocket_reconnect_backfill_unavailable',
]);
const STALE_TARGET_PRUNING_SIGNAL_TYPES = Object.freeze([
  'call/answer',
  'call/control-state',
  'call/ice',
  'call/media-quality-pressure',
  'call/offer',
]);
const INTERNAL_STATUS_SIGNAL_TYPES = Object.freeze([
  CALL_APP_PRESENCE_SIGNAL_TYPE,
  'call-app/grants-updated',
  'call/gossip-recovery',
  'call/gossip-topology',
  'call/media-quality-pressure',
  'call/moderation-state',
  'gossip/recovery/request',
]);
const CALL_WORKSPACE_FORCE_RELOAD_EVENT = 'kingrt:call-workspace-force-reload';
const EXPECTED_BROWSER_PAGE_EXIT_SOCKET_CLOSE_GRACE_MS = 15 * 1000;

let browserPageExitObservedAtMs = 0;
let browserPageExitCloseGuardBound = false;

function isInternalStatusSignalType(type) {
  return INTERNAL_STATUS_SIGNAL_TYPES.includes(String(type || '').trim().toLowerCase());
}

function markBrowserPageExitObserved(event = null) {
  if (event?.persisted === true) return;
  browserPageExitObservedAtMs = Date.now();
}

function clearBrowserPageExitObserved() {
  browserPageExitObservedAtMs = 0;
}

function bindBrowserPageExitCloseGuard() {
  if (browserPageExitCloseGuardBound || typeof window === 'undefined') return;
  browserPageExitCloseGuardBound = true;
  window.addEventListener(CALL_WORKSPACE_FORCE_RELOAD_EVENT, markBrowserPageExitObserved, { capture: true });
  window.addEventListener('beforeunload', markBrowserPageExitObserved, { capture: true });
  window.addEventListener('pagehide', markBrowserPageExitObserved, { capture: true });
  window.addEventListener('pageshow', clearBrowserPageExitObserved, { capture: true });
}

function isExpectedBrowserPageExitSocketClose(event, nowMs = Date.now()) {
  const closeCode = Number(event?.code || 0);
  const closeReason = String(event?.reason || '').trim();
  if (closeCode !== 1006 || closeReason !== '') return false;
  if (browserPageExitObservedAtMs <= 0) return false;
  return (nowMs - browserPageExitObservedAtMs) <= EXPECTED_BROWSER_PAGE_EXIT_SOCKET_CLOSE_GRACE_MS;
}

export function createCallWorkspaceSocketHelpers({
  callbacks,
  constants,
  refs,
  state,
}) {
  const {
    applyCallLayoutPayload,
    applyGossipTelemetryAck = () => false,
    applyGossipTopologyHint = () => false,
    applyLobbySnapshot,
    applyParticipantActivityPayload,
    applyReactionEvent,
    applyRemoteControlState,
    applyRoomSnapshot,
    applyTypingEvent,
    applyViewerContext,
    appendChatMessage,
    bootstrapChatArchive = () => false,
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
    handleCallAppPresenceSignal = () => false,
    handleGossipBinaryServerFrame = () => false,
    handleGossipNeighborSignal = () => false,
    handleGossipServerFrame = () => false,
    handleNativeSignalingEvent,
    hideLobbyJoinToast,
    mediaDebugLog,
    normalizeRoomId,
    pruneGossipNeighborForUserId = () => false,
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
    sendRoomJoin,
    setAdmissionGate,
    setBackendWebSocketOrigin,
    setNotice,
    stopLocalEncodingPipeline = () => {},
    syncControlStateToPeers,
    syncModerationStateToPeers,
    tryDirectJoinWithModeratorBypass,
  } = callbacks;

  const {
    callStateSignalTypes,
    strictStabilityPolicy,
  } = constants;
  const fallbackSfuTransportState = {
    sfuBrowserEncoderCompatibilityDisabledUntilMs: 0,
    sfuBrowserEncoderCompatibilityLastRequestedAtMs: 0,
    sfuBrowserEncoderCompatibilityReason: '',
    sfuBrowserEncoderCompatibilityRequestedByUserId: 0,
    sfuRemotePrimaryLayerRequestedUntilMs: 0,
    sfuRemoteLayerPreferenceLastAtMs: 0,
    sfuRemoteLayerPreferenceLastAction: '',
  };
  const mediaCapabilityPlanBridge = createCallWorkspaceMediaCapabilityBridge({
    callbacks: {
      captureClientDiagnostic,
    },
    refs: {
      ...refs,
      canStartRealtimeMediaSending,
    },
  });

  function normalizeParticipantUserId(row) {
    const normalizedUserId = Number(row?.userId || row?.user_id || row?.user?.id || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return 0;
    if (isScreenShareUserId(normalizedUserId)) return 0;
    return normalizedUserId;
  }

  function participantRows(refOrRows) {
    const rows = refOrRows && typeof refOrRows === 'object' && 'value' in refOrRows
      ? refOrRows.value
      : refOrRows;
    return Array.isArray(rows) ? rows : [];
  }

  function uniqueParticipantIds(rows) {
    return Array.from(new Set(
      participantRows(rows)
        .map((row) => normalizeParticipantUserId(row))
        .filter((userId) => Number.isInteger(userId) && userId > 0)
    )).sort((left, right) => left - right);
  }

  function currentUserParticipantId() {
    const userId = Number(refs.sessionState?.userId || 0);
    return Number.isInteger(userId) && userId > 0 ? userId : 0;
  }

  function expectedCallParticipantIds() {
    const expectedIds = uniqueParticipantIds(refs.participantUsers);
    if (expectedIds.length > 0) return expectedIds;
    const currentUserId = currentUserParticipantId();
    return currentUserId > 0 ? [currentUserId] : [];
  }

  function connectedCallParticipantIds() {
    return uniqueParticipantIds(refs.connectedParticipantUsers);
  }

  function participantIdsKey(ids) {
    return ids.join(',');
  }

  function allExpectedCallParticipantsConnected() {
    if (refs.hasRealtimeRoomSync?.value !== true) return false;
    const expectedIds = expectedCallParticipantIds();
    if (expectedIds.length < 1) return false;
    const connectedIds = new Set(connectedCallParticipantIds());
    return expectedIds.every((userId) => connectedIds.has(userId));
  }

  function canStartRealtimeMediaSending() {
    if (String(refs.connectionState?.value || '').trim().toLowerCase() !== 'online') return false;
    return allExpectedCallParticipantsConnected();
  }

  function controlLaneHasParticipantsForConnect() {
    if (refs.hasRealtimeRoomSync?.value !== true && state.connectCycleAuthoritativeRosterSeen !== true) return false;
    return expectedCallParticipantIds().length > 0;
  }

  function clearControlLaneReadinessTimer() {
    if (state.controlLaneReadinessTimer !== null && state.controlLaneReadinessTimer !== undefined) {
      clearTimeout(state.controlLaneReadinessTimer);
      state.controlLaneReadinessTimer = null;
    }
  }

  function observeExpectedParticipantRoster(reason = 'room_snapshot') {
    const expectedIds = expectedCallParticipantIds();
    const expectedKey = participantIdsKey(expectedIds);
    if (expectedKey === '') return false;
    const previousKnownIds = new Set(Array.isArray(state.connectCycleKnownParticipantIds)
      ? state.connectCycleKnownParticipantIds
      : []);
    const addedParticipantIds = expectedIds.filter((userId) => !previousKnownIds.has(userId));
    const authoritativeRosterSeen = state.connectCycleAuthoritativeRosterSeen === true;
    state.connectCycleKnownParticipantIds = expectedIds;
    state.connectCycleAuthoritativeRosterSeen = true;
    if (!authoritativeRosterSeen || state.connectCycleStarted !== true || addedParticipantIds.length < 1) {
      return false;
    }

    state.connectCycleParticipantGrowthPending = true;
    state.connectCycleParticipantGrowthKey = expectedKey;
    captureClientDiagnostic({
      category: 'realtime',
      level: 'info',
      eventType: 'websocket_one_shot_participant_join_observed',
      code: 'websocket_one_shot_participant_join_observed',
      message: 'A new call participant was observed; one additional websocket connect cycle is permitted.',
      payload: {
        reason,
        added_participant_ids: addedParticipantIds,
        expected_participant_ids: expectedIds,
        connected_participant_ids: connectedCallParticipantIds(),
        requested_room_id: refs.desiredRoomId.value,
        active_call_id: refs.activeSocketCallId.value,
      },
      immediate: true,
    });
    return true;
  }

  function sfuTransportStateForSocketLifecycle() {
    if (refs.sfuTransportState && typeof refs.sfuTransportState === 'object') {
      return refs.sfuTransportState;
    }
    return fallbackSfuTransportState;
  }

  function removeParticipantLocallyAfterHangup(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;

    resetPeerControlState(normalizedUserId);
    closeNativePeerConnection(normalizedUserId);
    removeSfuRemotePeersForUserId(normalizedUserId);
    const gossipNeighborPruned = pruneGossipNeighborForUserId(normalizedUserId, 'target_not_in_room');
    const participantsChanged = removeParticipantFromSnapshot(normalizedUserId);
    delete refs.participantActivityByUserId[normalizedUserId];
    delete refs.pinnedUsers[normalizedUserId];
    delete refs.mutedUsers[normalizedUserId];
    delete refs.callParticipantRoles[normalizedUserId];
    refreshUsersDirectoryPresentation();
    return participantsChanged || gossipNeighborPruned;
  }

  function recoverExpectedSignalingPublishFailure({
    failedCommandType,
    failedTargetUserId,
    signalingError,
  }) {
    const normalizedTargetUserId = Number(failedTargetUserId || 0);
    const normalizedError = String(signalingError || '').trim().toLowerCase();
    const targetIsKnown = Number.isInteger(normalizedTargetUserId) && normalizedTargetUserId > 0;
    const failedStaleTargetPruningSignal = STALE_TARGET_PRUNING_SIGNAL_TYPES.includes(failedCommandType);

    const shouldPruneTargetNotInRoom = targetIsKnown
      && normalizedError === 'target_not_in_room'
      && failedStaleTargetPruningSignal;
    if (shouldPruneTargetNotInRoom) {
      removeParticipantLocallyAfterHangup(normalizedTargetUserId);
    }

    requestRoomSnapshot();

    if (targetIsKnown && normalizedError !== 'target_not_in_room') {
      scheduleNativeOfferRetryForUserId(normalizedTargetUserId, 'signaling_publish_retry');
    }
  }

  function isExpectedStaleTargetPublishFailure(code, failedCommandType, signalingError, failedTargetUserId) {
    if (code !== 'signaling_publish_failed') return false;
    const normalizedTargetUserId = Number(failedTargetUserId || 0);
    if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return false;
    if (String(signalingError || '').trim().toLowerCase() !== 'target_not_in_room') return false;
    return STALE_TARGET_PRUNING_SIGNAL_TYPES.includes(failedCommandType);
  }

  function handleMediaQualityPressure(payloadBody, sender) {
    const kind = String(payloadBody?.kind || '').trim().toLowerCase();
    if (kind !== 'sfu-video-quality-pressure') return false;
    if (
      strictPolicyEnabled(strictStabilityPolicy, 'disableAutoQuality')
      || strictPolicyEnabled(strictStabilityPolicy, 'disableForcedKeyframeRecovery')
    ) {
      return true;
    }

    const nowMs = Date.now();
    const senderUserId = Number(sender?.user_id || 0);
    const requestedAction = String(payloadBody?.requested_action || '').trim().toLowerCase();
    const sourceReason = String(payloadBody?.reason || '').trim().toLowerCase();
    const requestedVideoLayer = String(payloadBody?.requested_video_layer || '').trim().toLowerCase();
    const requestedVideoQualityProfile = String(payloadBody?.requested_video_quality_profile || '').trim().toLowerCase();
    const compatibilityCodecRequested = shouldRequestSfuCompatibilityCodecFallback(requestedAction, payloadBody || {});
    const primaryLayerRequested = requestedAction === 'prefer_primary_video_layer' || requestedVideoLayer === 'primary';
    const thumbnailLayerRequested = requestedAction === 'prefer_thumbnail_video_layer' || requestedVideoLayer === 'thumbnail';
    const primaryLayerPreferenceTtlMs = 12000;
    const sfuTransportState = sfuTransportStateForSocketLifecycle();
    if (compatibilityCodecRequested) {
      const disableUntilMs = nowMs + 60000;
      sfuTransportState.sfuBrowserEncoderCompatibilityDisabledUntilMs = Math.max(
        Number(sfuTransportState.sfuBrowserEncoderCompatibilityDisabledUntilMs || 0),
        disableUntilMs,
      );
      sfuTransportState.sfuBrowserEncoderCompatibilityLastRequestedAtMs = nowMs;
      sfuTransportState.sfuBrowserEncoderCompatibilityRequestedByUserId = senderUserId;
      sfuTransportState.sfuBrowserEncoderCompatibilityReason = sourceReason || requestedAction;
    }
    if (primaryLayerRequested) {
      sfuTransportState.sfuRemotePrimaryLayerRequestedUntilMs = nowMs + primaryLayerPreferenceTtlMs;
    }
    sfuTransportState.sfuRemoteLayerPreferenceLastAtMs = nowMs;
    sfuTransportState.sfuRemoteLayerPreferenceLastAction = requestedAction;
    const fullKeyframeRequested = Boolean(payloadBody?.request_full_keyframe)
      || requestedAction === 'force_full_keyframe'
      || compatibilityCodecRequested
      || shouldRequestSfuFullKeyframeForReason(sourceReason);
    const keyframeOnlyRequest = fullKeyframeRequested
      && requestedAction === 'force_full_keyframe'
      && !compatibilityCodecRequested
      && !primaryLayerRequested
      && !thumbnailLayerRequested
      && requestedVideoQualityProfile === '';
    const forcedFullKeyframe = fullKeyframeRequested && typeof requestWlvcFullFrameKeyframe === 'function'
      ? requestWlvcFullFrameKeyframe(sourceReason || 'sfu_remote_quality_pressure', {
        ...payloadBody,
        senderUserId,
      })
      : false;
    const primaryLayerActive = nowMs < Number(sfuTransportState.sfuRemotePrimaryLayerRequestedUntilMs || 0);
    let downgraded = false;
    let upgraded = false;
    let ignoredThumbnailRequest = false;
    if (typeof downgradeSfuVideoQualityAfterEncodePressure === 'function') {
      if (compatibilityCodecRequested) {
        // Codec compatibility is handled by the publisher pipeline switching
        // away from WebCodecs; quality profile changes are a separate signal.
      } else if (keyframeOnlyRequest) {
        // Full-frame recovery already resets the encoder; avoid profile churn
        // that can hide the next keyframe behind another layer rollover.
      } else if (primaryLayerRequested) {
        upgraded = downgradeSfuVideoQualityAfterEncodePressure('sfu_remote_primary_layer_requested', {
          direction: 'up',
          requested_video_layer: 'primary',
          requested_video_quality_profile: requestedVideoQualityProfile || 'balanced',
        });
      } else if (thumbnailLayerRequested) {
        if (primaryLayerActive) {
          ignoredThumbnailRequest = true;
        } else {
          downgraded = downgradeSfuVideoQualityAfterEncodePressure('sfu_remote_thumbnail_layer_requested', {
            requested_video_layer: 'thumbnail',
            requested_video_quality_profile: requestedVideoQualityProfile || 'realtime',
          });
        }
      } else {
        downgraded = downgradeSfuVideoQualityAfterEncodePressure('sfu_remote_quality_pressure');
      }
    }
    captureClientDiagnostic({
      category: 'media',
      level: primaryLayerRequested ? 'info' : 'warning',
      eventType: 'sfu_remote_quality_pressure_received',
      code: 'sfu_remote_quality_pressure_received',
      message: 'A remote receiver requested an automatic outgoing SFU video layer or quality change.',
      payload: {
        sender_user_id: senderUserId,
        requested_action: requestedAction || 'downgrade_outgoing_video',
        requested_video_layer: requestedVideoLayer,
        requested_video_quality_profile: requestedVideoQualityProfile,
        source_reason: sourceReason,
        source_publisher_id: String(payloadBody?.publisher_id || '').trim(),
        full_keyframe_requested: forcedFullKeyframe,
        keyframe_only_request: keyframeOnlyRequest,
        compatibility_codec_requested: compatibilityCodecRequested,
        compatibility_disabled_until_ms: Number(sfuTransportState.sfuBrowserEncoderCompatibilityDisabledUntilMs || 0),
        primary_layer_active: primaryLayerActive,
        ignored_thumbnail_request: ignoredThumbnailRequest,
        downgraded,
        upgraded,
      },
      immediate: true,
    });
    return true;
  }

  function handleSignalingEvent(payload) {
    const type = String(payload?.type || '').trim().toLowerCase();
    if (!['call/offer', 'call/answer', 'call/ice', 'call/hangup', 'call/gossip-topology', 'call/gossip-recovery', 'gossip/recovery/request', ...callStateSignalTypes].includes(type)) return;

    const sender = typeof payload.sender === 'object' ? payload.sender : {};
    const senderUserId = Number(sender.user_id || 0);
    const payloadBody = typeof payload.payload === 'object' ? payload.payload : null;
    if (type === 'call/gossip-topology' || String(payloadBody?.kind || payloadBody?.type || '').trim().toLowerCase() === 'topology_hint') {
      if (applyGossipTopologyHint(payload)) return;
    }
    if (handleGossipNeighborSignal(type, senderUserId, payloadBody || {})) return;

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

    if (type === CALL_APP_PRESENCE_SIGNAL_TYPE) {
      handleCallAppPresenceSignal(payloadBody || {}, sender);
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

    if (isInternalStatusSignalType(type)) {
      return;
    }

    const senderName = String(sender.display_name || `User ${senderUserId || 'unknown'}`).trim();
    setNotice(`Received ${type.replace('call/', '')} from ${senderName}.`);
  }

  function handleSocketMessage(event) {
    if (event?.data instanceof ArrayBuffer) {
      handleGossipBinaryServerFrame(event.data);
      return;
    }
    if (typeof Blob !== 'undefined' && event?.data instanceof Blob) {
      void event.data.arrayBuffer().then((buffer) => {
        handleGossipBinaryServerFrame(buffer);
      }).catch(() => {});
      return;
    }

    let payload;
    try {
      payload = JSON.parse(String(event.data || ''));
    } catch {
      return;
    }

    if (!payload || typeof payload !== 'object') return;
    if (handleAssetVersionSocketPayload(payload)) return;
    const type = String(payload.type || '').trim().toLowerCase();
    if (type === '') return;

    if (type === 'topology_hint') {
      applyGossipTopologyHint(payload);
      return;
    }

    if (type === 'gossip/telemetry/ack') {
      applyGossipTelemetryAck(payload);
      return;
    }

    if (type === 'system/welcome') {
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
      void mediaCapabilityPlanBridge.sendClientCapabilities('system_welcome', payload)
        .then(() => mediaCapabilityPlanBridge.requestLocalMediaPublicationForLastPlan('system_welcome', payload));
      requestRoomSnapshot();
      if (refs.desiredRoomId.value !== welcomeRoom) {
        void sendRoomJoin(refs.desiredRoomId.value);
      }
      return;
    }

    if (type === 'room/snapshot') {
      applyRoomSnapshot(payload);
      observeExpectedParticipantRoster('room_snapshot');
      scheduleControlLaneSecondConnectReadinessCheck('room_snapshot');
      mediaCapabilityPlanBridge.handleRoomSnapshotMediaSessionPlan(payload);
      void mediaCapabilityPlanBridge.sendClientCapabilities('room_snapshot', payload)
        .then(() => mediaCapabilityPlanBridge.applyLocalMediaStateForLastPlan('room_snapshot', payload));
      applyGossipTopologyFromRoomStatePayload(payload, refs.sessionState?.userId, applyGossipTopologyHint);
      return;
    }

    if (type === 'client.capabilities.v1/ack') {
      mediaCapabilityPlanBridge.handleClientCapabilitiesAck(payload);
      void mediaCapabilityPlanBridge.applyLocalMediaStateForLastPlan('client_capabilities_ack', payload);
      return;
    }

    if (type === 'room/left') {
      const leftUserId = Number(payload?.participant?.user?.id || 0);
      if (Number.isInteger(leftUserId) && leftUserId > 0) removeParticipantLocallyAfterHangup(leftUserId);
      applyGossipTopologyFromRoomStatePayload(payload, refs.sessionState?.userId, applyGossipTopologyHint);
      requestRoomSnapshot();
      return;
    }
    if (type === 'room/joined') {
      applyGossipTopologyFromRoomStatePayload(payload, refs.sessionState?.userId, applyGossipTopologyHint);
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
      const rawSignalType = String(payload?.signal_type || '').trim().toLowerCase();
      const signalType = rawSignalType.replace('call/', '').trim() || 'signal';
      if (signalType === 'offer' && Number(payload?.sent_count ?? 0) === 0) {
        scheduleNativeOfferRetryForUserId(payload?.target_user_id, 'brokered_offer_unanswered');
      }
      return;
    }

    if (type === 'call/gossip-topology') {
      applyGossipTopologyHint(payload);
      return;
    }

    if (type === 'call/gossip-server-frame') {
      if (handleGossipServerFrame(payload)) return;
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
      const signalingError = String(payload?.details?.error || '').trim().toLowerCase();
      const expectedStaleTargetPublishFailure = isExpectedStaleTargetPublishFailure(
        code,
        failedCommandType,
        signalingError,
        failedTargetUserId,
      );
      if (code === 'signaling_publish_failed' && !expectedStaleTargetPublishFailure) {
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
      } else if (expectedStaleTargetPublishFailure) {
        captureClientDiagnostic({
          category: 'realtime',
          level: 'warning',
          eventType: 'realtime_signaling_stale_target_pruned',
          code: 'target_not_in_room',
          message: 'Realtime signaling pruned an expected stale target that is no longer in the room.',
          payload: {
            failed_command_type: failedCommandType,
            failed_target_user_id: failedTargetUserId,
            expected_stale_target_prune: true,
            signaling_reason: signalingError,
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
      const transientAuthBackendError = code === 'websocket_auth_temporarily_unavailable'
        || closeReason === 'auth_backend_error';
      const transientBackfillError = code === 'websocket_reconnect_backfill_unavailable'
        || TRANSIENT_BACKFILL_REASONS.includes(closeReason);
      const transientRealtimeConnectError = transientAuthBackendError || transientBackfillError;
      if (transientRealtimeConnectError) {
        const failureReason = transientAuthBackendError
          ? 'auth_backend_error'
          : (closeReason || code || 'websocket_reconnect_backfill_unavailable');
        failConnectCycleOnce({
          reason: failureReason,
          code: code || failureReason,
          message,
          payload: {
            details: payload?.details || {},
          },
        });
        return;
      }
      if (code === 'websocket_session_invalidated' || closeReason === 'session_invalidated') {
        state.manualSocketClose = true;
        refs.connectionReason.value = closeReason || 'session_invalidated';
        refs.connectionState.value = 'expired';
        closeSocketLocal();
      } else if (code === 'websocket_auth_failed' || code === 'websocket_forbidden' || closeReason === 'role_not_allowed') {
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
          signalingError,
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

  function normalizeConnectFailureReason(reason, fallback = 'network_error') {
    const normalized = String(reason || '').trim().toLowerCase();
    return normalized !== '' ? normalized : fallback;
  }

  function failConnectCycleOnce({
    reason = 'socket_closed',
    code = '',
    message = '',
    payload = {},
    closeActiveSocket = true,
  } = {}) {
    const failureReason = normalizeConnectFailureReason(reason, 'socket_closed');
    const failureMessage = String(message || '').trim()
      || 'websocket_one_shot_connect_failed';
    clearReconnectTimer();
    refs.connectionState.value = 'offline';
    refs.connectionReason.value = failureReason;
    state.manualSocketClose = true;
    try {
      stopLocalEncodingPipeline();
    } catch {
      // Terminal socket failures must not keep the publisher loop alive.
    }
    captureClientDiagnostic({
      category: 'realtime',
      level: 'warning',
      eventType: 'realtime_websocket_one_shot_failed',
      code: String(code || failureReason || 'realtime_websocket_one_shot_failed'),
      message: failureMessage,
      payload: {
        automatic_second_connect: false,
        retryable: false,
        second_connect_scheduled: false,
        next_connect_cycle_requires_new_participant: true,
        failure_reason: failureReason,
        local_encoding_stopped_after_socket_failure: true,
        requested_room_id: refs.desiredRoomId.value,
        active_call_id: refs.activeSocketCallId.value,
        expected_participant_ids: expectedCallParticipantIds(),
        connected_participant_ids: connectedCallParticipantIds(),
        ...payload,
      },
      immediate: true,
    });
    if (closeActiveSocket) {
      closeSocketLocal({ closeReason: failureReason });
    }
  }

  function connectCycleAdmission() {
    const expectedIds = expectedCallParticipantIds();
    const expectedKey = participantIdsKey(expectedIds);
    if (state.connectCycleStarted !== true) {
      return { allowed: true, reason: 'initial_connect_cycle', expectedIds, expectedKey };
    }
    if (state.connectCycleSecondConnectPending === true) {
      return { allowed: true, reason: 'control_lane_second_connect', expectedIds, expectedKey };
    }
    if (state.connectCycleParticipantGrowthPending === true) {
      return { allowed: true, reason: 'participant_joined', expectedIds, expectedKey };
    }
    return { allowed: false, reason: 'one_shot_cycle_already_used', expectedIds, expectedKey };
  }

  function scheduleControlLaneSecondConnect({
    reason = 'socket_closed',
    code = 'websocket_closed_second_connect_scheduled',
    message = '',
    payload = {},
    delayMs = CONTROL_LANE_SECOND_CONNECT_DELAY_MS,
  } = {}) {
    if (state.manualSocketClose) return false;
    if (!controlLaneHasParticipantsForConnect()) return false;
    if ((Number(state.connectCycleSecondConnectAttempts || 0)) >= CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS) return false;
    if (state.connectCycleSecondConnectPending === true || state.reconnectTimer !== null) return false;

    const retryReason = normalizeConnectFailureReason(reason, 'socket_closed');
    const retryDelayMs = Math.max(0, Number(delayMs) || 0);
    clearControlLaneReadinessTimer();
    state.connectCycleSecondConnectAttempts = Number(state.connectCycleSecondConnectAttempts || 0) + 1;
    state.connectCycleSecondConnectPending = true;
    refs.connectionState.value = 'retrying';
    refs.connectionReason.value = 'control_lane_second_connect_wait';
    try {
      stopLocalEncodingPipeline();
    } catch {
      // Encoding restarts only after the second websocket path reaches readiness.
    }
    captureClientDiagnostic({
      category: 'realtime',
      level: 'warning',
      eventType: 'realtime_websocket_control_lane_second_connect_scheduled',
      code,
      message: String(message || '').trim()
        || 'websocket_control_lane_second_connect_scheduled',
      payload: {
        automatic_second_connect: true,
        retryable: true,
        second_connect_scheduled: true,
        second_connect_delay_ms: retryDelayMs,
        second_connect_attempt: state.connectCycleSecondConnectAttempts,
        second_connect_max_attempts: CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS,
        failure_reason: retryReason,
        local_encoding_stopped_before_second_connect: true,
        requested_room_id: refs.desiredRoomId.value,
        active_call_id: refs.activeSocketCallId.value,
        expected_participant_ids: expectedCallParticipantIds(),
        connected_participant_ids: connectedCallParticipantIds(),
        ...payload,
      },
      immediate: true,
    });

    state.reconnectTimer = setTimeout(() => {
      state.reconnectTimer = null;
      if (state.manualSocketClose) return;
      void connectSocket();
    }, retryDelayMs);
    return true;
  }

  function scheduleControlLaneSecondConnectReadinessCheck(reason = 'room_snapshot') {
    if (state.manualSocketClose) return false;
    if (state.controlLaneReadinessTimer !== null && state.controlLaneReadinessTimer !== undefined) return false;
    if (state.connectCycleSecondConnectPending === true) return false;
    if ((Number(state.connectCycleSecondConnectAttempts || 0)) >= CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS) return false;
    if (!controlLaneHasParticipantsForConnect() || allExpectedCallParticipantsConnected()) return false;
    const generation = state.connectGeneration;
    state.controlLaneReadinessTimer = setTimeout(() => {
      state.controlLaneReadinessTimer = null;
      if (generation !== state.connectGeneration || state.manualSocketClose) return;
      if (allExpectedCallParticipantsConnected()) return;
      const activeSocket = refs.socketRef.value;
      if (activeSocket instanceof WebSocket && activeSocket.readyState === WebSocket.OPEN) {
        refs.connectionState.value = 'online';
        refs.connectionReason.value = 'ready';
        requestRoomSnapshot();
        captureClientDiagnostic({
          category: 'realtime',
          level: 'info',
          eventType: 'websocket_control_lane_open_socket_kept_after_unready_roster',
          code: 'websocket_control_lane_open_socket_kept_after_unready_roster',
          message: 'websocket_control_lane_open_socket_kept_after_unready_roster',
          payload: {
            automatic_second_connect: false,
            retryable: false,
            second_connect_scheduled: false,
            readiness_check_reason: String(reason || 'room_snapshot'),
            readiness_check_delay_ms: CONTROL_LANE_SECOND_CONNECT_DELAY_MS,
            requested_room_id: refs.desiredRoomId.value,
            active_call_id: refs.activeSocketCallId.value,
            expected_participant_ids: expectedCallParticipantIds(),
            connected_participant_ids: connectedCallParticipantIds(),
          },
          immediate: false,
        });
        return;
      }
      const secondConnectScheduled = scheduleControlLaneSecondConnect({
        reason: 'control_lane_participants_not_connected_after_5s',
        code: 'websocket_control_lane_second_connect_after_unready_roster',
        message: 'Control lane still has participants that are not connected after 5 seconds; one second connect will be attempted.',
        delayMs: 0,
        payload: {
          readiness_check_reason: String(reason || 'room_snapshot'),
          readiness_check_delay_ms: CONTROL_LANE_SECOND_CONNECT_DELAY_MS,
        },
      });
      if (!secondConnectScheduled) return;
    }, CONTROL_LANE_SECOND_CONNECT_DELAY_MS);
    return true;
  }

  function isAbnormalControlLaneClose(event, closeReason = '') {
    const closeCode = Number(event?.code || 0);
    const normalizedReason = String(closeReason || '').trim().toLowerCase();
    if (normalizedReason === 'client_leave' || normalizedReason === 'client_close') return false;
    if (normalizedReason === 'session_invalidated' || normalizedReason === 'stale_asset_version') return false;
    return closeCode === 1006 || closeCode === 1011 || normalizedReason === 'socket_error';
  }

  function shouldScheduleCloseSecondConnect({ opened = false, event = null, closeReason = '' } = {}) {
    if (opened !== true) {
      return !allExpectedCallParticipantsConnected();
    }
    return isAbnormalControlLaneClose(event, closeReason);
  }

  function suppressConnectCycle(reason = 'one_shot_cycle_already_used') {
    const normalizedReason = normalizeConnectFailureReason(reason, 'one_shot_cycle_already_used');
    captureClientDiagnostic({
      category: 'realtime',
      level: 'info',
      eventType: 'websocket_one_shot_connect_suppressed',
      code: 'websocket_one_shot_connect_suppressed',
      message: 'A websocket connect request was suppressed because this call already used its one-shot connect cycle.',
      payload: {
        automatic_second_connect: false,
        second_connect_scheduled: false,
        suppression_reason: normalizedReason,
        next_connect_cycle_requires_new_participant: true,
        expected_participant_ids: expectedCallParticipantIds(),
        connected_participant_ids: connectedCallParticipantIds(),
        requested_room_id: refs.desiredRoomId.value,
        active_call_id: refs.activeSocketCallId.value,
      },
      immediate: false,
    });
  }

  function observeExpectedBrowserPageExitSocketClose(event, closeReason = '', opened = false) {
    captureClientDiagnostic({
      category: 'realtime',
      level: 'warning',
      eventType: 'realtime_websocket_expected_browser_page_exit_close',
      code: 'websocket_expected_browser_page_exit_close',
      message: 'websocket_expected_browser_page_exit_close',
      payload: {
        automatic_second_connect: false,
        retryable: false,
        second_connect_scheduled: false,
        expected_browser_page_exit_close: true,
        next_connect_cycle_requires_new_participant: true,
        close_code: Number(event?.code || 0),
        close_reason: closeReason,
        opened,
        browser_page_exit_observed_at_ms: browserPageExitObservedAtMs,
        requested_room_id: refs.desiredRoomId.value,
        active_call_id: refs.activeSocketCallId.value,
        expected_participant_ids: expectedCallParticipantIds(),
        connected_participant_ids: connectedCallParticipantIds(),
      },
      immediate: true,
    });
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
    const requestedCloseReason = String(options?.closeReason || '').trim();
    clearReconnectTimer();
    clearControlLaneReadinessTimer();
    clearPingTimer();
    state.connectInFlight = false;
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
      socket.close(1000, requestedCloseReason || (leaveRoom ? 'client_leave' : 'client_close'));
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
      if (failureReason === 'auth_backend_error' || code === 'auth_session_probe_failed') {
        return { ok: false, state: 'retrying', reason: 'auth_backend_error', message: extractErrorMessage(payload, 'Session validation is temporarily unavailable.') };
      }
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

  async function connectSocket() {
    bindBrowserPageExitCloseGuard();
    if (state.connectInFlight && !state.manualSocketClose) return;
    const admission = connectCycleAdmission();
    if (!admission.allowed) {
      suppressConnectCycle(admission.reason);
      return;
    }
    const existingSocket = refs.socketRef.value;
    if (existingSocket instanceof WebSocket) {
      if (existingSocket.readyState === WebSocket.OPEN && !state.manualSocketClose) {
        refs.connectionState.value = 'online';
        refs.connectionReason.value = 'ready';
        captureClientDiagnostic({
          category: 'realtime',
          level: 'info',
          eventType: 'websocket_one_shot_existing_socket_kept',
          code: 'websocket_one_shot_existing_socket_kept',
          message: 'The existing websocket is already open; no new connect cycle was started.',
          payload: {
            automatic_second_connect: false,
            requested_room_id: refs.desiredRoomId.value,
            active_call_id: refs.activeSocketCallId.value,
          },
        });
        return;
      }
      if (existingSocket.readyState === WebSocket.CONNECTING && !state.manualSocketClose) {
        return;
      }
    }
    const generation = ++state.connectGeneration;
    state.connectInFlight = true;
    const finishConnectInFlight = () => {
      if (generation === state.connectGeneration) {
        state.connectInFlight = false;
      }
    };
    const token = String(refs.sessionState.sessionToken || '').trim();
    if (token === '') {
      finishConnectInFlight();
      refs.connectionReason.value = 'missing_session';
      refs.connectionState.value = 'expired';
      return;
    }
    state.connectCycleStarted = true;
    const isSecondConnect = admission.reason === 'control_lane_second_connect';
    state.connectCycleParticipantGrowthPending = false;
    state.connectCycleParticipantGrowthKey = '';
    state.connectCycleSecondConnectPending = false;
    if (!isSecondConnect) {
      state.connectCycleSecondConnectAttempts = 0;
    }
    state.connectCycleStartedAtMs = Date.now();
    state.connectCycleStartedReason = admission.reason;
    state.connectCycleStartedParticipantKey = admission.expectedKey;
    state.connectCycleKnownParticipantIds = admission.expectedIds;
    state.connectCycleAuthoritativeRosterSeen = refs.hasRealtimeRoomSync?.value === true;

    const previousSocket = refs.socketRef.value;
    if (previousSocket instanceof WebSocket) {
      try {
        previousSocket.close(1000, 'one_shot_cycle_replaced');
      } catch {
        // ignore
      }
      if (refs.socketRef.value === previousSocket) {
        refs.socketRef.value = null;
      }
    }

    clearReconnectTimer();
    clearControlLaneReadinessTimer();
    clearPingTimer();
    state.manualSocketClose = false;
    refs.hasRealtimeRoomSync.value = false;
    refs.pendingAdmissionJoinRoomId.value = '';
    clearAdmissionGate();
    refs.lobbyNotificationState.hasSnapshot = false;
    hideLobbyJoinToast();
    refs.connectionState.value = 'retrying';
    refs.connectionReason.value = 'probing_session';

    const sessionProbe = await probeWorkspaceSession();
    if (generation !== state.connectGeneration || state.manualSocketClose) {
      finishConnectInFlight();
      return;
    }
    if (!sessionProbe.ok) {
      refs.connectionReason.value = sessionProbe.reason;
      if (sessionProbe.state !== 'retrying') {
        refs.connectionState.value = sessionProbe.state;
        setNotice(sessionProbe.message, 'error');
        finishConnectInFlight();
        return;
      }
      failConnectCycleOnce({
        reason: sessionProbe.reason || 'session_probe_failed',
        code: 'websocket_session_probe_failed',
        message: sessionProbe.message,
        closeActiveSocket: false,
      });
      finishConnectInFlight();
      return;
    }

    const orderedSocketOrigins = refs.resolveBackendWebSocketOriginCandidates();
    if (orderedSocketOrigins.length === 0) {
      refs.connectionState.value = 'blocked';
      refs.connectionReason.value = 'secure_transport_required';
      setNotice('secure_websocket_transport_required', 'error');
      finishConnectInFlight();
      return;
    }

    const connectWithOriginAt = (originIndex) => {
      if (generation !== state.connectGeneration || state.manualSocketClose) return;
      if (originIndex >= orderedSocketOrigins.length) {
        failConnectCycleOnce({
          reason: 'socket_unreachable',
          code: 'websocket_connect_one_shot_failed',
          message: 'websocket_one_shot_connect_unreachable',
          payload: {
            origin_count: orderedSocketOrigins.length,
          },
          closeActiveSocket: false,
        });
        finishConnectInFlight();
        return;
      }

      const socketOrigin = orderedSocketOrigins[originIndex] || '';
      const socketUrl = refs.socketUrlForRoom(refs.desiredRoomId.value, socketOrigin, refs.activeSocketCallId.value);
      if (!socketUrl) {
        connectWithOriginAt(originIndex + 1);
        return;
      }
      const socket = new WebSocket(socketUrl);
      socket.binaryType = 'arraybuffer';
      if (generation !== state.connectGeneration || state.manualSocketClose) {
        try {
          socket.close(1000, 'stale_connect');
        } catch {
          // ignore
        }
        finishConnectInFlight();
        return;
      }

      refs.socketRef.value = socket;
      let opened = false;
      let negotiationTimer = null;
      const clearNegotiationTimer = () => {
        if (negotiationTimer === null) return;
        clearTimeout(negotiationTimer);
        negotiationTimer = null;
      };

      socket.addEventListener('open', () => {
        if (generation !== state.connectGeneration || state.manualSocketClose) {
          clearNegotiationTimer();
          try {
            socket.close(1000, 'stale_connect');
          } catch {
            // ignore
          }
          return;
        }

        const participantJoinConnect = admission.reason === 'participant_joined';
        opened = true;
        clearNegotiationTimer();
        finishConnectInFlight();
        refs.connectionState.value = 'online';
        refs.connectionReason.value = 'ready';
        setBackendWebSocketOrigin(socketOrigin);
        clearErrors();
        startPingLoop();
        captureClientDiagnostic({
          category: 'realtime',
          level: 'info',
          eventType: 'realtime_websocket_open',
          code: 'realtime_websocket_open',
          message: 'websocket_open_room_snapshot_requested',
          payload: {
            participant_join_connect: participantJoinConnect,
            requested_room_id: refs.desiredRoomId.value,
            active_call_id: refs.activeSocketCallId.value,
          },
        });
        requestRoomSnapshot();
        void bootstrapChatArchive('websocket_open');
        if (refs.usersSourceMode.value === 'directory' && refs.activeTab.value === 'users') {
          void refreshUsersDirectory();
        }
        void syncControlStateToPeers();
        void syncModerationStateToPeers();
      });

      socket.addEventListener('message', handleSocketMessage);

      socket.addEventListener('error', () => {
        if (generation !== state.connectGeneration || state.manualSocketClose) return;
        if (!opened) {
          // The browser will emit close after a failed handshake; the close path
          // records the one-shot failure without opening another websocket.
          return;
        }
        failConnectCycleOnce({
          reason: 'socket_error',
          code: 'websocket_error',
          message: 'websocket_one_shot_socket_error',
          closeActiveSocket: false,
        });
      });

      socket.addEventListener('close', (event) => {
        if (generation !== state.connectGeneration) return;

        clearNegotiationTimer();
        clearPingTimer();
        if (refs.socketRef.value === socket) {
          refs.socketRef.value = null;
        }
        refs.hasRealtimeRoomSync.value = false;

        if (state.manualSocketClose) {
          finishConnectInFlight();
          return;
        }
        if (handleAssetVersionSocketClose(event)) {
          finishConnectInFlight();
          return;
        }

        const closeReason = String(event?.reason || '').trim().toLowerCase();
        if (isExpectedBrowserPageExitSocketClose(event)) {
          observeExpectedBrowserPageExitSocketClose(event, closeReason, opened);
          finishConnectInFlight();
          return;
        }
        if (closeReason === 'session_invalidated') {
          refs.connectionState.value = 'expired';
          refs.connectionReason.value = closeReason;
          state.manualSocketClose = true;
          finishConnectInFlight();
          return;
        }
        if (closeReason === 'control_lane_second_connect' && state.connectCycleSecondConnectPending === true) {
          finishConnectInFlight();
          return;
        }
        if (event?.code === 1008 && closeReason !== '') {
          refs.connectionState.value = 'blocked';
          refs.connectionReason.value = closeReason;
          state.manualSocketClose = true;
          finishConnectInFlight();
          return;
        }
        const canScheduleCloseSecondConnect = shouldScheduleCloseSecondConnect({
          opened,
          event,
          closeReason,
        });
        if (canScheduleCloseSecondConnect && scheduleControlLaneSecondConnect({
          reason: closeReason || (event?.code === 1011 ? 'socket_internal_error' : 'socket_closed'),
          code: opened
            ? 'websocket_closed_control_lane_second_connect_scheduled'
            : 'websocket_open_control_lane_second_connect_scheduled',
          message: opened
            ? 'websocket_control_lane_second_connect_scheduled'
            : 'websocket_open_control_lane_second_connect_scheduled',
          payload: {
            close_code: Number(event?.code || 0),
            close_reason: closeReason,
            opened,
          },
        })) {
          finishConnectInFlight();
          return;
        }
        failConnectCycleOnce({
          reason: closeReason || (event?.code === 1011 ? 'socket_internal_error' : 'socket_closed'),
          code: 'websocket_closed_one_shot',
          message: opened
            ? 'websocket_one_shot_closed'
            : 'websocket_one_shot_open_failed',
          payload: {
            close_code: Number(event?.code || 0),
            close_reason: closeReason,
            opened,
          },
          closeActiveSocket: false,
        });
        finishConnectInFlight();
      });

      negotiationTimer = setTimeout(() => {
        if (generation !== state.connectGeneration || state.manualSocketClose) return;
        if (opened) return;
        failConnectCycleOnce({
          reason: 'socket_negotiation_timeout',
          code: 'websocket_negotiation_timeout',
          message: 'websocket_one_shot_negotiation_timeout',
          payload: {
            origin: socketOrigin,
            negotiation_timeout_ms: CONNECT_CYCLE_TIMEOUT_MS,
          },
          closeActiveSocket: false,
        });
        try {
          socket.close(1000, 'socket_negotiation_timeout');
        } catch {
          // ignore
        }
        finishConnectInFlight();
      }, CONNECT_CYCLE_TIMEOUT_MS);
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
    startPingLoop,
  };
}
