import { test, expect } from '@playwright/test';
import {
  bootstrapStoredSession,
  corsHeaders,
  matrixUsers,
} from './helpers/videochatMatrixHarness.js';

function jsonHeaders() {
  return { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' };
}

function callFixture({ id, title, status, owner, currentUser }) {
  const startsAt = status === 'ended' ? '2026-04-20T12:00:00.000Z' : '2026-04-22T08:00:00.000Z';
  const endsAt = status === 'ended' ? '2026-04-20T13:00:00.000Z' : '2030-01-01T00:00:00.000Z';

  return {
    id,
    room_id: `room-${id}`,
    title,
    status,
    access_mode: 'invite_only',
    starts_at: startsAt,
    ends_at: endsAt,
    owner: {
      user_id: owner.id,
      display_name: owner.displayName,
      email: owner.email,
    },
    participants: {
      total: 2,
      internal: 2,
      external: 0,
    },
    my_participation: {
      call_role: owner.id === currentUser.id ? 'owner' : 'participant',
      invite_state: 'allowed',
    },
  };
}

function callsForPage(page, currentUser) {
  const owner = currentUser.role === 'admin' ? matrixUsers.admin : matrixUsers.user;

  if (page === 2) {
    return [
      callFixture({
        id: 'shared-surface-follow-up',
        title: 'Shared Surface Follow-up',
        status: 'active',
        owner,
        currentUser,
      }),
    ];
  }

  return [
    callFixture({
      id: 'shared-surface-call',
      title: 'Shared Surface Call',
      status: 'scheduled',
      owner,
      currentUser,
    }),
    callFixture({
      id: 'shared-surface-archive',
      title: 'Shared Surface Archive',
      status: 'ended',
      owner,
      currentUser,
    }),
  ];
}

async function installQuietBrowserApis(context) {
  await context.addInitScript(() => {
    const listeners = Symbol('listeners');

    class QuietWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = url;
        this.readyState = QuietWebSocket.CONNECTING;
        this[listeners] = {};
        setTimeout(() => {
          this.readyState = QuietWebSocket.OPEN;
          this.dispatchEvent({ type: 'open' });
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listeners][type]) this[listeners][type] = [];
        this[listeners][type].push(callback);
      }

      removeEventListener(type, callback) {
        this[listeners][type] = (this[listeners][type] || []).filter((listener) => listener !== callback);
      }

      dispatchEvent(event) {
        const type = String(event?.type || '');
        for (const callback of this[listeners][type] || []) {
          callback(event);
        }
      }

      send() {}

      close() {
        this.readyState = QuietWebSocket.CLOSED;
        this.dispatchEvent({ type: 'close', code: 1000, reason: 'test_close' });
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

async function installSharedSurfaceRoutes(context, currentUser, requestLog) {
  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: corsHeaders() });
      return;
    }

    const url = new URL(request.url());
    requestLog.push({ method: request.method(), pathname: url.pathname, searchParams: url.searchParams.toString() });

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill({
        status: 200,
        headers: jsonHeaders(),
        json: {
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: currentUser.sessionId,
            token: currentUser.sessionToken,
            expires_at: '2030-01-01T00:00:00.000Z',
          },
          user: {
            id: currentUser.id,
            email: currentUser.email,
            display_name: currentUser.displayName,
            role: currentUser.role,
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

    const chatArchiveMatch = url.pathname.match(/^\/api\/calls\/([^/]+)\/chat-archive$/);
    if (chatArchiveMatch) {
      const callId = chatArchiveMatch[1];
      await route.fulfill({
        status: 200,
        headers: jsonHeaders(),
        json: {
          status: 'ok',
          result: {
            state: 'loaded',
            archive: {
              call_id: callId,
              room_id: `room-${callId}`,
              read_only: true,
              messages: [
                {
                  id: `msg-${callId}`,
                  text: 'shared smoke message',
                  sender: {
                    user_id: currentUser.id,
                    display_name: currentUser.displayName,
                    role: currentUser.role,
                  },
                  server_time: '2026-04-22T08:05:00.000Z',
                  attachments: [],
                },
              ],
              files: {
                groups: {
                  images: [],
                  pdfs: [],
                  office: [],
                  text: [],
                  documents: [],
                },
              },
              pagination: {
                cursor: 0,
                limit: 50,
                returned: 1,
                has_next: false,
                next_cursor: null,
              },
            },
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/calls' && request.method() === 'GET') {
      const page = Number.parseInt(url.searchParams.get('page') || '1', 10);
      await route.fulfill({
        status: 200,
        headers: jsonHeaders(),
        json: {
          status: 'ok',
          calls: callsForPage(page, currentUser),
          pagination: {
            page,
            page_size: Number.parseInt(url.searchParams.get('page_size') || '10', 10),
            total: 12,
            page_count: 2,
            returned: page === 2 ? 1 : 2,
            has_prev: page > 1,
            has_next: page < 2,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/user/directory' || url.pathname === '/api/admin/users') {
      await route.fulfill({
        status: 200,
        headers: jsonHeaders(),
        json: {
          status: 'ok',
          users: [],
          pagination: {
            page: 1,
            page_size: 10,
            total: 0,
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
      headers: jsonHeaders(),
      json: { status: 'error', error: { code: 'not_found', message: `missing fixture: ${url.pathname}` } },
    });
  });
}

async function newSharedSurfacePage(browser, user) {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const context = await browser.newContext({ baseURL });
  const requestLog = [];

  await bootstrapStoredSession(context, user);
  await installQuietBrowserApis(context);
  await installSharedSurfaceRoutes(context, user, requestLog);

  return { context, page: await context.newPage(), requestLog };
}

test('user call list keeps shared table, pagination, and archive modal behavior', async ({ browser }) => {
  const { context, page, requestLog } = await newSharedSurfacePage(browser, matrixUsers.user);

  try {
    await page.goto('/user/dashboard');

    const table = page.locator('.calls-list-table');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(table).toContainText('Shared Surface Call');
    await expect(page.locator('.calls-pagination-wrap .page-info')).toContainText('Page 1 / 2');
    await expect(page.getByRole('button', { name: 'Cancel call Shared Surface Call' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Delete call Shared Surface Call' })).toHaveCount(0);

    await page.locator('.calls-pagination-wrap .pager-icon-btn').nth(1).click();
    await expect(table).toContainText('Shared Surface Follow-up');
    expect(requestLog.some((entry) => entry.pathname === '/api/calls' && entry.searchParams.includes('page=2'))).toBe(true);

    await page.getByRole('button', { name: 'Open chat archive for Shared Surface Follow-up' }).click();
    await expect(page.getByTestId('chat-archive-modal')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Chat archive' })).toBeVisible();
    await expect(page.getByLabel('Archived chat messages')).toContainText('shared smoke message');
  } finally {
    await context.close();
  }
});

test('admin call list keeps shared table admin actions and footer pagination surface', async ({ browser }) => {
  const { context, page } = await newSharedSurfacePage(browser, matrixUsers.admin);

  try {
    await page.goto('/admin/calls');

    const table = page.locator('.calls-list-table.calls-list-table-admin');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(table).toContainText('Shared Surface Call');
    await expect(page.locator('.calls-pagination-wrap .page-info')).toContainText('Page 1 / 2');
    await expect(page.getByRole('button', { name: 'Edit call Shared Surface Call' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enter video call Shared Surface Call' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel call Shared Surface Call' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete call Shared Surface Call' })).toBeVisible();

    await page.getByRole('button', { name: 'Open chat archive for Shared Surface Call' }).click();
    await expect(page.getByTestId('chat-archive-modal')).toBeVisible();
    await expect(page.getByLabel('Archived chat messages')).toContainText('shared smoke message');
  } finally {
    await context.close();
  }
});
