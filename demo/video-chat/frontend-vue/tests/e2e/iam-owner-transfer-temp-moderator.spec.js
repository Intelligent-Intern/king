import { expect, test } from '@playwright/test';
import {
  bootstrapStoredSession,
  storedSessionFor,
} from './helpers/videochatMatrixHarness.js';

const CALL_ID = 'call-iam-owner-transfer-temp-mods';
const ROOM_ID = 'room-iam-owner-transfer-temp-mods';
const OTHER_CALL_ID = 'call-iam-other-temp-mods';
const OTHER_ROOM_ID = 'room-iam-other-temp-mods';
test.describe.configure({ timeout: 60_000 });
const ownerUser = {
  id: 41,
  email: 'iam-owner@example.test',
  displayName: 'IAM Owner',
  role: 'user',
  callRole: 'owner',
  sessionId: 'sess_iam_owner',
  sessionToken: 'sess_iam_owner',
};

const nextOwnerUser = {
  id: 42,
  email: 'iam-next-owner@example.test',
  displayName: 'IAM Next Owner',
  role: 'user',
  callRole: 'participant',
  sessionId: 'sess_iam_next_owner',
  sessionToken: 'sess_iam_next_owner',
};

const managedUser = {
  id: 43,
  email: 'iam-managed@example.test',
  displayName: 'IAM Managed',
  role: 'user',
  callRole: 'participant',
  sessionId: 'sess_iam_managed',
  sessionToken: 'sess_iam_managed',
};

const waitingUser = {
  id: 44,
  email: 'iam-waiting@example.test',
  displayName: 'IAM Waiting',
  role: 'user',
  callRole: 'participant',
  sessionId: 'sess_iam_waiting',
  sessionToken: 'sess_iam_waiting',
};
function tempModeratorParticipants() {
  return [
    ownerUser,
    { ...nextOwnerUser, callRole: 'moderator' },
    managedUser,
  ];
}

