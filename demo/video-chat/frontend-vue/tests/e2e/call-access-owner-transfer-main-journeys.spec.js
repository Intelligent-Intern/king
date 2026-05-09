import { expect, test } from '@playwright/test';
import {
  bootstrapStoredSession,
  corsHeaders,
  matrixUsers,
} from './helpers/videochatMatrixHarness.js';

test.describe.configure({ timeout: 90_000 });

const guestUser = {
  id: 10,
  email: 'matrix-guest-list@example.test',
  displayName: 'Guest List User',
  role: 'user',
  callRole: 'none',
  sessionId: 'sess_matrix_guest_list',
  sessionToken: 'sess_matrix_guest_list',
};

function userRows() {
  return [matrixUsers.admin, matrixUsers.user, matrixUsers.outsider, guestUser].map((user) => ({
    id: user.id,
    user_id: user.id,
    display_name: user.displayName,
    email: user.email,
    role: user.role,
  }));
}

function participantRowsFromIds(ids, state) {
  const users = new Map(userRows().map((row) => [Number(row.id), row]));
  return ids
    .map((id) => users.get(Number(id)))
    .filter(Boolean)
    .map((user) => {
      const role = Number(user.id) === Number(state.ownerUserId) ? 'owner' : 'participant';
      return {
        user_id: Number(user.id),
        display_name: user.display_name,
        email: user.email,
        call_role: role,
        invite_state: 'allowed',
        joined_at: '2026-05-08T10:00:00.000Z',
        connected_at: '2026-05-08T10:00:00.000Z',
      };
    });
}

function buildCall(state) {
  const owner = userRows().find((row) => Number(row.id) === Number(state.ownerUserId)) || userRows()[0];
  const participantIds = [
    state.actor.id,
    ...state.participantIds,
  ].filter((id, index, rows) => Number(id) > 0 && rows.indexOf(id) === index);
  const internal = participantRowsFromIds(participantIds, state);
  const actorRole = Number(state.actor.id) === Number(state.ownerUserId) ? 'owner' : 'participant';

  return {
    id: state.callId,
    room_id: state.roomId,
    title: state.title,
    status: 'active',
    access_mode: 'invite_only',
    starts_at: state.startsAt,
    ends_at: state.endsAt,
    owner: {
      user_id: Number(owner.id),
      display_name: owner.display_name,
      email: owner.email,
    },
    participants: {
      total: internal.length,
      internal,
      external: [],
    },
    my_participation: {
      call_role: actorRole,
      invite_state: 'allowed',
    },
  };
}

function sessionPayload(actor) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: actor.sessionId,
      token: actor.sessionToken,
      expires_at: '2030-01-01T00:00:00.000Z',
    },
    user: {
      id: actor.id,
      email: actor.email,
      display_name: actor.displayName,
      role: actor.role,
      status: 'active',
      time_format: '24h',
      date_format: 'dmy_dot',
      theme: 'dark',
      account_type: 'account',
      is_guest: false,
    },
  };
}

function directoryPayload(rows = userRows()) {
  return {
    status: 'ok',
    users: rows,
    pagination: {
      page: 1,
      page_size: 20,
      total: rows.length,
      page_count: 1,
      has_prev: false,
      has_next: false,
    },
  };
}

