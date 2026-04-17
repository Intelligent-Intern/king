import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const sessionStorageKey = 'ii_videocall_v1_session';
const inviteFixtureCode = '11111111-1111-4111-8111-111111111111';
const inviteFixtureExpiresAt = '2030-01-01T00:00:00.000Z';

function buildStoredSession(payload) {
  const session = payload?.session || {};
  const user = payload?.user || {};

  return {
    role: String(user.role || '').trim(),
    displayName: String(user.display_name || '').trim(),
    email: String(user.email || '').trim(),
    userId: Number.isInteger(user.id) ? user.id : 0,
    avatarPath: typeof user.avatar_path === 'string' && user.avatar_path.trim() !== '' ? user.avatar_path.trim() : null,
    timeFormat: typeof user.time_format === 'string' && user.time_format.trim() !== '' ? user.time_format.trim() : '24h',
    theme: typeof user.theme === 'string' && user.theme.trim() !== '' ? user.theme.trim() : 'dark',
    status: typeof user.status === 'string' ? user.status.trim() : '',
    sessionId: String(session.id || session.token || '').trim(),
    sessionToken: String(session.token || session.id || '').trim(),
    expiresAt: typeof session.expires_at === 'string' ? session.expires_at.trim() : '',
  };
}

async function fetchStoredSession(email, password) {
  const params = new URLSearchParams({
    email,
    password,
  });

  const response = await fetch(`${backendOrigin}/api/auth/login?${params.toString()}`, {
    method: 'GET',
    headers: {
      accept: 'application/json',
    },
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok || !payload || payload.status !== 'ok') {
    const message = payload?.error?.message || `Login failed (${response.status}).`;
    throw new Error(message);
  }

  return buildStoredSession(payload);
}

async function createAuthenticatedPage(browser, baseURL, { email, password }) {
  const storedSession = await fetchStoredSession(email, password);
  const context = await browser.newContext({ baseURL });
  await context.addInitScript(
    ({ key, value }) => {
      try {
        localStorage.setItem(key, value);
      } catch {
        // Ignore storage bootstrap errors for non-http documents.
      }
    },
    { key: sessionStorageKey, value: JSON.stringify(storedSession) },
  );
  await installInviteFixtureRoutes(context, storedSession);

  const page = await context.newPage();
  return { context, page, storedSession };
}

function parseRequestBody(request) {
  const rawBody = request.postData() || '';
  if (rawBody.trim() === '') return {};

  try {
    const payload = JSON.parse(rawBody);
    return payload && typeof payload === 'object' ? payload : {};
  } catch {
    return {};
  }
}

function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, OPTIONS',
    'access-control-max-age': '86400',
  };
}

function buildInviteFixtureCode({ scope, roomId, callId, issuedByUserId }) {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    code: inviteFixtureCode,
    scope,
    room_id: roomId,
    call_id: callId,
    issued_by_user_id: issuedByUserId,
    expires_at: inviteFixtureExpiresAt,
    expires_in_seconds: 86_400,
    max_redemptions: 1,
    redemption_count: 0,
    created_at: '2026-01-01T00:00:00.000Z',
    expiry_policy: {
      managed_by: 'test_fixture',
      scope_ttl_seconds: 86_400,
    },
    context: {
      [scope]: scope === 'call'
        ? {
            id: callId,
            room_id: roomId,
            title: 'UI parity call',
            status: 'scheduled',
          }
        : {
            id: roomId,
            name: roomId === 'lobby' ? 'Lobby' : roomId,
          },
    },
  };
}

function buildInviteRedemptionFixture({ code, scope, roomId, callId, userId, role }) {
  return {
    invite_code: {
      id: '11111111-1111-4111-8111-111111111111',
      code,
      scope,
      room_id: roomId,
      call_id: callId,
      issued_by_user_id: userId,
      expires_at: inviteFixtureExpiresAt,
      max_redemptions: 1,
      redemption_count: 1,
      remaining_redemptions: 0,
      redeemed_at: '2026-01-01T00:00:00.000Z',
      redeemed_by_user_id: userId,
      created_at: '2026-01-01T00:00:00.000Z',
    },
    join_context: {
      scope,
      room: {
        id: roomId,
        name: roomId === 'lobby' ? 'Lobby' : roomId,
      },
      call: scope === 'call'
        ? {
            id: callId,
            room_id: roomId,
            title: 'UI parity call',
            status: 'scheduled',
            starts_at: '2026-01-01T00:00:00.000Z',
            ends_at: '2026-01-01T01:00:00.000Z',
          }
        : null,
      request_user: {
        user_id: userId,
        role,
      },
    },
    redeemed_at: '2026-01-01T00:00:00.000Z',
  };
}

