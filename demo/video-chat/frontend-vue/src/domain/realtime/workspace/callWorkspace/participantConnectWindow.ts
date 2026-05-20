import { isScreenShareUserId } from '../../screenShareIdentity.js';

export const CALL_PARTICIPANT_CONNECT_WINDOW_MS = 5 * 60 * 1000;

function normalizedUserId(value) {
  const userId = Number(value || 0);
  return Number.isInteger(userId) && userId > 0 ? userId : 0;
}

function sortedUniqueUserIds(values) {
  return Array.from(new Set(values
    .map((value) => normalizedUserId(value))
    .filter((userId) => userId > 0 && !isScreenShareUserId(userId))))
    .sort((left, right) => left - right);
}

function expectedParticipantIds(rows = [], currentUserId = 0) {
  const localUserId = normalizedUserId(currentUserId);
  return sortedUniqueUserIds((Array.isArray(rows) ? rows : [])
    .map((row) => row?.userId ?? row?.user_id ?? row?.id)
    .filter((userId) => normalizedUserId(userId) !== localUserId));
}

function peerUserId(peer) {
  return normalizedUserId(peer?.userId ?? peer?.user_id ?? peer?.publisherUserId ?? peer?.publisher_user_id);
}

function peerHasConnectedMedia(peer) {
  if (!peer || typeof peer !== 'object') return false;
  const mediaState = String(peer.mediaConnectionState || peer.media_connection_state || '').trim().toLowerCase();
  if (mediaState === 'live' || mediaState === 'connected') return true;
  if (Number(peer.frameCount || 0) > 0) return true;
  if (Number(peer.receivedFrameCount || 0) > 0) return true;
  if (Number(peer.lastFrameAtMs || 0) > 0) return true;
  return Number(peer.lastDecodedFrameAtMs || 0) > 0;
}

function connectedParticipantIds(remotePeersRef) {
  const remotePeers = remotePeersRef?.value instanceof Map ? remotePeersRef.value : new Map();
  const userIds = [];
  for (const peer of remotePeers.values()) {
    if (peerHasConnectedMedia(peer)) {
      userIds.push(peerUserId(peer));
    }
  }
  return sortedUniqueUserIds(userIds);
}

function difference(left, right) {
  const rightSet = new Set(right);
  return left.filter((entry) => !rightSet.has(entry));
}