function installIamBrowserHarness(context, options = {}) {
  const activeUser = options.activeUser || ownerUser;
  const participants = options.participants || [ownerUser, nextOwnerUser, managedUser];
  const authorizationMode = options.authorizationMode || 'normal';
  const oldOwnerRetainsModeration = Boolean(options.oldOwnerRetainsModeration);
  const lobbyQueue = options.lobbyQueue || [];

  return context.addInitScript(
    ({ callId, roomId, otherCallId, otherRoomId, activeUser, participants, authorizationMode, oldOwnerRetainsModeration, lobbyQueue }) => {
      const listenersSymbol = Symbol('listeners');
      const originalOwnerUserId = Number(participants.find((user) => user.callRole === 'owner')?.id || participants[0]?.id || 0);
      const roles = {};
      for (const participant of participants) {
        roles[Number(participant.id)] = String(participant.callRole || 'participant');
      }

      function lobbyEntry(user, status = 'queued') {
        return {
          user_id: Number(user.id),
          display_name: String(user.displayName),
          role: String(user.role || 'user'),
          status,
          requested_unix_ms: 1_780_500_000_000,
          requested_at: '2026-06-01T00:00:00.000Z',
        };
      }

      window.__iamProof = {
        callId,
        roomId,
        otherCallId,
        otherRoomId,
        activeUser,
        participants,
        ownerUserId: originalOwnerUserId,
        originalOwnerUserId,
        roles,
        authorizationMode,
        oldOwnerRetainsModeration,
        lobbyQueue: lobbyQueue.map((user) => lobbyEntry(user, 'queued')),
        lobbyAdmitted: [],
        requests: [],
        socketCommands: [],
        sockets: [],
      };

      function proofState() {
        return window.__iamProof;
      }

      function userById(userId) {
        return proofState().participants.find((user) => Number(user.id) === Number(userId)) || null;
      }

      function activeServerContext() {
        const state = proofState();
        const activeUserId = Number(state.activeUser.id);
        const callRole = String(state.roles[activeUserId] || 'participant');
        const isCurrentOwner = Number(state.ownerUserId) === activeUserId;
        const retainedByOrgRole = !isCurrentOwner
          && activeUserId === Number(state.originalOwnerUserId)
          && Boolean(state.oldOwnerRetainsModeration);
        return {
          callRole: isCurrentOwner ? 'owner' : callRole,
          effectiveCallRole: isCurrentOwner ? 'owner' : (retainedByOrgRole ? 'moderator' : callRole),
          canModerate: isCurrentOwner || callRole === 'moderator' || retainedByOrgRole,
          canManageOwner: isCurrentOwner,
        };
      }

      function callPayload() {
        const state = proofState();
        const internal = state.participants.map((user) => {
          const userId = Number(user.id);
          const callRole = Number(state.ownerUserId) === userId ? 'owner' : String(state.roles[userId] || 'participant');
          return {
            user_id: userId,
            display_name: String(user.displayName),
            email: String(user.email),
            call_role: callRole,
            invite_state: 'allowed',
            is_owner: callRole === 'owner',
            is_moderator: callRole === 'moderator',
          };
        });
        const owner = userById(state.ownerUserId) || state.participants[0];
        return {
          id: callId,
          room_id: roomId,
          title: 'IAM Owner Transfer Temp Moderator',
          access_mode: 'invite_only',
          status: 'active',
          starts_at: '2026-10-12T09:00:00.000Z',
          ends_at: '2026-10-12T10:00:00.000Z',
          owner: {
            user_id: Number(owner.id),
            display_name: String(owner.displayName),
            email: String(owner.email),
          },
          participants: {
            total: internal.length,
            internal,
            external: [],
          },
          my_participation: true,
        };
      }

      function snapshotPayload(reason = 'requested', viewerOverride = null) {
        const state = proofState();
        const context = activeServerContext();
        const viewer = viewerOverride || {
          user_id: Number(state.activeUser.id),
          role: String(state.activeUser.role || 'user'),
          call_id: callId,
          call_role: context.callRole,
          effective_call_role: context.effectiveCallRole,
          can_moderate: context.canModerate,
          can_manage_owner: context.canManageOwner,
        };
        return {
          type: 'room/snapshot',
          room_id: roomId,
          call_id: callId,
          participant_count: state.participants.length,
          participants: state.participants.map((user) => {
            const userId = Number(user.id);
            const callRole = Number(state.ownerUserId) === userId ? 'owner' : String(state.roles[userId] || 'participant');
            return {
              connection_id: `conn-${userId}`,
              room_id: roomId,
              active_call_id: callId,
              user: {
                id: userId,
                display_name: String(user.displayName),
                role: String(user.role || 'user'),
                call_role: callRole,
              },
              call_role: callRole,
              connected_at: '2026-10-12T09:00:00.000Z',
            };
          }),
          viewer,
          layout: {
            call_id: callId,
            room_id: roomId,
            mode: 'main_mini',
            strategy: 'manual_pinned',
            automation_paused: false,
            pinned_user_ids: [],
            selected_user_ids: state.participants.map((user) => Number(user.id)),
            main_user_id: Number(state.ownerUserId),
            selection: {
              main_user_id: Number(state.ownerUserId),
              visible_user_ids: state.participants.map((user) => Number(user.id)),
              mini_user_ids: state.participants.map((user) => Number(user.id)).filter((id) => id !== Number(state.ownerUserId)),
              pinned_user_ids: [],
            },
          },
          reason,
          time: new Date().toISOString(),
        };
      }

      function lobbySnapshotPayload(reason = 'requested') {
        const state = proofState();
        return {
          type: 'lobby/snapshot',
          room_id: roomId,
          call_id: callId,
          queue: [...state.lobbyQueue],
          admitted: [...state.lobbyAdmitted],
          reason,
          time: new Date().toISOString(),
        };
      }

      function dispatchToWorkspace(payload) {
        const handler = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState?.handleSocketMessage;
        if (typeof handler === 'function') {
          handler({ data: JSON.stringify(payload) });
        }
      }

      function dispatchToOpenSockets(payload) {
        dispatchToWorkspace(payload);
        for (const socket of proofState().sockets) {
          if (socket.readyState === FakeWebSocket.OPEN) {
            socket.dispatch('message', { data: JSON.stringify(payload) });
          }
        }
      }

      function jsonResponse(payload, status = 200) {
        return new Response(JSON.stringify(payload), {
          status,
          headers: { 'content-type': 'application/json; charset=utf-8' },
        });
      }

      function canPatchRole(targetUserId, role) {
        const state = proofState();
        if (state.authorizationMode === 'deny') return false;
        const context = activeServerContext();
        if (role === 'owner') return context.canManageOwner;
        if (Number(targetUserId) === Number(state.ownerUserId)) return false;
        return context.canModerate;
      }

      function canApplyAssignedLobbyCommand(payload) {
        const state = proofState();
        const context = activeServerContext();
        const commandRoomId = String(payload.room_id || roomId).trim();
        const commandCallId = String(payload.call_id || callId).trim();
        return state.authorizationMode !== 'deny'
          && context.canModerate
          && commandRoomId === roomId
          && (commandCallId === '' || commandCallId === callId);
      }

      function applyLobbyCommand(payload) {
        const state = proofState();
        const type = String(payload.type || '').trim();
        const targetUserId = Number(payload.target_user_id || 0);
        const allowed = canApplyAssignedLobbyCommand(payload);
        state.socketCommands.push({
          type,
          targetUserId,
          roomId: String(payload.room_id || roomId),
          callId: String(payload.call_id || callId),
          allowed,
        });
        if (!allowed) return false;

        if (type === 'lobby/allow_all') {
          state.lobbyAdmitted = [
            ...state.lobbyAdmitted,
            ...state.lobbyQueue.map((entry) => ({ ...entry, status: 'admitted', admitted_at: new Date().toISOString() })),
          ];
          state.lobbyQueue = [];
          return true;
        }

        if (targetUserId <= 0) return false;
        if (type === 'lobby/allow') {
          const target = state.lobbyQueue.find((entry) => Number(entry.user_id) === targetUserId);
          state.lobbyQueue = state.lobbyQueue.filter((entry) => Number(entry.user_id) !== targetUserId);
          if (target) {
            state.lobbyAdmitted.push({ ...target, status: 'admitted', admitted_at: new Date().toISOString() });
          }
          return true;
        }

        if (type === 'lobby/remove' || type === 'lobby/reject' || type === 'lobby/kick') {
          state.lobbyQueue = state.lobbyQueue.filter((entry) => Number(entry.user_id) !== targetUserId);
          state.lobbyAdmitted = state.lobbyAdmitted.filter((entry) => Number(entry.user_id) !== targetUserId);
          return true;
        }

        return false;
      }

      const nativeFetch = window.fetch.bind(window);
      window.fetch = async (...args) => {
        const request = args[0] instanceof Request ? args[0] : null;
        const url = new URL(String(request?.url || args[0] || ''), window.location.origin);
        const method = String(args[1]?.method || request?.method || 'GET').toUpperCase();
        if (!url.pathname.startsWith('/api/')) {
          return nativeFetch(...args);
        }

        if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
          const state = proofState();
          return jsonResponse({
            status: 'ok',
            result: { state: 'authenticated' },
            session: {
              id: state.activeUser.sessionId,
              token: state.activeUser.sessionToken,
              expires_at: '2030-01-01T00:00:00.000Z',
            },
            user: {
              id: Number(state.activeUser.id),
              email: String(state.activeUser.email),
              display_name: String(state.activeUser.displayName),
              role: String(state.activeUser.role || 'user'),
              status: 'active',
              time_format: '24h',
              date_format: 'dmy_dot',
              theme: 'dark',
              account_type: 'account',
              is_guest: false,
            },
          });
        }

        if (url.pathname === `/api/calls/resolve/${callId}`) {
          return jsonResponse({
            status: 'ok',
            result: {
              state: 'resolved',
              resolved_as: 'call_id',
              call: callPayload(),
              access_link: null,
            },
          });
        }

        if (url.pathname === `/api/calls/${callId}` && method === 'GET') {
          return jsonResponse({ status: 'ok', call: callPayload() });
        }

        if (url.pathname === `/api/calls/${callId}` && method === 'PATCH') {
          const body = args[1]?.body || request?.body || '';
          const payload = typeof body === 'string' && body.trim() !== '' ? JSON.parse(body) : {};
          const hasGuestListMutation = Object.prototype.hasOwnProperty.call(payload, 'internal_participant_user_ids')
            || Object.prototype.hasOwnProperty.call(payload, 'external_participants');
          const context = activeServerContext();
          const allowed = hasGuestListMutation ? context.canManageOwner : context.canModerate;
          proofState().requests.push({
            method,
            path: url.pathname,
            action: 'call_update',
            hasGuestListMutation,
            allowed,
          });
          if (!allowed) {
            return jsonResponse({
              status: 'error',
              error: { code: 'forbidden', message: 'You are not allowed to edit this call.' },
            }, 403);
          }
          return jsonResponse({ status: 'ok', result: { state: 'updated', call: callPayload() } });
        }

        if (url.pathname.startsWith(`/api/calls/${otherCallId}/participants/`) && method === 'PATCH') {
          proofState().requests.push({ method, path: url.pathname, action: 'foreign_call_role_update', allowed: false });
          return jsonResponse({
            status: 'error',
            error: { code: 'forbidden', message: 'You are not allowed to change call participant roles.' },
          }, 403);
        }

        if (url.pathname === '/api/admin/tenancy/context' || url.pathname.startsWith('/api/governance/')) {
          proofState().requests.push({ method, path: url.pathname, action: 'tenant_admin_probe', allowed: false });
          return jsonResponse({
            status: 'error',
            error: { code: 'tenant_admin_required', message: 'Tenant administration requires an active tenant admin membership.' },
          }, 403);
        }

        const roleMatch = url.pathname.match(new RegExp(`^/api/calls/${callId}/participants/(\\d+)/role$`));
        if (roleMatch && method === 'PATCH') {
          const body = args[1]?.body || request?.body || '';
          const payload = typeof body === 'string' && body.trim() !== '' ? JSON.parse(body) : {};
          const targetUserId = Number(roleMatch[1]);
          const role = String(payload.role || payload.call_role || '').trim().toLowerCase();
          const allowed = canPatchRole(targetUserId, role);
          proofState().requests.push({
            method,
            path: url.pathname,
            targetUserId,
            role,
            allowed,
          });
          if (!allowed) {
            return jsonResponse({
              status: 'error',
              error: {
                code: 'forbidden',
                message: 'You are not allowed to change call participant roles.',
              },
            }, 403);
          }

          if (role === 'owner') {
            proofState().roles[proofState().ownerUserId] = 'participant';
            proofState().ownerUserId = targetUserId;
            proofState().roles[targetUserId] = 'owner';
          } else {
            proofState().roles[targetUserId] = role;
          }
          setTimeout(() => dispatchToOpenSockets(snapshotPayload('role_patch')), 0);
          return jsonResponse({
            status: 'ok',
            result: {
              state: 'participant_role_updated',
              call: callPayload(),
            },
          });
        }

        if (url.pathname === '/api/user/client-diagnostics' && method === 'POST') {
          return jsonResponse({ status: 'ok', result: { accepted: true } });
        }

        return jsonResponse({
          status: 'error',
          error: {
            code: 'not_found',
            message: `missing IAM fixture route: ${url.pathname}`,
          },
        }, 404);
      };

      class FakeWebSocket {
        static CONNECTING = 0;
        static OPEN = 1;
        static CLOSING = 2;
        static CLOSED = 3;

        constructor(url) {
          this.url = url;
          this.readyState = FakeWebSocket.CONNECTING;
          this[listenersSymbol] = {};
          proofState().sockets.push(this);
          setTimeout(() => {
            this.readyState = FakeWebSocket.OPEN;
            this.dispatch('open', {});
            this.dispatch('message', {
              data: JSON.stringify({
                type: 'system/welcome',
                active_room_id: roomId,
                call_context: {
                  user_id: Number(proofState().activeUser.id),
                  call_id: callId,
                  call_role: activeServerContext().callRole,
                  can_moderate: activeServerContext().canModerate,
                  can_manage_owner: activeServerContext().canManageOwner,
                },
              }),
            });
            this.dispatch('message', { data: JSON.stringify(snapshotPayload('welcome')) });
            this.dispatch('message', { data: JSON.stringify(lobbySnapshotPayload('welcome')) });
          }, 0);
        }

        addEventListener(type, callback) {
          if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
          this[listenersSymbol][type].push(callback);
        }

        removeEventListener(type, callback) {
          this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((row) => row !== callback);
        }

        dispatch(type, event) {
          for (const callback of this[listenersSymbol][type] || []) callback(event);
        }

        send(data) {
          let payload = {};
          try {
            payload = JSON.parse(String(data || '{}'));
          } catch {
            return;
          }
          if (payload.type === 'room/snapshot/request' || payload.type === 'room/join') {
            setTimeout(() => {
              this.dispatch('message', { data: JSON.stringify(snapshotPayload(payload.type)) });
            }, 0);
            return;
          }
          if (['lobby/allow', 'lobby/remove', 'lobby/reject', 'lobby/kick', 'lobby/allow_all'].includes(payload.type)) {
            const allowed = applyLobbyCommand(payload);
            setTimeout(() => {
              if (!allowed) {
                this.dispatch('message', {
                  data: JSON.stringify({
                    type: 'system/error',
                    code: 'lobby_command_failed',
                    details: {
                      type: String(payload.type || ''),
                      target_user_id: Number(payload.target_user_id || 0),
                      room_id: String(payload.room_id || roomId),
                    },
                    time: new Date().toISOString(),
                  }),
                });
                return;
              }
              dispatchToOpenSockets(lobbySnapshotPayload(payload.type));
            }, 0);
          }
        }

        close() {
          this.readyState = FakeWebSocket.CLOSED;
          this.dispatch('close', { code: 1000, reason: 'test_close' });
        }
      }

      window.WebSocket = FakeWebSocket;
      Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: {
          enumerateDevices: async () => [],
          getUserMedia: async () => new MediaStream(),
          addEventListener: () => {},
          removeEventListener: () => {},
        },
      });

      window.__iamEmitForgedModeratorSnapshot = () => {
        dispatchToOpenSockets(snapshotPayload('forged_viewer', {
          user_id: Number(proofState().activeUser.id),
          role: 'user',
          call_id: callId,
          call_role: 'moderator',
          effective_call_role: 'moderator',
          can_moderate: true,
          can_manage_owner: false,
        }));
      };
      window.__iamSendSocketFrame = (payload) => {
        const socket = [...proofState().sockets].reverse().find((candidate) => candidate.readyState === FakeWebSocket.OPEN);
        if (!socket) return false;
        socket.send(JSON.stringify(payload));
        return true;
      };
      window.__iamEmitLobbySnapshot = () => {
        dispatchToOpenSockets(lobbySnapshotPayload('manual'));
      };
    },
    {
      callId: CALL_ID,
      roomId: ROOM_ID,
      otherCallId: OTHER_CALL_ID,
      otherRoomId: OTHER_ROOM_ID,
      activeUser,
      participants,
      authorizationMode,
      oldOwnerRetainsModeration,
      lobbyQueue,
    },
  );
}
async function createIamPage(browser, baseURL, options = {}) {
  const activeUser = options.activeUser || ownerUser;
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await bootstrapStoredSession(context, activeUser);
  await installIamBrowserHarness(context, options);
  const page = await context.newPage();
  return { context, page };
}