async function installInviteFixtureRoutes(context, storedSession) {
  const role = String(storedSession?.role || '').trim().toLowerCase() || 'user';
  const userId = Number.isInteger(storedSession?.userId) ? storedSession.userId : 0;

  await context.route(/\/api\/invite-codes$/, async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();

    if (method === 'OPTIONS') {
      await route.fulfill({
        status: 204,
        headers: corsHeaders(),
      });
      return;
    }

    if (method !== 'POST') {
      await route.continue();
      return;
    }

    const body = parseRequestBody(request);
    const scope = String(body.scope || 'room').trim() === 'call' ? 'call' : 'room';
    const roomId = String(body.room_id || 'lobby').trim() || 'lobby';
    const callId = scope === 'call' ? String(body.call_id || 'call-lobby-001').trim() || 'call-lobby-001' : null;

    await route.fulfill({
      status: 200,
      headers: {
        ...corsHeaders(),
        'content-type': 'application/json; charset=utf-8',
      },
      json: {
        status: 'ok',
        result: {
          invite_code: buildInviteFixtureCode({
            scope,
            roomId,
            callId,
            issuedByUserId: userId,
          }),
        },
      },
    });
  });

  await context.route(/\/api\/invite-codes\/redeem$/, async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();

    if (method === 'OPTIONS') {
      await route.fulfill({
        status: 204,
        headers: corsHeaders(),
      });
      return;
    }

    if (method !== 'POST') {
      await route.continue();
      return;
    }

    const body = parseRequestBody(request);
    const code = String(body.code || inviteFixtureCode).trim() || inviteFixtureCode;

    await route.fulfill({
      status: 200,
      headers: {
        ...corsHeaders(),
        'content-type': 'application/json; charset=utf-8',
      },
      json: {
        status: 'ok',
        result: {
          redemption: buildInviteRedemptionFixture({
            code,
            scope: 'room',
            roomId: 'lobby',
            callId: null,
            userId,
            role,
          }),
        },
      },
    });
  });
}

async function signIn(page, { email, password }) {
  await page.goto('/login');
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();

  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

async function openWorkspace(page, roomId, credentials = null) {
  await page.goto(`/workspace/call/${encodeURIComponent(roomId)}`);
  if (credentials && /\/login(\?.*)?$/.test(page.url())) {
    await signIn(page, credentials);
    await page.goto(`/workspace/call/${encodeURIComponent(roomId)}`);
  }
  await expect(page.locator('.workspace-call-head')).toContainText('Call Workspace', { timeout: 12_000 });
  await expect(page.locator('.workspace-call-head')).toContainText(roomId, { timeout: 12_000 });
  await expect(page.locator('.workspace-call-head')).toContainText('Signal online', { timeout: 12_000 });
}

async function clickWorkspaceTab(page, index) {
  await page.locator('.tabs-right [role="tab"]').nth(index).click();
}

async function readCurrentInviteCode(page) {
  const inviteCode = page.locator('.workspace-invite-hint .code').first();
  await expect(inviteCode).toBeVisible();
  const code = (await inviteCode.textContent())?.trim() || '';
  expect(code.length).toBeGreaterThan(0);
  return code;
}

test('admin flow matches the UI-parity admin journey', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const { context, page } = await createAuthenticatedPage(browser, baseURL, {
    email: 'admin@intelligent-intern.com',
    password: 'admin123',
  });

  try {
    await page.goto('/admin/overview');
    await expect(page).toHaveURL(/\/admin\/overview$/);
    await expect(page.locator('h1.title')).toContainText('Admin Overview');

    await page.getByRole('link', { name: 'Video Calls' }).click();
    await expect(page).toHaveURL(/\/admin\/calls$/);
    await expect(page.getByRole('button', { name: 'New Video Call' })).toBeVisible();

    await page.getByRole('tab', { name: 'Calendar' }).click();
    await expect(page.locator('.calls-calendar-wrap')).toBeVisible();
    await page.getByRole('tab', { name: 'Calls' }).click();
    await expect(page.locator('.calls-table-wrap')).toBeVisible();

    await page.goto('/workspace/call/lobby');
    await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
    await expect(page.locator('.workspace-call-head')).toContainText('Call Workspace');
    await expect(page.locator('.workspace-call-head')).toContainText('Signal online');
    await expect(page.locator('.workspace-main-video-status')).toContainText('users');

    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page).toHaveURL(/\/login$/);
  } finally {
    await context.close();
  }
});

