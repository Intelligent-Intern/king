const DEFAULT_CONNECT_QUORUM_WINDOW_MS = 5 * 60 * 1000;
const MAX_DIAGNOSTIC_IDS = 8;

function stringValue(value: any, fallback = ''): string {
  const text = String(value ?? '').trim();
  return text === '' ? fallback : text.slice(0, 160);
}

function positiveInt(value: any, fallback = 0): number {
  const numeric = Number(value);
  return Number.isFinite(numeric) && numeric > 0 ? Math.floor(numeric) : fallback;
}

function nowMsFromOptions(options: Record<string, any> = {}): number {
  return positiveInt(options.nowMs ?? options.now_ms, Date.now());
}

function uniqueSorted(values: any[]): string[] {
  return Array.from(new Set(
    values
      .map((value) => stringValue(value))
      .filter((value) => value !== '')
  )).sort();
}

function mediaSessionPlan(snapshot: Record<string, any> = {}) {
  const plan = snapshot.media_session_plan ?? snapshot.mediaSessionPlan ?? null;
  return plan && typeof plan === 'object' ? plan : {};
}

function snapshotParticipants(snapshot: Record<string, any> = {}) {
  return Array.isArray(snapshot.participants) ? snapshot.participants : [];
}

function participantConnectionIds(snapshot: Record<string, any> = {}) {
  return uniqueSorted(snapshotParticipants(snapshot).map((participant) => (
    participant?.connection_id ?? participant?.connectionId
  )));
}

function participantPeerIds(snapshot: Record<string, any> = {}) {
  return uniqueSorted(snapshotParticipants(snapshot).map((participant) => (
    participant?.user?.id ?? participant?.user_id ?? participant?.userId
  )));
}

function planParticipantSessionIds(snapshot: Record<string, any> = {}) {
  const plan = mediaSessionPlan(snapshot);
  return uniqueSorted((Array.isArray(plan.participants) ? plan.participants : []).map((participant) => (
    participant?.participant_session_id ?? participant?.participantSessionId
  )));
}

function topologyAdmittedPeerIds(snapshot: Record<string, any> = {}) {
  const topology = snapshot.gossip_topology ?? snapshot.gossipTopology ?? {};
  const admittedPeers = Array.isArray(topology?.admitted_peers)
    ? topology.admitted_peers
    : (Array.isArray(topology?.admittedPeers) ? topology.admittedPeers : []);
  return uniqueSorted(admittedPeers.map((peer) => peer?.peer_id ?? peer?.peerId ?? peer?.user_id ?? peer?.userId));
}

function expectedParticipantKeys(snapshot: Record<string, any> = {}) {
  const planSessions = planParticipantSessionIds(snapshot);
  if (planSessions.length > 0) {
    return { ids: planSessions, source: 'media_session_plan.participants', connectedIds: participantConnectionIds(snapshot) };
  }

  const participantSessions = participantConnectionIds(snapshot);
  if (participantSessions.length > 0) {
    return { ids: participantSessions, source: 'room_snapshot.participants', connectedIds: participantSessions };
  }

  const topologyPeers = topologyAdmittedPeerIds(snapshot);
  return { ids: topologyPeers, source: 'gossip_topology.admitted_peers', connectedIds: participantPeerIds(snapshot) };
}

function missingIds(expectedIds: string[], connectedIds: string[]) {
  const connected = new Set(connectedIds);
  return expectedIds.filter((id) => !connected.has(id));
}

function readinessTimeoutMs() {
  return DEFAULT_CONNECT_QUORUM_WINDOW_MS;
}

function planEpoch(snapshot: Record<string, any> = {}) {
  return positiveInt(mediaSessionPlan(snapshot).plan_epoch ?? mediaSessionPlan(snapshot).planEpoch, 1);
}

function snapshotCallId(snapshot: Record<string, any> = {}, fallback = '') {
  return stringValue(
    snapshot.call_id
      ?? snapshot.callId
      ?? mediaSessionPlan(snapshot).call_id
      ?? mediaSessionPlan(snapshot).callId
      ?? snapshot.viewer?.call_id
      ?? snapshot.viewer?.callId,
    fallback,
  );
}

