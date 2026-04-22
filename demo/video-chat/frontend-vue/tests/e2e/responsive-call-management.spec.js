import { test, expect } from '@playwright/test';
import {
  bootstrapStoredSession,
  corsHeaders,
  matrixUsers,
} from './helpers/videochatMatrixHarness.js';

function installQuietBrowserAPIs(context) {
  return context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');

    class QuietWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = url;
        this.readyState = QuietWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        window.__responsiveSocketUrls = [...(window.__responsiveSocketUrls || []), String(url || '')];
        setTimeout(() => {
          this.readyState = QuietWebSocket.OPEN;
          this.dispatch('open', {});
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
        for (const callback of this[listenersSymbol][type] || []) {
          callback(event);
        }
      }

      send(data) {
        window.__responsiveSocketFrames = [...(window.__responsiveSocketFrames || []), String(data || '')];
      }

      close() {
        this.readyState = QuietWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    }

    window.WebSocket = QuietWebSocket;
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        enumerateDevices: async () => [],
        getUserMedia: async () => new MediaStream(),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  });
}

function userDirectoryRows() {
  return [
    {
      id: matrixUsers.admin.id,
      user_id: matrixUsers.admin.id,
      display_name: matrixUsers.admin.displayName,
      email: matrixUsers.admin.email,
      role: matrixUsers.admin.role,
    },
    {
      id: matrixUsers.user.id,
      user_id: matrixUsers.user.id,
      display_name: matrixUsers.user.displayName,
      email: matrixUsers.user.email,
      role: matrixUsers.user.role,
    },
    {
      id: matrixUsers.outsider.id,
      user_id: matrixUsers.outsider.id,
      display_name: matrixUsers.outsider.displayName,
      email: matrixUsers.outsider.email,
      role: matrixUsers.outsider.role,
    },
  ];
}

function internalParticipantRowsFromIds(ids) {
  const directory = new Map(userDirectoryRows().map((row) => [Number(row.id), row]));
  return (Array.isArray(ids) ? ids : [])
    .map((id) => directory.get(Number(id)))
    .filter(Boolean)
    .map((user) => ({
      user_id: Number(user.id),
      display_name: user.display_name,
      email: user.email,
      call_role: 'participant',
      invite_state: 'invited',
    }));
}

function buildCallFromBody(body, id = 'call-responsive-created') {
  const internalRows = internalParticipantRowsFromIds(body?.internal_participant_user_ids);
  return {
    id,
    room_id: 'room-responsive-created',
    title: String(body?.title || 'Responsive created call'),
    status: 'scheduled',
    access_mode: String(body?.access_mode || 'invite_only'),
    starts_at: String(body?.starts_at || '2026-04-22T12:00:00.000Z'),
    ends_at: String(body?.ends_at || '2026-04-22T12:30:00.000Z'),
    owner: {
      user_id: matrixUsers.user.id,
      display_name: matrixUsers.user.displayName,
      email: matrixUsers.user.email,
    },
    participants: {
      total: internalRows.length + 1,
      internal: internalRows,
      external: [],
    },
    my_participation: {
      call_role: 'owner',
      invite_state: 'allowed',
    },
  };
}

