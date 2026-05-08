import {
  directJoinDecisionForSeedUser,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  installCallAccessSeedRoutes,
  installStoredSeedSession,
} from './callAccessSeedMatrix.js';

function participantPayload(user, callRole = 'participant', inviteState = 'allowed') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    call_role: callRole,
    invite_state: inviteState,
    joined_at: null,
    connected_at: null,
  };
}

function callParticipants(call) {
  const owner = getSeedUser(call.owner_user_key);
  const guestUsers = (Array.isArray(call.guest_list_user_keys) ? call.guest_list_user_keys : [])
    .map((key) => getSeedUser(key));
  return [
    participantPayload(owner, 'owner', 'allowed'),
    ...guestUsers.map((user) => participantPayload(user, 'participant', 'allowed')),
  ];
}

export async function installCallAccessFakeRealtime(context, {
  linkKey = '',
  callKey = '',
  userKey = '',
  requiresAdmission = null,
}) {
  const link = String(linkKey || '').trim() !== '' ? getSeedAccessLink(linkKey) : null;
  const call = link ? getSeedCall(link.call_key) : getSeedCall(callKey);
  const user = String(userKey || '').trim() !== '' ? getSeedUser(userKey) : null;
  const owner = getSeedUser(call.owner_user_key);
  const decision = user ? directJoinDecisionForSeedUser(userKey, call.key) : null;
  const resolvedRequiresAdmission = requiresAdmission === null
    ? Boolean(link && link.requires_admission !== false)
    : Boolean(requiresAdmission);

  await context.addInitScript(({ roomId, callId, ownerUserId, admissionRequired, participants, viewer }) => {
    const listenersSymbol = Symbol('listeners');

    window.__iamCallAccessSocketFrames = [];
    window.__iamCallAccessSocketEvents = [];
    window.__iamCallAccessSockets = [];

    function ownerAbsencePayload(overrides = {}) {
      const activeParticipantCount = participants.length;
      const activeNonOwnerCount = participants.filter((participant) => Number(participant?.user_id || 0) !== ownerUserId).length;
      return {
        enabled: true,
        call_id: callId,
        room_id: roomId,
        call_status: 'active',
        owner_user_id: ownerUserId,
        owner_present: true,
        active_participant_count: activeParticipantCount,
        active_non_owner_count: activeNonOwnerCount,
        timer_ms: 15 * 60 * 1000,
        countdown_ms: 5 * 60 * 1000,
        status: 'owner_present',
        countdown_started: false,
        ...overrides,
      };
    }

    function snapshotPayload(reason = 'requested', overrides = {}) {
      const lifecycleOverrides = overrides.call_lifecycle && typeof overrides.call_lifecycle === 'object'
        ? overrides.call_lifecycle
        : {};
      const ownerAbsenceOverrides = lifecycleOverrides.owner_absence && typeof lifecycleOverrides.owner_absence === 'object'
        ? lifecycleOverrides.owner_absence
        : {};
      const base = {
        type: 'room/snapshot',
        room_id: roomId,
        call_id: callId,
        participant_count: participants.length,
        participants,
        viewer,
        layout: null,
        activity: [],
        call_lifecycle: {
          status: 'active',
          ...lifecycleOverrides,
          owner_absence: ownerAbsencePayload(ownerAbsenceOverrides),
        },
        reason,
        time: new Date().toISOString(),
      };
      return {
        ...base,
        ...overrides,
        call_lifecycle: {
          ...base.call_lifecycle,
          ...lifecycleOverrides,
          owner_absence: ownerAbsencePayload(ownerAbsenceOverrides),
        },
      };
    }

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = String(url || '');
        this.readyState = FakeWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        window.__iamCallAccessSockets.push(this);
        setTimeout(() => {
          if (this.readyState === FakeWebSocket.CLOSED) return;
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: roomId,
            call_context: viewer,
            admission: {
              requires_admission: Boolean(admissionRequired),
              pending_room_id: roomId,
              call_id: callId,
            },
          });
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
        if (type === 'open' && this.readyState === FakeWebSocket.OPEN) {
          setTimeout(() => callback({}), 0);
        }
      }

      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || [])
          .filter((registered) => registered !== callback);
      }

      dispatch(type, event) {
        for (const callback of this[listenersSymbol][type] || []) callback(event);
      }

      emit(payload) {
        window.__iamCallAccessSocketEvents.push(payload);
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        let payload = null;
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {
          payload = { type: 'invalid_json' };
        }
        window.__iamCallAccessSocketFrames.push(payload);
        if (payload.type === 'room/snapshot/request' || payload.type === 'room/join') {
          setTimeout(() => this.emit(snapshotPayload(payload.type)), 0);
          return;
        }
        if (payload.type === 'lobby/queue/join') {
          setTimeout(() => {
            this.emit({
              type: 'lobby/snapshot',
              room_id: roomId,
              call_id: callId,
              pending: [],
              admitted: [],
              rejected: [],
            });
          }, 0);
        }
      }

      close(code = 1000, reason = 'test_close') {
        if (this.readyState === FakeWebSocket.CLOSED) return;
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code, reason });
      }
    }

    window.__iamCallAccessEmitRoomSnapshot = (overrides = {}) => {
      const payload = snapshotPayload('test_emit', overrides && typeof overrides === 'object' ? overrides : {});
      let sent = 0;
      for (const socket of window.__iamCallAccessSockets) {
        if (socket?.readyState !== FakeWebSocket.OPEN || typeof socket.emit !== 'function') continue;
        socket.emit(payload);
        sent += 1;
      }
      return sent;
    };

    window.WebSocket = FakeWebSocket;
  }, {
    roomId: call.room_id,
    callId: call.id,
    ownerUserId: owner.id,
    admissionRequired: resolvedRequiresAdmission,
    participants: callParticipants(call).map((participant) => ({
      user_id: participant.user_id,
      display_name: participant.display_name,
      email: participant.email,
      role: 'user',
      call_role: participant.call_role,
      effective_call_role: participant.call_role,
      invite_state: participant.invite_state,
      joined_at: participant.joined_at,
      connected_at: participant.connected_at,
    })),
    viewer: {
      user_id: user?.id || 0,
      role: user?.role || 'user',
      call_id: call.id,
      call_role: decision?.source === 'owner' ? 'owner' : 'participant',
      effective_call_role: decision?.source === 'system_admin'
        ? 'owner'
        : (decision?.source === 'organization_admin' ? 'moderator' : (decision?.source === 'owner' ? 'owner' : 'participant')),
      can_moderate: Boolean(decision?.can_manage_lobby),
      can_manage_owner: decision?.source === 'system_admin' || decision?.source === 'owner',
    },
  });
}