async function openIamWorkspace(page) {
  await page.goto(`/workspace/call/${CALL_ID}`);
  await page.waitForSelector('.workspace-call-view');
  await expect(page.locator('.user-row').first()).toBeVisible();
}
function userRow(page, displayName) {
  return page.locator('.user-row', { hasText: displayName }).first();
}
async function rolePatchRequests(page) {
  return page.evaluate(() => window.__iamProof.requests);
}
async function socketCommands(page) {
  return page.evaluate(() => window.__iamProof.socketCommands);
}
async function openLobbyPanel(page) {
  await page.locator('button.tab-lobby').click();
  const panel = page.locator('.panel-lobby.active');
  await expect(panel).toBeVisible();
  return panel;
}

async function fetchProbe(page, path, options = {}) {
  return page.evaluate(
    async ({ path: probePath, options: probeOptions }) => {
      const response = await fetch(probePath, probeOptions);
      const payload = await response.json().catch(() => null);
      return { status: response.status, payload };
    },
    { path, options },
  );
}

test('e2e_temp_mod_001_host_assigns_temp_moderator', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const owner = await createIamPage(browser, baseURL, { activeUser: ownerUser });

  try {
    await openIamWorkspace(owner.page);
    const nextOwnerRow = userRow(owner.page, nextOwnerUser.displayName);
    await expect(nextOwnerRow.locator('.user-role')).toContainText('participant');
    await nextOwnerRow.getByRole('button', { name: 'Set moderator role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('moderator');
    expect(await rolePatchRequests(owner.page)).toMatchObject([
      { targetUserId: nextOwnerUser.id, role: 'moderator', allowed: true },
    ]);
  } finally {
    await owner.context.close();
  }
});

test('e2e_temp_mod_002_temp_moderator_admits_participant', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const moderator = await createIamPage(browser, baseURL, {
    activeUser: { ...nextOwnerUser, callRole: 'moderator' },
    participants: tempModeratorParticipants(),
    lobbyQueue: [waitingUser],
  });

  try {
    await openIamWorkspace(moderator.page);
    const lobbyPanel = await openLobbyPanel(moderator.page);
    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUser.displayName })).toBeVisible();
    const allowButton = lobbyPanel.locator('button[title="Allow user"]').first();
    await expect(allowButton).toBeEnabled();
    await allowButton.click();
    await expect.poll(() => socketCommands(moderator.page)).toContainEqual(expect.objectContaining({
      type: 'lobby/allow',
      targetUserId: waitingUser.id,
      allowed: true,
    }));
  } finally {
    await moderator.context.close();
  }
});

