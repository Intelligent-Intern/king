import { test, expect } from '@playwright/test';

import {
  installCallAccessMediaDeviceShim,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';

const accessId = '33333333-3333-4333-8333-333333333333';
const joinPath = `/join/${accessId}`;
const call = {
  id: 'calendar-unregistered-call',
  room_id: 'calendar-unregistered-room',
  title: 'Calendar Invite Lobby Call',
  status: 'active',
  starts_at: '2026-05-10T14:00:00.000Z',
  ends_at: '2026-05-10T14:30:00.000Z',
};
const inviteeEmail = 'calendar-unregistered-invitee@example.test';
const guestUserId = 9303;
const guestName = 'Calendar Walk-In Guest';
const session = {
  id: 'sess_calendar_unregistered_guest_lobby',
  token: 'sess_calendar_unregistered_guest_lobby',
  token_type: 'session_id',
  issued_at: '2026-05-10T13:55:00.000Z',
  expires_at: '2030-01-01T00:00:00.000Z',
  expires_in_seconds: 43200,
};
const tenant = {
  id: 1414,
  uuid: 'tenant-calendar-unregistered',
  label: 'Calendar Invite Tenant',
  role: 'guest',
  permissions: {
    platform_admin: false,
    tenant_admin: false,
    manage_lobby: false,
    admit_participants: false,
  },
};

function jsonHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'content-type': 'application/json; charset=utf-8',
  };
}

async function fulfillJson(route, status, payload) {
  await route.fulfill({
    status,
    headers: jsonHeaders(),
    body: JSON.stringify(payload),
  });
}

function readJsonPostData(request) {
  try {
    return JSON.parse(request.postData() || '{}');
  } catch {
    return null;
  }
}

function ownerPayload() {
  return {
    user_id: 3101,
    display_name: 'Calendar Host',
    email: 'calendar-host@example.test',
  };
}

function callPayload(inviteState = 'pending') {
  return {
    ...call,
    owner: ownerPayload(),
    participants: {
      total: 1,
      internal: [
        {
          user_id: ownerPayload().user_id,
          display_name: ownerPayload().display_name,
          email: ownerPayload().email,
          call_role: 'owner',
          invite_state: 'allowed',
          joined_at: null,
          connected_at: null,
        },
      ],
      external: [
        {
          participant_email: inviteeEmail,
          invite_state: inviteState,
          booking_origin: 'calendar',
        },
      ],
    },
    my_participation: {
      call_role: 'participant',
      invite_state: inviteState,
    },
  };
}

function accessLinkPayload() {
  return {
    id: accessId,
    call_id: call.id,
    room_id: call.room_id,
    tenant_id: tenant.id,
    link_kind: 'personal',
    participant_user_id: null,
    participant_email: inviteeEmail,
    created_by_user_id: ownerPayload().user_id,
    created_at: '2026-05-10T13:45:00.000Z',
    expires_at: '2030-01-01T00:00:00.000Z',
    consumed_at: null,
    last_used_at: null,
    booking_origin: 'calendar',
  };
}

function guestUserPayload(displayName) {
  return {
    id: guestUserId,
    display_name: displayName,
    email: inviteeEmail,
    role: 'user',
    account_type: 'guest',
    is_guest: true,
    status: 'active',
    tenant,
  };
}

async function installCalendarInviteRoutes(context, counters) {
  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: jsonHeaders() });
      return;
    }

    const url = new URL(request.url());
    if (url.pathname === `/api/call-access/${accessId}/join` && request.method() === 'GET') {
      counters.join += 1;
      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'resolved',
          access_link: accessLinkPayload(),
          link_kind: 'personal',
          call: callPayload('pending'),
          target_user: null,
          target_hint: {
            participant_email: inviteeEmail,
            booking_origin: 'calendar',
          },
          requires_guest_name: true,
          join_path: joinPath,
        },
        time: '2026-05-10T13:55:00.000Z',
      });
      return;
    }

    if (url.pathname === `/api/call-access/${accessId}/session` && request.method() === 'POST') {
      counters.session += 1;
      const body = readJsonPostData(request);
      counters.sessionBodies.push(body);
      const displayName = String(body?.guest_name || '').trim();
      if (displayName === '') {
        await fulfillJson(route, 422, {
          status: 'error',
          error: { code: 'call_access_validation_failed', message: 'Guest name is required.' },
        });
        return;
      }

      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'session_started',
          session,
          user: guestUserPayload(displayName),
          tenant,
          access_link: accessLinkPayload(),
          link_kind: 'personal',
          call: callPayload('pending'),
          requires_guest_name: true,
          join_path: joinPath,
        },
        time: '2026-05-10T13:55:01.000Z',
      });
      return;
    }

    if (
      (url.pathname === `/api/calls/resolve/${call.id}` || url.pathname === `/api/calls/${call.id}`)
      && request.method() === 'GET'
    ) {
      counters.directCallAccess += 1;
      await fulfillJson(route, 403, {
        status: 'error',
        error: { code: 'calls_forbidden', message: 'Host admission is required.' },
      });
      return;
    }

    await fulfillJson(route, 404, {
      status: 'error',
      error: { code: 'not_found', message: `Unexpected IAM2 calendar invite route: ${url.pathname}` },
    });
  });
}

