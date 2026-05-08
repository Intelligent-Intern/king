import { expect, test } from '@playwright/test';
import {
  bootstrapStoredSession,
  storedSessionFor,
} from './helpers/videochatMatrixHarness.js';

const CALL_ID = 'call-iam-owner-transfer-temp-mods';
const ROOM_ID = 'room-iam-owner-transfer-temp-mods';

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

function installIamBrowserHarness(context, options = {}) {
  const activeUser = options.activeUser || ownerUser;
  const participants = options.participants || [ownerUser, nextOwnerUser, managedUser];
  const authorizationMode = options.authorizationMode || 'normal';
  const oldOwnerRetainsModeration = Boolean(options.oldOwnerRetainsModeration);

  return context.addInitScript(
    ({ callId, roomId, activeUser, participants, authorizationMode, oldOwnerRetainsModeration }) => {
      const listenersSymbol = Symbol('listeners');
      const originalOwnerUserId = Number(participants.find((user) => user.callRole === 'owner')?.id || participants[0]?.id || 0);
      const roles = {};
      for (const participant of participants) {
        roles[Number(participant.id)] = String(participant.callRole || 'participant');
      }

      window.__iamProof = {
        callId,
        roomId,
        activeUser,
        participants,
        ownerUserId: originalOwnerUserId,
        originalOwnerUserId,
        roles,
        authorizationMode,
        oldOwnerRetainsModeration,
        requests: [],
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
    },
    {
      callId: CALL_ID,
      roomId: ROOM_ID,
      activeUser,
      participants,
      authorizationMode,
      oldOwnerRetainsModeration,
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

test('owner grants/revokes temporary moderator and loses owner controls after transfer', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const owner = await createIamPage(browser, baseURL, { activeUser: ownerUser });

  try {
    await openIamWorkspace(owner.page);
    const nextOwnerRow = userRow(owner.page, nextOwnerUser.displayName);
    await expect(nextOwnerRow.locator('.user-role')).toContainText('participant');

    await nextOwnerRow.getByRole('button', { name: 'Set moderator role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('moderator');
    await nextOwnerRow.getByRole('button', { name: 'Set participant role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('participant');

    await nextOwnerRow.getByRole('button', { name: 'Transfer owner role' }).click();
    await expect(nextOwnerRow.locator('.user-role')).toContainText('owner');
    await expect(userRow(owner.page, ownerUser.displayName).locator('.user-role')).toContainText('participant');

    const transferButtonsDisabled = await owner.page.getByRole('button', { name: 'Transfer owner role' })
      .evaluateAll((buttons) => buttons.length > 0 && buttons.every((button) => button.disabled));
    expect(transferButtonsDisabled).toBe(true);
    await expect(userRow(owner.page, managedUser.displayName).getByRole('button', { name: 'Set moderator role' })).toBeDisabled();

    expect(await rolePatchRequests(owner.page)).toMatchObject([
      { targetUserId: nextOwnerUser.id, role: 'moderator', allowed: true },
      { targetUserId: nextOwnerUser.id, role: 'participant', allowed: true },
      { targetUserId: nextOwnerUser.id, role: 'owner', allowed: true },
    ]);
  } finally {
    await owner.context.close();
  }
});

test('demoted organization owner retains moderator controls but not owner transfer', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const owner = await createIamPage(browser, baseURL, {
    activeUser: ownerUser,
    oldOwnerRetainsModeration: true,
  });

  try {
    await openIamWorkspace(owner.page);
    await userRow(owner.page, nextOwnerUser.displayName).getByRole('button', { name: 'Transfer owner role' }).click();
    await expect(userRow(owner.page, ownerUser.displayName).locator('.user-role')).toContainText('moderator');
    expect(await owner.page.evaluate((userId) => window.__iamProof.roles[userId], ownerUser.id)).toBe('participant');
    const visibleOwnerCount = await owner.page.locator('.user-row .user-role')
      .evaluateAll((roles) => roles.filter((role) => role.textContent.trim() === 'owner').length);
    expect(visibleOwnerCount).toBe(1);

    await expect(userRow(owner.page, managedUser.displayName).getByRole('button', { name: 'Transfer owner role' })).toBeDisabled();
    await expect(userRow(owner.page, managedUser.displayName).getByRole('button', { name: 'Set moderator role' })).toBeEnabled();
    await userRow(owner.page, managedUser.displayName).getByRole('button', { name: 'Set moderator role' }).click();
    await expect(userRow(owner.page, managedUser.displayName).locator('.user-role')).toContainText('moderator');

    expect(await rolePatchRequests(owner.page)).toMatchObject([
      { targetUserId: nextOwnerUser.id, role: 'owner', allowed: true },
      { targetUserId: managedUser.id, role: 'moderator', allowed: true },
    ]);
  } finally {
    await owner.context.close();
  }
});

test('forged moderator UI state cannot mutate participant roles', async ({ browser }) => {
  test.setTimeout(60_000);
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