test('e2e_temp_mod_003_temp_moderator_rejects_participant', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const moderator = await createIamPage(browser, baseURL, {
    activeUser: { ...nextOwnerUser, callRole: 'moderator' },
    participants: tempModeratorParticipants(),
    lobbyQueue: [waitingUser],
  });

  try {
    await openIamWorkspace(moderator.page);
    const lobbyPanel = await openLobbyPanel(moderator.page);
    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUser.displayName })).toBeVisible();
    await moderator.page.evaluate((targetUserId) => window.__iamSendSocketFrame({
      type: 'lobby/reject',
      target_user_id: targetUserId,
    }), waitingUser.id);
    await expect.poll(() => socketCommands(moderator.page)).toContainEqual(expect.objectContaining({
      type: 'lobby/reject',
      targetUserId: waitingUser.id,
      allowed: true,
    }));
  } finally {
    await moderator.context.close();
  }
});

test('e2e_temp_mod_004_temp_moderator_limited_to_assigned_call', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const moderator = await createIamPage(browser, baseURL, {
    activeUser: { ...nextOwnerUser, callRole: 'moderator' },
    participants: tempModeratorParticipants(),
  });

  try {
    await openIamWorkspace(moderator.page);
    await moderator.page.evaluate(({ roomId, callId, targetUserId }) => window.__iamSendSocketFrame({
      type: 'lobby/allow',
      room_id: roomId,
      call_id: callId,
      target_user_id: targetUserId,
    }), { roomId: OTHER_ROOM_ID, callId: OTHER_CALL_ID, targetUserId: waitingUser.id });
    await expect.poll(() => socketCommands(moderator.page)).toContainEqual(expect.objectContaining({
      type: 'lobby/allow',
      roomId: OTHER_ROOM_ID,
      callId: OTHER_CALL_ID,
      allowed: false,
    }));

    const roleProbe = await fetchProbe(moderator.page, `/api/calls/${OTHER_CALL_ID}/participants/${managedUser.id}/role`, {
      method: 'PATCH',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ role: 'moderator' }),
    });
    expect(roleProbe.status).toBe(403);
  } finally {
    await moderator.context.close();
  }
});