async function installBrowserRuntime(context, state) {
  await context.addInitScript(({ initialState }) => {
    const listenersSymbol = Symbol('listeners');

    window.__iamOwnerMainJourney = {
      ...initialState,
      ownerUserId: initialState.actor.id,
      participantIds: initialState.initialBrowserParticipantIds || initialState.participantIds || [],
      rolePatchRequests: [],
      socketFrames: [],
    };

    function proofState() {
      return window.__iamOwnerMainJourney;
    }

    function actorContext() {
      const state = proofState();
      const isOwner = Number(state.actor.id) === Number(state.ownerUserId);
      const orgAdminRetained = !isOwner && state.actor.role === 'admin';
      return {
        callRole: isOwner ? 'owner' : 'participant',
        effectiveCallRole: isOwner ? 'owner' : (orgAdminRetained ? 'moderator' : 'participant'),
        canModerate: isOwner || orgAdminRetained,
        canManageOwner: isOwner,
      };
    }

    function userById(userId) {
      return proofState().users.find((user) => Number(user.id) === Number(userId)) || null;
    }

    function participants() {
      const state = proofState();
      const ids = [state.actor.id, ...state.participantIds]
        .filter((id, index, rows) => Number(id) > 0 && rows.indexOf(id) === index);
      return ids
        .map((id) => userById(id))
        .filter(Boolean)
        .map((user) => {
          const callRole = Number(user.id) === Number(state.ownerUserId) ? 'owner' : 'participant';
          return {
            connection_id: `conn-${user.id}`,
            room_id: state.roomId,
            active_call_id: state.callId,
            user: {
              id: Number(user.id),
              display_name: String(user.displayName),
              role: String(user.role || 'user'),
              call_role: callRole,
            },
            user_id: Number(user.id),
            display_name: String(user.displayName),
            email: String(user.email),
            role: String(user.role || 'user'),
            call_role: callRole,
            effective_call_role: callRole,
            invite_state: 'allowed',
            joined_at: '2026-05-08T10:00:00.000Z',
            connected_at: '2026-05-08T10:00:00.000Z',
          };
        });
    }

    function callPayload() {
      const state = proofState();
      const owner = userById(state.ownerUserId) || state.actor;
      const rows = participants();
      const context = actorContext();
      return {
        id: state.callId,
        room_id: state.roomId,
        title: state.title,
        status: 'active',
        access_mode: 'invite_only',
        starts_at: state.startsAt,
        ends_at: state.endsAt,
        owner: {
          user_id: Number(owner.id),
          display_name: String(owner.displayName),
          email: String(owner.email),
        },
        participants: {
          total: rows.length,
          internal: rows.map((row) => ({
            user_id: row.user_id,
            display_name: row.display_name,
            email: row.email,
            call_role: row.call_role,
            invite_state: row.invite_state,
          })),
          external: [],
        },
        my_participation: {
          call_role: context.callRole,
          invite_state: 'allowed',
        },
      };
    }

    function snapshotPayload(reason = 'requested') {
      const state = proofState();
      const context = actorContext();
      return {
        type: 'room/snapshot',
        room_id: state.roomId,
        call_id: state.callId,
        participant_count: participants().length,
        participants: participants(),
        viewer: {
          user_id: Number(state.actor.id),
          role: String(state.actor.role || 'user'),
          call_id: state.callId,
          call_role: context.callRole,
          effective_call_role: context.effectiveCallRole,
          can_moderate: context.canModerate,
          can_manage_owner: context.canManageOwner,
        },
        layout: null,
        reason,
        time: new Date().toISOString(),
      };
    }

    function jsonResponse(payload, status = 200) {
      return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json; charset=utf-8' },
      });
    }

    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const request = args[0] instanceof Request ? args[0] : null;
      const url = new URL(String(request?.url || args[0] || ''), window.location.origin);
      const method = String(args[1]?.method || request?.method || 'GET').toUpperCase();
      const state = proofState();

      if (url.pathname === `/api/calls/resolve/${state.callId}`) {
        return jsonResponse({
          status: 'ok',
          result: {
            state: 'resolved',
            resolved_as: 'call_id',
            access_link: null,
            call: callPayload(),
          },
        });
      }

      if (url.pathname === `/api/calls/${state.callId}` && method === 'GET') {
        return jsonResponse({ status: 'ok', call: callPayload() });
      }

      const roleMatch = url.pathname.match(new RegExp(`^/api/calls/${state.callId}/participants/(\\d+)/role$`));
      if (roleMatch && method === 'PATCH') {
        const body = args[1]?.body || '';
        const payload = typeof body === 'string' && body.trim() !== '' ? JSON.parse(body) : {};
        const targetUserId = Number(roleMatch[1]);
        const role = String(payload.role || payload.call_role || '').trim().toLowerCase();
        const context = actorContext();
        const allowed = role === 'owner'
          ? context.canManageOwner
          : (context.canModerate && targetUserId !== Number(state.ownerUserId));
        state.rolePatchRequests.push({ targetUserId, role, allowed });
        if (!allowed) {
          return jsonResponse({
            status: 'error',
            error: { code: 'forbidden', message: 'You are not allowed to change call participant roles.' },
          }, 403);
        }
        if (role === 'owner') {
          state.ownerUserId = targetUserId;
        }
        return jsonResponse({
          status: 'ok',
          result: {
            state: 'participant_role_updated',
            call: callPayload(),
          },
        });
      }

      return nativeFetch(...args);
    };

    class OwnerJourneyWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = String(url || '');
        this.readyState = OwnerJourneyWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        setTimeout(() => {
          if (this.readyState === OwnerJourneyWebSocket.CLOSED) return;
          this.readyState = OwnerJourneyWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: proofState().roomId,
            call_context: snapshotPayload('welcome').viewer,
          });
          this.emit(snapshotPayload('welcome'));
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

      emit(payload) {
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        let payload = {};
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {}
        proofState().socketFrames.push(payload);
        if (payload.type === 'room/snapshot/request' || payload.type === 'room/join') {
          setTimeout(() => this.emit(snapshotPayload(payload.type)), 0);
        }
      }

      close() {
        this.readyState = OwnerJourneyWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    }

    window.WebSocket = OwnerJourneyWebSocket;
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        enumerateDevices: async () => [],
        getUserMedia: async () => new MediaStream(),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  }, {
    initialState: {
      ...state,
      users: [matrixUsers.admin, matrixUsers.user, matrixUsers.outsider, guestUser],
    },
  });
}