test('user flow matches the UI-parity user journey', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const { context, page } = await createAuthenticatedPage(browser, baseURL, {
    email: 'user@intelligent-intern.com',
    password: 'user123',
  });

  try {
    await page.goto('/user/dashboard');
    await expect(page).toHaveURL(/\/user\/dashboard$/);
    await expect(page.locator('h1.title')).toContainText('User Dashboard');

    await page.getByRole('button', { name: 'Join with Invite' }).click();
    const joinModal = page.getByRole('dialog', { name: 'Join invite modal' });
    await expect(joinModal).toBeVisible();
    await joinModal.getByLabel('Invite code').fill(inviteFixtureCode);
    await joinModal.getByRole('button', { name: 'Join' }).click();

    await expect(page).toHaveURL(/\/workspace\/call\/lobby$/);
    await expect(page.locator('.workspace-call-head')).toContainText('Call Workspace');
    await expect(page.locator('.workspace-call-head')).toContainText('Signal online');
    await expect(page.locator('.workspace-call-head')).toContainText('lobby');

    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page).toHaveURL(/\/login$/);
  } finally {
    await context.close();
  }
});

test('two-user call/chat/invite/reconnect journey stays in sync', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const { context: hostContext, page: hostPage } = await createAuthenticatedPage(browser, baseURL, {
    email: 'admin@intelligent-intern.com',
    password: 'admin123',
  });
  const { context: guestContext, page: guestPage } = await createAuthenticatedPage(browser, baseURL, {
    email: 'user@intelligent-intern.com',
    password: 'user123',
  });

  try {
    await hostPage.goto('/admin/overview');
    await expect(hostPage).toHaveURL(/\/admin\/overview$/);
    await guestPage.goto('/user/dashboard');
    await expect(guestPage).toHaveURL(/\/user\/dashboard$/);

    await openWorkspace(hostPage, 'lobby', {
      email: 'admin@intelligent-intern.com',
      password: 'admin123',
    });

    await hostPage.getByRole('button', { name: 'Create invite' }).click();
    const inviteCode = await readCurrentInviteCode(hostPage);

    await openWorkspace(guestPage, 'lobby', {
      email: 'user@intelligent-intern.com',
      password: 'user123',
    });
    await guestPage.getByPlaceholder('Invite code').fill(inviteCode);
    await guestPage.getByRole('button', { name: 'Join' }).click();
    await expect(guestPage.locator('.workspace-call-banner.ok')).toContainText('Joined invite context for room lobby.');
    await expect(hostPage.locator('.workspace-call-head')).toContainText('Signal online');
    await expect(guestPage.locator('.workspace-call-head')).toContainText('Signal online');

    await clickWorkspaceTab(hostPage, 2);
    await expect(hostPage.getByPlaceholder('Write a message')).toBeVisible();
    await clickWorkspaceTab(guestPage, 2);
    await expect(guestPage.getByPlaceholder('Write a message')).toBeVisible();

    await guestPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(guestPage).toHaveURL(/\/workspace\/call\/lobby$/);
    await expect(guestPage.locator('.workspace-call-head')).toContainText('Signal online');
    await clickWorkspaceTab(guestPage, 2);
    await expect(guestPage.getByPlaceholder('Write a message')).toBeVisible();
  } finally {
    await Promise.allSettled([
      hostContext.close(),
      guestContext.close(),
    ]);
  }
});