export async function installCallAccessMediaDeviceShim(context) {
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        ...(navigator.mediaDevices || {}),
        getUserMedia: async () => new MediaStream(),
        enumerateDevices: async () => [
          { kind: 'audioinput', deviceId: 'iam-audio', label: 'IAM matrix microphone', groupId: 'iam-call-access' },
          { kind: 'videoinput', deviceId: 'iam-video', label: 'IAM matrix camera', groupId: 'iam-call-access' },
          { kind: 'audiooutput', deviceId: 'iam-speaker', label: 'IAM matrix speaker', groupId: 'iam-call-access' },
        ],
        getSupportedConstraints: () => ({ audio: true, video: true, deviceId: true }),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  });
}

export async function createCallAccessMatrixPage(browser, baseURL, {
  scenarioKey,
  storedSessionUserKey = '',
  storedSessionCallKey = 'alpha_active',
} = {}) {
  const scenario = getSeedScenario(scenarioKey);
  const linkKey = String(scenario.link_key || '').trim();
  if (linkKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a call-access link.`);

  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessSeedRoutes(context, { scenarioKey: scenario.key });
  if (String(storedSessionUserKey || '').trim() !== '') {
    await installStoredSeedSession(context, storedSessionUserKey, storedSessionCallKey);
  }
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, {
    linkKey,
    userKey: String(scenario.principal_user_key || '').trim(),
    requiresAdmission: Object.prototype.hasOwnProperty.call(scenario.expected || {}, 'requires_admission')
      ? Boolean(scenario.expected.requires_admission)
      : null,
  });
  const page = await context.newPage();
  return { context, page, scenario };
}

export async function createDirectJoinMatrixPage(browser, baseURL, { scenarioKey }) {
  const scenario = getSeedScenario(scenarioKey);
  const callKey = String(scenario.call_key || '').trim();
  const userKey = String(scenario.principal_user_key || '').trim();
  if (callKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a direct-join call.`);
  if (userKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a principal user.`);

  const directJoinDecisions = [];
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installStoredSeedSession(context, userKey, callKey, scenario.client_session_overrides || {});
  await installCallAccessSeedRoutes(context, { directJoinDecisions });
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, { callKey, userKey, requiresAdmission: false });
  const page = await context.newPage();
  return { context, page, scenario, directJoinDecisions };
}