async function installCalendarInviteLobbySocket(context) {
  await context.addInitScript(({ roomId, callId }) => {
    const listenersSymbol = Symbol('listeners');
    window.__iamCalendarInviteSocketFrames = [];
    window.__iamCalendarInviteSocketEvents = [];
    window.__iamCalendarInviteSockets = [];

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = String(url || '');
        this.readyState = FakeWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        window.__iamCalendarInviteSockets.push(this);
        setTimeout(() => {
          if (this.readyState === FakeWebSocket.CLOSED) return;
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: roomId,
            admission: {
              requires_admission: true,
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
        window.__iamCalendarInviteSocketEvents.push(payload);
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        let payload = null;
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {
          payload = { type: 'invalid_json' };
        }
        window.__iamCalendarInviteSocketFrames.push(payload);
        if (payload.type === 'lobby/queue/join') {
          setTimeout(() => {
            this.emit({
              type: 'lobby/snapshot',
              room_id: roomId,
              call_id: callId,
              pending: [
                {
                  user_id: guestUserId,
                  display_name: guestName,
                  source: 'calendar',
                },
              ],
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

    window.WebSocket = FakeWebSocket;
  }, { roomId: call.room_id, callId: call.id });
}

test('unregistered calendar invitee must provide a guest name and wait in lobby for host admission', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const counters = {
    join: 0,
    session: 0,
    sessionBodies: [],
    directCallAccess: 0,
  };
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessMediaDeviceShim(context);
  await installCalendarInviteRoutes(context, counters);
  await installCalendarInviteLobbySocket(context);
  const page = await context.newPage();

  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(joinPath);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.status).toBe('ok');
    expect(joinPayload?.result?.link_kind).toBe('personal');
    expect(joinPayload?.result?.requires_guest_name).toBe(true);
    expect(joinPayload?.result?.target_user).toBeNull();
    expect(joinPayload?.result?.target_hint?.participant_email).toBe(inviteeEmail);
    expect(joinPayload?.result?.target_hint?.booking_origin).toBe('calendar');
    expect(joinPayload?.result?.call?.id).toBe(call.id);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Personalized link');
    const guestNameInput = joinDialog.getByPlaceholder('Enter your display name');
    await expect(guestNameInput).toBeVisible();

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('Name is required for this link.');
    await page.waitForTimeout(250);
    expect(counters.session).toBe(0);
    expect(counters.directCallAccess).toBe(0);

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await guestNameInput.fill(guestName);
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(counters.sessionBodies).toEqual([{ guest_name: guestName }]);
    expect(sessionPayload?.status).toBe('ok');
    expect(sessionPayload?.result?.user?.id).toBe(guestUserId);
    expect(sessionPayload?.result?.user?.display_name).toBe(guestName);
    expect(sessionPayload?.result?.user?.email).toBe(inviteeEmail);
    expect(sessionPayload?.result?.user?.role).toBe('user');
    expect(sessionPayload?.result?.user?.account_type).toBe('guest');
    expect(sessionPayload?.result?.user?.is_guest).toBe(true);
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
    expect(JSON.stringify(sessionPayload)).not.toMatch(/\b(?:sdp|ice|candidate|media_token|turn_credential)\b/i);

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    await expect(page).toHaveURL(new RegExp(`${joinPath.replaceAll('-', '\\-')}(?:[/?#].*)?$`));
    expect(page.url()).not.toContain('/workspace/call');

    const socketFrames = await page.evaluate(() => window.__iamCalendarInviteSocketFrames || []);
    expect(socketFrames.some((frame) => (
      frame?.type === 'lobby/queue/join'
      && frame?.room_id === call.room_id
    ))).toBe(true);
    expect(socketFrames.some((frame) => String(frame?.type || '').startsWith('call/'))).toBe(false);

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionToken).toBe(session.token);
    expect(storedSession.sessionId).toBe(session.id);

    await page.waitForTimeout(250);
    expect(page.url()).toContain(joinPath);
    expect(page.url()).not.toContain('/workspace/call');
    expect(counters.directCallAccess).toBe(0);
  } finally {
    await context.close();
  }
});
