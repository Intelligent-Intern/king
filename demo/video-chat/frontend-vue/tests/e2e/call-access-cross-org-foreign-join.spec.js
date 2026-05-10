import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

function parseJsonPostData(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function storedSessionFor(account) {
  return {
    role: 'user',
    displayName: account.displayName,
    email: account.email,
    userId: account.userId,
    accountType: 'account',
    isGuest: false,
    sessionId: account.sessionId,
    sessionToken: account.sessionToken,
    expiresAt: '2026-12-11T10:00:00Z',
  };
}

function authSessionPayload(account, tenant) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: account.sessionId,
      token: account.sessionToken,
      expires_at: '2026-12-11T10:00:00Z',
    },
    user: {
      id: account.userId,
      email: account.email,
      display_name: account.displayName,
      role: 'user',
      status: 'active',
      account_type: 'account',
      is_guest: false,
    },
    tenant,
  };
}

function joinPayload({ accessId, call, linkKind }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId },
      link_kind: linkKind,
      call,
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionSuccessPayload({ issuedSessionId, account, tenant, call }) {
  return {
    status: 'ok',
    result: {
      session: {
        id: issuedSessionId,
        token: issuedSessionId,
        expires_at: '2026-12-11T10:05:00Z',
      },
      user: {
        id: account.userId,
        email: account.email,
        display_name: account.displayName,
        role: 'user',
        status: 'active',
        account_type: 'account',
        is_guest: false,
      },
      tenant,
      call,
    },
  };
}

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
    class FakeWebSocket {
      static OPEN = 1;
      static CLOSED = 3;
      constructor(url) {
        this.url = String(url || '');
        this.readyState = 0;
        this[listenersSymbol] = {};
        setTimeout(() => {
          if (this.readyState === FakeWebSocket.CLOSED) return;
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            admission: { requires_admission: true, pending_room_id: 'lobby' },
          });
        }, 0);
      }
      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
      }
      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((item) => item !== callback);
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
        } catch {
          payload = {};
        }
        if (payload.type === 'lobby/queue/join') {
          this.emit({ type: 'lobby/snapshot', room_id: payload.room_id || 'lobby', pending: [], admitted: [], rejected: [] });
        }
      }
      close(code = 1000, reason = 'test_close') {
        if (this.readyState === FakeWebSocket.CLOSED) return;
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code, reason });
      }
    }
    window.WebSocket = FakeWebSocket;
  });
}

async function createJoinPage(browser, baseURL, account) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  await context.addInitScript(({ key, session }) => {
    localStorage.setItem(key, JSON.stringify(session));
  }, { key: sessionStorageKey, session: storedSessionFor(account) });
  const page = await context.newPage();
  return { context, page };
}

async function routeSessionState(page, account, activeTenant) {
  await page.route('**/api/auth/session-state', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(authSessionPayload(account, activeTenant)),
    });
  });
}

async function readStoredSession(page) {
  return page.evaluate((key) => {
    try {
      return JSON.parse(localStorage.getItem(key) || '{}');
    } catch {
      return {};
    }
  }, sessionStorageKey);
}

const orgAAccount = {
  userId: 71,
  email: 'org-a-cross-context@example.invalid',
  displayName: 'Org A Cross Context',
  sessionId: 'sess_org_a_cross_context',
  sessionToken: 'sess_org_a_cross_context',
};

const orgATenant = {
  id: 501,
  uuid: 'tenant-org-a',
  label: 'Organization A',
  role: 'member',
  permissions: { tenant_admin: false, platform_admin: false },
};

const orgBTenant = {
  id: 502,
  uuid: 'tenant-org-b',
  label: 'Organization B',
  role: 'member',
  permissions: { tenant_admin: false, platform_admin: false },
};