async function installRoutes(context, state, createdBodies, updatedBodies) {
  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: corsHeaders() });
      return;
    }

    const url = new URL(request.url());
    const jsonHeaders = { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' };

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill({ status: 200, headers: jsonHeaders, json: sessionPayload(state.actor) });
      return;
    }

    if (url.pathname === '/api/user/directory' || url.pathname === '/api/admin/users') {
      await route.fulfill({ status: 200, headers: jsonHeaders, json: directoryPayload() });
      return;
    }

    if (url.pathname === '/api/calls' && request.method() === 'POST') {
      const body = request.postDataJSON();
      createdBodies.push(body);
      state.created = true;
      state.title = String(body?.title || state.title);
      state.startsAt = String(body?.starts_at || state.startsAt);
      state.endsAt = String(body?.ends_at || state.endsAt);
      state.participantIds = Array.isArray(body?.internal_participant_user_ids)
        ? body.internal_participant_user_ids.map(Number)
        : [];
      const call = buildCall(state);
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: { status: 'ok', call, result: { call } },
      });
      return;
    }

    if (url.pathname === `/api/calls/${state.callId}` && request.method() === 'PATCH') {
      const body = request.postDataJSON();
      updatedBodies.push(body);
      state.title = String(body?.title || state.title);
      state.startsAt = String(body?.starts_at || state.startsAt);
      state.endsAt = String(body?.ends_at || state.endsAt);
      if (Array.isArray(body?.internal_participant_user_ids)) {
        state.participantIds = body.internal_participant_user_ids.map(Number);
      }
      const call = buildCall(state);
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: { status: 'ok', call, result: { call } },
      });
      return;
    }

    if (url.pathname === '/api/calls' && request.method() === 'GET') {
      const rows = state.created ? [buildCall(state)] : [];
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: {
          status: 'ok',
          calls: rows,
          pagination: {
            page: 1,
            page_size: 10,
            total: rows.length,
            page_count: 1,
            has_prev: false,
            has_next: false,
          },
        },
      });
      return;
    }

    if (url.pathname === `/api/calls/${state.callId}` && request.method() === 'GET') {
      await route.fulfill({ status: 200, headers: jsonHeaders, json: { status: 'ok', call: buildCall(state) } });
      return;
    }

    await route.fulfill({
      status: 404,
      headers: jsonHeaders,
      json: { status: 'error', error: { code: 'not_found', message: `missing fixture: ${url.pathname}` } },
    });
  });
}

function journeyState({ actor, callId, roomId, title }) {
  return {
    actor,
    callId,
    roomId,
    title,
    startsAt: '2026-05-08T10:00:00.000Z',
    endsAt: '2026-05-08T10:30:00.000Z',
    ownerUserId: actor.id,
    created: false,
    participantIds: [],
    initialBrowserParticipantIds: [],
  };
}

async function createContext(browser, state) {
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL || 'http://127.0.0.1:4174' });
  await bootstrapStoredSession(context, state.actor);
  await installBrowserRuntime(context, state);
  return context;
}