export function createParticipantConnectWindow({
  callbacks = {},
  refs = {},
  windowMs = CALL_PARTICIPANT_CONNECT_WINDOW_MS,
} = {}) {
  const captureClientDiagnostic = typeof callbacks.captureClientDiagnostic === 'function'
    ? callbacks.captureClientDiagnostic
    : () => {};
  let knownExpectedIds = [];
  let active = false;
  let startedAtMs = 0;
  let untilMs = 0;
  let cycleSeq = 0;
  let lastDiagnosticKey = '';

  function diagnostic(eventType, level, message, payload = {}, immediate = false) {
    const key = [
      eventType,
      payload.reason || '',
      payload.expected_participant_ids?.join(',') || '',
      payload.missing_participant_ids?.join(',') || '',
      payload.cycle_seq || 0,
      payload.active ? 'active' : 'inactive',
    ].join('|');
    if (key === lastDiagnosticKey && eventType !== 'call_participant_connect_window_reconnect_blocked') return;
    lastDiagnosticKey = key;
    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      payload,
      immediate,
    });
  }

  function status(reason = 'participant_connect_window', nowMs = Date.now()) {
    const expectedIds = expectedParticipantIds(refs.connectedParticipantUsers?.value, refs.currentUserId?.value);
    const connectedIds = connectedParticipantIds(refs.remotePeersRef);
    const missingIds = difference(expectedIds, connectedIds);
    const addedIds = difference(expectedIds, knownExpectedIds);
    const removedIds = difference(knownExpectedIds, expectedIds);

    if (expectedIds.length === 0) {
      knownExpectedIds = [];
      active = false;
      startedAtMs = 0;
      untilMs = 0;
      return {
        active: false,
        reason,
        expectedParticipantIds: [],
        connectedParticipantIds: connectedIds,
        missingParticipantIds: [],
        remainingMs: 0,
        startedAtMs: 0,
        untilMs: 0,
        cycleSeq,
      };
    }

    if (addedIds.length > 0) {
      active = true;
      startedAtMs = nowMs;
      untilMs = nowMs + Math.max(1, Number(windowMs || CALL_PARTICIPANT_CONNECT_WINDOW_MS));
      cycleSeq += 1;
      diagnostic(
        'call_participant_connect_window_started',
        'info',
        'A participant-triggered media connect window started.',
        {
          reason,
          active: true,
          cycle_seq: cycleSeq,
          added_participant_ids: addedIds,
          expected_participant_ids: expectedIds,
          connected_participant_ids: connectedIds,
          missing_participant_ids: missingIds,
          connect_window_ms: Math.max(1, Number(windowMs || CALL_PARTICIPANT_CONNECT_WINDOW_MS)),
          connect_window_until_ms: untilMs,
        },
      );
    } else if (removedIds.length > 0 && active) {
      untilMs = Math.max(untilMs, nowMs);
    }
    knownExpectedIds = expectedIds;

    if (active && missingIds.length === 0) {
      active = false;
      diagnostic(
        'call_participant_connect_window_completed',
        'info',
        'All expected participants connected before the media connect window expired.',
        {
          reason,
          active: false,
          cycle_seq: cycleSeq,
          expected_participant_ids: expectedIds,
          connected_participant_ids: connectedIds,
          missing_participant_ids: missingIds,
          connect_window_started_at_ms: startedAtMs,
          connect_window_until_ms: untilMs,
        },
      );
    } else if (active && nowMs >= untilMs) {
      active = false;
      diagnostic(
        'call_participant_connect_window_expired',
        'warning',
        'The participant-triggered media connect window expired; video sending continues without hard socket reconnect.',
        {
          reason,
          active: false,
          cycle_seq: cycleSeq,
          expected_participant_ids: expectedIds,
          connected_participant_ids: connectedIds,
          missing_participant_ids: missingIds,
          connect_window_started_at_ms: startedAtMs,
          connect_window_until_ms: untilMs,
          video_sending_after_connect_window: true,
        },
        true,
      );
    }

    return {
      active,
      reason,
      expectedParticipantIds: expectedIds,
      connectedParticipantIds: connectedIds,
      missingParticipantIds: missingIds,
      remainingMs: active ? Math.max(0, untilMs - nowMs) : 0,
      startedAtMs,
      untilMs,
      cycleSeq,
    };
  }

  function canRequestReconnect({ reason = 'participant_connect_window', nowMs = Date.now(), payload = {} } = {}) {
    const currentStatus = status(reason, nowMs);
    if (currentStatus.active && currentStatus.remainingMs > 0) {
      return { allowed: true, ...currentStatus };
    }

    diagnostic(
      'call_participant_connect_window_reconnect_blocked',
      'warning',
      'A hard SFU reconnect was suppressed because no participant-triggered connect window is active.',
      {
        ...payload,
        reason,
        active: false,
        cycle_seq: currentStatus.cycleSeq,
        expected_participant_ids: currentStatus.expectedParticipantIds,
        connected_participant_ids: currentStatus.connectedParticipantIds,
        missing_participant_ids: currentStatus.missingParticipantIds,
        connect_window_started_at_ms: currentStatus.startedAtMs,
        connect_window_until_ms: currentStatus.untilMs,
        reconnect_allowed: false,
        video_sending_after_connect_window: true,
      },
      true,
    );
    return { allowed: false, ...currentStatus };
  }

  return {
    canRequestReconnect,
    sync: status,
  };
}