test('cross-org personalized link uses the linked call tenant instead of the browser active organization', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '11111111-1111-4111-8111-111111111111';
  const call = {
    id: 'call-org-a-personalized',
    room_id: 'call-org-a-personalized',
    title: 'Organization A Personalized Link',
  };
  const issuedSessionId = 'sess_org_a_personalized_call_access';
  const { context, page } = await createJoinPage(browser, baseURL, orgAAccount);
  const sessionRequests = [];

  try {
    await routeSessionState(page, orgAAccount, orgBTenant);
    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(joinPayload({ accessId, call, linkKind: 'personal' })),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push(route.request());
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({ issuedSessionId, account: orgAAccount, tenant: orgATenant, call })),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();

    await expect.poll(() => sessionRequests.length).toBe(1);
    const request = sessionRequests[0];
    expect(request.headers().authorization).toBe(`Bearer ${orgAAccount.sessionToken}`);
    expect(parseJsonPostData(request)).toEqual({
      verified_user_id: orgAAccount.userId,
      verified_session_id: orgAAccount.sessionId,
    });
    await expect.poll(async () => (await readStoredSession(page)).sessionToken).toBe(issuedSessionId);
  } finally {
    await context.close();
  }
});

test('foreign anonymous link keeps the logged-in org A account call-scoped in org B', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '22222222-2222-4222-8222-222222222222';
  const call = {
    id: 'call-org-b-open',
    room_id: 'call-org-b-open',
    title: 'Organization B Anonymous Link',
  };
  const issuedSessionId = 'sess_org_a_account_org_b_open_call_access';
  const { context, page } = await createJoinPage(browser, baseURL, orgAAccount);
  const sessionRequests = [];

  try {
    await routeSessionState(page, orgAAccount, orgATenant);
    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(joinPayload({ accessId, call, linkKind: 'open' })),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push(route.request());
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({ issuedSessionId, account: orgAAccount, tenant: orgBTenant, call })),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await joinDialog.getByPlaceholder('Enter your display name').fill('Org A User Via Anonymous Link');
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();

    await expect.poll(() => sessionRequests.length).toBe(1);
    const request = sessionRequests[0];
    expect(request.headers().authorization).toBe(`Bearer ${orgAAccount.sessionToken}`);
    expect(parseJsonPostData(request)).toEqual({
      guest_name: 'Org A User Via Anonymous Link',
      verified_user_id: orgAAccount.userId,
      verified_session_id: orgAAccount.sessionId,
    });
    await expect.poll(async () => (await readStoredSession(page)).sessionToken).toBe(issuedSessionId);
  } finally {
    await context.close();
  }
});

test('foreign personalized link mismatch does not replace the org A session or expose foreign data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '33333333-3333-4333-8333-333333333333';
  const call = {
    id: 'call-org-b-private',
    room_id: 'call-org-b-private',
    title: 'Organization B Private Link',
  };
  const foreignNeedles = [
    'foreign-org-b-invitee@example.invalid',
    'Foreign Organization B Invitee',
    'sess_foreign_org_b_should_not_bind',
  ];
  const { context, page } = await createJoinPage(browser, baseURL, orgAAccount);
  const sessionRequests = [];

  try {
    await routeSessionState(page, orgAAccount, orgATenant);
    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(joinPayload({ accessId, call, linkKind: 'personal' })),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push(route.request());
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_forbidden',
            message: 'This call link is not available for your session.',
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link is not available for your session.');

    await expect.poll(() => sessionRequests.length).toBe(1);
    const request = sessionRequests[0];
    expect(request.headers().authorization).toBe(`Bearer ${orgAAccount.sessionToken}`);
    expect(parseJsonPostData(request)).toEqual({
      verified_user_id: orgAAccount.userId,
      verified_session_id: orgAAccount.sessionId,
    });

    const dialogText = await joinDialog.innerText();
    for (const needle of foreignNeedles) {
      expect(dialogText).not.toContain(needle);
    }
    expect((await readStoredSession(page)).sessionToken).toBe(orgAAccount.sessionToken);
  } finally {
    await context.close();
  }
});