test('e2e_temp_mod_005_temp_moderator_no_org_admin_actions', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const moderator = await createIamPage(browser, baseURL, {
    activeUser: { ...nextOwnerUser, callRole: 'moderator' },
    participants: tempModeratorParticipants(),
  });

  try {
    await openIamWorkspace(moderator.page);
    const adminProbe = await fetchProbe(moderator.page, '/api/admin/tenancy/context');
    expect(adminProbe.status).toBe(403);
    expect(adminProbe.payload?.error?.code).toBe('tenant_admin_required');
  } finally {
    await moderator.context.close();
  }
});

test('e2e_temp_mod_006_temp_moderator_rights_revoked_immediately', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const owner = await createIamPage(browser, baseURL, { activeUser: ownerUser });

  try {
    await openIamWorkspace(owner.page);
    const nextOwnerRow = userRow(owner.page, nextOwnerUser.displayName);
    await nextOwnerRow.getByRole('button', { name: 'Set moderator role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('moderator');
    await nextOwnerRow.getByRole('button', { name: 'Set participant role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('participant');
    expect(await rolePatchRequests(owner.page)).toMatchObject([
      { targetUserId: nextOwnerUser.id, role: 'moderator', allowed: true },
      { targetUserId: nextOwnerUser.id, role: 'participant', allowed: true },
    ]);
  } finally {
    await owner.context.close();
  }
});

test('e2e_temp_mod_007_client_side_temp_mod_role_forgery_rejected', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const participant = await createIamPage(browser, baseURL, {
    activeUser: nextOwnerUser,
    authorizationMode: 'deny',
  });

  try {
    await openIamWorkspace(participant.page);
    const managedRow = userRow(participant.page, managedUser.displayName);
    await expect(managedRow.getByRole('button', { name: 'Set moderator role' })).toBeDisabled();

    await participant.page.evaluate(() => window.__iamEmitForgedModeratorSnapshot());
    await expect(managedRow.getByRole('button', { name: 'Set moderator role' })).toBeEnabled();
    await managedRow.getByRole('button', { name: 'Set moderator role' }).click();
    await expect(managedRow.locator('.user-role')).toContainText('participant');

    expect(await rolePatchRequests(participant.page)).toMatchObject([
      { targetUserId: managedUser.id, role: 'moderator', allowed: false },
    ]);
  } finally {
    await participant.context.close();
  }
});