async function createCallFromList(page, { path, title, participant, participants = [participant] }) {
  await page.goto(path);
  const newCallButton = path.startsWith('/admin/')
    ? page.getByRole('button', { name: 'New video call' })
    : page.getByRole('button', { name: 'New call' });
  await expect(newCallButton).toBeVisible({ timeout: 20_000 });
  await newCallButton.click();

  const modal = page.getByRole('dialog', { name: 'Call compose modal' });
  await expect(modal).toBeVisible();
  await modal.getByLabel('Title').fill(title);
  await expect(modal.getByText('Registered users')).toBeVisible();
  for (const rowUser of participants) {
    await modal.locator('.calls-participant-row', { hasText: rowUser.displayName }).getByRole('checkbox').check();
  }

  const submitName = path.startsWith('/admin/') ? 'Start now' : 'Create call';
  const createResponsePromise = page.waitForResponse((response) => (
    response.url().includes('/api/calls')
    && response.request().method() === 'POST'
  ));
  await modal.getByRole('button', { name: submitName }).click();
  expect((await createResponsePromise).status()).toBe(200);
  return modal;
}

async function syncBrowserJourneyState(page, state) {
  await page.evaluate((nextState) => {
    if (!window.__iamOwnerMainJourney) return;
    window.__iamOwnerMainJourney.title = nextState.title;
    window.__iamOwnerMainJourney.startsAt = nextState.startsAt;
    window.__iamOwnerMainJourney.endsAt = nextState.endsAt;
    window.__iamOwnerMainJourney.participantIds = [...nextState.participantIds];
  }, {
    title: state.title,
    startsAt: state.startsAt,
    endsAt: state.endsAt,
    participantIds: state.participantIds,
  });
}

async function addGuestListUserFromDashboard(page, { title, existingParticipant, addedParticipant }) {
  await expect(page.locator('.call-title', { hasText: title })).toBeVisible({ timeout: 20_000 });
  await page.getByRole('button', { name: `Edit call ${title}` }).click();

  const modal = page.getByRole('dialog', { name: 'Call compose modal' });
  await expect(modal.getByRole('heading', { name: 'Edit video call' })).toBeVisible();
  await modal.getByText('Replace participant list during edit').click();
  await expect(modal.getByText('Registered users')).toBeVisible();
  await expect(modal.locator('.calls-participant-row', { hasText: existingParticipant.displayName }).getByRole('checkbox')).toBeChecked();
  await modal.locator('.calls-participant-row', { hasText: addedParticipant.displayName }).getByRole('checkbox').check();
  const updateResponsePromise = page.waitForResponse((response) => (
    response.url().includes('/api/calls/')
    && response.request().method() === 'PATCH'
  ));
  await modal.getByRole('button', { name: 'Save changes' }).click();
  expect((await updateResponsePromise).status()).toBe(200);
  await expect(page.locator('.calls-banner.ok')).toContainText('Call updated.');
}

async function openWorkspace(page, state) {
  await page.goto(`/workspace/call/${state.callId}`);
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('.user-row', { hasText: state.actor.displayName })).toBeVisible();
}

async function transferOwner(page, target) {
  const targetRow = page.locator('.user-row', { hasText: target.displayName }).first();
  await expect(targetRow.locator('.user-role')).toContainText('participant');
  await targetRow.getByRole('button', { name: 'Transfer owner role' }).click();
  await expect(targetRow.locator('.user-role')).toContainText('owner');
}

async function proofSnapshot(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    return {
      canModerate: Boolean(setup?.canModerate),
      canManageOwnerRole: Boolean(setup?.canManageOwnerRole),
      rolePatchRequests: window.__iamOwnerMainJourney?.rolePatchRequests || [],
      socketFrames: window.__iamOwnerMainJourney?.socketFrames || [],
    };
  });
}

async function roleProbe(page, state, target, role) {
  return page.evaluate(async ({ callId, targetId, nextRole }) => {
    const response = await fetch(`/api/calls/${callId}/participants/${targetId}/role`, {
      method: 'PATCH',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ role: nextRole }),
    });
    return {
      status: response.status,
      payload: await response.json().catch(() => null),
    };
  }, { callId: state.callId, targetId: target.id, nextRole: role });
}