function snapshotRoomId(snapshot: Record<string, any> = {}, fallback = '') {
  return stringValue(
    snapshot.room_id
      ?? snapshot.roomId
      ?? mediaSessionPlan(snapshot).room_id
      ?? mediaSessionPlan(snapshot).roomId,
    fallback,
  );
}

function frameKind(frame: Record<string, any> = {}) {
  return stringValue(frame.type ?? frame.frame_kind ?? frame.frameKind).toLowerCase() === 'keyframe'
    ? 'keyframe'
    : 'delta';
}

function frameTrackId(frame: Record<string, any> = {}) {
  return stringValue(frame.trackId ?? frame.track_id ?? frame.track?.id);
}

export function createGossipConnectQuorum({
  captureClientDiagnostic = () => {},
  getCallId = () => '',
  getLocalPeerId = () => '',
  getRoomId = () => '',
  mediaCarrierDiagnosticPayload = () => ({}),
}: Record<string, any> = {}) {
  let currentWindowKey = '';
  let currentWindowStartedAtMs = 0;
  let lastSnapshotState: Record<string, any> | null = null;
  let lastPendingDiagnosticKey = '';

  function applyRoomStatePayload(payload: Record<string, any> = {}, options: Record<string, any> = {}) {
    if (!payload || typeof payload !== 'object') return false;
    const hasRoomState = Array.isArray(payload.participants)
      || (payload.media_session_plan && typeof payload.media_session_plan === 'object')
      || (payload.mediaSessionPlan && typeof payload.mediaSessionPlan === 'object');
    if (!hasRoomState) return false;

    const nowMs = nowMsFromOptions(options);
    const expected = expectedParticipantKeys(payload);
    const missing = missingIds(expected.ids, expected.connectedIds);
    const callId = snapshotCallId(payload, getCallId());
    const roomId = snapshotRoomId(payload, getRoomId());
    const windowKey = [
      callId || 'call',
      roomId || 'room',
      expected.source,
      expected.ids.join(','),
      planEpoch(payload),
    ].join('|');
    if (currentWindowKey !== windowKey) {
      currentWindowKey = windowKey;
      currentWindowStartedAtMs = nowMs;
      lastPendingDiagnosticKey = '';
    }

    lastSnapshotState = {
      all_connected: expected.ids.length > 0 && missing.length === 0,
      call_id: callId,
      connected_ids: expected.connectedIds,
      connected_participant_count: expected.connectedIds.length,
      connect_window_elapsed_ms: Math.max(0, nowMs - currentWindowStartedAtMs),
      connect_window_ms: readinessTimeoutMs(),
      connect_window_started_at_ms: currentWindowStartedAtMs,
      expected_ids: expected.ids,
      expected_participant_count: expected.ids.length,
      expected_source: expected.source,
      media_session_plan_present: Object.keys(mediaSessionPlan(payload)).length > 0,
      missing_ids: missing,
      missing_participant_count: missing.length,
      plan_epoch: planEpoch(payload),
      room_id: roomId,
      snapshot_received_at_ms: nowMs,
      window_key: windowKey,
    };
    return true;
  }

  function currentState(options: Record<string, any> = {}) {
    if (!lastSnapshotState) {
      return {
        allowed: false,
        reason: 'connect_quorum_missing_snapshot',
        connect_window_ms: DEFAULT_CONNECT_QUORUM_WINDOW_MS,
        connect_window_elapsed_ms: 0,
        expected_participant_count: 0,
        connected_participant_count: 0,
        missing_participant_count: 0,
        missing_ids: [],
        media_session_plan_present: false,
      };
    }

    const nowMs = nowMsFromOptions(options);
    const elapsedMs = Math.max(0, nowMs - Number(lastSnapshotState.connect_window_started_at_ms || nowMs));
    const timeoutMs = positiveInt(lastSnapshotState.connect_window_ms, DEFAULT_CONNECT_QUORUM_WINDOW_MS);
    const activeCallId = stringValue(getCallId());
    const activeRoomId = stringValue(getRoomId());
    const snapshotCall = stringValue(lastSnapshotState.call_id);
    const snapshotRoom = stringValue(lastSnapshotState.room_id);
    if (activeCallId !== '' && activeCallId !== 'call' && snapshotCall !== '' && activeCallId !== snapshotCall) {
      return { ...lastSnapshotState, allowed: false, reason: 'connect_quorum_stale_call', connect_window_elapsed_ms: elapsedMs };
    }
    if (activeRoomId !== '' && snapshotRoom !== '' && activeRoomId !== snapshotRoom) {
      return { ...lastSnapshotState, allowed: false, reason: 'connect_quorum_stale_room', connect_window_elapsed_ms: elapsedMs };
    }
    if (Number(lastSnapshotState.expected_participant_count || 0) <= 0) {
      return { ...lastSnapshotState, allowed: false, reason: 'connect_quorum_missing_expected_participants', connect_window_elapsed_ms: elapsedMs };
    }
    if (lastSnapshotState.all_connected === true) {
      return { ...lastSnapshotState, allowed: true, reason: 'connect_quorum_satisfied', connect_window_elapsed_ms: elapsedMs };
    }
    return {
      ...lastSnapshotState,
      allowed: false,
      reason: elapsedMs >= timeoutMs ? 'connect_quorum_timeout' : 'connect_quorum_waiting',
      connect_window_elapsed_ms: elapsedMs,
    };
  }

  function capturePendingDiagnostic(frame: Record<string, any> = {}, state: Record<string, any> = {}, context: Record<string, any> = {}) {
    const diagnosticKey = [
      state.reason,
      state.window_key,
      frameTrackId(frame),
      state.expected_participant_count,
      state.connected_participant_count,
      (state.missing_ids || []).join(','),
    ].join('|');
    if (diagnosticKey === lastPendingDiagnosticKey) return;
    lastPendingDiagnosticKey = diagnosticKey;

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'gossip_connect_quorum_pending',
      code: 'gossip_connect_quorum_pending',
      message: 'Gossip media frame publication is continuing while the connect quorum is still pending.',
      payload: {
        ...mediaCarrierDiagnosticPayload(),
        assigned_neighbor_count: positiveInt(context.assignedNeighborCount, 0),
        call_id: stringValue(state.call_id || getCallId()),
        connected_participant_count: positiveInt(state.connected_participant_count, 0),
        connect_window_elapsed_ms: positiveInt(state.connect_window_elapsed_ms, 0),
        connect_window_ms: positiveInt(state.connect_window_ms, DEFAULT_CONNECT_QUORUM_WINDOW_MS),
        connect_window_started_at_ms: positiveInt(state.connect_window_started_at_ms, 0),
        data_lane_mode: stringValue(context.dataLaneMode),
        diagnostics_label: stringValue(context.diagnosticsLabel),
        expected_participant_count: positiveInt(state.expected_participant_count, 0),
        expected_participant_source: stringValue(state.expected_source),
        frame_kind: frameKind(frame),
        local_peer_id: stringValue(getLocalPeerId()),
        media_session_plan_present: state.media_session_plan_present === true,
        missing_participant_count: positiveInt(state.missing_participant_count, 0),
        missing_participant_ids: Array.isArray(state.missing_ids) ? state.missing_ids.slice(0, MAX_DIAGNOSTIC_IDS) : [],
        open_data_channel_neighbor_count: positiveInt(context.openDataChannelNeighborCount, 0),
        plan_epoch: positiveInt(state.plan_epoch, 1),
        reason: stringValue(state.reason, 'connect_quorum_waiting'),
        room_id: stringValue(state.room_id || getRoomId()),
        track_id: frameTrackId(frame),
      },
      immediate: true,
    });
  }

  function evaluateBeforePublish(frame: Record<string, any> = {}, context: Record<string, any> = {}) {
    const state = currentState(context);
    if (state.allowed !== true) {
      capturePendingDiagnostic(frame, state, context);
    }
    return {
      allowed: true,
      quorum_satisfied: state.allowed === true,
      state,
    };
  }

  function clear() {
    currentWindowKey = '';
    currentWindowStartedAtMs = 0;
    lastSnapshotState = null;
    lastPendingDiagnosticKey = '';
  }

  return {
    applyRoomStatePayload,
    clear,
    currentState,
    evaluateBeforePublish,
  };
}