async function installUserDashboardRoutes(context, createdBodies, updatedBodies) {
  let createdCall = null;

  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: corsHeaders() });
      return;
    }

    const url = new URL(request.url());
    const jsonHeaders = { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' };

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: {
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: matrixUsers.user.sessionId,
            token: matrixUsers.user.sessionToken,
            expires_at: '2030-01-01T00:00:00.000Z',
          },
          user: {
            id: matrixUsers.user.id,
            email: matrixUsers.user.email,
            display_name: matrixUsers.user.displayName,
            role: matrixUsers.user.role,
            status: 'active',
            time_format: '24h',
            date_format: 'dmy_dot',
            theme: 'dark',
            account_type: 'account',
            is_guest: false,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/user/directory') {
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: {
          status: 'ok',
          users: userDirectoryRows(),
          pagination: {
            page: 1,
            page_size: 10,
            total: userDirectoryRows().length,
            page_count: 1,
            has_prev: false,
            has_next: false,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/calls' && request.method() === 'POST') {
      const body = request.postDataJSON();
      createdBodies.push(body);
      createdCall = buildCallFromBody(body);
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: { status: 'ok', call: createdCall },
      });
      return;
    }

    if (url.pathname === '/api/calls/call-responsive-created' && request.method() === 'GET' && createdCall) {
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: { status: 'ok', call: createdCall },
      });
      return;
    }

    if (url.pathname === '/api/calls/call-responsive-created' && request.method() === 'PATCH' && createdCall) {
      const body = request.postDataJSON();
      updatedBodies.push(body);
      createdCall = {
        ...buildCallFromBody(body, createdCall.id),
        starts_at: String(body?.starts_at || createdCall.starts_at),
        ends_at: String(body?.ends_at || createdCall.ends_at),
      };
      await route.fulfill({
        status: 200,
        headers: jsonHeaders,
        json: { status: 'ok', call: createdCall },
      });
      return;
    }

    if (url.pathname === '/api/calls' && request.method() === 'GET') {
      const rows = createdCall ? [createdCall] : [];
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

    await route.fulfill({
      status: 404,
      headers: jsonHeaders,
      json: { status: 'error', error: { code: 'not_found', message: `missing fixture: ${url.pathname}` } },
    });
  });
}

test('mobile user can create and edit a call with internal participants', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const createdBodies = [];
  const updatedBodies = [];
  const context = await browser.newContext({
    baseURL,
    viewport: { width: 390, height: 844 },
    isMobile: true,
  });

  try {
    await bootstrapStoredSession(context, matrixUsers.user);
    await installQuietBrowserAPIs(context);
    await installUserDashboardRoutes(context, createdBodies, updatedBodies);

    const page = await context.newPage();
    await page.goto('/user/dashboard');
    await expect(page.getByRole('button', { name: 'New call' })).toBeVisible();

    await page.getByRole('button', { name: 'New call' }).click();
    const modal = page.getByRole('dialog', { name: 'Call compose modal' });
    await expect(modal).toBeVisible();
    await expect(modal.getByText('Registered users')).toBeVisible();

    await modal.getByLabel('Title').fill('Mobile participant coverage');
    const participantRow = modal.locator('.calls-participant-row', { hasText: matrixUsers.admin.displayName });
    await expect(participantRow).toBeVisible();
    await participantRow.getByRole('checkbox').check();

    await modal.getByRole('button', { name: 'Create call' }).click();
    await expect(page.locator('.calls-banner.ok')).toContainText('Call created.');
    await expect(page.locator('.call-title')).toContainText('Mobile participant coverage');

    expect(createdBodies).toHaveLength(1);
    expect(createdBodies[0].title).toBe('Mobile participant coverage');
    expect(createdBodies[0].internal_participant_user_ids).toEqual([matrixUsers.admin.id]);

    await page.getByRole('button', { name: 'Edit call Mobile participant coverage' }).click();
    await expect(modal.getByRole('heading', { name: 'Edit video call' })).toBeVisible();
    await modal.getByLabel('Title').fill('Mobile participant coverage edited');
    await modal.getByText('Replace participant list during edit').click();

    const newParticipantRow = modal.locator('.calls-participant-row', { hasText: matrixUsers.outsider.displayName });
    await expect(newParticipantRow).toBeVisible();
    await newParticipantRow.getByRole('checkbox').check();

    await modal.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('.calls-banner.ok')).toContainText('Call updated.');
    await expect(page.locator('.call-title')).toContainText('Mobile participant coverage edited');

    expect(updatedBodies).toHaveLength(1);
    expect(updatedBodies[0].title).toBe('Mobile participant coverage edited');
    expect(updatedBodies[0].internal_participant_user_ids).toEqual([
      matrixUsers.admin.id,
      matrixUsers.outsider.id,
    ]);
  } finally {
    await context.close();
  }
});