test('e2e_owner_003_owner_can_manage_guest_list and normal-owner transfer removes call-admin rights', async ({ browser }) => {
  const state = journeyState({
    actor: matrixUsers.user,
    callId: 'call-owner-main-normal',
    roomId: 'room-owner-main-normal',
    title: 'Owner main normal transfer',
  });
  state.initialBrowserParticipantIds = [matrixUsers.admin.id];
  const createdBodies = [];
  const updatedBodies = [];
  const context = await createContext(browser, state);

  try {
    await installRoutes(context, state, createdBodies, updatedBodies);
    const page = await context.newPage();

    await createCallFromList(page, {
      path: '/user/dashboard',
      title: state.title,
      participant: matrixUsers.admin,
    });
    expect(createdBodies).toHaveLength(1);
    expect(createdBodies[0].internal_participant_user_ids).toEqual([matrixUsers.admin.id]);
    expect(buildCall(state).owner.user_id).toBe(matrixUsers.user.id);
    expect(buildCall(state).my_participation.call_role).toBe('owner');

    await syncBrowserJourneyState(page, state);
    await addGuestListUserFromDashboard(page, {
      title: state.title,
      existingParticipant: matrixUsers.admin,
      addedParticipant: guestUser,
    });
    expect(updatedBodies).toHaveLength(1);
    expect(updatedBodies[0].internal_participant_user_ids).toEqual([matrixUsers.admin.id, guestUser.id]);

    await syncBrowserJourneyState(page, state);
    await openWorkspace(page, state);
    await transferOwner(page, matrixUsers.admin);

    const afterTransfer = await proofSnapshot(page);
    expect(afterTransfer.rolePatchRequests).toContainEqual(expect.objectContaining({
      targetUserId: matrixUsers.admin.id,
      role: 'owner',
      allowed: true,
    }));
    expect(afterTransfer.canModerate).toBe(false);
    expect(afterTransfer.canManageOwnerRole).toBe(false);
    expect(afterTransfer.socketFrames.some((frame) => frame?.type === 'room/snapshot/request')).toBe(true);

    const denied = await roleProbe(page, state, guestUser, 'moderator');
    expect(denied.status).toBe(403);
    expect(denied.payload?.error?.code).toBe('forbidden');
  } finally {
    await context.close();
  }
});

test('e2e_journey_017_org_admin_create_call_transfer_owner_keeps_admin_rights', async ({ browser }) => {
  const state = journeyState({
    actor: matrixUsers.admin,
    callId: 'call-owner-main-admin',
    roomId: 'room-owner-main-admin',
    title: 'Owner main admin transfer',
  });
  state.initialBrowserParticipantIds = [matrixUsers.user.id, matrixUsers.outsider.id];
  const createdBodies = [];
  const updatedBodies = [];
  const context = await createContext(browser, state);

  try {
    await installRoutes(context, state, createdBodies, updatedBodies);
    const page = await context.newPage();

    await createCallFromList(page, {
      path: '/admin/calls',
      title: state.title,
      participant: matrixUsers.user,
      participants: [matrixUsers.user, matrixUsers.outsider],
    });
    expect(createdBodies).toHaveLength(1);
    expect(createdBodies[0].internal_participant_user_ids).toEqual([matrixUsers.user.id, matrixUsers.outsider.id]);
    expect(buildCall(state).owner.user_id).toBe(matrixUsers.admin.id);
    expect(buildCall(state).my_participation.call_role).toBe('owner');

    await page.waitForURL(new RegExp(`/workspace/call/${state.callId}(?:[/?#].*)?$`), { timeout: 20_000 });
    await syncBrowserJourneyState(page, state);
    await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
    await transferOwner(page, matrixUsers.user);

    const afterTransfer = await proofSnapshot(page);
    expect(afterTransfer.rolePatchRequests).toContainEqual(expect.objectContaining({
      targetUserId: matrixUsers.user.id,
      role: 'owner',
      allowed: true,
    }));
    expect(afterTransfer.canModerate).toBe(true);

    const retained = await roleProbe(page, state, matrixUsers.outsider, 'moderator');
    expect(retained.status).toBe(200);
    expect(retained.payload?.result?.state).toBe('participant_role_updated');
  } finally {
    await context.close();
  }
});
